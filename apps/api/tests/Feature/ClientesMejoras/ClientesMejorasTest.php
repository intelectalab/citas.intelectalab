<?php

use App\Support\Phones;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->co = $this->company();
});

function csvFile(string $content, string $name = 'clientes.csv'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'imp');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, 'text/csv', null, true);
}

describe('celular en un formato único', function () {
    it('normaliza números peruanos con o sin prefijo', function () {
        expect(Phones::normalize('987 654 321'))->toBe('+51987654321')
            ->and(Phones::normalize('+51 987-654-321'))->toBe('+51987654321')
            ->and(Phones::normalize('51987654321'))->toBe('+51987654321')
            ->and(Phones::normalize('0051987654321'))->toBe('+51987654321')
            ->and(Phones::normalize('12'))->toBeNull()
            ->and(Phones::display('+51987654321'))->toBe('+51 987 654 321');
    });

    it('guarda el celular con +51 al registrar y lo encuentra con o sin prefijo', function () {
        $id = $this->as($this->co->owner)->postJson('/api/customers', ['first_name' => 'Rosa', 'last_name' => 'Quispe', 'phone' => '987 654 321'])
            ->assertCreated()->json('customer.id');

        expect(DB::table('customers')->where('id', $id)->value('phone'))->toBe('+51987654321');

        foreach (['987654321', '+51 987 654 321', '51987654321'] as $term) {
            $found = collect($this->as($this->co->owner)->getJson('/api/customers?search=' . urlencode($term))->assertOk()->json('data'))->pluck('id');
            expect($found)->toContain($id);
        }
    });

    it('encuentra por celular sin prefijo un número antiguo guardado sin él', function () {
        $clienta = $this->customer($this->co->tenant, 'Marta', 'Vieja');
        DB::table('customers')->where('id', $clienta->id)->update(['phone' => '987 111 222']);

        $found = collect($this->as($this->co->owner)->getJson('/api/customers?search=' . urlencode('+51987111222'))->json('data'))->pluck('id');
        expect($found)->toContain($clienta->id);
    });

    it('rechaza un celular que no parece número', function () {
        $this->as($this->co->owner)->postJson('/api/customers', ['first_name' => 'Rosa', 'last_name' => 'Q', 'phone' => 'abc'])
            ->assertStatus(422)->assertJsonValidationErrors('phone');
    });
});

describe('sede habitual, profesional preferido y alerta', function () {
    it('guarda los tres datos y la alerta queda en customers.alert', function () {
        $proId = $this->professional($this->co, 'Ana Torres')->id;

        $id = $this->as($this->co->owner)->postJson('/api/customers', [
            'first_name' => 'Lucía', 'last_name' => 'Rojas',
            'home_branch_id' => $this->co->branch->id, 'preferred_professional_id' => $proId, 'alert' => 'Alérgica al látex',
        ])->assertCreated()->json('customer.id');

        $row = DB::table('customers')->where('id', $id)->first();
        expect($row->alert)->toBe('Alérgica al látex')->and($row->home_branch_id)->toBe($this->co->branch->id)->and($row->preferred_professional_id)->toBe($proId);

        $show = $this->as($this->co->owner)->getJson("/api/customers/{$id}")->assertOk()->json('customer');
        expect($show['alert'])->toBe('Alérgica al látex')->and($show['home_branch']['name'])->not->toBeNull();
    });

    it('no acepta una sede de otra empresa', function () {
        $otra = $this->company();

        $this->as($this->co->owner)->postJson('/api/customers', ['first_name' => 'Lucía', 'last_name' => 'Rojas', 'home_branch_id' => $otra->branch->id])
            ->assertStatus(422)->assertJsonValidationErrors('home_branch_id');
    });
});

