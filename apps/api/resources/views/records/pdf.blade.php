@php
    $toothStates = ['caries' => 'Caries', 'restored' => 'Restaurada', 'crown' => 'Corona', 'treatment' => 'En tratamiento', 'missing' => 'Ausente'];
    $statusLabels = ['draft' => 'Borrador', 'signed' => 'Firmada', 'locked' => 'Firmada'];
    $date = fn (?string $local) => $local ? \Illuminate\Support\Carbon::parse($local)->format('d/m/Y H:i') : '—';
    $day = fn (?string $value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('d/m/Y') : '—';
    $isDraft = $record['status'] === 'draft';
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>{{ $record['template_name'] }}</title>
    <style>
        @page { margin: 28px 34px 46px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #1f2937; }
        .header { background: {{ $color }}; color: #ffffff; padding: 14px 18px; }
        .header .kicker { font-size: 8px; letter-spacing: 1.5px; text-transform: uppercase; opacity: .85; }
        .header h1 { font-size: 17px; margin: 3px 0 2px; }
        .header .company { font-size: 10px; opacity: .9; }
        .badge { float: right; border: 1px solid #ffffff; padding: 3px 8px; font-size: 9px; text-transform: uppercase; letter-spacing: 1px; }
        table.meta { width: 100%; border-collapse: collapse; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
        table.meta td { width: 33%; padding: 8px 18px; vertical-align: top; }
        .label { font-size: 7.5px; font-weight: bold; text-transform: uppercase; letter-spacing: .8px; color: #94a3b8; }
        .value { font-size: 10.5px; font-weight: bold; color: #1e293b; }
        .fields { padding: 14px 18px 0; }
        .field { margin-bottom: 11px; page-break-inside: avoid; }
        .field .name { font-size: 9px; font-weight: bold; color: #475569; margin-bottom: 3px; }
        .field .answer { border: 1px solid #e2e8f0; background: #ffffff; padding: 6px 9px; min-height: 12px; white-space: pre-line; }
        .empty { color: #94a3b8; font-style: italic; }
        .chip { display: inline-block; border: 1px solid #cbd5e1; padding: 2px 7px; margin: 0 4px 3px 0; font-size: 9.5px; }
        .box { margin: 6px 18px 0; border: 1px solid #e2e8f0; background: #f8fafc; padding: 10px 12px; page-break-inside: avoid; }
        .box h2 { font-size: 8px; text-transform: uppercase; letter-spacing: .8px; color: #64748b; margin: 0 0 6px; }
        .note { border-top: 1px solid #e2e8f0; padding: 6px 0; }
        .note:first-of-type { border-top: 0; }
        .small { font-size: 8.5px; color: #64748b; }
        table.sign { width: 100%; margin-top: 44px; border-collapse: collapse; page-break-inside: avoid; }
        table.sign td { width: 50%; padding: 0 28px; text-align: center; }
        .line { border-top: 1px solid #475569; padding-top: 4px; font-size: 9px; color: #475569; }
        .footer { position: fixed; bottom: -30px; left: 0; right: 0; font-size: 8px; color: #94a3b8; text-align: center; }
        .draft { color: #b45309; border: 1px solid #f59e0b; background: #fffbeb; padding: 6px 10px; margin: 10px 18px 0; font-size: 9.5px; }
    </style>
</head>
<body>
    <div class="footer">
        {{ $company }} · Documento generado el {{ $printedAt }} por {{ $printedBy }} · Información confidencial
    </div>

    <div class="header">
        <span class="badge">{{ $statusLabels[$record['status']] ?? $record['status'] }}</span>
        <div class="kicker">Ficha de atención</div>
        <h1>{{ $record['template_name'] ?? 'Ficha de atención' }}</h1>
        <div class="company">{{ $company }}</div>
    </div>

    <table class="meta">
        <tr>
            <td>
                <div class="label">{{ $client }}</div>
                <div class="value">{{ $record['customer']['name'] ?? '—' }}</div>
                <div class="small">{{ $record['customer']['code'] ?? '' }}</div>
            </td>
            <td>
                <div class="label">Servicio</div>
                <div class="value">{{ $record['appointment']['service'] ?? '—' }}</div>
                <div class="small">Cita {{ $record['appointment']['code'] ?? '—' }}</div>
            </td>
            <td>
                <div class="label">Fecha de la atención</div>
                <div class="value">{{ $date($record['appointment']['starts_at'] ?? $record['created_at']) }}</div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="label">{{ $pro }}</div>
                <div class="value">{{ $record['professional'] ?? '—' }}</div>
            </td>
            <td>
                @if ($record['dependent'])
                    <div class="label">{{ $dependentLabel }}</div>
                    <div class="value">{{ $record['dependent']['name'] }}</div>
                @endif
            </td>
            <td>
                <div class="label">Firmada</div>
                <div class="value">{{ $record['signed_at'] ? $date($record['signed_at']) : 'Aún no' }}</div>
                <div class="small">{{ $record['author'] }}</div>
            </td>
        </tr>
    </table>

    @if ($isDraft)
        <div class="draft">Esta ficha es un borrador: aún no está firmada y puede cambiar.</div>
    @endif

    <div class="fields">
        @foreach ($record['fields'] as $index => $field)
            @php $value = $values[$field['key']] ?? null; @endphp
            <div class="field">
                <div class="name">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }} · {{ $field['label'] }}</div>
                <div class="answer">
                    @if ($value === null || $value === '' || $value === [])
                        <span class="empty">Sin registrar</span>
                    @elseif ($field['type'] === 'boolean')
                        {{ $value ? 'Sí' : 'No' }}
                    @elseif ($field['type'] === 'multiselect')
                        @foreach ((array) $value as $option)<span class="chip">{{ $option }}</span>@endforeach
                    @elseif ($field['type'] === 'scale')
                        {{ $value }} de 10
                    @elseif ($field['type'] === 'date')
                        {{ $day($value) }}
                    @elseif ($field['type'] === 'next_control')
                        {{ $day(((array) $value)['date'] ?? null) }}
                    @elseif ($field['type'] === 'odontogram')
                        @foreach ((array) $value as $tooth => $state)<span class="chip">Pieza {{ $tooth }}: {{ $toothStates[$state] ?? $state }}</span>@endforeach
                    @else
                        {{ is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE) }}
                    @endif
                </div>
            </div>
        @endforeach
    </div>

    @if ($record['consent_text'])
        <div class="box">
            <h2>Consentimiento informado</h2>
            <div>{{ $record['consent_text'] }}</div>
            <div class="small" style="margin-top: 6px;">
                {{ $record['consent_accepted'] ? '☑ Aceptado por ' . ($record['customer']['name'] ?? 'el cliente') : '☐ Sin aceptar' }}
            </div>
        </div>
    @endif

    @if (count($record['addenda']) > 0)
        <div class="box">
            <h2>Notas agregadas</h2>
            @foreach ($record['addenda'] as $note)
                <div class="note">
                    <div style="white-space: pre-line;">{{ $note['note'] }}</div>
                    <div class="small">{{ $date($note['at']) }}{{ $note['by'] ? ' · ' . $note['by'] : '' }}</div>
                </div>
            @endforeach
        </div>
    @endif

    <table class="sign">
        <tr>
            <td><div class="line">{{ $record['professional'] ?? $pro }}</div></td>
            <td><div class="line">{{ $record['customer']['name'] ?? $client }}</div></td>
        </tr>
    </table>
</body>
</html>
