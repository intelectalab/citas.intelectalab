<?php

use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Support\DataExports;
use App\Support\ErrorLogs;
use App\Support\PlanInvoices;
use App\Support\PlatformMail;
use App\Support\SaasEvents;
use App\Support\Subscriptions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

const SUS_PLAN_INDIVIDUAL = '44444444-4444-4444-4444-444444444444';

function susSubscription(string $tenantId): Subscription
{
    Subscriptions::forget($tenantId);

    return Subscriptions::latest($tenantId);
}

function susCard(Subscription $subscription, string $last4 = '4242'): void
{
    $subscription->update([
        'external_provider' => 'simulated', 'external_subscription_id' => 'sim_card',
        'card_status' => 'authorized', 'card_brand' => 'visa', 'card_last4' => $last4, 'card_authorized_at' => now(),
    ]);
    Subscriptions::forget($subscription->tenant_id);
}

function susPlatform(): User
{
    return User::forceCreate([
        'id' => (string) Str::uuid(),
        'first_name' => 'Admin', 'last_name' => 'Plataforma', 'email' => 'sus.' . Str::lower(Str::random(6)) . '@plataforma.test',
        'password_hash' => bcrypt('clave-de-prueba-123'), 'status' => 'active', 'tenant_id' => null,
    ]);
}