describe('ficha 360', function () {
    it('muestra gasto, saldo, compras y segmentos', function () {
        $servicio = $this->servicePriced($this->co->tenant, 120, 'Corte');
        $clienta = $this->customer($this->co->tenant, 'Carla', 'Paz');
        $this->openCash($this->co);
        $venta = $this->sell($this->co, [['type' => 'service', 'id' => $servicio->id, 'quantity' => 1]], ['customer_id' => $clienta->id]);
        DB::table('sales')->where('id', $venta['id'])->update(['status' => 'paid', 'paid' => 120, 'balance' => 0]);

        $perfil = $this->as($this->co->owner)->getJson("/api/customers/{$clienta->id}/profile")->assertOk()->json();

        expect($perfil['money']['spent'])->toEqual(120)
            ->and($perfil['purchases'])->toHaveCount(1)
            ->and($perfil['purchases'][0]['id'])->toBe($venta['id'])
            ->and(collect($perfil['segments'])->pluck('key'))->toContain('no_visits');
    });

    it('quien no puede ver ventas no ve montos', function () {
        $clienta = $this->customer($this->co->tenant, 'Carla', 'Paz');
        $pro = $this->professional($this->co, 'Pro Sin Ventas');
        $this->book($this->co, $clienta, $this->servicePriced($this->co->tenant, 40), $pro, $this->at($this->co->tenant, '10:00'))->assertCreated();
        $profesional = $pro->user;

        $perfil = $this->as($profesional)->getJson("/api/customers/{$clienta->id}/profile")->assertOk()->json();
        expect($perfil['money'])->toBeNull()->and($perfil['purchases'])->toBeNull();
    });
});

describe('etiquetas', function () {
    it('crea, asigna y filtra por etiqueta en la lista y en campañas', function () {
        $una = $this->customer($this->co->tenant, 'Ana', 'Uno');
        $otra = $this->customer($this->co->tenant, 'Bea', 'Dos');

        $tag = $this->as($this->co->owner)->postJson('/api/customer-tags', ['name' => 'VIP de verano', 'color' => '#3F768C'])->assertCreated()->json('tag.id');
        $this->as($this->co->owner)->postJson('/api/customer-tags', ['name' => 'VIP de verano'])->assertStatus(422);
        $this->as($this->co->owner)->putJson("/api/customers/{$una->id}/tags", ['tag_ids' => [$tag]])->assertOk()->assertJsonPath('tags.0.name', 'VIP de verano');

        $this->as($this->co->owner)->getJson('/api/customer-tags')->assertOk()->assertJsonPath('tags.0.customers', 1);

        $lista = collect($this->as($this->co->owner)->getJson("/api/customers?tag_id={$tag}")->json('data'))->pluck('id');
        expect($lista->all())->toBe([$una->id]);

        $segmento = \App\Support\Campaigns::customers($this->co->tenant, ['group' => 'tag', 'tag_id' => $tag])->pluck('c.id')->all();
        expect($segmento)->toBe([$una->id])
            ->and(\App\Support\Campaigns::label($this->co->tenant, ['group' => 'tag', 'tag_id' => $tag]))->toContain('VIP de verano');

        expect(DB::table('audit_events')->where('action', 'customer.tags_changed')->count())->toBe(1);
    });

    it('no asigna etiquetas de otra empresa', function () {
        $otra = $this->company();
        $ajena = $this->as($otra->owner)->postJson('/api/customer-tags', ['name' => 'Ajena'])->json('tag.id');
        $clienta = $this->customer($this->co->tenant, 'Ana', 'Uno');

        $this->as($this->co->owner)->putJson("/api/customers/{$clienta->id}/tags", ['tag_ids' => [$ajena]])->assertStatus(422);
    });
});

describe('notas privadas', function () {
    it('solo quien tiene el permiso ve y escribe notas privadas', function () {
        $clienta = $this->customer($this->co->tenant, 'Ana', 'Uno');
        $recepcion = $this->staff($this->co, 'reception', $this->co->branch);

        $this->as($this->co->owner)->postJson("/api/customers/{$clienta->id}/notes", ['body' => 'Prefiere la mañana'])->assertCreated();
        $this->as($this->co->owner)->postJson("/api/customers/{$clienta->id}/notes", ['body' => 'Deuda conversada', 'is_private' => true])->assertCreated();

        $deDuena = $this->as($this->co->owner)->getJson("/api/customers/{$clienta->id}/notes")->json('notes');
        $deRecepcion = $this->as($recepcion)->getJson("/api/customers/{$clienta->id}/notes")->json('notes');

        expect($deDuena)->toHaveCount(2)->and($deRecepcion)->toHaveCount(1)->and($deRecepcion[0]['body'])->toBe('Prefiere la mañana');

        $this->as($recepcion)->postJson("/api/customers/{$clienta->id}/notes", ['body' => 'Intento', 'is_private' => true])->assertForbidden();
    });

    it('el gerente de sede tiene el permiso de notas privadas e importar', function () {
        $gerente = $this->staff($this->co, 'manager', $this->co->branch);
        $this->as($gerente);

        expect($gerente->fresh()->allows('customers.notes'))->toBeTrue()
            ->and($gerente->fresh()->allows('customers.import'))->toBeTrue();
    });
});

