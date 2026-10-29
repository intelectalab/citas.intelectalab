@php
    $isDraft = in_array($quote['status'], ['draft', 'cancelled', 'rejected', 'expired'], true);
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Presupuesto {{ $quote['code'] }}</title>
    <style>
        @page { margin: 28px 34px 50px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #1f2937; }
        .header { background: {{ $document['color'] }}; color: #ffffff; padding: 14px 18px; }
        .header .kicker { font-size: 8px; letter-spacing: 1.5px; text-transform: uppercase; opacity: .85; }
        .header h1 { font-size: 17px; margin: 3px 0 2px; }
        .header .company { font-size: 10px; opacity: .9; }
        .logo { float: left; background: #ffffff; padding: 5px 8px; margin-right: 12px; }
        .logo img { max-height: 38px; max-width: 120px; }
        .code { float: right; text-align: right; }
        .code .number { font-size: 14px; font-weight: bold; }
        .code .date { font-size: 9px; color: #ffffff; opacity: .9; }
        table.meta { width: 100%; border-collapse: collapse; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
        table.meta td { width: 33%; padding: 8px 18px; vertical-align: top; }
        .label { font-size: 7.5px; font-weight: bold; text-transform: uppercase; letter-spacing: .8px; color: #94a3b8; }
        .value { font-size: 10.5px; font-weight: bold; color: #1e293b; }
        .small { font-size: 8.5px; color: #64748b; }
        h2 { font-size: 12px; margin: 16px 18px 6px; color: #0f172a; }
        .wrap { padding: 0 18px; }
        table.items { width: 100%; border-collapse: collapse; }
        table.items th { font-size: 8px; text-transform: uppercase; letter-spacing: .6px; color: #64748b; text-align: left; border-bottom: 1.5px solid {{ $document['color'] }}; padding: 6px 6px; }
        table.items td { border-bottom: 1px solid #e2e8f0; padding: 6px 6px; vertical-align: top; }
        .right { text-align: right; }
        table.totals { margin: 8px 18px 0 auto; border-collapse: collapse; width: 230px; }
        table.totals td { padding: 3px 6px; }
        table.totals .total td { font-size: 13px; font-weight: bold; border-top: 1.5px solid {{ $document['color'] }}; padding-top: 6px; }
        .box { margin: 14px 18px 0; border: 1px solid #e2e8f0; background: #f8fafc; padding: 10px 12px; page-break-inside: avoid; }
        .box h3 { font-size: 8px; text-transform: uppercase; letter-spacing: .8px; color: #64748b; margin: 0 0 6px; }
        .pre { white-space: pre-line; }
        table.sign { width: 100%; margin-top: 30px; border-collapse: collapse; page-break-inside: avoid; }
        table.sign td { width: 50%; padding: 0 28px; text-align: center; vertical-align: top; }
        .signature { height: 70px; }
        .signature img { max-height: 68px; max-width: 190px; }
        .line { border-top: 1px solid #475569; padding-top: 4px; font-size: 9px; color: #475569; }
        .footer { position: fixed; bottom: -36px; left: 0; right: 70px; font-size: 8px; color: #94a3b8; }
        .footer .brand { color: #64748b; }
        .warning { color: #b45309; border: 1px solid #f59e0b; background: #fffbeb; padding: 6px 10px; margin: 10px 18px 0; font-size: 9.5px; }
    </style>
</head>
<body>
    <div class="footer">
        @if ($document['footer'])<div class="brand">{{ $document['footer'] }}</div>@endif
        Documento generado el {{ $printedAt }}
    </div>

    <div class="header">
        @if ($document['logo'])<div class="logo"><img src="{{ $document['logo'] }}" alt=""></div>@endif
        <div class="code">
            <div class="number">{{ $quote['code'] }}</div>
            <div class="date">{{ $quote['date'] }}</div>
        </div>
        <div class="kicker">Presupuesto</div>
        <h1>{{ $quote['title'] ?: 'Presupuesto' }}</h1>
        <div class="company">{{ $company }}</div>
        <div style="clear: both;"></div>
    </div>

    <table class="meta">
        <tr>
            <td>
                <div class="label">{{ $client }}</div>
                <div class="value">{{ $quote['customer'] }}</div>
                @if ($quote['document'])<div class="small">Documento {{ $quote['document'] }}</div>@endif
                @if ($quote['phone'])<div class="small">{{ $quote['phone'] }}</div>@endif
            </td>
            <td>
                <div class="label">{{ $pro }}</div>
                <div class="value">{{ $quote['professional'] ?: '—' }}</div>
                @if ($quote['branch'])<div class="small">{{ $quote['branch'] }}{{ $quote['branch_address'] ? ' · ' . $quote['branch_address'] : '' }}</div>@endif
            </td>
            <td>
                <div class="label">Válido hasta</div>
                <div class="value">{{ $quote['valid_until'] ?? 'Sin fecha' }}</div>
                <div class="small">Estado: {{ $quote['status_label'] }}</div>
            </td>
        </tr>
    </table>

    @if ($isDraft)
        <div class="warning">Este presupuesto está {{ mb_strtolower($quote['status_label']) }}: no es una oferta vigente.</div>
    @endif

    <h2>Detalle</h2>
    <div class="wrap">
    <table class="items">
        <thead>
            <tr>
                <th>Descripción</th>
                <th class="right">Cant.</th>
                <th class="right">Precio</th>
                <th class="right">Descuento</th>
                <th class="right">Importe</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($items as $item)
                <tr>
                    <td>
                        {{ $item['description'] }}
                        @if ($item['detail'])<div class="small">{{ $item['detail'] }}</div>@endif
                    </td>
                    <td class="right">{{ $item['quantity'] }}</td>
                    <td class="right">{{ $item['unit_price'] }}</td>
                    <td class="right">{{ $item['discount'] ?? '—' }}</td>
                    <td class="right">{{ $item['total'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    </div>

    <table class="totals">
        <tr><td>Subtotal</td><td class="right">{{ $quote['subtotal'] }}</td></tr>
        @if ($quote['discount'])<tr><td>Descuento</td><td class="right">− {{ $quote['discount'] }}</td></tr>@endif
        @if ($quote['tax'])<tr><td>{{ $quote['tax_label'] }}</td><td class="right">{{ $quote['tax'] }}</td></tr>@endif
        <tr class="total"><td>Total</td><td class="right">{{ $quote['total'] }}</td></tr>
    </table>

    @if (count($installments) > 0)
        <div class="box">
            <h3>Cuotas</h3>
            @foreach ($installments as $installment)
                <div>Cuota {{ $installment['number'] }} · {{ $installment['due_date'] }} · <b>{{ $installment['amount'] }}</b></div>
            @endforeach
        </div>
    @endif

    @if ($quote['notes'])
        <div class="box">
            <h3>Notas</h3>
            <div class="pre">{{ $quote['notes'] }}</div>
        </div>
    @endif

    @if ($document['terms'])
        <div class="box">
            <h3>Condiciones</h3>
            <div class="pre">{{ $document['terms'] }}</div>
        </div>
    @endif

    <table class="sign">
        <tr>
            <td>
                <div class="signature">@if ($document['signature']['image'])<img src="{{ $document['signature']['image'] }}" alt="">@endif</div>
                <div class="line">
                    {{ $document['signature']['name'] ?: $company }}
                    @if ($document['signature']['role'])<br>{{ $document['signature']['role'] }}@endif
                </div>
            </td>
            <td>
                <div class="signature"></div>
                <div class="line">{{ $quote['customer'] }}<br>Conforme</div>
            </td>
        </tr>
    </table>
</body>
</html>
