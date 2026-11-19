<?php

use App\Models\PlatformSetting;
use App\Models\User;
use App\Support\Complaints;
use App\Support\PlatformMail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function reclamo(array $extra = []): array
{
    return $extra + [
        'name'             => 'Rosa Quispe Huamán',
        'document_type'    => 'DNI',
        'document_number'  => '45678912',
        'address'          => 'Av. Arequipa 1234, Lince',
        'phone'            => '987654321',
        'email'            => 'rosa.quispe@correo.test',
        'is_minor'         => false,
        'good_type'        => 'servicio',
        'good_description' => 'Corte de cabello',
        'amount'           => 35,
        'kind'             => 'reclamo',
        'detail'           => 'Pagué un corte con lavado y no me hicieron el lavado.',
        'request'          => 'Que me devuelvan la diferencia.',
        'accepted'         => true,
    ];
}

function adminDelLibro(): User
{
    return User::forceCreate([
        'id' => (string) Str::uuid(),
        'first_name' => 'Admin', 'last_name' => 'Libro', 'email' => 'libro.' . Str::lower(Str::random(5)) . '@plataforma.test',
        'password_hash' => bcrypt('clave-de-prueba-123'), 'status' => 'active', 'tenant_id' => null,
    ]);
}

function correosA(string $to): array
{
    return array_values(array_filter(PlatformMail::$outbox ?? [], fn ($mail) => $mail['to'] === $to));
}

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-03-30 10:00', 'America/Lima'));
});

describe('plazo de 15 días hábiles', function () {
    it('salta fines de semana y la Semana Santa', function () {
        expect(Complaints::easter(2026)->toDateString())->toBe('2026-04-05')
            ->and(Complaints::isBusinessDay(Carbon::parse('2026-04-02', 'America/Lima')))->toBeFalse()
            ->and(Complaints::isBusinessDay(Carbon::parse('2026-04-03', 'America/Lima')))->toBeFalse()
            ->and(Complaints::dueOn(Carbon::parse('2026-03-30 10:00', 'America/Lima'))->toDateString())->toBe('2026-04-22');
    });

    it('salta los feriados nacionales de fin de año', function () {
        expect(Complaints::dueOn(Carbon::parse('2026-12-01 09:00', 'America/Lima'))->toDateString())->toBe('2026-12-24')
            ->and(Complaints::dueOn(Carbon::parse('2026-12-15 09:00', 'America/Lima'))->toDateString())->toBe('2027-01-07');
    });
});

