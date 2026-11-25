<?php

use App\Models\CareRecordAddendum;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('local');
    $this->co = $this->company(['business' => 'medical']);
    $this->servicio = $this->servicePriced($this->co->tenant, 120, 'Consulta');
    $this->doctora = $this->professional($this->co, 'Elena Ruiz');
    $this->otro = $this->professional($this->co, 'Pablo Otro');
    $this->paciente = $this->customer($this->co->tenant, 'Mario', 'Salas');

    $plantilla = (string) Str::uuid();
    DB::table('record_templates')->insert([
        'id' => $plantilla, 'tenant_id' => $this->co->tenant->id, 'name' => 'Consulta general',
        'fields' => json_encode([['key' => 'motivo', 'label' => 'Motivo', 'type' => 'textarea', 'required' => false]]),
    ]);
    DB::table('record_template_services')->insert(['tenant_id' => $this->co->tenant->id, 'record_template_id' => $plantilla, 'service_id' => $this->servicio->id]);

    $this->cita = $this->book($this->co, $this->paciente, $this->servicio, $this->doctora, $this->at($this->co->tenant, '09:00'))->assertCreated()->json('id');
    $this->ficha = fn (array $extra = []) => $this->as($this->doctora->user)
        ->putJson("/api/appointments/{$this->cita}/record", $extra + ['data' => ['motivo' => 'Dolor de cabeza']])
        ->assertOk()
        ->json('id');
});

describe('corregir una ficha firmada', function () {
    it('ya no se cambia: se corrige con una nota con autor y fecha, y lo firmado queda igual', function () {
        $id = ($this->ficha)(['sign' => true]);

        $this->as($this->doctora->user)
            ->putJson("/api/appointments/{$this->cita}/record", ['data' => ['motivo' => 'Dolor de cabeza y mareos'], 'correction_reason' => 'Faltó anotar los mareos'])
            ->assertStatus(422);

        $this->as($this->doctora->user)
            ->postJson("/api/care-records/{$id}/addenda", ['note' => 'También refiere mareos'])
            ->assertSuccessful();

        $nota = CareRecordAddendum::where('care_record_id', $id)->first();

        expect($nota)->not->toBeNull()
            ->and($nota->author_user_id)->toBe($this->doctora->user->id)
            ->and($nota->note)->toContain('También refiere mareos')
            ->and(\App\Models\CareRecord::find($id)->data)->toBe(['motivo' => 'Dolor de cabeza']);
    });

    it('a una ficha firmada se le pueden agregar archivos', function () {
        $id = ($this->ficha)(['sign' => true]);

        $this->as($this->doctora->user)
            ->post("/api/care-records/{$id}/files", ['file' => UploadedFile::fake()->create('resultado.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated();

        $cita = DB::table('care_records')->where('id', $id)->value('appointment_id');
        $this->as($this->doctora->user)->getJson("/api/appointments/{$cita}/record")->assertOk()->assertJsonPath('record.can_attach', true);
    });

    it('un borrador se cambia sin dejar notas', function () {
        $id = ($this->ficha)();
        ($this->ficha)(['data' => ['motivo' => 'Otra cosa']]);

        expect(CareRecordAddendum::where('care_record_id', $id)->count())->toBe(0);
    });
});

describe('ver todas las fichas', function () {
    it('otro profesional sin el permiso no ve la ficha; con el permiso sí', function () {
        $id = ($this->ficha)(['sign' => true]);

        $this->as($this->otro->user)->getJson("/api/care-records/{$id}")->assertForbidden();

        $rol = DB::table('roles')->where('tenant_id', $this->co->tenant->id)->where('slug', 'professional')->value('id');
        DB::table('role_permissions')->insert(['role_id' => $rol, 'permission_id' => DB::table('permissions')->where('name', 'records.all')->value('id')]);

        $this->as(\App\Models\User::find($this->otro->user->id))->getJson("/api/care-records/{$id}")->assertOk();
    });

    it('un profesional con un rol propio de otro nombre sigue viendo solo sus fichas', function () {
        $id = ($this->ficha)(['sign' => true]);

        $tenant = $this->co->tenant->id;
        $rolPropio = (string) Str::uuid();
        DB::table('roles')->insert(['id' => $rolPropio, 'tenant_id' => $tenant, 'name' => 'Especialista', 'slug' => 'especialista', 'scope' => 'tenant', 'permissions_seeded_at' => now()]);
        DB::table('role_permissions')->insert(['role_id' => $rolPropio, 'permission_id' => DB::table('permissions')->where('name', 'records.view')->value('id')]);
        DB::table('user_roles')->where('user_id', $this->otro->user->id)->update(['role_id' => $rolPropio]);

        $otro = \App\Models\User::find($this->otro->user->id);

        $this->as($otro)->getJson("/api/care-records/{$id}")->assertForbidden();
        expect(collect($this->as($otro)->getJson('/api/care-records')->assertOk()->json('data') ?? [])->pluck('id'))->not->toContain($id);
    });
});

describe('archivos adjuntos', function () {
    it('se adjunta un PDF, se abre con un enlace firmado y la lectura queda anotada', function () {
        $id = ($this->ficha)();

        $this->as($this->doctora->user)
            ->post("/api/care-records/{$id}/files", ['file' => UploadedFile::fake()->create('examen.pdf', 300, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('files.0.name', 'examen.pdf');

        $archivo = DB::table('care_record_files')->where('care_record_id', $id)->first();
        Storage::disk('local')->assertExists($archivo->path);
        expect($archivo->path)->not->toStartWith('public');

        $enlace = $this->as($this->doctora->user)->getJson("/api/care-records/{$id}/files/{$archivo->id}/link")->assertOk()->json('url');
        $partes = parse_url($enlace);

        $this->app['auth']->forgetGuards();
        $this->get($partes['path'] . '?' . $partes['query'])->assertOk();
        $this->get($partes['path'])->assertForbidden();

        expect(DB::table('care_record_reads')->where('care_record_id', $id)->where('action', 'file')->exists())->toBeTrue();
    });

    it('rechaza archivos muy pesados o de otro tipo', function () {
        $id = ($this->ficha)();

        $this->as($this->doctora->user)
            ->post("/api/care-records/{$id}/files", ['file' => UploadedFile::fake()->create('grande.pdf', 11000, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');

        $this->as($this->doctora->user)
            ->post("/api/care-records/{$id}/files", ['file' => UploadedFile::fake()->create('programa.exe', 10, 'application/x-msdownload')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('file');
    });

    it('sin permiso para llenar fichas no se adjunta; firmada, el archivo ya no se quita', function () {
        $id = ($this->ficha)();
        $caja = $this->staff($this->co, 'cashier');

        $this->as($caja)
            ->post("/api/care-records/{$id}/files", ['file' => UploadedFile::fake()->create('examen.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->as($this->doctora->user)
            ->post("/api/care-records/{$id}/files", ['file' => UploadedFile::fake()->create('examen.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertCreated();
        $archivo = DB::table('care_record_files')->where('care_record_id', $id)->value('id');

        ($this->ficha)(['sign' => true]);

        $this->as($this->doctora->user)->deleteJson("/api/care-records/{$id}/files/{$archivo}")->assertStatus(422);
    });
});

it('completar sin la ficha firmada responde 409 RECORD_REQUIRED', function () {
    $this->setRules($this->co->tenant, ['records.require_on_complete' => true, 'pos.pay_before_service' => false, 'agenda.checkin_step' => false]);

    $this->as($this->co->owner)->postJson("/api/appointments/{$this->cita}/status", ['status' => 'completed'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'RECORD_REQUIRED');
});
