<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Campos de una ficha de atención: qué tipos existen, cómo se guarda la
 * definición de una plantilla y cómo se validan los valores que se llenan.
 */
class RecordFields
{
    public const TYPES = ['text', 'textarea', 'number', 'select', 'multiselect', 'date', 'boolean', 'scale', 'odontogram', 'next_control'];

    // Estados que se pueden marcar en cada pieza del odontograma.
    public const TOOTH_STATES = ['caries', 'restored', 'missing', 'crown', 'treatment'];

    /**
     * Deja la definición de campos lista para guardar. Un campo nuevo recibe
     * su clave interna a partir de su nombre; la de uno existente no cambia,
     * para que las fichas ya llenadas sigan encontrando su valor.
     */
    public static function clean(array $fields): array
    {
        $taken = collect($fields)->pluck('key')->filter()->all();
        $clean = [];

        foreach ($fields as $field) {
            $label = trim((string) ($field['label'] ?? ''));
            $type = in_array($field['type'] ?? null, self::TYPES, true) ? $field['type'] : 'text';
            $key = (string) ($field['key'] ?? '');

            if ($key === '') {
                $base = Str::slug($label, '_') ?: 'campo';
                $base = substr($base, 0, 50);
                $key = $base;
                for ($n = 2; in_array($key, $taken, true); $n++) {
                    $key = "{$base}_{$n}";
                }
                $taken[] = $key;
            }

            $item = ['key' => $key, 'label' => $label, 'type' => $type, 'required' => !empty($field['required'])];

            if (in_array($type, ['select', 'multiselect'], true)) {
                $item['options'] = collect($field['options'] ?? [])->map(fn ($o) => trim((string) $o))->filter()->unique()->values()->all();
            }
            if (in_array($type, ['text', 'textarea'], true) && trim((string) ($field['placeholder'] ?? '')) !== '') {
                $item['placeholder'] = trim((string) $field['placeholder']);
            }
            if ($type === 'number' && is_numeric($field['step'] ?? null)) {
                $item['step'] = (float) $field['step'];
            }
            if ($type === 'next_control' && (int) ($field['days'] ?? 0) >= 1) {
                $item['days'] = (int) $field['days'];
            }

            $clean[] = $item;
        }

        return $clean;
    }

    /**
     * Problemas de una definición de campos, en palabras para quien la edita.
     */
    public static function problems(array $fields): array
    {
        $problems = [];

        foreach ($fields as $index => $field) {
            $name = $field['label'] !== '' ? "\"{$field['label']}\"" : 'número ' . ($index + 1);

            if ($field['label'] === '') {
                $problems[] = 'Ponle nombre al campo ' . ($index + 1) . '.';
            }
            if (in_array($field['type'], ['select', 'multiselect'], true) && empty($field['options'])) {
                $problems[] = "El campo {$name} es una lista y no tiene opciones.";
            }
        }

        if (collect($fields)->pluck('key')->duplicates()->isNotEmpty()) {
            $problems[] = 'Hay dos campos con la misma clave interna.';
        }

        return $problems;
    }

    /**
     * Valores de una ficha, ya limpios según sus campos. Con $strict (al
     * firmar) también devuelve qué campos obligatorios faltan.
     *
     * @return array{0: array, 1: array<string, string>}
     */
    public static function values(array $fields, array $data, bool $strict = false): array
    {
        $values = [];
        $errors = [];

        foreach ($fields as $field) {
            $key = $field['key'];
            $value = self::value($field, $data[$key] ?? null);

            if ($value !== null) {
                $values[$key] = $value;
            } elseif ($strict && !empty($field['required'])) {
                $errors[$key] = "Falta llenar \"{$field['label']}\".";
            }
        }

        return [$values, $errors];
    }

    /**
     * Fecha del próximo control que indica la ficha, si tiene alguno.
     *
     * @return array{label: string, date: string}|null
     */
    public static function nextControl(array $fields, array $data): ?array
    {
        foreach ($fields as $field) {
            $date = $data[$field['key']]['date'] ?? null;

            if ($field['type'] === 'next_control' && $date) {
                return ['label' => $field['label'], 'date' => $date];
            }
        }

        return null;
    }

    private static function value(array $field, mixed $raw): mixed
    {
        $date = fn ($value) => is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && strtotime($value) ? $value : null;

        switch ($field['type']) {
            case 'number':
                return is_numeric($raw) ? $raw + 0 : null;

            case 'select':
                return is_string($raw) && in_array($raw, $field['options'] ?? [], true) ? $raw : null;

            case 'multiselect':
                $chosen = array_values(array_intersect($field['options'] ?? [], is_array($raw) ? $raw : []));
                return $chosen ?: null;

            case 'date':
                return $date($raw);

            case 'boolean':
                return is_bool($raw) ? $raw : null;

            case 'scale':
                return is_numeric($raw) && $raw >= 0 && $raw <= 10 ? (int) $raw : null;

            case 'odontogram':
                $teeth = collect(is_array($raw) ? $raw : [])
                    ->filter(fn ($state, $tooth) => preg_match('/^[1-8][1-8]$/', (string) $tooth) && in_array($state, self::TOOTH_STATES, true))
                    ->all();
                return $teeth ?: null;

            case 'next_control':
                $value = $date(is_array($raw) ? ($raw['date'] ?? null) : $raw);
                return $value ? ['date' => $value] : null;

            default:
                $text = is_scalar($raw) ? trim((string) $raw) : '';
                return $text === '' ? null : mb_substr($text, 0, 10000);
        }
    }
}
