<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->co = $this->company(['modules_off' => []]);
    $this->svc = $this->service($this->co->tenant);
    $this->cli = $this->customer($this->co->tenant, 'Rosa', 'Quispe');
    $this->pro = $this->professional($this->co, 'Pía Profesional');
    $this->cita = function (string $status, int $daysFromToday, string $time, array $extra = []) {
        $start = now('America/Lima')->startOfDay()->addDays($daysFromToday)->setTimeFromTimeString($time);
        $id = (string) Str::uuid();
        DB::table('appointments')->insert($extra + [
            'id' => $id, 'tenant_id' => $this->co->tenant->id, 'code' => 'C-' . Str::upper(Str::random(6)),
            'branch_id' => $this->co->branch->id, 'customer_id' => $this->cli->id, 'service_id' => $this->svc->id,
            'professional_id' => $this->pro->id, 'starts_at' => $start->copy()->utc(), 'ends_at' => $start->copy()->addMinutes(30)->utc(),
            'status' => $status, 'source' => 'online', 'price' => 50, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    };
    $this->aviso = fn ($user, string $key) => collect($this->as($user)->getJson('/api/dashboard')->assertOk()->json('pending'))->firstWhere('key', $key);
});

it('cuenta las atenciones terminadas sin cobrar y se van al cobrarlas', function () {
    ($this->cita)('completed', -1, '10:00');

    expect(($this->aviso)($this->co->owner, 'uncharged')['count'])->toBe(1);

    DB::table('appointments')->where('tenant_id', $this->co->tenant->id)->update(['price' => 0]);

    expect(($this->aviso)($this->co->owner, 'uncharged')['count'])->toBe(0);
});

it('avisa la caja cerrada y deja de avisar al abrirla', function () {
    $caja = ($this->aviso)($this->co->owner, 'cash_closed');
    expect($caja['count'])->toBe(1)->and($caja['names'])->toContain($this->co->branch->name);

    $this->openCash($this->co);

    expect(($this->aviso)($this->co->owner, 'cash_closed')['count'])->toBe(0)
        ->and(($this->aviso)($this->co->owner, 'cash_stale')['count'])->toBe(0);
});

it('avisa la caja abierta desde otro día', function () {
    $this->openCash($this->co);
    DB::table('cash_sessions')->where('tenant_id', $this->co->tenant->id)->update(['opened_at' => now()->subDays(2)]);

    expect(($this->aviso)($this->co->owner, 'cash_stale')['count'])->toBe(1);
});

it('cuenta los mensajes no entregados de los dos últimos días', function () {
    DB::table('messages')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $this->co->tenant->id, 'customer_id' => $this->cli->id,
        'channel' => 'email', 'kind' => 'manual', 'status' => 'failed', 'body' => 'Hola', 'created_at' => now(), 'updated_at' => now(),
    ]);

    expect(($this->aviso)($this->co->owner, 'messages_failed')['count'])->toBe(1);
});

it('el profesional no ve avisos de caja, ingresos ni marketing', function () {
    $panel = $this->as($this->pro->user)->getJson('/api/dashboard')->assertOk()->json();
    $keys = collect($panel['pending'])->pluck('key');

    expect($panel['scope'])->toBe('own')
        ->and($keys)->not->toContain('cash_closed')
        ->and($keys)->not->toContain('messages_failed')
        ->and($panel['revenue'])->toBeNull()
        ->and($panel['marketing'])->toBeNull()
        ->and($panel['month']['ticket'])->toBeNull();
});

