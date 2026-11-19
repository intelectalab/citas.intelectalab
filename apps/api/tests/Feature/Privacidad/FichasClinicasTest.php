<?php

use App\Models\CareRecord;
use App\Models\CareRecordAddendum;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->co = $this->company(['business' => 'medical']);
    $this->servicio = $this->servicePriced($this->co->tenant, 120, 'Consulta');
    $this->doctora = $this->professional($this->co, 'Elena Ruiz');
    $this->paciente = $this->customer($this->co->tenant, 'Mario', 'Salas');

    $this->plantilla = (string) Str::uuid();
    DB::table('record_templates')->insert([
        'id' => $this->plantilla, 'tenant_id' => $this->co->tenant->id, 'name' => 'Consulta general',
        'fields' => json_encode([['key' => 'motivo', 'label' => 'Motivo', 'type' => 'textarea', 'required' => false]]),
    ]);
    DB::table('record_template_services')->insert(['tenant_id' => $this->co->tenant->id, 'record_template_id' => $this->plantilla, 'service_id' => $this->servicio->id]);

    $this->cita = $this->book($this->co, $this->paciente, $this->servicio, $this->doctora, $this->at($this->co->tenant, '09:00'))->assertCreated()->json('id');
    $this->escribirFicha = fn (string $motivo = 'Dolor de cabeza desde hace tres días', bool $firmar = false): string => $this->as($this->doctora->user)
        ->putJson("/api/appointments/{$this->cita}/record", ['data' => ['motivo' => $motivo], 'sign' => $firmar])
        ->assertOk()
        ->json('id');
});

it('guarda lo escrito en la ficha cifrado en la base y lo muestra normal', function () {
    $id = ($this->escribirFicha)();

    $crudo = DB::table('care_records')->where('id', $id)->value('data');

    expect($crudo)->not->toContain('Dolor de cabeza')
        ->and(json_decode(Crypt::decryptString($crudo), true))->toBe(['motivo' => 'Dolor de cabeza desde hace tres días'])
        ->and(CareRecord::find($id)->data)->toBe(['motivo' => 'Dolor de cabeza desde hace tres días']);

    $this->as($this->doctora->user)->getJson("/api/appointments/{$this->cita}/record")
        ->assertOk()
        ->assertJsonPath('record.data.motivo', 'Dolor de cabeza desde hace tres días');
});

it('las notas agregadas a una ficha firmada también se guardan cifradas', function () {
    $id = ($this->escribirFicha)('Control', true);

    $this->as($this->doctora->user)->postJson("/api/care-records/{$id}/addenda", ['note' => 'Mario refiere mejoría'])->assertOk()
        ->assertJsonPath('addenda.0.note', 'Mario refiere mejoría');

    $crudo = DB::table('care_record_addenda')->where('care_record_id', $id)->value('note');

    expect($crudo)->not->toContain('Mario')
        ->and(CareRecordAddendum::where('care_record_id', $id)->value('note'))->toBe('Mario refiere mejoría');
});

it('firmar sin cambios no se anota como corrección', function () {
    $id = ($this->escribirFicha)('Revisión', true);

    $this->as($this->doctora->user)->putJson("/api/appointments/{$this->cita}/record", ['data' => ['motivo' => 'Revisión']])->assertOk();

    expect(DB::table('audit_events')->where('entity_id', $id)->where('action', 'record.corrected')->count())->toBe(0);
});

it('una ficha antigua guardada sin cifrar se sigue leyendo', function () {
    $id = ($this->escribirFicha)();
    DB::table('care_records')->where('id', $id)->update(['data' => json_encode(['motivo' => 'Texto antiguo'])]);

    expect(CareRecord::find($id)->data)->toBe(['motivo' => 'Texto antiguo']);
});

