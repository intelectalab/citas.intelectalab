<?php

namespace App\Support\Assistant;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class AnthropicProvider extends LlmProvider
{
    public const VERSION = '2023-06-01';

    public const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    public function key(): string
    {
        return 'anthropic';
    }

    public function label(): string
    {
        return 'Claude (Anthropic)';
    }

    private function request(array $body): array
    {
        try {
            $response = Http::withHeaders([
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => self::VERSION,
                'anthropic-beta'    => self::FALLBACK_BETA,
            ])->acceptJson()->asJson()->timeout(60)->connectTimeout(8)
                ->post(rtrim((string) config('services.assistant.anthropic_url'), '/') . '/v1/messages', $body + ['model' => $this->model, 'fallbacks' => 'default']);
        } catch (ConnectionException $e) {
            throw $this->unreachable($e);
        }

        if ($response->failed()) {
            throw $this->fail($response);
        }

        return (array) $response->json();
    }

    protected function send(string $system, array $history, Tools $tools): array
    {
        $messages = array_map(fn ($turn) => ['role' => $turn['role'], 'content' => $turn['text']], $history);
        $definitions = array_map(fn ($tool) => ['name' => $tool['name'], 'description' => $tool['description'], 'input_schema' => $tool['schema']], $tools->definitions());

        for ($turn = 0; $turn < self::maxTurns(); $turn++) {
            $data = $this->request([
                'max_tokens'    => 1024,
                'system'        => $system,
                'messages'      => $messages,
                'tools'         => $definitions,
                'output_config' => ['effort' => 'low'],
            ]);

            $stop = (string) ($data['stop_reason'] ?? '');
            $content = (array) ($data['content'] ?? []);

            if ($stop === 'refusal') {
                return ['refused' => true];
            }

            $text = collect($content)->where('type', 'text')->pluck('text')->implode("\n");
            $calls = collect($content)->where('type', 'tool_use')->values();

            if ($stop !== 'tool_use' || $calls->isEmpty()) {
                return ['text' => $text];
            }

            $messages[] = ['role' => 'assistant', 'content' => $content];
            $results = [];

            foreach ($calls as $call) {
                $output = $tools->run((string) ($call['name'] ?? ''), (array) ($call['input'] ?? []));
                $results[] = ['type' => 'tool_result', 'tool_use_id' => (string) $call['id'], 'content' => self::encode($output), 'is_error' => !($output['ok'] ?? false)];
            }

            $messages[] = ['role' => 'user', 'content' => $results];
        }

        return ['text' => '', 'reason' => 'El asistente dio demasiadas vueltas sin responder.'];
    }

    public function ping(): array
    {
        $data = $this->request(['max_tokens' => 16, 'messages' => [['role' => 'user', 'content' => 'Responde solo: OK']]]);

        return ['ok' => true, 'message' => "{$this->label()} respondió con el modelo " . ($data['model'] ?? $this->model) . '.'];
    }
}
