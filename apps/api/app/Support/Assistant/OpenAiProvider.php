<?php

namespace App\Support\Assistant;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class OpenAiProvider extends LlmProvider
{
    public function key(): string
    {
        return 'openai';
    }

    public function label(): string
    {
        return 'OpenAI';
    }

    private function request(array $body): array
    {
        try {
            $response = Http::withToken($this->apiKey)->acceptJson()->asJson()->timeout(60)->connectTimeout(8)
                ->post(rtrim((string) config('services.assistant.openai_url'), '/') . '/v1/chat/completions', $body + ['model' => $this->model]);
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
        $messages = array_merge([['role' => 'system', 'content' => $system]], array_map(fn ($turn) => ['role' => $turn['role'], 'content' => $turn['text']], $history));
        $definitions = array_map(fn ($tool) => ['type' => 'function', 'function' => ['name' => $tool['name'], 'description' => $tool['description'], 'parameters' => $tool['schema']]], $tools->definitions());

        for ($turn = 0; $turn < self::maxTurns(); $turn++) {
            $data = $this->request(['messages' => $messages, 'tools' => $definitions, 'tool_choice' => 'auto']);
            $choice = (array) ($data['choices'][0] ?? []);
            $message = (array) ($choice['message'] ?? []);
            $calls = (array) ($message['tool_calls'] ?? []);

            if (!empty($message['refusal'])) {
                return ['refused' => true];
            }

            if (!$calls) {
                return ['text' => (string) ($message['content'] ?? '')];
            }

            $messages[] = array_filter(['role' => 'assistant', 'content' => $message['content'] ?? null, 'tool_calls' => $calls], fn ($v) => $v !== null);

            foreach ($calls as $call) {
                $input = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);
                $output = is_array($input) ? $tools->run((string) ($call['function']['name'] ?? ''), $input) : ['ok' => false, 'error' => 'Datos no válidos.'];
                $messages[] = ['role' => 'tool', 'tool_call_id' => (string) $call['id'], 'content' => self::encode($output)];
            }
        }

        return ['text' => '', 'reason' => 'El asistente dio demasiadas vueltas sin responder.'];
    }

    public function ping(): array
    {
        $data = $this->request(['messages' => [['role' => 'user', 'content' => 'Responde solo: OK']], 'max_completion_tokens' => 16]);

        return ['ok' => true, 'message' => "{$this->label()} respondió con el modelo " . ($data['model'] ?? $this->model) . '.'];
    }
}
