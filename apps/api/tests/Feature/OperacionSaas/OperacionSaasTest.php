<?php

use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Support\PlatformMail;
use App\Support\Referrals;
use App\Support\Subscriptions;
use App\Support\SupportDesk;
use App\Support\TenantHealth;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function plataforma(bool $support = false): User
{
    $user = User::forceCreate([
        'id' => (string) Str::uuid(),
        'first_name' => $support ? 'Soporte' : 'Admin', 'last_name' => 'Plataforma', 'email' => 'saas.' . Str::lower(Str::random(6)) . '@plataforma.test',
        'password_hash' => bcrypt('clave-de-prueba-123'), 'status' => 'active', 'tenant_id' => null,
    ]);

    if ($support) {
        DB::table('user_roles')->insert(['tenant_id' => null, 'user_id' => $user->id, 'role_id' => DB::table('roles')->where('slug', 'saas_support')->value('id')]);
    }

    return $user;
}

function pedido($test, $user, array $extra = [])
{
    \Tests\TestCase::forgetCaches();
    $user->unsetRelations();
    \Laravel\Sanctum\Sanctum::actingAs($user, ['*']);

    return $test->post('/api/support/tickets', $extra + [
        'subject' => 'No puedo cobrar con Yape',
        'topic'   => 'sales',
        'body'    => 'Al cobrar una cita sale un error y no se registra el pago.',
    ], ['Accept' => 'application/json']);
}

beforeEach(function () {
    Storage::fake('local');
});

describe('soporte', function () {
    it('cualquier persona de la empresa abre un pedido con captura y la plataforma recibe el aviso', function () {
        $co = $this->company(['plan' => self::PLAN_PROFESIONAL]);
        $recepcion = $this->staff($co, 'reception');
        $admin = plataforma();
        PlatformSetting::put(['support_email' => 'ayuda@plataforma.test']);

        $res = pedido($this, $recepcion, ['attachment' => UploadedFile::fake()->image('error.png'), 'priority' => 'urgent', 'allow_access' => '1'])->assertCreated();
        $ticket = SupportDesk::find($res->json('ticket.id'));

        expect($ticket->code)->toStartWith('SOP-')
            ->and($ticket->priority)->toBe('urgent')
            ->and((int) round(Carbon::parse($ticket->created_at)->diffInHours(Carbon::parse($ticket->due_at))))->toBe(2)
            ->and($ticket->access_until)->not->toBeNull()
            ->and(DB::table('user_notifications')->where('user_id', $admin->id)->where('type', 'support.new')->exists())->toBeTrue()
            ->and(collect(PlatformMail::$outbox)->where('to', 'ayuda@plataforma.test')->count())->toBe(1);

        $detail = $this->as($co->owner)->getJson("/api/support/tickets/{$ticket->id}")->assertOk();
        expect($detail->json('messages.0.attachment.name'))->toBe('error.png');
        $this->as($co->owner)->get('/api' . $detail->json('messages.0.attachment.url'))->assertOk();
    });

    it('cada empresa solo ve sus pedidos', function () {
        $mia = $this->company();
        $otra = $this->company();
        $id = pedido($this, $otra->owner)->assertCreated()->json('ticket.id');

        $this->as($mia->owner)->getJson("/api/support/tickets/{$id}")->assertNotFound();
        expect($this->as($mia->owner)->getJson('/api/support/tickets')->json('tickets'))->toBe([]);
    });

    it('la respuesta de soporte llega a la empresa por aviso y correo, y su respuesta reabre el pedido', function () {
        $co = $this->company();
        $agente = plataforma(true);
        $id = pedido($this, $co->owner)->json('ticket.id');

        $this->as($agente)->postJson("/api/admin/support/{$id}/messages", ['body' => 'Ya lo revisamos: vuelve a intentarlo.'])->assertOk()
            ->assertJsonPath('ticket.status', 'waiting')
            ->assertJsonPath('ticket.assigned_to', $agente->id);

        expect(DB::table('user_notifications')->where('user_id', $co->owner->id)->where('type', 'support.reply')->exists())->toBeTrue()
            ->and(collect(PlatformMail::$outbox)->where('to', $co->owner->email)->count())->toBe(1);

        $this->as($co->owner)->postJson("/api/support/tickets/{$id}/messages", ['body' => 'Sigue igual.'])->assertOk()->assertJsonPath('ticket.status', 'open');
        expect(DB::table('user_notifications')->where('user_id', $agente->id)->where('type', 'support.reply')->exists())->toBeTrue();
    });

    it('la bandeja muestra primero los vencidos', function () {
        $co = $this->company();
        $admin = plataforma();
        $nuevo = pedido($this, $co->owner, ['subject' => 'Pedido nuevo'])->json('ticket.id');
        $viejo = pedido($this, $co->owner, ['subject' => 'Pedido vencido'])->json('ticket.id');
        DB::table('saas_support_tickets')->where('id', $viejo)->update(['due_at' => now()->subHour()]);

        $rows = $this->as($admin)->getJson('/api/admin/support')->assertOk()->json('tickets');
        expect($rows[0]['id'])->toBe($viejo)->and($rows[0]['overdue'])->toBeTrue()->and($rows[1]['id'])->toBe($nuevo);

        $this->as($admin)->putJson("/api/admin/support/{$nuevo}", ['priority' => 'high', 'status' => 'in_progress', 'assigned_to' => $admin->id])->assertOk()
            ->assertJsonPath('ticket.status', 'in_progress');
    });

    it('una empresa no entra a la bandeja de la plataforma', function () {
        $co = $this->company();
        $this->as($co->owner)->getJson('/api/admin/support')->assertForbidden();
    });
});

