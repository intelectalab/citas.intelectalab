<?php

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

const D360 = 'https://waba-v2.360dialog.io';

beforeEach(function () {
    config(['services.whatsapp.simulate' => false]);
    Storage::fake('local');
    $this->co = $this->company();
    $this->clienta = $this->customer($this->co->tenant, 'Lucía', 'Paredes');
    DB::table('customers')->where('id', $this->clienta->id)->update(['phone' => '987654321']);

    $this->hook = function (array $message, ?string $token) {
        $payload = json_encode(['entry' => [['changes' => [['value' => ['contacts' => [['wa_id' => '51987654321', 'profile' => ['name' => 'Lucía']]], 'messages' => [$message]]]]]]]);

        return $this->call('POST', "/api/webhooks/whatsapp/{$this->co->tenant->id}", [], [], [], array_filter(['CONTENT_TYPE' => 'application/json', 'HTTP_X_WEBHOOK_TOKEN' => $token, 'HTTP_X_HUB_SIGNATURE_256' => 'sha256=' . hash_hmac('sha256', $payload, 'app-secreto')]), $payload);
    };
});

it('360dialog: se conecta con la clave, registra los avisos con su clave y envía por la misma interfaz', function () {
    Http::fake([
        D360 . '/v1/configs/webhook' => Http::response(['url' => 'x', 'headers' => []], 200),
        D360 . '/messages' => Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.D360']]]),
    ]);

    $this->as($this->co->owner)->putJson('/api/messaging/whatsapp', ['provider' => '360dialog'])->assertStatus(422)->assertJsonValidationErrors('token');
    $this->as($this->co->owner)->putJson('/api/messaging/whatsapp', ['provider' => '360dialog', 'token' => 'D360-clave', 'verify_token' => 'clave-avisos-360'])->assertOk()
        ->assertJsonPath('config.provider', '360dialog');

    $this->as($this->co->owner)->postJson('/api/messaging/whatsapp/test')->assertOk()->assertJsonPath('ok', true);
    Http::assertSent(fn ($r) => $r->method() === 'POST' && $r->url() === D360 . '/v1/configs/webhook' && $r['headers']['X-Webhook-Token'] === 'clave-avisos-360' && $r->hasHeader('D360-API-KEY', 'D360-clave'));

    ($this->hook)(['from' => '51987654321', 'id' => 'wamid.in1', 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => 'Hola']], 'otra')->assertStatus(403);
    ($this->hook)(['from' => '51987654321', 'id' => 'wamid.in1', 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => 'Hola']], 'clave-avisos-360')->assertOk();

    $conversation = DB::table('whatsapp_conversations')->where('tenant_id', $this->co->tenant->id)->first();
    $this->as($this->co->owner)->postJson("/api/whatsapp/conversations/{$conversation->id}/reply", ['body' => 'Hola Lucía'])->assertOk();

    Http::assertSent(fn ($r) => $r->url() === D360 . '/messages' && $r['text']['body'] === 'Hola Lucía' && $r['to'] === '51987654321');
});

it('lee las plantillas aprobadas desde Meta con sus datos', function () {
    Http::fake(['https://graph.facebook.com/*' => Http::response(['data' => [
        ['name' => 'recordatorio_cita', 'language' => 'es', 'status' => 'APPROVED', 'category' => 'UTILITY', 'components' => [['type' => 'BODY', 'text' => 'Hola {{1}}, te esperamos para {{2}} el {{3}}.']]],
        ['name' => 'borrador', 'language' => 'es', 'status' => 'PENDING', 'components' => []],
    ]])]);
    $this->as($this->co->owner)->putJson('/api/messaging/whatsapp', ['provider' => 'cloud', 'phone_number_id' => '123', 'business_account_id' => '555', 'token' => 'EAA', 'app_secret' => 's'])->assertOk();

    $res = $this->as($this->co->owner)->getJson('/api/messaging/whatsapp/templates')->assertOk()->json();

    expect($res['ok'])->toBeTrue()->and($res['templates'])->toHaveCount(1)
        ->and($res['templates'][0])->toMatchArray(['name' => 'recordatorio_cita', 'language' => 'es', 'params' => 3]);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/555/message_templates'));
});