it('la búsqueda de fichas por nombre del paciente sigue funcionando', function () {
    ($this->escribirFicha)();

    $filas = $this->as($this->co->owner)->getJson('/api/care-records?search=Salas')->assertOk()->json();

    expect($filas)->toHaveCount(1)
        ->and($filas[0]['customer']['name'])->toContain('Mario');
});

it('anota quién abrió la ficha y no repite la misma lectura en pocos minutos', function () {
    $id = ($this->escribirFicha)();

    $this->as($this->co->owner)->getJson("/api/appointments/{$this->cita}/record")->assertOk();
    $this->as($this->co->owner)->getJson("/api/appointments/{$this->cita}/record")->assertOk();

    expect(DB::table('care_record_reads')->where('care_record_id', $id)->where('user_id', $this->co->owner->id)->count())->toBe(1);

    $this->travel(11)->minutes();
    $this->as($this->co->owner)->getJson("/api/care-records/{$id}")->assertOk();

    expect(DB::table('care_record_reads')->where('care_record_id', $id)->where('user_id', $this->co->owner->id)->pluck('action')->sort()->values()->all())
        ->toBe(['appointment', 'view']);
});

it('quien administra ve la lista de lecturas y el profesional no', function () {
    $id = ($this->escribirFicha)();
    $this->as($this->doctora->user)->getJson("/api/care-records/{$id}")->assertOk()
        ->assertJsonPath('can_see_reads', false)
        ->assertJsonPath('reads', []);

    $respuesta = $this->as($this->co->owner)->getJson("/api/appointments/{$this->cita}/record")->assertOk()
        ->assertJsonPath('record.can_see_reads', true);

    $quienes = collect($respuesta->json('record.reads'))->pluck('by')->all();

    expect($quienes)->toContain('Elena Ruiz')
        ->and(collect($respuesta->json('record.reads'))->pluck('action')->all())->toContain('view', 'appointment');
});

it('al ver una ficha anterior mientras atiende otra cita, también queda anotado', function () {
    $id = ($this->escribirFicha)('Primera consulta', true);
    $otra = $this->book($this->co, $this->paciente, $this->servicio, $this->doctora, $this->at($this->co->tenant, '11:00', 4))->assertCreated()->json('id');
    $gerente = $this->staff($this->co, 'manager', null, 'Gina Lopez');

    $this->as($gerente)->getJson("/api/appointments/{$otra}/record")->assertOk()->assertJsonCount(1, 'previous');

    expect(DB::table('care_record_reads')->where('care_record_id', $id)->where('user_id', $gerente->id)->value('action'))->toBe('previous');
});

it('una ficha privada que no se puede leer no anota lectura', function () {
    $id = ($this->escribirFicha)();
    DB::table('care_records')->where('id', $id)->update(['is_private' => true]);

    $this->as($this->co->owner)->getJson("/api/care-records/{$id}")->assertOk()->assertJsonPath('hidden', true);

    expect(DB::table('care_record_reads')->where('care_record_id', $id)->where('user_id', $this->co->owner->id)->count())->toBe(0);
});

it('el acceso de emergencia a una ficha privada queda anotado como tal', function () {
    $id = ($this->escribirFicha)();
    DB::table('care_records')->where('id', $id)->update(['is_private' => true]);

    $this->as($this->co->owner)->postJson("/api/care-records/{$id}/emergency-access", ['reason' => 'El paciente llegó descompensado'])->assertOk();

    $lectura = DB::table('care_record_reads')->where('care_record_id', $id)->where('user_id', $this->co->owner->id)->first();

    expect($lectura->action)->toBe('emergency')
        ->and($lectura->by_emergency)->toBeTrue();
});

it('las lecturas de otra empresa no se mezclan', function () {
    $id = ($this->escribirFicha)();
    $otra = $this->company(['business' => 'medical']);

    $this->as($otra->owner)->getJson("/api/care-records/{$id}")->assertNotFound();

    expect(DB::table('care_record_reads')->where('care_record_id', $id)->where('user_id', $otra->owner->id)->count())->toBe(0);
});