describe('consentimientos con historial', function () {
    it('guarda quién, cuándo y desde qué IP cambió el consentimiento', function () {
        $clienta = $this->customer($this->co->tenant, 'Ana', 'Uno');

        $res = $this->as($this->co->owner)->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])
            ->putJson("/api/customers/{$clienta->id}/consents", ['email' => true])->assertOk();

        $evento = DB::table('customer_consent_events')->where('customer_id', $clienta->id)->first();
        expect($evento->granted)->toBeTrue()->and($evento->ip_address)->toBe('10.1.2.3')->and($evento->user_id)->toBe($this->co->owner->id)
            ->and($res->json('history'))->toHaveCount(1);

        $perfil = $this->as($this->co->owner)->getJson("/api/customers/{$clienta->id}/profile")->json('consent_history');
        expect($perfil[0]['by'])->toContain('Dueña');
    });
});

describe('importar clientes', function () {
    it('muestra la vista previa con errores por fila y no repite documento ni celular', function () {
        $existente = $this->customer($this->co->tenant, 'Rosa', 'Antigua');
        DB::table('customers')->where('id', $existente->id)->update(['document_number' => '45678912']);

        $csv = "Nombres *;Apellidos *;Número de documento;Celular;Correo;Fecha de nacimiento;Sexo\n"
            . "Rosa;Nueva;45678912;;;;\n"
            . "Pedro;Soto;11112222;987000111;pedro@correo.com;15/03/1990;Masculino\n"
            . ";SinNombre;;;;;\n"
            . "Juan;Repetido;;987000111;correo-malo;31/02/1990;X\n";

        $vista = $this->as($this->co->owner)->post('/api/customers/import/preview', ['file' => csvFile($csv), 'mode' => 'skip'], ['Accept' => 'application/json'])
            ->assertOk()->json();

        expect($vista['counts'])->toBe(['create' => 1, 'update' => 0, 'skip' => 1, 'error' => 2])
            ->and($vista['rows'][0]['match']['by'])->toBe('documento')
            ->and($vista['rows'][2]['errors'])->toContain('Falta el nombre.')
            ->and(implode(' ', $vista['rows'][3]['errors']))->toContain('se repite en la fila 3')->toContain('correo')->toContain('Sexo');
    });

    it('importa, actualiza los que ya existen si se elige y lo deja en auditoría', function () {
        $existente = $this->customer($this->co->tenant, 'Rosa', 'Antigua');
        DB::table('customers')->where('id', $existente->id)->update(['document_number' => '45678912', 'district' => null]);

        $csv = "Nombres,Apellidos,Número de documento,Celular,Distrito\n"
            . "Rosa,Antigua,45678912,,Surco\n"
            . "Pedro,Soto,11112222,987000111,Miraflores\n";

        $this->as($this->co->owner)->post('/api/customers/import', ['file' => csvFile($csv), 'mode' => 'update'], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('result.created', 1)->assertJsonPath('result.updated', 1);

        expect(DB::table('customers')->where('id', $existente->id)->value('district'))->toBe('Surco')
            ->and(DB::table('customers')->where('tenant_id', $this->co->tenant->id)->where('document_number', '11112222')->value('phone'))->toBe('+51987000111')
            ->and(DB::table('audit_events')->where('action', 'customer.imported')->count())->toBe(1);
    });

    it('lee un Excel .xlsx', function () {
        $dir = sys_get_temp_dir() . '/xlsx' . Str::random(6);
        $path = $dir . '.xlsx';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>Nombres</t></si><si><t>Apellidos</t></si><si><t>Lía</t></si><si><t>Mora</t></si></sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="inlineStr"><is><t>Fecha de nacimiento</t></is></c></row><row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2" t="s"><v>3</v></c><c r="C2"><v>32947</v></c></row></sheetData></worksheet>');
        $zip->close();

        $file = new UploadedFile($path, 'clientes.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
        $vista = $this->as($this->co->owner)->post('/api/customers/import/preview', ['file' => $file], ['Accept' => 'application/json'])->assertOk()->json();

        expect($vista['counts']['create'])->toBe(1)->and($vista['rows'][0]['name'])->toBe('Lía Mora');
    });

    it('recepción no puede importar', function () {
        $recepcion = $this->staff($this->co, 'reception', $this->co->branch);

        $this->as($recepcion)->post('/api/customers/import/preview', ['file' => csvFile("Nombres,Apellidos\nA,B\n")], ['Accept' => 'application/json'])->assertForbidden();
    });
});
