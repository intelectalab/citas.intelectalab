<?php

use App\Support\Webhooks;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

function contractSpec(): array
{
    static $spec = null;

    return $spec ??= Yaml::parseFile(base_path('openapi/openapi.yaml'));
}

function contractResolve(string $ref): array
{
    $node = contractSpec();

    foreach (explode('/', substr($ref, 2)) as $part) {
        if (!is_array($node) || !array_key_exists($part, $node)) {
            throw new RuntimeException("No existe {$ref} en el contrato.");
        }
        $node = $node[$part];
    }

    return $node;
}

function contractRefs(mixed $node, array &$found = []): array
{
    if (is_array($node)) {
        foreach ($node as $key => $value) {
            if ($key === '$ref' && is_string($value)) {
                $found[] = $value;
            } else {
                contractRefs($value, $found);
            }
        }
    }

    return $found;
}

function contractMatches(mixed $value, array $schema, string $path, array &$errors): void
{
    if (isset($schema['$ref'])) {
        contractMatches($value, contractResolve($schema['$ref']), $path, $errors);

        return;
    }

    if (isset($schema['allOf'])) {
        foreach ($schema['allOf'] as $part) {
            contractMatches($value, $part, $path, $errors);
        }

        return;
    }

    if (isset($schema['oneOf'])) {
        $ok = collect($schema['oneOf'])->contains(function (array $option) use ($value, $path) {
            $inner = [];
            contractMatches($value, $option, $path, $inner);

            return $inner === [];
        });

        if (!$ok) {
            $errors[] = "{$path}: no coincide con ninguna opción";
        }

        return;
    }

    if (array_key_exists('const', $schema) && $value !== $schema['const']) {
        $errors[] = "{$path}: se esperaba {$schema['const']}";
    }

    $types = (array) ($schema['type'] ?? []);

    if ($types) {
        $actual = match (true) {
            $value === null                                  => 'null',
            is_bool($value)                                  => 'boolean',
            is_int($value)                                   => 'integer',
            is_float($value)                                 => 'number',
            is_string($value)                                => 'string',
            is_array($value) && array_is_list($value)        => 'array',
            default                                          => 'object',
        };
        $accepted = in_array($actual, $types, true)
            || ($actual === 'integer' && in_array('number', $types, true))
            || ($actual === 'array' && $value === [] && in_array('object', $types, true));

        if (!$accepted) {
            $errors[] = "{$path}: es {$actual} y el contrato dice " . implode('|', $types);

            return;
        }
    }

    if (isset($schema['enum']) && $value !== null && !in_array($value, $schema['enum'], true)) {
        $errors[] = "{$path}: «{$value}» no está en la lista del contrato";
    }

    if (is_array($value) && !array_is_list($value)) {
        foreach ($schema['required'] ?? [] as $field) {
            if (!array_key_exists($field, $value)) {
                $errors[] = "{$path}.{$field}: falta en la respuesta";
            }
        }

        foreach ($value as $field => $inner) {
            if (isset($schema['properties'][$field])) {
                contractMatches($inner, $schema['properties'][$field], "{$path}.{$field}", $errors);
            } elseif (isset($schema['properties']) && !isset($schema['additionalProperties']) && !in_array($field, ['debug', 'exception', 'file', 'line', 'trace'], true)) {
                $errors[] = "{$path}.{$field}: la API lo devuelve pero no está en el contrato";
            }
        }
    }

    if (is_array($value) && array_is_list($value) && isset($schema['items'])) {
        foreach ($value as $i => $inner) {
            contractMatches($inner, $schema['items'], "{$path}[{$i}]", $errors);
        }
    }
}

function contractCheck(\Illuminate\Testing\TestResponse $response, string $method, string $path): void
{
    $status = (string) $response->getStatusCode();
    $operation = contractSpec()['paths'][$path][strtolower($method)] ?? null;
    expect($operation)->not->toBeNull("El contrato no tiene {$method} {$path}");

    $declared = $operation['responses'][$status] ?? null;
    expect($declared)->not->toBeNull("El contrato no declara la respuesta {$status} de {$method} {$path}");

    if (isset($declared['$ref'])) {
        $declared = contractResolve($declared['$ref']);
    }

    $schema = $declared['content']['application/json']['schema'] ?? null;
    expect($schema)->not->toBeNull();

    $errors = [];
    contractMatches($response->json(), $schema, "{$method} {$path} {$status}", $errors);
    expect($errors)->toBe([]);
}

function contractCompany(object $test): object
{
    return (function () {
        $co = $this->company(['modules_off' => []]);
        $co->service = $this->servicePriced($co->tenant, 80, 'Corte del contrato');
        DB::table('services')->where('id', $co->service->id)->update(['duration_minutes' => 30]);
        $co->pro = $this->professional($co, 'Ana Contrato');
        $co->client = $this->customer($co->tenant, 'Lucía', 'Contrato');
        $co->key = $this->as($co->owner)->postJson('/api/integrations/api/keys', [
            'name'   => 'Contrato',
            'scopes' => ['appointments' => 'write', 'availability' => 'read', 'customers' => 'write', 'services' => 'read'],
        ])->assertCreated()->json('plain');

        return $co;
    })->call($test);
}

