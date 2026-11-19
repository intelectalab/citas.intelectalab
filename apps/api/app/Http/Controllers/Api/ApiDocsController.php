<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Yaml\Yaml;

class ApiDocsController extends Controller
{
    private const METHODS = ['get', 'post', 'put', 'patch', 'delete'];

    public static function path(): string
    {
        return base_path('openapi/openapi.yaml');
    }

    public static function spec(): array
    {
        $file = self::path();

        return Cache::store('array')->rememberForever('openapi:' . filemtime($file), fn () => Yaml::parseFile($file));
    }

    public function yaml()
    {
        return response()->file(self::path(), ['Content-Type' => 'application/yaml; charset=utf-8']);
    }

    public function json()
    {
        return response()->json(self::spec(), 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function page()
    {
        $spec = self::spec();
        $sections = [];

        foreach ($spec['tags'] ?? [] as $tag) {
            $sections[$tag['name']] = ['name' => $tag['name'], 'description' => $tag['description'] ?? null, 'operations' => [], 'webhooks' => []];
        }

        foreach ($spec['paths'] ?? [] as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (in_array($method, self::METHODS, true)) {
                    $sections[$operation['tags'][0] ?? 'Otros']['operations'][] = $this->operation($spec, strtoupper($method), $path, $operation);
                }
            }
        }

        $webhooks = [];

        foreach ($spec['webhooks'] ?? [] as $event => $operations) {
            $operation = $operations['post'];
            $webhooks[] = [
                'event'       => $event,
                'summary'     => $operation['summary'] ?? $event,
                'description' => $operation['description'] ?? null,
                'example'     => $this->bodyExample($spec, $operation['requestBody'] ?? []),
            ];
        }

        return response()->view('api-docs', [
            'spec'      => $spec,
            'sections'  => array_values(array_filter($sections, fn ($s) => $s['operations'] !== [])),
            'webhooks'  => $webhooks,
            'headers'   => collect(['WebhookSignature', 'WebhookEvent', 'WebhookId', 'WebhookDelivery'])->map(fn ($k) => $spec['components']['parameters'][$k] ?? null)->filter()->values(),
            'received'  => $spec['components']['responses']['WebhookReceived']['description'] ?? null,
            'errors'    => $this->errors($spec),
        ]);
    }

    private function operation(array $spec, string $method, string $path, array $operation): array
    {
        $parameters = collect($operation['parameters'] ?? [])->map(fn ($p) => $this->resolve($spec, $p))->values()->all();
        $body = $this->resolve($spec, $operation['requestBody'] ?? []);
        $bodySchema = $this->resolve($spec, $body['content']['application/json']['schema'] ?? []);
        $responses = [];

        foreach ($operation['responses'] ?? [] as $status => $response) {
            $response = $this->resolve($spec, $response);
            $responses[] = ['status' => (string) $status, 'description' => $response['description'] ?? '', 'example' => $this->bodyExample($spec, $response)];
        }

        return [
            'id'          => $operation['operationId'] ?? md5($method . $path),
            'method'      => $method,
            'path'        => $path,
            'summary'     => $operation['summary'] ?? '',
            'description' => $operation['description'] ?? null,
            'parameters'  => $parameters,
            'fields'      => collect($bodySchema['properties'] ?? [])->map(fn ($field, $name) => [
                'name'        => $name,
                'type'        => $this->typeOf($spec, $field),
                'required'    => in_array($name, $bodySchema['required'] ?? [], true),
                'description' => $field['description'] ?? null,
            ])->values()->all(),
            'request'     => $this->bodyExample($spec, $body),
            'responses'   => $responses,
        ];
    }

    private function errors(array $spec): array
    {
        return collect($spec['components']['responses'] ?? [])->except('WebhookReceived')->map(fn ($r, $name) => [
            'name'        => $name,
            'description' => $r['description'] ?? '',
            'example'     => $this->bodyExample($spec, $r),
        ])->values()->all();
    }

    private function bodyExample(array $spec, array $node): ?string
    {
        $node = $this->resolve($spec, $node);
        $content = $node['content']['application/json'] ?? null;

        if (!$content) {
            return null;
        }

        $example = $content['example'] ?? null;

        if ($example === null && !empty($content['examples'])) {
            $first = $this->resolve($spec, reset($content['examples']));
            $example = $first['value'] ?? null;
        }

        return $example === null ? null : json_encode($example, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function typeOf(array $spec, array $schema): string
    {
        $schema = $this->resolve($spec, $schema);
        $type = implode(' o ', array_map(fn ($t) => $t === 'null' ? 'nulo' : $t, (array) ($schema['type'] ?? 'objeto')));

        return isset($schema['format']) ? "{$type} ({$schema['format']})" : $type;
    }

    private function resolve(array $spec, mixed $node): array
    {
        $guard = 0;

        while (is_array($node) && isset($node['$ref']) && $guard++ < 10) {
            $target = $spec;

            foreach (explode('/', substr($node['$ref'], 2)) as $part) {
                $target = $target[$part] ?? [];
            }

            $node = $target;
        }

        return is_array($node) ? $node : [];
    }
}