describe('libro de la plataforma', function () {
    it('muestra los datos legales, da código correlativo, envía la constancia y avisa a soporte', function () {
        PlatformSetting::put(['legal_name' => 'Plataforma Prueba S.A.C.', 'legal_tax_id' => '20123456789', 'legal_address' => 'Calle Uno 100, Lima', 'support_email' => 'soporte@plataforma.test']);
        $admin = adminDelLibro();

        $this->getJson('/api/complaints-book')->assertOk()
            ->assertJsonPath('issuer.legal_name', 'Plataforma Prueba S.A.C.')
            ->assertJsonPath('issuer.tax_id', '20123456789');

        $this->postJson('/api/complaints-book', reclamo())->assertCreated()
            ->assertJsonPath('complaint.code', 'LR-2026-0001')
            ->assertJsonPath('complaint.due_on', '2026-04-22')
            ->assertJsonPath('complaint.company.legal_name', 'Plataforma Prueba S.A.C.');
        $this->postJson('/api/complaints-book', reclamo(['kind' => 'queja']))->assertCreated()
            ->assertJsonPath('complaint.code', 'LR-2026-0002');

        $constancia = correosA('rosa.quispe@correo.test');
        expect($constancia)->toHaveCount(2)
            ->and($constancia[0]['subject'])->toContain('LR-2026-0001')
            ->and($constancia[0]['body'])->toContain('45678912')->toContain('Que me devuelvan la diferencia.')->toContain('22 de abril de 2026')
            ->and(correosA('soporte@plataforma.test'))->toHaveCount(2)
            ->and(DB::table('user_notifications')->where('user_id', $admin->id)->whereNull('tenant_id')->where('type', 'complaint.new')->count())->toBe(2);

        $this->as($admin)->getJson('/api/admin/complaints')->assertOk()
            ->assertJsonPath('counts.pending', 2)
            ->assertJsonCount(2, 'complaints');
    });

    it('pide los datos del apoderado si es menor de edad y la casilla de aceptación', function () {
        $this->postJson('/api/complaints-book', reclamo(['is_minor' => true, 'accepted' => false]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['guardian_name', 'guardian_document_number', 'accepted']);

        $this->postJson('/api/complaints-book', reclamo(['is_minor' => true, 'guardian_name' => 'Juan Quispe', 'guardian_document_type' => 'DNI', 'guardian_document_number' => '12345678']))
            ->assertCreated()
            ->assertJsonPath('complaint.guardian.name', 'Juan Quispe');
    });

    it('responde desde la consola por correo, una sola vez, y el reclamo no se puede cambiar', function () {
        $admin = adminDelLibro();
        $id = $this->postJson('/api/complaints-book', reclamo())->json('complaint.id');

        $this->as($admin)->putJson("/api/admin/complaints/{$id}", ['response' => 'Te devolvemos la diferencia hoy mismo por transferencia.'])
            ->assertOk()->assertJsonPath('complaint.status', 'answered');
        $respuesta = correosA('rosa.quispe@correo.test');
        expect(end($respuesta)['subject'])->toContain('Respuesta')
            ->and(end($respuesta)['body'])->toContain('Te devolvemos la diferencia');

        $this->as($admin)->putJson("/api/admin/complaints/{$id}", ['response' => 'Otra respuesta distinta a la primera.'])
            ->assertStatus(422);

        expect(fn () => DB::transaction(fn () => DB::table('complaints')->where('id', $id)->update(['detail' => 'Cambiado'])))->toThrow(Exception::class);
        $this->as($admin)->getJson('/api/admin/complaints?tab=answered')->assertJsonCount(1, 'complaints');
    });

    it('avisa en la campana dos días hábiles antes de vencer y cuando ya venció', function () {
        $admin = adminDelLibro();
        $this->postJson('/api/complaints-book', reclamo())->assertCreated();

        $this->travelTo(Carbon::parse('2026-04-20 09:00', 'America/Lima'));
        $this->as($admin)->getJson('/api/notifications')->assertOk();
        expect(DB::table('user_notifications')->where('user_id', $admin->id)->where('type', 'complaint.soon')->count())->toBe(1);
        $this->as($admin)->getJson('/api/admin/complaints?tab=due')->assertJsonCount(1, 'complaints')->assertJsonPath('complaints.0.state', 'soon');

        $this->travelTo(Carbon::parse('2026-04-23 09:00', 'America/Lima'));
        Complaints::notices(null);
        expect(DB::table('user_notifications')->where('user_id', $admin->id)->where('type', 'complaint.late')->count())->toBe(1);
        $this->as($admin)->getJson('/api/admin/complaints?tab=due')->assertJsonPath('complaints.0.state', 'late');
    });
});

describe('libro de cada empresa', function () {
    it('usa los datos legales de la empresa y su propio correlativo', function () {
        $a = $this->company();
        $b = $this->company();
        DB::table('companies')->where('tenant_id', $a->tenant->id)->update(['legal_name' => 'Barbería Prueba E.I.R.L.', 'tax_id' => '10456789123', 'address' => 'Jr. Lima 200']);

        $this->postJson('/api/complaints-book', reclamo())->assertCreated()->assertJsonPath('complaint.code', 'LR-2026-0001');

        $this->getJson("/api/complaints-book/{$a->tenant->code}")->assertOk()
            ->assertJsonPath('issuer.legal_name', 'Barbería Prueba E.I.R.L.')
            ->assertJsonPath('issuer.tax_id', '10456789123');

        $this->postJson("/api/complaints-book/{$a->tenant->code}", reclamo())->assertCreated()
            ->assertJsonPath('complaint.code', 'LR-2026-0001')
            ->assertJsonPath('complaint.company.legal_name', 'Barbería Prueba E.I.R.L.');
        $this->postJson("/api/complaints-book/{$a->tenant->code}", reclamo())->assertJsonPath('complaint.code', 'LR-2026-0002');
        $this->postJson("/api/complaints-book/{$b->tenant->code}", reclamo())->assertJsonPath('complaint.code', 'LR-2026-0001');

        expect(DB::table('user_notifications')->where('user_id', $a->owner->id)->where('type', 'complaint.new')->count())->toBe(2)
            ->and(correosA('rosa.quispe@correo.test'))->toHaveCount(4);
    });

    it('una empresa no ve los reclamos de otra ni los de la plataforma', function () {
        $a = $this->company();
        $b = $this->company();
        $id = $this->postJson("/api/complaints-book/{$a->tenant->code}", reclamo())->json('complaint.id');
        $this->postJson('/api/complaints-book', reclamo())->assertCreated();

        $this->as($b->owner)->getJson('/api/complaints')->assertOk()->assertJsonCount(0, 'complaints');
        $this->as($b->owner)->getJson("/api/complaints/{$id}")->assertNotFound();
        $this->as($b->owner)->putJson("/api/complaints/{$id}", ['response' => 'Intento de responder lo ajeno.'])->assertNotFound();

        $this->as($a->owner)->getJson('/api/complaints')->assertOk()->assertJsonCount(1, 'complaints')
            ->assertJsonPath('code', $a->tenant->code);
    });

    it('pide el permiso para ver y para responder', function () {
        $a = $this->company();
        $id = $this->postJson("/api/complaints-book/{$a->tenant->code}", reclamo())->json('complaint.id');
        $recepcion = $this->staff($a, 'reception');
        $gerente = $this->staff($a, 'manager', null, 'Gerente Prueba');

        $this->as($recepcion)->getJson('/api/complaints')->assertForbidden();
        $this->as($gerente)->getJson("/api/complaints/{$id}")->assertOk()->assertJsonPath('can_answer', false);
        $this->as($gerente)->putJson("/api/complaints/{$id}", ['response' => 'Respuesta del gerente sin permiso.'])->assertForbidden();

        $this->as($a->owner)->putJson("/api/complaints/{$id}", ['response' => 'Te devolvemos los 35 soles hoy mismo.'])->assertOk();
        expect(DB::table('complaints')->where('id', $id)->value('status'))->toBe('answered');
    });
});