describe('entrar como la empresa y suspender', function () {
    it('entrar pide motivo, queda en la auditoría de la empresa y ella ve el aviso mientras dura', function () {
        $co = $this->company();
        $agente = plataforma(true);

        $this->as($agente)->postJson("/api/tenants/{$co->tenant->id}/impersonate", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $token = $this->as($agente)->postJson("/api/tenants/{$co->tenant->id}/impersonate", ['reason' => 'Revisar el error al cobrar'])->assertOk()->json('access_token');

        $event = DB::table('audit_events')->where('tenant_id', $co->tenant->id)->where('action', 'impersonation.started')->first();
        expect(json_decode($event->after_data, true)['reason'])->toBe('Revisar el error al cobrar')
            ->and(DB::table('user_notifications')->where('user_id', $co->owner->id)->where('type', 'support.access')->exists())->toBeTrue();

        $staff = $this->staff($co, 'reception');
        $sessions = $this->as($staff)->getJson('/api/support/access')->assertOk()->json('sessions');
        expect($sessions)->toHaveCount(1)->and($sessions[0]['reason'])->toBe('Revisar el error al cobrar');

        app('auth')->forgetGuards();
        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/impersonation/stop')->assertOk();
        app('auth')->forgetGuards();
        expect($this->as($staff)->getJson('/api/support/access')->json('sessions'))->toBe([]);
    });

    it('si la plataforma lo exige, solo entra con el permiso de la empresa', function () {
        $co = $this->company();
        $admin = plataforma();
        $this->as($admin)->putJson('/api/admin/support-settings', ['consent_required' => true])->assertOk();

        $this->as($admin)->postJson("/api/tenants/{$co->tenant->id}/impersonate", ['reason' => 'Revisar la agenda'])->assertStatus(422)->assertJsonValidationErrors('reason');

        $id = pedido($this, $co->owner, ['allow_access' => true])->json('ticket.id');
        $this->as($admin)->postJson("/api/tenants/{$co->tenant->id}/impersonate", ['reason' => 'Revisar la agenda', 'ticket_id' => $id])->assertOk();
    });

    it('suspender exige motivo', function () {
        $co = $this->company();
        $admin = plataforma();

        $this->as($admin)->postJson("/api/tenants/{$co->tenant->id}/suspend", [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->as($admin)->postJson("/api/tenants/{$co->tenant->id}/suspend", ['reason' => 'Uso indebido de la cuenta'])->assertOk();
        expect(DB::table('tenants')->where('id', $co->tenant->id)->value('suspension_reason'))->toBe('Uso indebido de la cuenta');
    });
});

describe('anuncios', function () {
    it('un anuncio por plan solo lo ven esas empresas, sale en la campana y al cerrarlo no vuelve', function () {
        $esencial = $this->company(['plan' => self::PLAN_ESENCIAL]);
        $empresarial = $this->company(['plan' => self::PLAN_EMPRESARIAL]);
        $admin = plataforma();
        $today = now('America/Lima')->format('Y-m-d');

        $this->as($admin)->postJson('/api/admin/announcements', [
            'title' => 'Nuevo reporte de ventas', 'body' => '<p>Ya puedes verlo.</p>', 'kind' => 'news',
            'audience' => 'plans', 'plan_ids' => [self::PLAN_EMPRESARIAL], 'starts_on' => $today,
        ])->assertCreated()->assertJsonPath('reach', 1);
        $this->as($admin)->postJson('/api/admin/announcements', [
            'title' => 'Mantenimiento el domingo', 'body' => 'De 2 a 4 a. m.', 'kind' => 'maintenance',
            'audience' => 'all', 'starts_on' => now('America/Lima')->addDay()->format('Y-m-d'),
        ])->assertCreated();

        expect($this->as($esencial->owner)->getJson('/api/announcements')->json('items'))->toBe([]);
        $items = $this->as($empresarial->owner)->getJson('/api/announcements')->json('items');
        expect($items)->toHaveCount(1);

        $this->as($empresarial->owner)->getJson('/api/notifications')->assertOk();
        expect(DB::table('user_notifications')->where('user_id', $empresarial->owner->id)->where('type', 'announcement')->count())->toBe(1);

        $this->as($empresarial->owner)->postJson("/api/announcements/{$items[0]['id']}/dismiss")->assertOk()->assertJsonPath('items', []);
        expect($this->as($empresarial->owner)->getJson('/api/announcements')->json('items'))->toBe([]);

        $this->travel(2)->days();
        expect($this->as($esencial->owner)->getJson('/api/announcements')->json('items.0.kind'))->toBe('maintenance');
    });

    it('un anuncio vencido ya no se ve', function () {
        $co = $this->company();
        $admin = plataforma();
        $this->as($admin)->postJson('/api/admin/announcements', [
            'title' => 'Solo hoy', 'body' => 'Texto', 'kind' => 'important', 'audience' => 'all',
            'starts_on' => now('America/Lima')->format('Y-m-d'), 'ends_on' => now('America/Lima')->format('Y-m-d'),
        ])->assertCreated();

        expect($this->as($co->owner)->getJson('/api/announcements')->json('items'))->toHaveCount(1);
        $this->travel(2)->days();
        expect($this->as($co->owner)->getJson('/api/announcements')->json('items'))->toBe([]);
    });
});

describe('métricas y salud', function () {
    it('el ingreso mensual de métricas coincide con el resumen y muestra los motivos de baja', function () {
        $co = $this->company(['plan' => self::PLAN_ESENCIAL]);
        $admin = plataforma();
        $sub = Subscription::where('tenant_id', $co->tenant->id)->first();
        Subscriptions::recordPayment($sub, ['amount' => 149]);
        DB::table('saas_cancellations')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $co->tenant->id, 'subscription_id' => $sub->id,
            'reason' => 'price', 'status' => 'scheduled', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $data = $this->as($admin)->getJson('/api/admin/reports?tab=metrics')->assertOk()->json('data');
        $summary = $this->as($admin)->getJson('/api/admin/reports/summary')->json();

        expect($data['summary']['mrr']['amount'])->toEqual($summary['mrr']['amount'])
            ->and($data['summary']['arr']['amount'])->toEqual($summary['mrr']['amount'] * 12)
            ->and($data['by_month'])->toHaveCount(6)
            ->and(collect($data['reasons'])->firstWhere('key', 'price')['value'])->toBe(1);
    });

    it('la salud ordena por riesgo y avisa la prueba que vence sin datos', function () {
        $prueba = $this->company(['trial' => true]);
        $sana = $this->company();
        $admin = plataforma(true);
        DB::table('subscriptions')->where('tenant_id', $prueba->tenant->id)->update(['trial_ends_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)]);
        DB::table('customers')->where('tenant_id', $prueba->tenant->id)->delete();
        DB::table('users')->where('tenant_id', $sana->tenant->id)->update(['last_login_at' => now()]);
        $this->customer($sana->tenant);

        $data = $this->as($admin)->getJson('/api/admin/health')->assertOk()->json();
        $first = collect($data['rows'])->firstWhere('id', $prueba->tenant->id);
        $scores = array_column($data['rows'], 'score');

        expect($first['alert'])->toBeTrue()
            ->and(collect($first['signals'])->pluck('key')->all())->toContain('trial_soon', 'no_data')
            ->and($scores)->toBe(collect($scores)->sortDesc()->values()->all())
            ->and(collect($data['alerts'])->pluck('id')->all())->toContain($prueba->tenant->id);
    });
});

describe('recomienda y gana', function () {
    it('apagado por defecto: el código no se registra', function () {
        $quien = $this->company();
        $nueva = $this->company();
        expect(Referrals::config()['enabled'])->toBeFalse()
            ->and(Referrals::attach($nueva->tenant, 'CUALQUIER'))->toBeFalse();
        $this->as($quien->owner)->getJson('/api/support/referral')->assertOk()->assertJsonPath('enabled', false)->assertJsonPath('code', null);
    });

    it('la empresa que recomendó gana días una sola vez cuando la referida paga su primer periodo', function () {
        $admin = plataforma();
        $this->as($admin)->putJson('/api/admin/referrals', ['enabled' => true, 'kind' => 'days', 'days' => 30, 'percent' => 50])->assertOk();
        $quien = $this->company();
        $code = $this->as($quien->owner)->getJson('/api/support/referral')->assertOk()->json('code');
        $nueva = $this->company(['trial' => true]);

        expect(Referrals::attach($quien->tenant, $code, 'otra@correo.test'))->toBeFalse()
            ->and(Referrals::attach($nueva->tenant, $code, $quien->owner->email))->toBeFalse()
            ->and(Referrals::attach($nueva->tenant, strtolower($code), 'duena.nueva@correo.test'))->toBeTrue();

        $antes = Carbon::parse(Subscription::where('tenant_id', $quien->tenant->id)->value('ends_at'));
        $sub = Subscription::where('tenant_id', $nueva->tenant->id)->first();
        Subscriptions::recordPayment($sub, ['amount' => 599]);
        Subscriptions::recordPayment($sub->fresh(), ['amount' => 599]);

        $despues = Carbon::parse(Subscription::where('tenant_id', $quien->tenant->id)->value('ends_at'));
        expect((int) round($antes->diffInDays($despues)))->toBe(30)
            ->and(DB::table('saas_referrals')->where('referred_tenant_id', $nueva->tenant->id)->value('status'))->toBe('rewarded')
            ->and(DB::table('user_notifications')->where('user_id', $quien->owner->id)->where('type', 'referral.rewarded')->count())->toBe(1);
    });

    it('el descuento queda para el siguiente cobro mensual', function () {
        PlatformSetting::put(['referral' => ['enabled' => true, 'kind' => 'discount', 'days' => 30, 'percent' => 40]]);
        $quien = $this->company(['plan' => self::PLAN_ESENCIAL]);
        $nueva = $this->company(['trial' => true]);
        Referrals::attach($nueva->tenant, Referrals::codeFor($quien->tenant), 'nueva@correo.test');

        Subscriptions::recordPayment(Subscription::where('tenant_id', $nueva->tenant->id)->first(), ['amount' => 599]);
        $sub = Subscription::with('plan')->where('tenant_id', $quien->tenant->id)->first();

        expect((float) $sub->discount_percent)->toEqual(40.0)
            ->and(\App\Support\Cancellations::chargeFor($sub, $sub->plan, 'monthly'))->toEqual(round(149 * 0.6, 2));
    });

    it('el registro de empresas acepta ?ref=CODIGO', function () {
        PlatformSetting::put(['referral' => ['enabled' => true, 'kind' => 'days', 'days' => 15, 'percent' => 50]]);
        $quien = $this->company();
        $code = Referrals::codeFor($quien->tenant);

        $this->postJson('/api/register-tenant', [
            'ruc' => '20123456786', 'company_name' => 'Spa Referido', 'department' => 'Lima',
            'first_name' => 'Ana', 'last_name' => 'Ruiz', 'email' => 'ana.ruiz.' . Str::lower(Str::random(4)) . '@correo.test',
            'password' => 'clave-segura-123', 'plan_id' => self::PLAN_ESENCIAL, 'ref' => $code,
            'form_token' => \App\Support\SignupGuard::token(now()->subMinute()),
        ])->assertCreated();

        expect(DB::table('saas_referrals')->where('referrer_tenant_id', $quien->tenant->id)->count())->toBe(1);
    });

    it('la empresa recomendada tiene 20 % en sus primeros 3 cobros mensuales', function () {
        PlatformSetting::put(['referral' => ['enabled' => true, 'kind' => 'days', 'days' => 30, 'percent' => 50]]);
        $quien = $this->company();
        $nueva = $this->company(['trial' => true]);
        Subscription::where('tenant_id', $nueva->tenant->id)->update(['billing_cycle' => 'monthly', 'discount_cycles' => 0, 'discount_percent' => 0]);

        expect(Referrals::attach($nueva->tenant, Referrals::codeFor($quien->tenant), 'nueva@correo.test'))->toBeTrue();

        $sub = Subscription::where('tenant_id', $nueva->tenant->id)->first();
        expect((float) $sub->discount_percent)->toEqual(20.0)
            ->and((int) $sub->discount_cycles)->toBe(3)
            ->and(DB::table('user_notifications')->where('user_id', $nueva->owner->id)->where('type', 'referral.welcome')->count())->toBe(1);
    });

    it('con plan anual la recomendada no recibe descuento y se le explica', function () {
        PlatformSetting::put(['referral' => ['enabled' => true, 'kind' => 'days', 'days' => 30, 'percent' => 50]]);
        $quien = $this->company();
        $nueva = $this->company(['trial' => true]);
        Subscription::where('tenant_id', $nueva->tenant->id)->update(['billing_cycle' => 'yearly', 'discount_cycles' => 0, 'discount_percent' => 0]);

        Referrals::attach($nueva->tenant, Referrals::codeFor($quien->tenant), 'nueva@correo.test');

        expect((int) Subscription::where('tenant_id', $nueva->tenant->id)->value('discount_cycles'))->toBe(0)
            ->and(DB::table('user_notifications')->where('user_id', $nueva->owner->id)->where('type', 'referral.welcome')->value('body'))->toContain('por año');
    });
});

describe('suspender desde editar la empresa', function () {
    it('sin motivo no se guarda; con motivo queda suspendida y guarda el motivo', function () {
        $co = $this->company();
        $admin = plataforma();

        $this->as($admin)->putJson("/api/tenants/{$co->tenant->id}", ['status' => 'suspended'])->assertStatus(422)->assertJsonValidationErrors('status');
        $this->as($admin)->putJson("/api/tenants/{$co->tenant->id}", ['status' => 'suspended', 'reason' => '  '])->assertStatus(422);
        expect(DB::table('tenants')->where('id', $co->tenant->id)->value('status'))->toBe('active');

        $this->as($admin)->putJson("/api/tenants/{$co->tenant->id}", ['status' => 'suspended', 'reason' => 'Dos meses sin pagar'])->assertOk();
        expect(DB::table('tenants')->where('id', $co->tenant->id)->value('status'))->toBe('suspended')
            ->and(DB::table('tenants')->where('id', $co->tenant->id)->value('suspension_reason'))->toBe('Dos meses sin pagar');
    });
});
