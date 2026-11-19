<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{{ $spec['info']['title'] ?? 'API' }} · Desarrolladores</title>
<style>
:root {
    --brand-ink: #022340;
    --brand-link: #3f768c;
    --brand-soft: #84d0d9;
    --bg: #f5f7fa;
    --card: #ffffff;
    --line: #e2e8f0;
    --text: #1f2937;
    --muted: #64748b;
    --code-bg: #0b192c;
    --code-text: #dbe3ec;
    --get: #1c9bb0;
    --post: #10b981;
    --warn: #c77a1e;
}
@media (prefers-color-scheme: dark) {
    :root {
        --brand-ink: #dbe3ec;
        --brand-link: #84d0d9;
        --bg: #0a1626;
        --card: #101f33;
        --line: #263f5d;
        --text: #dbe3ec;
        --muted: #98abc1;
        --code-bg: #06101d;
    }
}
* { box-sizing: border-box; }
body { margin: 0; background: var(--bg); color: var(--text); font-family: system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif; line-height: 1.55; }
a { color: var(--brand-link); }
header { background: var(--code-bg); color: var(--code-text); padding: 28px 16px; }
header .wrap, main { max-width: 1080px; margin: 0 auto; }
header h1 { margin: 0 0 6px; font-size: 26px; }
header p { margin: 0; color: var(--brand-soft); }
header .links { margin-top: 14px; display: flex; gap: 14px; flex-wrap: wrap; font-size: 14px; }
header .links a { color: var(--code-text); }
main { padding: 24px 16px 64px; display: grid; grid-template-columns: 220px 1fr; gap: 28px; }
nav { position: sticky; top: 16px; align-self: start; font-size: 14px; }
nav a { display: block; padding: 4px 0; text-decoration: none; color: var(--muted); }
nav a:hover { color: var(--brand-link); }
nav strong { display: block; margin-top: 14px; color: var(--text); }
section { margin-bottom: 36px; }
h2 { font-size: 20px; color: var(--brand-ink); margin: 0 0 8px; }
h3 { font-size: 16px; margin: 0; }
.card { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 18px; margin-top: 14px; }
.intro { white-space: pre-line; }
.op-head { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }
.method { font: 600 12px ui-monospace, monospace; color: #fff; border-radius: 6px; padding: 3px 8px; }
.method.GET { background: var(--get); }
.method.POST { background: var(--post); }
.path { font-family: ui-monospace, monospace; font-size: 14px; overflow-wrap: anywhere; }
.muted { color: var(--muted); font-size: 14px; }
table { width: 100%; border-collapse: collapse; font-size: 14px; margin-top: 10px; }
th, td { text-align: left; padding: 7px 8px; border-bottom: 1px solid var(--line); vertical-align: top; }
th { color: var(--muted); font-weight: 600; }
td code, p code, li code { font-family: ui-monospace, monospace; font-size: 13px; }
.req { color: var(--warn); font-size: 12px; }
pre { background: var(--code-bg); color: var(--code-text); padding: 12px 14px; border-radius: 10px; overflow-x: auto; font-size: 12.5px; margin: 8px 0 0; }
details { margin-top: 10px; }
summary { cursor: pointer; font-size: 14px; color: var(--brand-link); }
.status { font-family: ui-monospace, monospace; font-weight: 600; }
@media (max-width: 800px) {
    main { grid-template-columns: 1fr; }
    nav { position: static; }
    .table-wrap { overflow-x: auto; }
}
</style>
</head>
<body>
<header>
    <div class="wrap">
        <h1>{{ $spec['info']['title'] ?? 'API' }}</h1>
        <p>{{ $spec['info']['summary'] ?? '' }} Versión {{ $spec['info']['version'] ?? '' }}.</p>
        <div class="links">
            <a href="{{ url('/api/docs/openapi.yaml') }}">Descargar el contrato (OpenAPI, YAML)</a>
            <a href="{{ url('/api/docs/openapi.json') }}">Contrato en JSON</a>
        </div>
    </div>
</header>
<main>
    <nav aria-label="Contenido">
        <a href="#inicio">Cómo empezar</a>
        <a href="#errores">Errores</a>
        <a href="#webhooks">Webhooks</a>
        @foreach ($sections as $section)
            <strong>{{ $section['name'] }}</strong>
            @foreach ($section['operations'] as $op)
                <a href="#{{ $op['id'] }}">{{ $op['summary'] }}</a>
            @endforeach
        @endforeach
    </nav>
    <div>
        <section id="inicio">
            <h2>Cómo empezar</h2>
            <div class="card intro">{!! preg_replace(['/\*\*(.+?)\*\*/', '/`([^`]+)`/'], ['<strong>$1</strong>', '<code>$1</code>'], e($spec['info']['description'] ?? '')) !!}</div>
            <div class="card">
                <h3>Dirección base</h3>
                <ul>
                    @foreach ($spec['servers'] ?? [] as $server)
                        <li><code>{{ $server['url'] }}</code> <span class="muted">{{ $server['description'] ?? '' }}</span></li>
                    @endforeach
                </ul>
                <pre>curl {{ $spec['servers'][0]['url'] ?? '' }}/services \
  -H "Authorization: Bearer apk_tu_clave"</pre>
            </div>
        </section>

        @foreach ($sections as $section)
            <section>
                <h2>{{ $section['name'] }}</h2>
                <p class="muted">{{ $section['description'] }}</p>
                @foreach ($section['operations'] as $op)
                    <div class="card" id="{{ $op['id'] }}" data-operation="{{ $op['method'] }} {{ $op['path'] }}">
                        <div class="op-head">
                            <span class="method {{ $op['method'] }}">{{ $op['method'] }}</span>
                            <span class="path">{{ $op['path'] }}</span>
                        </div>
                        <h3 style="margin-top:8px">{{ $op['summary'] }}</h3>
                        @if ($op['description'])<p class="muted">{{ $op['description'] }}</p>@endif

                        @if ($op['parameters'])
                            <div class="table-wrap"><table>
                                <tr><th>Parámetro</th><th>Dónde</th><th>Detalle</th></tr>
                                @foreach ($op['parameters'] as $p)
                                    <tr>
                                        <td><code>{{ $p['name'] }}</code> @if (!empty($p['required']))<span class="req">obligatorio</span>@endif</td>
                                        <td>{{ ['query' => 'consulta', 'path' => 'ruta', 'header' => 'encabezado'][$p['in']] ?? $p['in'] }}</td>
                                        <td>{{ $p['description'] ?? '' }}</td>
                                    </tr>
                                @endforeach
                            </table></div>
                        @endif

                        @if ($op['fields'])
                            <div class="table-wrap"><table>
                                <tr><th>Campo</th><th>Tipo</th><th>Detalle</th></tr>
                                @foreach ($op['fields'] as $f)
                                    <tr>
                                        <td><code>{{ $f['name'] }}</code> @if ($f['required'])<span class="req">obligatorio</span>@endif</td>
                                        <td>{{ $f['type'] }}</td>
                                        <td>{{ $f['description'] }}</td>
                                    </tr>
                                @endforeach
                            </table></div>
                        @endif

                        @if ($op['request'])
                            <details open><summary>Ejemplo de lo que envías</summary><pre>{{ $op['request'] }}</pre></details>
                        @endif

                        <div class="table-wrap"><table>
                            <tr><th>Respuesta</th><th>Cuándo</th></tr>
                            @foreach ($op['responses'] as $r)
                                <tr><td class="status">{{ $r['status'] }}</td><td>{{ $r['description'] }}</td></tr>
                            @endforeach
                        </table></div>
                        @foreach ($op['responses'] as $r)
                            @if ($r['example'] && str_starts_with($r['status'], '2'))
                                <details><summary>Ejemplo de respuesta {{ $r['status'] }}</summary><pre>{{ $r['example'] }}</pre></details>
                            @endif
                        @endforeach
                    </div>
                @endforeach
            </section>
        @endforeach

        <section id="errores">
            <h2>Errores</h2>
            <p class="muted">Todos los errores traen <code>message</code> en español y, cuando aplica, un <code>code</code> estable.</p>
            <div class="card">
                <div class="table-wrap"><table>
                    <tr><th>Caso</th><th>Ejemplo</th></tr>
                    @foreach ($errors as $error)
                        <tr><td>{{ $error['description'] }}</td><td>@if ($error['example'])<pre>{{ $error['example'] }}</pre>@endif</td></tr>
                    @endforeach
                </table></div>
            </div>
        </section>

        <section id="webhooks">
            <h2>Webhooks</h2>
            <p class="muted">En Configuración → Integraciones registra la dirección de tu sistema y elige los avisos. Cada aviso llega como POST con JSON y estos encabezados:</p>
            <div class="card">
                <div class="table-wrap"><table>
                    <tr><th>Encabezado</th><th>Detalle</th></tr>
                    @foreach ($headers as $h)
                        <tr><td><code>{{ $h['name'] }}</code></td><td>{{ $h['description'] ?? '' }}</td></tr>
                    @endforeach
                </table></div>
                <p class="muted">{{ $received }}</p>
                <details><summary>Cómo verificar la firma (PHP)</summary><pre>[$t, $v1] = sscanf(str_replace(['t=', 'v1='], '', $_SERVER['HTTP_X_WEBHOOK_SIGNATURE']), '%d,%s');
$esperada = hash_hmac('sha256', $t . '.' . file_get_contents('php://input'), 'whsec_tu_clave');
$valida = hash_equals($esperada, $v1) &amp;&amp; abs(time() - $t) &lt; 300;</pre></details>
            </div>
            @foreach ($webhooks as $hook)
                <div class="card" data-webhook="{{ $hook['event'] }}">
                    <div class="op-head"><span class="method POST">AVISO</span><span class="path">{{ $hook['event'] }}</span></div>
                    <h3 style="margin-top:8px">{{ $hook['summary'] }}</h3>
                    @if ($hook['description'])<p class="muted">{{ $hook['description'] }}</p>@endif
                    @if ($hook['example'])<details><summary>Ejemplo del cuerpo</summary><pre>{{ $hook['example'] }}</pre></details>@endif
                </div>
            @endforeach
        </section>
    </div>
</main>
</body>
</html>
