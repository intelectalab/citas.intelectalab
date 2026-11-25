<?php

namespace App\Support\Assistant;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;

abstract class LlmProvider implements Provider
{
    public function __construct(protected string $apiKey, protected string $model)
    {
    }

    abstract public function label(): string;

    abstract protected function send(string $system, array $history, Tools $tools): array;

    abstract public function ping(): array;

    public static function system(array $context, Tools $tools): string
    {
        $tenant = $tools->tenant;
        $now = Carbon::now($tenant->zone());
        $name = $context['customer']['name'] ?? null;
        $can = $tools->customer && $tools->canBook();

        return implode("\n", array_filter([
            "Eres la recepcionista virtual de {$context['company']} y atiendes por WhatsApp en español, con un trato amable y frases cortas (máximo 3 o 4 líneas por mensaje).",
            'Hoy es ' . Tools::WEEKDAYS[(int) $now->dayOfWeek] . ' ' . $now->format('Y-m-d') . ' y son las ' . $now->format('H:i') . ' (hora local).',
            $name ? "La persona que escribe es cliente registrada y se llama {$name}." : 'El número que escribe no está unido a un cliente registrado.',
            'Reglas:',
            '- Responde solo sobre servicios, precios, sedes, horarios de atención y citas, usando las herramientas. Nunca inventes precios, horarios ni datos.',
            '- Antes de ofrecer una hora, consulta buscar_horarios. Ofrece como máximo 4 horas a la vez. Si el cliente dice "la segunda" o "en la tarde", interprétalo sobre la lista que le diste.',
            $can
                ? '- Para reservar, reprogramar, cancelar o confirmar, primero repite servicio, sede, día y hora y espera que el cliente diga que sí; luego usa la herramienta y da el código de la cita.'
                : ($tools->customer
                    ? '- No puedes reservar ni cambiar citas: solo informa y ofrece pasar con una persona del equipo para hacerlo.'
                    : '- No puedes reservar ni cambiar citas porque el número no está registrado: informa y ofrece pasar con una persona del equipo.'),
            '- No des indicaciones de salud, diagnósticos, dosis ni opiniones médicas: si preguntan algo así, usa pasar_a_una_persona.',
            '- Si el cliente pide hablar con una persona, se queja, o no entiendes lo que necesita después de preguntar una vez, usa pasar_a_una_persona.',
            '- No pidas ni repitas datos personales que no hagan falta (documento, dirección, datos de salud).',
            '- Si una herramienta responde con un error, explícalo con palabras sencillas y ofrece otra opción.',
        ]));
    }

    public static function history(array $context): array
    {
        $turns = [];

        foreach ((array) ($context['history'] ?? []) as $message) {
            $role = ($message['from'] ?? 'customer') === 'customer' ? 'user' : 'assistant';
            $text = trim((string) ($message['text'] ?? ''));

            if ($text === '') {
                continue;
            }

            if ($turns && end($turns)['role'] === $role) {
                $turns[array_key_last($turns)]['text'] .= "\n" . $text;
            } else {
                $turns[] = ['role' => $role, 'text' => $text];
            }
        }

        while ($turns && $turns[0]['role'] !== 'user') {
            array_shift($turns);
        }

        if (!$turns || end($turns)['role'] !== 'user') {
            $turns[] = ['role' => 'user', 'text' => (string) ($context['text'] ?? '')];
        }

        return $turns;
    }

    public function answer(array $context): array
    {
        $tools = $context['tools'] ?? null;

        if (!$tools instanceof Tools) {
            return ['action' => 'none', 'reason' => 'Faltan las herramientas del asistente.'];
        }

        $result = $this->send(self::system($context, $tools), self::history($context), $tools);

        if ($tools->handoff) {
            return ['action' => 'handoff', 'reason' => $tools->handoff, 'text' => $result['text'] ?? null, 'done' => $tools->done];
        }

        if (!empty($result['refused'])) {
            return ['action' => 'handoff', 'reason' => 'El proveedor de inteligencia artificial no quiso responder este mensaje.', 'done' => $tools->done];
        }

        $text = trim((string) ($result['text'] ?? ''));

        if ($text === '') {
            return ['action' => 'handoff', 'reason' => 'El asistente no supo qué responder.', 'done' => $tools->done];
        }

        return ['action' => 'reply', 'text' => $text, 'reason' => $result['reason'] ?? null, 'done' => $tools->done];
    }

    public static function maxTurns(): int
    {
        return max(1, (int) config('services.assistant.max_turns', 6));
    }

    protected static function encode(array $result): string
    {
        return json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{"ok":false}';
    }

    protected function fail(Response $response): \RuntimeException
    {
        $status = $response->status();

        return new \RuntimeException(match (true) {
            $status === 401 || $status === 403 => "{$this->label()} no aceptó la clave (API key): revísala en Integraciones.",
            $status === 404                    => "{$this->label()} no reconoce el modelo «{$this->model}».",
            $status === 429                    => "{$this->label()} pidió esperar: se enviaron muchos mensajes seguidos o se acabó el saldo de la cuenta.",
            $status >= 500                     => "{$this->label()} no está disponible en este momento.",
            default                            => "{$this->label()} respondió con un error ({$status}): " . mb_substr((string) ($response->json('error.message') ?? ''), 0, 200),
        });
    }

    protected function unreachable(ConnectionException $e): \RuntimeException
    {
        return new \RuntimeException("No se pudo conectar con {$this->label()}.");
    }
}