it('sin el identificador de la cuenta lo dice en vez de fallar', function () {
    Http::fake();
    $this->as($this->co->owner)->putJson('/api/messaging/whatsapp', ['provider' => 'cloud', 'phone_number_id' => '123', 'token' => 'EAA', 'app_secret' => 's'])->assertOk();

    $this->as($this->co->owner)->getJson('/api/messaging/whatsapp/templates')->assertOk()->assertJsonPath('ok', false)->assertJsonPath('message', fn ($m) => str_contains($m, 'WABA'));
});

it('guarda la foto que manda el cliente y el personal la puede ver', function () {
    Http::fake([
        'https://graph.facebook.com/v21.0/media-77' => Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp/abc', 'mime_type' => 'image/jpeg']),
        'https://lookaside.fbsbx.com/*' => Http::response('JPEGDATA', 200, ['Content-Type' => 'image/jpeg']),
    ]);
    DB::table('tenant_integrations')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->co->tenant->id, 'kind' => 'whatsapp', 'provider' => 'cloud', 'mode' => 'production', 'status' => 'active',
        'settings' => json_encode(['phone_number_id' => '123']), 'credentials' => \Illuminate\Support\Facades\Crypt::encryptString(json_encode(['token' => 'EAA', 'app_secret' => 'app-secreto', 'verify_token' => 'v12345678']))]);

    ($this->hook)(['from' => '51987654321', 'id' => 'wamid.img', 'timestamp' => (string) now()->timestamp, 'type' => 'image', 'image' => ['id' => 'media-77', 'caption' => 'Así quedó']], null)->assertOk();

    $row = DB::table('whatsapp_messages')->where('provider_message_id', 'wamid.img')->first();
    expect($row->media_mime)->toBe('image/jpeg')->and(Storage::disk('local')->get($row->media_path))->toBe('JPEGDATA');

    $thread = $this->as($this->co->owner)->getJson("/api/whatsapp/conversations/{$row->conversation_id}")->assertOk()->json('messages');
    expect(collect($thread)->firstWhere('id', $row->id)['media']['image'])->toBeTrue();
    $this->as($this->co->owner)->get("/api/whatsapp/messages/{$row->id}/media")->assertOk();
});

it('el personal manda un PDF: se sube a WhatsApp y se envía con su identificador', function () {
    Http::fake([
        'https://graph.facebook.com/v21.0/123/media' => Http::response(['id' => 'up-1']),
        'https://graph.facebook.com/v21.0/123/messages' => Http::response(['messages' => [['id' => 'wamid.pdf']]]),
    ]);
    DB::table('tenant_integrations')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->co->tenant->id, 'kind' => 'whatsapp', 'provider' => 'cloud', 'mode' => 'production', 'status' => 'active',
        'settings' => json_encode(['phone_number_id' => '123']), 'credentials' => \Illuminate\Support\Facades\Crypt::encryptString(json_encode(['token' => 'EAA', 'app_secret' => 'app-secreto', 'verify_token' => 'v12345678']))]);
    ($this->hook)(['from' => '51987654321', 'id' => 'wamid.t', 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => 'Mándame la proforma']], null)->assertOk();
    $conversation = DB::table('whatsapp_conversations')->where('tenant_id', $this->co->tenant->id)->first();

    $this->as($this->co->owner)->post("/api/whatsapp/conversations/{$conversation->id}/file", ['file' => UploadedFile::fake()->create('proforma.pdf', 20, 'application/pdf'), 'caption' => 'Tu proforma'], ['Accept' => 'application/json'])->assertOk();
    $this->as($this->co->owner)->post("/api/whatsapp/conversations/{$conversation->id}/file", ['file' => UploadedFile::fake()->create('virus.exe', 20, 'application/x-msdownload')], ['Accept' => 'application/json'])->assertStatus(422);

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/123/messages') && $r['type'] === 'document' && $r['document']['id'] === 'up-1' && $r['document']['filename'] === 'proforma.pdf');
    expect(DB::table('whatsapp_messages')->where('provider_message_id', 'wamid.pdf')->value('media_name'))->toBe('proforma.pdf');
});
