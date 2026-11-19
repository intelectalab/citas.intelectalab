<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->co = $this->company();
    $this->clienta = $this->customer($this->co->tenant, 'Rosa', 'Quispe');
    DB::table('customers')->where('id', $this->clienta->id)->update(['phone' => '987654321', 'email' => 'rosa.quispe@correo.test']);
    $this->borrarDatos = fn () => $this->as($this->co->owner)->postJson("/api/customers/{$this->clienta->id}/anonymize", ['confirm' => 'BORRAR'])->assertOk();
});

it('borra las notas internas del cliente, también las privadas', function () {
    $this->as($this->co->owner)->postJson("/api/customers/{$this->clienta->id}/notes", ['body' => 'Rosa prefiere la mañana'])->assertCreated();
    $this->as($this->co->owner)->postJson("/api/customers/{$this->clienta->id}/notes", ['body' => 'Rosa Quispe tiene una deuda', 'is_private' => true])->assertCreated();

    expect(DB::table('customer_notes')->where('customer_id', $this->clienta->id)->count())->toBe(2);

    ($this->borrarDatos)();

    expect(DB::table('customer_notes')->where('customer_id', $this->clienta->id)->count())->toBe(0)
        ->and(DB::table('customer_notes')->where('body', 'like', '%Rosa%')->count())->toBe(0);
});

it('borra el texto de los mensajes y los datos de contacto que quedaron en otras tablas', function () {
    $t = $this->co->tenant->id;
    $c = $this->clienta->id;

    DB::table('customer_messages')->insert(['tenant_id' => $t, 'customer_id' => $c, 'channel' => 'email', 'subject' => 'Hola Rosa', 'body' => 'Hola Rosa, te esperamos']);
    DB::table('messages')->insert(['tenant_id' => $t, 'customer_id' => $c, 'kind' => 'reminder', 'channel' => 'email', 'status' => 'sent', 'to_name' => 'Rosa Quispe', 'to_address' => 'rosa.quispe@correo.test', 'subject' => 'Recordatorio para Rosa', 'body' => 'Rosa, mañana es tu cita']);
    $conversacion = (string) \Illuminate\Support\Str::uuid();
    DB::table('whatsapp_conversations')->insert(['id' => $conversacion, 'tenant_id' => $t, 'customer_id' => $c, 'phone' => '51987654321', 'contact_name' => 'Rosa Q']);
    DB::table('whatsapp_messages')->insert(['tenant_id' => $t, 'conversation_id' => $conversacion, 'direction' => 'in', 'status' => 'received', 'body' => 'Soy Rosa, quiero una cita']);
    DB::table('gift_cards')->insert(['tenant_id' => $t, 'code' => 'GC-PRIV-1', 'initial_amount' => 50, 'balance' => 50, 'status' => 'active', 'recipient_customer_id' => $c, 'recipient_name' => 'Rosa Quispe', 'recipient_email' => 'rosa.quispe@correo.test']);
    DB::table('customer_consent_events')->insert(['tenant_id' => $t, 'customer_id' => $c, 'channel' => 'email', 'granted' => true, 'ip_address' => '190.12.3.4']);

    ($this->borrarDatos)();

    $mensaje = DB::table('messages')->where('customer_id', $c)->first();
    $aviso = DB::table('customer_messages')->where('customer_id', $c)->first();
    $chat = DB::table('whatsapp_conversations')->where('id', $conversacion)->first();
    $tarjeta = DB::table('gift_cards')->where('code', 'GC-PRIV-1')->first();

    expect($mensaje->to_name)->toBeNull()
        ->and($mensaje->to_address)->toBeNull()
        ->and($mensaje->subject)->toBeNull()
        ->and($mensaje->body)->not->toContain('Rosa')
        ->and($aviso->subject)->toBeNull()
        ->and($aviso->body)->not->toContain('Rosa')
        ->and($chat->phone)->toBe('')
        ->and($chat->contact_name)->toBeNull()
        ->and(DB::table('whatsapp_messages')->where('conversation_id', $conversacion)->value('body'))->not->toContain('Rosa')
        ->and($tarjeta->recipient_name)->toBeNull()
        ->and($tarjeta->recipient_email)->toBeNull()
        ->and(DB::table('customer_consent_events')->where('customer_id', $c)->value('ip_address'))->toBeNull();
});

it('borra las notas escritas en sus citas y presupuestos, pero conserva la cita', function () {
    $servicio = $this->servicePriced($this->co->tenant, 60, 'Corte');
    $ana = $this->professional($this->co, 'Ana Torres');
    $cita = $this->book($this->co, $this->clienta, $servicio, $ana, $this->at($this->co->tenant, '11:00'))->assertCreated()->json('id');
    DB::table('appointments')->where('id', $cita)->update(['notes' => 'Rosa viene con su hija', 'cancel_reason' => 'Rosa viaja a Cusco']);

    ($this->borrarDatos)();

    $fila = DB::table('appointments')->where('id', $cita)->first();

    expect($fila)->not->toBeNull()
        ->and($fila->notes)->toBeNull()
        ->and($fila->cancel_reason)->toBeNull();
});

it('la auditoría del borrado no guarda el nombre del cliente', function () {
    ($this->borrarDatos)();

    $evento = DB::table('audit_events')->where('action', 'customer.anonymized')->where('entity_id', $this->clienta->id)->first();

    expect($evento)->not->toBeNull()
        ->and((string) $evento->before_data)->not->toContain('Rosa')
        ->and((string) $evento->entity_label)->not->toContain('Rosa');
});