function susBranches(object $co, int $extra): void
{
    for ($i = 0; $i < $extra; $i++) {
        DB::table('branches')->insert(array_intersect_key((array) DB::table('branches')->where('id', $co->branch->id)->first(), array_flip(['company_id', 'timezone'])) + [
            'id' => (string) Str::uuid(), 'tenant_id' => $co->tenant->id, 'name' => "Sede extra {$i}", 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}

describe('planes y cambio de plan', function () {
    it('existen los planes Individual, Esencial, Profesional y Empresarial con precio mensual y anual', function () {
        $plans = DB::table('saas_plans')->orderBy('presentation_order')->get(['slug', 'price_monthly', 'price_yearly', 'limits']);

        expect($plans->pluck('slug')->all())->toBe(['individual', 'esencial', 'profesional', 'empresarial']);
        $individual = $plans->first();
        expect((float) $individual->price_monthly)->toBe(59.0)
            ->and((float) $individual->price_yearly)->toBe(590.0)
            ->and(json_decode($individual->limits, true)['professionals'])->toBe(1);
        $plans->each(fn ($plan) => expect((float) $plan->price_yearly)->toBe((float) $plan->price_monthly * 10));
    });

    it('subir de plan muestra la vista previa con la diferencia por los días que faltan y la cobra al confirmar', function () {
        $co = $this->company(['plan' => self::PLAN_ESENCIAL]);
        $sub = susSubscription($co->tenant->id);
        $sub->update(['ends_at' => now()->addDays(15), 'starts_at' => now()->subDays(15)]);
        susCard($sub->fresh());

        $preview = $this->as($co->owner)->postJson('/api/billing/plan/preview', ['plan_id' => self::PLAN_PROFESIONAL, 'billing_cycle' => 'monthly'])->assertOk()->json();
        $days = $preview['prorated']['days_period'];
        $expected = round(150 * $preview['prorated']['days_left'] / $days, 2);

        expect($preview['kind'])->toBe('upgrade')
            ->and($preview['immediate'])->toBeTrue()
            ->and((float) $preview['today'])->toBe($expected)
            ->and($expected)->toBeGreaterThan(60.0)->toBeLessThan(90.0)
            ->and((float) $preview['next_charge']['amount'])->toBe(299.0)
            ->and($preview['modules_off'])->toBe([])
            ->and($preview['blocked'])->toBeFalse();

        $endsBefore = susSubscription($co->tenant->id)->ends_at->toIso8601String();
        $this->as($co->owner)->postJson('/api/billing/plan', ['plan_id' => self::PLAN_PROFESIONAL, 'billing_cycle' => 'monthly'])->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'por los días que faltan'));

        $after = susSubscription($co->tenant->id);
        $invoice = SubscriptionInvoice::where('tenant_id', $co->tenant->id)->where('billing_reason', 'subscription_upgrade')->first();
        expect($after->plan_id)->toBe(self::PLAN_PROFESIONAL)
            ->and($after->ends_at->toIso8601String())->toBe($endsBefore)
            ->and((float) $invoice->amount)->toBe($expected)
            ->and($invoice->doc_type)->toBe('boleta')
            ->and($invoice->serie)->toBe('B001')
            ->and($invoice->sunat_status)->toBe('accepted')
            ->and((bool) $invoice->simulated)->toBeTrue();

        $expansion = DB::table('saas_events')->where('tenant_id', $co->tenant->id)->where('type', 'expansion')->first();
        expect((float) $expansion->mrr_delta)->toBe(150.0)
            ->and(DB::table('saas_events')->where('tenant_id', $co->tenant->id)->where('type', 'payment')->value('amount'))->toEqual($expected);
    });

    it('repetir el cambio con la misma Idempotency-Key no cobra dos veces', function () {
        $co = $this->company(['plan' => self::PLAN_ESENCIAL]);
        $sub = susSubscription($co->tenant->id);
        $sub->update(['ends_at' => now()->addDays(10)]);
        susCard($sub->fresh());
        $key = (string) Str::uuid();

        $this->as($co->owner)->withHeaders(['Idempotency-Key' => $key])->postJson('/api/billing/plan', ['plan_id' => self::PLAN_PROFESIONAL, 'billing_cycle' => 'monthly'])->assertOk();
        $this->as($co->owner)->withHeaders(['Idempotency-Key' => $key])->postJson('/api/billing/plan', ['plan_id' => self::PLAN_PROFESIONAL, 'billing_cycle' => 'monthly'])->assertOk();

        expect(SubscriptionInvoice::where('tenant_id', $co->tenant->id)->where('billing_reason', 'subscription_upgrade')->count())->toBe(1);
    });

    it('bajar de plan con más sedes de las que permite responde 402 PLAN_LIMIT con el detalle y avisa los módulos que se apagan', function () {
        $co = $this->company(['plan' => self::PLAN_EMPRESARIAL]);
        susBranches($co, 2);

        $preview = $this->as($co->owner)->postJson('/api/billing/plan/preview', ['plan_id' => SUS_PLAN_INDIVIDUAL, 'billing_cycle' => 'monthly'])->assertOk()->json();
        expect($preview['blocked'])->toBeTrue()
            ->and(collect($preview['exceeded'])->firstWhere('key', 'branches'))->toMatchArray(['used' => 3, 'limit' => 1])
            ->and(collect($preview['modules_off'])->pluck('key')->all())->toContain('inventory', 'api', 'marketing');

        $this->as($co->owner)->postJson('/api/billing/plan', ['plan_id' => SUS_PLAN_INDIVIDUAL, 'billing_cycle' => 'monthly'])
            ->assertStatus(402)
            ->assertJsonPath('code', 'PLAN_LIMIT')
            ->assertJsonPath('limits.0.key', 'branches');

        expect(susSubscription($co->tenant->id)->pending_plan_id)->toBeNull();
    });

    it('una bajada que cabe queda para el próximo periodo, se puede anular y no borra datos', function () {
        $co = $this->company(['plan' => self::PLAN_PROFESIONAL]);

        $this->as($co->owner)->postJson('/api/billing/plan/preview', ['plan_id' => self::PLAN_ESENCIAL, 'billing_cycle' => 'monthly'])
            ->assertOk()->assertJsonPath('kind', 'scheduled')->assertJsonPath('today', 0);
        $this->as($co->owner)->postJson('/api/billing/plan', ['plan_id' => self::PLAN_ESENCIAL, 'billing_cycle' => 'monthly'])->assertOk();
        expect(susSubscription($co->tenant->id)->pending_plan_id)->toBe(self::PLAN_ESENCIAL);

        $this->as($co->owner)->postJson('/api/billing/plan/cancel')->assertOk()->assertJsonPath('billing.pending_plan', null);
        expect(susSubscription($co->tenant->id)->pending_plan_id)->toBeNull()
            ->and(DB::table('audit_events')->where('tenant_id', $co->tenant->id)->where('action', 'subscription.change_cancelled')->exists())->toBeTrue();
    });

    it('solo el administrador ve la vista previa y cambia el plan', function () {
        $co = $this->company(['plan' => self::PLAN_ESENCIAL]);
        $recepcion = $this->staff($co, 'reception');

        $this->as($recepcion)->postJson('/api/billing/plan/preview', ['plan_id' => self::PLAN_PROFESIONAL])->assertForbidden();
    });
});

describe('movimientos del ingreso mensual (saas_events)', function () {
    it('cada alta, subida, bajada y baja crea su evento y los movimientos cuadran con el cambio del ingreso mensual', function () {
        $start = now()->subSecond();
        $trial = $this->company(['plan' => self::PLAN_ESENCIAL, 'trial' => true]);
        expect(DB::table('saas_events')->where('tenant_id', $trial->tenant->id)->value('type'))->toBe('trial');

        Subscriptions::recordPayment(susSubscription($trial->tenant->id), ['reason' => 'subscription_create', 'amount' => 149]);
        expect(DB::table('saas_events')->where('tenant_id', $trial->tenant->id)->where('type', 'new')->value('mrr_delta'))->toEqual(149);

        $other = $this->company(['plan' => self::PLAN_PROFESIONAL]);
        $sub = susSubscription($other->tenant->id);
        susCard($sub);
        \App\Support\Cancellations::request($sub->fresh(), $other->owner, 'price', null, false);
        \App\Support\Cancellations::finish(susSubscription($other->tenant->id));

        expect(DB::table('saas_events')->where('tenant_id', $other->tenant->id)->pluck('type')->all())->toContain('new', 'cancel_scheduled', 'churn');

        $movements = SaasEvents::movements(Carbon::parse($start), now()->addSecond());
        expect($movements['balanced'])->toBeTrue()
            ->and($movements['by_type']['new'])->toEqual(149 + 299)
            ->and($movements['by_type']['churn'])->toEqual(-299)
            ->and($movements['end'])->toEqual(149)
            ->and($movements['trials']['converted'])->toBe(1);

        $admin = susPlatform();
        $data = $this->as($admin)->getJson('/api/admin/reports?tab=metrics')->assertOk()->json('data');
        expect($data['movements']['balanced'])->toBeTrue();
    });
});

describe('boleta o factura del plan', function () {
    it('los datos de factura piden un RUC válido y el siguiente cobro sale como factura', function () {
        $co = $this->company(['plan' => self::PLAN_ESENCIAL]);

        $this->as($co->owner)->putJson('/api/billing/details', ['doc_type' => 'factura', 'ruc' => '123', 'business_name' => 'Mi Spa SAC'])->assertStatus(422)->assertJsonValidationErrors('ruc');
        $this->as($co->owner)->putJson('/api/billing/details', ['doc_type' => 'factura', 'ruc' => '20601234567', 'business_name' => 'Mi Spa SAC', 'address' => 'Av. Larco 100'])->assertOk()
            ->assertJsonPath('billing_details.doc_type', 'factura');

        $invoice = Subscriptions::recordPayment(susSubscription($co->tenant->id), ['reason' => 'manual', 'amount' => 149]);
        $invoice->refresh();

        expect($invoice->doc_type)->toBe('factura')
            ->and($invoice->serie)->toBe('F001')
            ->and($invoice->number)->toBe(1)
            ->and($invoice->customer_doc_number)->toBe('20601234567')
            ->and((float) $invoice->tax)->toBe(22.73);

        $bill = $this->as($co->owner)->getJson('/api/billing')->assertOk()->json('invoices.0.document');
        expect($bill['label'])->toBe('Factura F001-00000001');
    });

    it('con Nubefact conectado en la plataforma envía la boleta del plan con el IGV incluido', function () {
        $sent = [];
        Http::fake(function (HttpRequest $request) use (&$sent) {
            $sent[] = $request->data();

            return Http::response(['aceptada_por_sunat' => true, 'enlace_del_pdf' => 'https://www.nubefact.com/pdf/abc', 'serie' => 'B001', 'numero' => 1]);
        });

        $admin = susPlatform();
        $this->as($admin)->putJson('/api/admin/billing/invoicing', [
            'mode' => 'live', 'ruc' => '20555555551', 'legal_name' => 'Plataforma SAC', 'route' => 'https://api.nubefact.com/api/v1/abc', 'token' => 'tok_123',
        ])->assertOk()->assertJsonPath('provider', 'nubefact');

        $co = $this->company(['plan' => self::PLAN_ESENCIAL]);
        $invoice = Subscriptions::recordPayment(susSubscription($co->tenant->id), ['reason' => 'manual', 'amount' => 149])->refresh();

        expect($invoice->sunat_status)->toBe('accepted')
            ->and((bool) $invoice->simulated)->toBeFalse()
            ->and($invoice->doc_pdf_url)->toBe('https://www.nubefact.com/pdf/abc')
            ->and($sent[0]['tipo_de_comprobante'])->toBe(2)
            ->and((float) $sent[0]['total'])->toBe(149.0);
    });
});

describe('exportar todos los datos', function () {
    it('prepara el archivo en cola, avisa con un enlace firmado de 7 días y solo trae datos de la empresa', function () {
        Storage::fake('local');
        $co = $this->company();
        $otra = $this->company();
        $this->customer($co->tenant, 'Lucía', 'Propia');
        $this->customer($otra->tenant, 'Ajena', 'DeOtra');

        $res = $this->as($co->owner)->postJson('/api/billing/exports')->assertStatus(202)->json('export');
        $row = DB::table('data_exports')->where('id', $res['id'])->first();

        expect($res['status'])->toBe('ready')
            ->and(Carbon::parse($row->expires_at)->diffInDays(now(), true))->toBeGreaterThan(6.9)
            ->and(DB::table('audit_events')->where('tenant_id', $co->tenant->id)->where('action', 'data.export')->exists())->toBeTrue()
            ->and(DB::table('user_notifications')->where('tenant_id', $co->tenant->id)->where('type', 'data.export_ready')->exists())->toBeTrue();

        $content = Storage::disk('local')->get($row->file_path);
        $zip = new ZipArchive();
        $tmp = tempnam(sys_get_temp_dir(), 'x');
        file_put_contents($tmp, $content);
        $zip->open($tmp);
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        expect($sheet)->toContain('Lucía')->not->toContain('Ajena');

        $path = parse_url($res['url'], PHP_URL_PATH) . '?' . parse_url($res['url'], PHP_URL_QUERY);
        $this->get($path)->assertOk();
        $this->get(str_replace('signature=', 'signature=x', $path))->assertForbidden();
        expect($this->as($otra->owner)->getJson('/api/billing/exports')->json('items'))->toBe([]);
    });

    it('también funciona con la empresa en solo lectura', function () {
        Storage::fake('local');
        $co = $this->company();
        susSubscription($co->tenant->id)->update(['ends_at' => now()->subDays(40), 'status' => 'past_due']);
        Subscriptions::forget($co->tenant->id);

        $this->as($co->owner)->postJson('/api/branches', ['name' => 'Otra'])->assertStatus(423)->assertJsonPath('code', 'TENANT_SUSPENDED');
        $this->as($co->owner)->postJson('/api/billing/exports')->assertStatus(202);
    });
});

describe('errores del sistema', function () {
    it('agrupa los errores iguales, ignora los avisos al usuario y avisa al administrador de la plataforma', function () {
        PlatformMail::$outbox = [];
        $admin = susPlatform();

        foreach ([123, 456] as $pedido) {
            ErrorLogs::capture(new RuntimeException("Falló el cálculo del pedido {$pedido}"));
        }

        ErrorLogs::capture(Illuminate\Validation\ValidationException::withMessages(['x' => 'y']));

        $rows = DB::table('error_logs')->get();
        expect($rows)->toHaveCount(1)->and($rows[0]->occurrences)->toBe(2)->and($rows[0]->source)->toBe('server');

        $this->postJson('/api/client-errors', ['message' => 'Cannot read properties of undefined', 'url' => 'https://app.test/agenda', 'file' => 'https://app.test/_next/x.js', 'line' => 10])->assertNoContent();
        expect(DB::table('error_logs')->where('source', 'web')->value('url'))->toBe('/agenda');

        $list = $this->as($admin)->getJson('/api/admin/errors')->assertOk()->json();
        expect($list['open'])->toBe(2);

        $id = $rows[0]->id;
        $this->as($admin)->putJson("/api/admin/errors/{$id}", ['resolved' => true])->assertOk();
        expect($this->as($admin)->getJson('/api/admin/errors')->json('open'))->toBe(1);

        $co = $this->company();
        $this->as($co->owner)->getJson('/api/admin/errors')->assertForbidden();
    });

    it('con el interruptor de Sentry encendido manda además el error a Sentry', function () {
        $hits = [];
        Http::fake(function (HttpRequest $request) use (&$hits) {
            $hits[] = $request->url();

            return Http::response(['id' => 'x']);
        });
        $admin = susPlatform();

        $this->as($admin)->putJson('/api/admin/errors-settings', ['notify' => false, 'notify_every_minutes' => 60, 'sentry_enabled' => true, 'sentry_dsn' => 'mal'])->assertStatus(422);
        $this->as($admin)->putJson('/api/admin/errors-settings', ['notify' => false, 'notify_every_minutes' => 60, 'sentry_enabled' => true, 'sentry_dsn' => 'https://clave123@o1.ingest.sentry.io/456'])->assertOk()->assertJsonPath('has_sentry_dsn', true);
        \Tests\TestCase::forgetCaches();

        ErrorLogs::capture(new LogicException('Algo raro'));

        expect($hits)->toContain('https://o1.ingest.sentry.io/api/456/store/');
    });
});

describe('chat comercial del sitio', function () {
    it('deja un interesado con fuente chat, el plan sugerido y el mensaje', function () {
        $this->postJson('/api/web/chat', ['name' => 'Rosa', 'phone' => '999888777', 'message' => 'Tengo 3 estilistas', 'professionals' => 3, 'plan' => 'Esencial', 'person' => true])
            ->assertStatus(422)->assertJsonValidationErrors('consent');

        $this->postJson('/api/web/chat', ['name' => 'Rosa', 'phone' => '999888777', 'message' => 'Tengo 3 estilistas', 'professionals' => 3, 'plan' => 'Esencial', 'person' => true, 'consent' => true])->assertCreated();

        $lead = DB::table('web_leads')->where('name', 'Rosa')->first();
        expect($lead->source)->toBe('chatbot')
            ->and($lead->professionals)->toBe(3)
            ->and(DB::table('web_lead_events')->where('lead_id', $lead->id)->value('body'))->toContain('Pidió hablar con una persona', 'Esencial', 'Tengo 3 estilistas');

        expect($this->getJson('/api/web/site')->json('site.chat.enabled'))->toBeTrue();
    });
});

describe('artículos de ayuda y blog', function () {
    it('un artículo desactivado deja de verse al instante y los de configuración no los ve recepción', function () {
        $admin = susPlatform();
        $co = $this->company();
        $recepcion = $this->staff($co, 'reception');

        $general = $this->as($admin)->postJson('/api/admin/help-articles', ['title' => 'Cómo reprogramar una cita', 'category' => 'Agenda', 'body' => '<p>Arrastra la cita.</p>', 'link' => '/agenda'])->assertCreated()->json();
        $this->as($admin)->postJson('/api/admin/help-articles', ['title' => 'Cambiar los permisos', 'category' => 'Configuración', 'body' => '<p>Ve a Usuarios.</p>', 'permission' => 'users.edit', 'replaces_topic' => 'usuarios-permisos'])->assertCreated();

        $seen = $this->as($recepcion)->getJson('/api/help/articles')->assertOk()->json();
        expect(collect($seen['items'])->pluck('title')->all())->toBe(['Cómo reprogramar una cita'])
            ->and($seen['hidden'])->toBe(['usuarios-permisos']);
        expect(collect($this->as($co->owner)->getJson('/api/help/articles')->json('items'))->pluck('title')->all())->toContain('Cambiar los permisos');

        $this->as($admin)->putJson("/api/admin/help-articles/{$general['id']}", ['is_active' => false])->assertOk();
        expect($this->as($recepcion)->getJson('/api/help/articles')->json('items'))->toBe([]);
    });

    it('el blog guarda enlaces a otras páginas del sitio y quita los que no son seguros', function () {
        $admin = susPlatform();
        $post = $this->as($admin)->postJson('/api/admin/web/posts', [
            'title' => 'Cómo llenar tu agenda', 'status' => 'draft',
            'body'  => '<p>Mira los <a href="/precios">precios</a>, <a href="//malo.test">esto</a> y <a href="javascript:alert(1)">aquello</a>.</p>',
        ])->assertSuccessful()->json();

        $body = DB::table('web_posts')->where('id', $post['item']['id'])->value('body');
        expect($body)->toContain('<a href="/precios">precios</a>')->not->toContain('malo.test')->not->toContain('javascript');
    });
});

describe('empresas desde la consola', function () {
    it('reactivar una empresa exige motivo y queda en la auditoría', function () {
        $admin = susPlatform();
        $co = $this->company();
        $this->as($admin)->postJson("/api/tenants/{$co->tenant->id}/suspend", ['reason' => 'Falta de pago del plan'])->assertOk();

        $this->as($admin)->postJson("/api/tenants/{$co->tenant->id}/reactivate")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->as($admin)->postJson("/api/tenants/{$co->tenant->id}/reactivate", ['reason' => 'Ya pagó por transferencia'])->assertOk();

        $event = DB::table('audit_events')->where('tenant_id', $co->tenant->id)->where('action', 'tenant.reactivated')->first();
        expect(json_decode($event->after_data, true)['reason'])->toBe('Ya pagó por transferencia');
    });
});

describe('avisos del plan en el panel', function () {
    it('el backend arma los avisos de cambio programado y de uso sobre 80 %, sin acción para quien no administra', function () {
        $this->travelTo(now('America/Lima')->startOfMonth()->addDay()->setTime(9, 0));
        $co = $this->company(['plan' => self::PLAN_PROFESIONAL]);
        $this->as($co->owner)->postJson('/api/billing/plan', ['plan_id' => self::PLAN_ESENCIAL, 'billing_cycle' => 'monthly'])->assertOk();
        DB::table('saas_plans')->where('id', self::PLAN_PROFESIONAL)->update(['limits' => json_encode(['users' => 20, 'branches' => 3, 'professionals' => 20, 'appointments_month' => 1, 'whatsapp_month' => 2000])]);
        $servicio = $this->servicePriced($co->tenant, 80, 'Corte');
        $this->book($co, $this->customer($co->tenant), $servicio, $this->professional($co, 'Ana Torres'), $this->at($co->tenant, '10:00'))->assertCreated();

        $notices = $this->as($co->owner)->getJson('/api/billing')->assertOk()->json('billing.notices');
        expect(collect($notices)->pluck('kind')->all())->toBe(['change', 'usage'])
            ->and($notices[0]['action'])->toBe('Ver el cambio');

        $recepcion = $this->staff($co, 'reception');
        $theirs = $this->as($recepcion)->getJson('/api/billing')->assertOk()->json('billing.notices');
        expect(collect($theirs)->pluck('action')->filter()->all())->toBe([])
            ->and($theirs[1]['text'])->toContain('Avísale al administrador');
    });
});
