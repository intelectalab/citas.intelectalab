<?php

use App\Http\Controllers\Api\MessageController;
use App\Support\Messaging;
use App\Support\MessageQuota;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function wcAs(object $test, object $user): object
{
    \Tests\TestCase::forgetCaches();
    $user->unsetRelations();
    \Laravel\Sanctum\Sanctum::actingAs($user, ['*']);

    return $test;
}

function wcConnect(object $test, object $co): void
{
    wcAs($test, $co->owner)->putJson('/api/messaging/whatsapp', [
        'provider' => 'cloud', 'phone_number_id' => '1234567890', 'business_account_id' => '555',
        'token' => 'EAA-token-de-prueba', 'app_secret' => 'secreto', 'verify_token' => 'verificar-123',
        'templates' => ['booking_confirmation' => ['name' => 'cita_agendada', 'language' => 'es']],
    ])->assertOk();
}

function wcEmail(string $tenantId): void
{
    DB::table('tenant_integrations')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'kind' => 'email', 'provider' => 'simulated', 'mode' => 'simulated',
        'settings' => json_encode(['from_address' => 'citas@empresa.test', 'from_name' => 'Empresa']), 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function wcLimits(object $co, array $limits): void
{
    $planId = $co->tenant->activeSubscription->plan_id;
    $current = json_decode((string) DB::table('saas_plans')->where('id', $planId)->value('limits'), true) ?: [];
    DB::table('saas_plans')->where('id', $planId)->update(['limits' => json_encode(array_merge($current, $limits))]);
    $co->tenant->unsetRelations();
}

function wcClient(object $test, object $co, string $name, ?string $phone, bool $wa = true, bool $email = true): object
{
    $customer = (fn () => $this->customer($co->tenant, $name, 'Prueba'))->call($test);
    DB::table('customers')->where('id', $customer->id)->update(['phone' => $phone]);
    MessageController::saveConsents($co->tenant->id, $customer->id, ['email' => $email, 'whatsapp' => $wa], 'staff');

    return $customer->refresh();
}

function wcPayload(array $extra = []): array
{
    return $extra + [
        'name' => 'Promo de noviembre', 'group' => 'all', 'channel' => 'whatsapp', 'send' => 'now',
        'wa_template' => 'promo_noviembre', 'wa_language' => 'es', 'wa_params' => ['Nombre', 'Empresa'],
    ];
}

beforeEach(function () {
    $this->co = $this->company();
    $this->sent = [];
    $test = $this;
    Http::fake(['https://graph.facebook.com/*' => function (HttpRequest $request) use ($test) {
        $test->sent[] = $request->data();

        return $request->method() === 'GET'
            ? Http::response(['display_phone_number' => '+51 999 888 777', 'verified_name' => 'Salón'], 200)
            : Http::response(['messages' => [['id' => 'wamid.C' . count($test->sent)]]], 200);
    }]);
});

it('la vista previa dice cuántos reciben por cada canal y por qué se excluye a los demás', function () {
    wcConnect($this, $this->co);
    wcEmail($this->co->tenant->id);
    wcClient($this, $this->co, 'Lucía', '987654321');
    wcClient($this, $this->co, 'Sin celular', null);
    wcClient($this, $this->co, 'Sin permiso', '987654322', false);
    $baja = wcClient($this, $this->co, 'De baja', '987654323');
    MessageController::saveConsents($this->co->tenant->id, $baja->id, ['email' => false], 'unsubscribe');

    $res = wcAs($this, $this->co->owner)->postJson('/api/campaigns/audience', ['group' => 'all', 'channel' => 'both'])->assertOk();

    expect($res->json('channels.whatsapp'))->toMatchArray(['eligible' => 1, 'no_phone' => 1, 'no_consent' => 1, 'unsubscribed' => 1, 'not_connected' => 0])
        ->and($res->json('channels.email.eligible'))->toBe(3)
        ->and($res->json('channels.email.no_consent'))->toBe(1)
        ->and($res->json('eligible'))->toBe(3)
        ->and($res->json('quota.whatsapp.needed'))->toBe(1)
        ->and($res->json('quota.whatsapp.limit'))->toBe(10000);
});

it('una campaña por WhatsApp sale con la plantilla aprobada y los datos de cada cliente', function () {
    wcConnect($this, $this->co);
    $lucia = wcClient($this, $this->co, 'Lucía', '987654321');
    wcClient($this, $this->co, 'Sin permiso', '987654322', false);

    $res = wcAs($this, $this->co->owner)->postJson('/api/campaigns', wcPayload())->assertCreated();
    Messaging::process($this->co->tenant->id);

    $post = collect($this->sent)->firstWhere('type', 'template');
    $recipients = DB::table('marketing_campaign_recipients')->where('marketing_campaign_id', $res->json('id'))->get();

    expect($res->json('channel'))->toBe('whatsapp')
        ->and($res->json('subject'))->toBe('')
        ->and($post['template']['name'])->toBe('promo_noviembre')
        ->and($post['template']['language']['code'])->toBe('es')
        ->and($post['template']['components'][0]['parameters'][0]['text'])->toBe('Lucía')
        ->and($post['to'])->toBe('51987654321')
        ->and($recipients->where('channel', 'whatsapp')->where('status', 'pending')->pluck('customer_id')->all())->toBe([$lucia->id])
        ->and($recipients->where('status', 'skipped')->first()->skip_reason)->toBe('No aceptó recibir mensajes por WhatsApp.')
        ->and(DB::table('messages')->where('entity_id', $res->json('id'))->where('channel', 'whatsapp')->value('status'))->toBe('sent');

    $show = wcAs($this, $this->co->owner)->getJson('/api/campaigns/' . $res->json('id'))->assertOk();
    expect($show->json('results.sent'))->toBe(1)
        ->and($show->json('results.by_channel.whatsapp.sent'))->toBe(1)
        ->and(collect($show->json('recipients'))->firstWhere('state', 'sent')['channel'])->toBe('whatsapp');
});

it('con los dos canales cada cliente recibe un correo y un WhatsApp', function () {
    wcConnect($this, $this->co);
    wcEmail($this->co->tenant->id);
    wcClient($this, $this->co, 'Lucía', '987654321');

    $res = wcAs($this, $this->co->owner)->postJson('/api/campaigns', wcPayload(['channel' => 'both', 'subject' => 'Hola [Nombre]', 'body' => 'Te esperamos']))->assertCreated();
    Messaging::process($this->co->tenant->id);

    expect(DB::table('marketing_campaign_recipients')->where('marketing_campaign_id', $res->json('id'))->orderBy('channel')->pluck('channel')->all())->toBe(['email', 'whatsapp'])
        ->and(DB::table('messages')->where('entity_id', $res->json('id'))->orderBy('channel')->pluck('status')->all())->toBe(['simulated', 'sent']);
});

it('pide la plantilla y que WhatsApp esté conectado', function () {
    wcClient($this, $this->co, 'Lucía', '987654321');

    wcAs($this, $this->co->owner)->postJson('/api/campaigns', wcPayload(['wa_template' => '']))->assertJsonValidationErrors('wa_template');
    wcAs($this, $this->co->owner)->postJson('/api/campaigns', wcPayload(['wa_template' => 'Promo Nov']))->assertJsonValidationErrors('wa_template');
    wcAs($this, $this->co->owner)->postJson('/api/campaigns', wcPayload())
        ->assertJsonValidationErrors(['channel' => 'conecta WhatsApp']);
    wcAs($this, $this->co->owner)->postJson('/api/campaigns', wcPayload(['wa_params' => ['Cupón']]))->assertJsonValidationErrors('coupon_id');
});

it('el enlace de baja también saca al cliente de las campañas por WhatsApp', function () {
    wcConnect($this, $this->co);
    $lucia = wcClient($this, $this->co, 'Lucía', '987654321');
    wcClient($this, $this->co, 'Otra', '987654329');

    $res = wcAs($this, $this->co->owner)->postJson('/api/campaigns', wcPayload(['wa_params' => ['Nombre', 'Baja']]))->assertCreated();
    $recipient = DB::table('marketing_campaign_recipients')->where('marketing_campaign_id', $res->json('id'))->where('customer_id', $lucia->id)->first();

    expect(json_decode(DB::table('messages')->where('id', $recipient->message_id)->value('meta'), true)['vars']['2'])->toContain('/promociones/baja/' . $recipient->id);

    $this->getJson('/api/promotions/unsubscribe/' . $recipient->id)->assertOk()->assertJsonPath('unsubscribed', false);
    $this->postJson('/api/promotions/unsubscribe/' . $recipient->id)->assertOk();

    $audience = wcAs($this, $this->co->owner)->postJson('/api/campaigns/audience', ['group' => 'all', 'channel' => 'whatsapp'])->assertOk();
    expect($audience->json('channels.whatsapp.unsubscribed'))->toBe(1)
        ->and($audience->json('channels.whatsapp.eligible'))->toBe(1);
});

it('si la campaña pasa el tope de WhatsApp del plan no sale y lo dice', function () {
    wcConnect($this, $this->co);
    wcLimits($this->co, ['whatsapp_month' => 1]);
    wcClient($this, $this->co, 'Lucía', '987654321');
    wcClient($this, $this->co, 'Rosa', '987654322');

    wcAs($this, $this->co->owner)->postJson('/api/campaigns', wcPayload())
        ->assertJsonValidationErrors(['send' => 'necesita 2 mensajes de WhatsApp y a tu plan le quedan 1']);

    expect(DB::table('marketing_campaigns')->where('tenant_id', $this->co->tenant->id)->count())->toBe(0)
        ->and($this->sent)->toBe([]);
});

it('una campaña programada que al salir no cabe en el tope queda como "No salió" y avisa', function () {
    wcConnect($this, $this->co);
    wcClient($this, $this->co, 'Lucía', '987654321');
    wcClient($this, $this->co, 'Rosa', '987654322');
    $at = now($this->co->tenant->zone())->addHour();

    $res = wcAs($this, $this->co->owner)->postJson('/api/campaigns', wcPayload(['send' => 'scheduled', 'scheduled_at' => $at->format('Y-m-d\TH:i')]))->assertCreated();
    wcLimits($this->co, ['whatsapp_month' => 1]);
    $this->travelTo($at->copy()->addMinutes(5));

    $list = wcAs($this, $this->co->owner)->getJson('/api/campaigns')->assertOk();
    $row = collect($list->json('campaigns'))->firstWhere('id', $res->json('id'));

    expect($row['status'])->toBe('blocked')
        ->and($row['status_label'])->toBe('No salió')
        ->and($row['blocked'])->toContain('le quedan 1')
        ->and(DB::table('messages')->where('entity_id', $res->json('id'))->count())->toBe(0)
        ->and(DB::table('user_notifications')->where('user_id', $this->co->owner->id)->where('entity_id', $res->json('id'))->exists())->toBeTrue();
})->skip(fn () => now()->addHours(2)->month !== now()->month, 'Cambio de mes');

it('el tope de correos de campañas vacío no limita y con número sí', function () {
    wcEmail($this->co->tenant->id);
    wcClient($this, $this->co, 'Lucía', '987654321');
    wcClient($this, $this->co, 'Rosa', '987654322');
    $payload = ['name' => 'Correo', 'group' => 'all', 'channel' => 'email', 'send' => 'now', 'subject' => 'Hola', 'body' => 'Promo'];

    wcLimits($this->co, ['campaign_emails_month' => 1]);
    wcAs($this, $this->co->owner)->postJson('/api/campaigns', $payload)->assertJsonValidationErrors(['send' => 'correos de campañas']);

    wcLimits($this->co, ['campaign_emails_month' => null]);
    wcAs($this, $this->co->owner)->postJson('/api/campaigns', $payload)->assertCreated();
    Messaging::process($this->co->tenant->id);

    expect(MessageQuota::used($this->co->tenant, 'campaign_email'))->toBe(2);
});

it('al 80 % del tope avisa a la administración una sola vez', function () {
    wcConnect($this, $this->co);
    wcLimits($this->co, ['whatsapp_month' => 5]);

    foreach (['Ana', 'Bea', 'Cata', 'Dora'] as $i => $name) {
        wcClient($this, $this->co, $name, '98765432' . $i);
    }

    wcAs($this, $this->co->owner)->postJson('/api/campaigns', wcPayload())->assertCreated();
    Messaging::process($this->co->tenant->id);

    $notices = DB::table('user_notifications')->where('user_id', $this->co->owner->id)->where('type', 'plan.limit')->get();

    expect(MessageQuota::used($this->co->tenant, 'whatsapp'))->toBe(4)
        ->and($notices)->toHaveCount(1)
        ->and($notices[0]->title)->toContain('80 %');
});

it('un aviso automático por WhatsApp con el tope alcanzado sale por correo y lo dice', function () {
    wcConnect($this, $this->co);
    wcEmail($this->co->tenant->id);
    wcLimits($this->co, ['whatsapp_month' => 0]);
    $this->setRules($this->co->tenant, ['whatsapp.channel' => 'whatsapp']);
    $servicio = $this->servicePriced($this->co->tenant, 80, 'Manicure');
    DB::table('services')->where('id', $servicio->id)->update(['duration_minutes' => 60]);
    $ana = $this->professional($this->co, 'Ana Torres');
    $lucia = wcClient($this, $this->co, 'Lucía', '987654321');

    $this->book($this->co, $lucia, $servicio, $ana, $this->at($this->co->tenant))->assertCreated();
    Messaging::process($this->co->tenant->id);
    Messaging::process($this->co->tenant->id);

    $rows = DB::table('messages')->where('tenant_id', $this->co->tenant->id)->where('kind', 'booking_confirmation')->get()->keyBy('channel');

    expect($rows['whatsapp']->status)->toBe('not_sent')
        ->and($rows['whatsapp']->error)->toContain('tope de 0 mensajes de WhatsApp')
        ->and($rows['whatsapp']->error)->toContain('Se envió por correo')
        ->and($rows['email']->status)->toBe('simulated')
        ->and(collect($this->sent)->where('type', 'template'))->toHaveCount(0);
});

it('los avisos automáticos por WhatsApp cuentan en el uso del mes y Tu plan lo muestra frente al límite', function () {
    wcConnect($this, $this->co);
    $this->setRules($this->co->tenant, ['whatsapp.channel' => 'whatsapp']);
    $servicio = $this->servicePriced($this->co->tenant, 80, 'Manicure');
    DB::table('services')->where('id', $servicio->id)->update(['duration_minutes' => 60]);
    $ana = $this->professional($this->co, 'Ana Torres');
    $lucia = wcClient($this, $this->co, 'Lucía', '987654321');

    $this->book($this->co, $lucia, $servicio, $ana, $this->at($this->co->tenant))->assertCreated();
    Messaging::process($this->co->tenant->id);

    $usage = collect(wcAs($this, $this->co->owner)->getJson('/api/company')->assertOk()->json('usage'))->keyBy('key');

    expect($usage['whatsapp_month'])->toMatchArray(['used' => 1, 'limit' => 10000])
        ->and($usage['campaign_emails_month'])->toMatchArray(['used' => 0, 'limit' => null]);
});

it('las opciones de campañas traen los canales, los datos de la plantilla y el uso del plan', function () {
    $res = wcAs($this, $this->co->owner)->getJson('/api/campaigns/options')->assertOk();

    expect(collect($res->json('channels'))->pluck('key')->all())->toBe(['email', 'whatsapp', 'both'])
        ->and(collect($res->json('wa_variables'))->pluck('key')->all())->toContain('Nombre', 'Baja')
        ->and($res->json('whatsapp.state'))->toBe('none')
        ->and($res->json('quota.whatsapp.limit'))->toBe(10000);
});

it('otra empresa no ve ni usa el tope de la primera', function () {
    wcConnect($this, $this->co);
    wcClient($this, $this->co, 'Lucía', '987654321');
    wcAs($this, $this->co->owner)->postJson('/api/campaigns', wcPayload())->assertCreated();
    Messaging::process($this->co->tenant->id);

    $otra = $this->company();

    expect(MessageQuota::used($this->co->tenant, 'whatsapp'))->toBe(1)
        ->and(MessageQuota::used($otra->tenant, 'whatsapp'))->toBe(0);
});