it('cada ruta de /api/v1 está en el contrato y cada ruta del contrato existe', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => str_starts_with($route->uri(), 'api/v1/'))
        ->flatMap(fn ($route) => collect($route->methods())->reject(fn ($m) => $m === 'HEAD')->map(fn ($m) => $m . ' ' . substr($route->uri(), 6)))
        ->sort()->values()->all();

    $contract = collect(contractSpec()['paths'])
        ->flatMap(fn (array $operations, string $path) => collect($operations)->keys()
            ->filter(fn ($m) => in_array($m, ['get', 'post', 'put', 'patch', 'delete'], true))
            ->map(fn ($m) => strtoupper($m) . ' ' . $path))
        ->sort()->values()->all();

    expect($routes)->not->toBeEmpty()
        ->and(array_values(array_diff($routes, $contract)))->toBe([], 'Rutas sin documentar en openapi/openapi.yaml')
        ->and(array_values(array_diff($contract, $routes)))->toBe([], 'Rutas del contrato que ya no existen');
});

it('el contrato es válido: referencias, ids de operación, errores y autenticación', function () {
    $spec = contractSpec();

    expect($spec['openapi'])->toStartWith('3.1')
        ->and($spec['components']['securitySchemes'])->toHaveKeys(['bearerAuth', 'apiKeyHeader']);

    foreach (array_unique(contractRefs($spec)) as $ref) {
        contractResolve($ref);
    }

    $ids = [];

    foreach (['paths', 'webhooks'] as $section) {
        foreach ($spec[$section] as $name => $operations) {
            foreach ($operations as $method => $operation) {
                expect($operation['operationId'] ?? null)->not->toBeNull("{$method} {$name} sin operationId");
                $ids[] = $operation['operationId'];

                if ($section === 'paths') {
                    expect($operation['responses'])->toHaveKeys(['401', '403', '429']);
                }
            }
        }
    }

    expect(count($ids))->toBe(count(array_unique($ids)));
});

it('el contrato trae todos los avisos de webhooks y nada más', function () {
    $documented = array_keys(contractSpec()['webhooks']);
    sort($documented);
    $real = array_keys(Webhooks::EVENTS);
    sort($real);

    expect($documented)->toBe($real)
        ->and(contractResolve('#/components/schemas/WebhookEnvelope')['properties']['event']['enum'])
        ->toEqualCanonicalizing([...$real, Webhooks::TEST_EVENT]);
});

it('las respuestas reales de la API coinciden con el contrato', function () {
    $co = contractCompany($this);
    $call = fn (string $method, string $url, array $data = []) => $this->withHeader('Authorization', 'Bearer ' . $co->key)->json($method, $url, $data);

    contractCheck($call('GET', '/api/v1/services')->assertOk(), 'GET', '/services');
    contractCheck($call('GET', '/api/v1/branches')->assertOk(), 'GET', '/branches');
    contractCheck($call('GET', '/api/v1/professionals')->assertOk(), 'GET', '/professionals');

    $day = $this->workday($co->tenant);
    contractCheck($call('GET', '/api/v1/availability?' . http_build_query(['service_id' => $co->service->id, 'branch_id' => $co->branch->id, 'date' => $day->toDateString()]))->assertOk(), 'GET', '/availability');

    $created = $call('POST', '/api/v1/appointments', [
        'customer_id'     => $co->client->id,
        'service_id'      => $co->service->id,
        'professional_id' => $co->pro->id,
        'branch_id'       => $co->branch->id,
        'starts_at'       => $day->copy()->setTime(10, 0)->format('Y-m-d\TH:i'),
    ])->assertCreated();
    contractCheck($created, 'POST', '/appointments');
    $id = $created->json('data.id');

    contractCheck($call('GET', '/api/v1/appointments?' . http_build_query(['from' => $day->toDateString(), 'to' => $day->toDateString()]))->assertOk(), 'GET', '/appointments');
    contractCheck($call('GET', "/api/v1/appointments/{$id}")->assertOk(), 'GET', '/appointments/{appointment}');
    contractCheck($call('POST', "/api/v1/appointments/{$id}/cancel", ['reason' => 'Prueba del contrato'])->assertOk(), 'POST', '/appointments/{appointment}/cancel');

    $customer = $call('POST', '/api/v1/customers', ['first_name' => 'Rosa', 'last_name' => 'Contrato', 'email' => 'rosa.contrato@example.com'])->assertCreated();
    contractCheck($customer, 'POST', '/customers');
    contractCheck($call('GET', '/api/v1/customers?search=Rosa')->assertOk(), 'GET', '/customers');
    contractCheck($call('GET', '/api/v1/customers/' . $customer->json('data.id'))->assertOk(), 'GET', '/customers/{customer}');

    contractCheck($call('POST', '/api/v1/appointments', ['customer_id' => $co->client->id])->assertStatus(422), 'POST', '/appointments');
    contractCheck($call('GET', '/api/v1/customers/' . (string) \Illuminate\Support\Str::uuid())->assertNotFound(), 'GET', '/customers/{customer}');
    contractCheck($this->withHeader('Authorization', 'Bearer apk_no_existe')->getJson('/api/v1/services')->assertUnauthorized(), 'GET', '/services');
});

it('la documentación pública se arma desde el contrato y lo ofrece para descargar', function () {
    $page = $this->get('/api/docs')->assertOk()->assertHeader('Content-Type', 'text/html; charset=utf-8');
    $html = $page->getContent();

    foreach (contractSpec()['paths'] as $path => $operations) {
        foreach (array_keys($operations) as $method) {
            expect($html)->toContain('data-operation="' . strtoupper($method) . ' ' . $path . '"');
        }
    }

    foreach (array_keys(contractSpec()['webhooks']) as $event) {
        expect($html)->toContain('data-webhook="' . $event . '"');
    }

    expect($this->get('/api/docs/openapi.yaml')->assertOk()->streamedContent())->toBe(file_get_contents(base_path('openapi/openapi.yaml')));
    $this->getJson('/api/docs/openapi.json')->assertOk()->assertJsonPath('info.title', contractSpec()['info']['title']);
});