it('la dueña ve los ingresos de 14 días separados en servicios y productos, y el ticket promedio', function () {
    $this->openCash($this->co);
    $sale = $this->sell($this->co, [['type' => 'service', 'id' => $this->svc->id, 'professional_id' => $this->pro->id]]);
    $this->pay($this->co, $sale['id'], ['method' => 'cash', 'amount' => $sale['total']])->assertSuccessful();

    $panel = $this->as($this->co->owner)->getJson('/api/dashboard')->assertOk()->json();

    expect($panel['revenue'])->toHaveCount(14)
        ->and(end($panel['revenue'])['services'])->toBeGreaterThan(0)
        ->and(end($panel['revenue'])['others'])->toEqual(0)
        ->and($panel['month']['ticket'])->toBeGreaterThan(0)
        ->and($panel['month']['sales'])->toBe(1)
        ->and($panel['marketing'])->toBeArray();
});

it('Mi día trae todas las citas del día con nota, origen y el paso siguiente', function () {
    DB::table('customers')->where('id', $this->cli->id)->update(['alert' => 'Alérgica al látex']);
    ($this->cita)('completed', 0, '08:00');
    ($this->cita)('pending', 0, '09:00');
    ($this->cita)('in_progress', 0, '10:00');
    ($this->cita)('cancelled', 0, '11:00');
    ($this->cita)('confirmed', 1, '10:00');
    ($this->cita)('confirmed', 3, '10:00');
    ($this->cita)('confirmed', 3, '11:00');
    DB::table('professionals')->where('id', $this->pro->id)->update(['goal_month' => 1000]);

    $dia = $this->as($this->pro->user)->getJson('/api/dashboard')->assertOk()->json('my_day');
    $citas = collect($dia['appointments']);

    expect($citas)->toHaveCount(3)
        ->and($citas->pluck('status')->all())->toBe(['completed', 'pending', 'in_progress'])
        ->and($citas[0]['ends_at'])->toEndWith('08:30')
        ->and($citas[0]['advance'])->toBeNull()
        ->and($citas[1]['advance']['label'])->toBe('Confirmar')
        ->and($citas[2]['advance']['label'])->toBe('Completar')
        ->and($citas[1]['alert'])->toBe('Alérgica al látex')
        ->and($citas[1]['source'])->toBe('En línea')
        ->and($dia['next_days'])->toHaveCount(6)
        ->and($dia['next_days'][0]['count'])->toBe(1)
        ->and($dia['next_days'][2]['count'])->toBe(2)
        ->and($dia['production']['goal'])->toEqual(1000)
        ->and($dia['production']['progress'])->toBe(5)
        ->and($dia['can_block'])->toBeTrue();
});

it('Mi día no aparece para la dueña ni muestra citas de otro profesional', function () {
    $otro = $this->professional($this->co, 'Otro Profesional');
    ($this->cita)('pending', 0, '09:00', ['professional_id' => $otro->id]);

    expect($this->as($this->co->owner)->getJson('/api/dashboard')->json('my_day'))->toBeNull()
        ->and($this->as($this->pro->user)->getJson('/api/dashboard')->json('my_day.appointments'))->toHaveCount(0);
});

it('el paso siguiente respeta los permisos del rol', function () {
    $role = DB::table('user_roles')->where('user_id', $this->pro->user->id)->value('role_id');
    $ids = \App\Support\Access::permissionIds();
    DB::table('role_permissions')->where('role_id', $role)->where('permission_id', $ids['agenda.confirm'])->delete();
    ($this->cita)('pending', 0, '09:00');

    $dia = $this->as(\App\Models\User::find($this->pro->user->id))->getJson('/api/dashboard')->json('my_day');

    expect($dia['appointments'][0]['advance'])->toBeNull();
});

it('cuenta las atenciones que piden ficha y no la tienen', function () {
    $template = DB::table('record_templates')->where('tenant_id', $this->co->tenant->id)->where('status', 'active')->first();
    if (!$template) {
        $this->markTestSkipped('El rubro no trae plantillas de ficha.');
    }
    $service = DB::table('record_template_services')->where('record_template_id', $template->id)->value('service_id');
    ($this->cita)('completed', -1, '10:00', ['service_id' => $service]);

    expect(($this->aviso)($this->co->owner, 'missing_records')['count'])->toBe(1);
});
