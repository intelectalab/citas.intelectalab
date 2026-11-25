<?php

namespace App\Support;

use App\Models\PlatformSetting;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ErrorLogs
{
    public const DEFAULTS = ['notify' => true, 'notify_every_minutes' => 60, 'sentry_enabled' => false, 'sentry_dsn' => null];

    private const IGNORE = [
        ValidationException::class,
        AuthenticationException::class,
        AuthorizationException::class,
        ModelNotFoundException::class,
        HttpResponseException::class,
    ];

    private static ?bool $ready = null;

    private static bool $writing = false;

    public static function ready(): bool
    {
        try {
            return self::$ready ??= Schema::hasTable('error_logs');
        } catch (\Throwable) {
            return false;
        }
    }

    public static function forget(): void
    {
        self::$ready = null;
    }

    public static function config(): array
    {
        $stored = PlatformSetting::value('error_monitoring');

        return array_merge(self::DEFAULTS, is_array($stored) ? array_intersect_key($stored, self::DEFAULTS) : []);
    }

    public static function shouldCapture(\Throwable $e): bool
    {
        foreach (self::IGNORE as $class) {
            if ($e instanceof $class) {
                return false;
            }
        }

        return !($e instanceof HttpExceptionInterface && $e->getStatusCode() < 500);
    }

    public static function relative(?string $file): ?string
    {
        if (!$file) {
            return null;
        }

        return mb_substr(str_replace([base_path() . DIRECTORY_SEPARATOR, '\\'], ['', '/'], $file), 0, 255);
    }

    public static function capture(\Throwable $e, ?Request $request = null): void
    {
        if (self::$writing || !self::shouldCapture($e)) {
            return;
        }

        $request ??= app()->bound('request') ? request() : null;
        $user = $request?->user();
        $trace = collect($e->getTrace())->take(8)->map(fn ($frame) => trim(self::relative($frame['file'] ?? null) . ':' . ($frame['line'] ?? '') . ' ' . ($frame['class'] ?? '') . ($frame['type'] ?? '') . ($frame['function'] ?? '')))->all();

        self::store([
            'source'  => 'server',
            'kind'    => get_class($e),
            'message' => $e->getMessage() ?: get_class($e),
            'file'    => self::relative($e->getFile()),
            'line'    => $e->getLine(),
            'url'     => $request ? mb_substr('/' . ltrim($request->path(), '/'), 0, 500) : (app()->runningInConsole() ? 'consola: ' . implode(' ', array_slice($_SERVER['argv'] ?? [], 1, 3)) : null),
            'method'  => $request?->method(),
            'tenant'  => $user?->tenant_id,
            'user'    => $user instanceof User ? $user->id : null,
            'sample'  => ['trace' => $trace],
        ]);
    }

    public static function captureWeb(array $values, ?Request $request = null): void
    {
        $user = $request?->user('sanctum');

        self::store([
            'source'  => 'web',
            'kind'    => mb_substr((string) ($values['kind'] ?? 'Error'), 0, 190),
            'message' => mb_substr((string) $values['message'], 0, 2000),
            'file'    => isset($values['file']) ? mb_substr(preg_replace('#^https?://[^/]+#', '', (string) $values['file']), 0, 255) : null,
            'line'    => isset($values['line']) ? (int) $values['line'] : null,
            'url'     => isset($values['url']) ? mb_substr(preg_replace('#^https?://[^/]+#', '', (string) $values['url']), 0, 500) : null,
            'method'  => null,
            'tenant'  => $user?->tenant_id,
            'user'    => $user instanceof User ? $user->id : null,
            'sample'  => ['stack' => mb_substr((string) ($values['stack'] ?? ''), 0, 3000), 'browser' => mb_substr((string) $request?->userAgent(), 0, 300)],
        ]);
    }

    public static function fingerprint(array $row): string
    {
        $message = preg_replace(['/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i', '/\d+/'], ['{id}', '{n}'], mb_substr($row['message'], 0, 300));

        return hash('sha256', implode('|', [$row['source'], $row['kind'], $row['file'], $row['line'], $message]));
    }

    private static function store(array $row): void
    {
        if (!self::ready()) {
            return;
        }

        self::$writing = true;

        try {
            $fingerprint = self::fingerprint($row);
            $sample = json_encode($row['sample'] + ['url' => $row['url']], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

            $saved = Rls::bypass(fn () => DB::selectOne(
                'INSERT INTO error_logs (id, fingerprint, source, kind, message, file, line, url, method, tenant_id, user_id, sample, occurrences, first_seen_at, last_seen_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?::jsonb, 1, now(), now())
                 ON CONFLICT (fingerprint) DO UPDATE SET occurrences = error_logs.occurrences + 1, last_seen_at = now(), url = EXCLUDED.url, sample = EXCLUDED.sample,
                    tenant_id = coalesce(EXCLUDED.tenant_id, error_logs.tenant_id), resolved_at = NULL, resolved_by = NULL
                 RETURNING id, occurrences, notified_at, (xmax = 0) AS inserted',
                [(string) Str::uuid(), $fingerprint, $row['source'], $row['kind'], mb_substr($row['message'], 0, 5000), $row['file'], $row['line'], $row['url'], $row['method'],
                    $row['tenant'] && Str::isUuid($row['tenant']) ? $row['tenant'] : null, $row['user'], $sample],
            ));

            $config = self::config();

            if ($saved && $config['notify'] && (!$saved->notified_at || now()->diffInMinutes($saved->notified_at, true) >= max(5, (int) $config['notify_every_minutes']))) {
                Rls::bypass(fn () => DB::table('error_logs')->where('id', $saved->id)->update(['notified_at' => now()]));
                self::notify($row, (int) $saved->occurrences);
            }

            if ($config['sentry_enabled'] && $config['sentry_dsn']) {
                self::sentry($config['sentry_dsn'], $row);
            }
        } catch (\Throwable) {
        } finally {
            self::$writing = false;
        }
    }

    private static function notify(array $row, int $occurrences): void
    {
        if (!PlatformMail::configured()) {
            return;
        }

        $emails = Rls::bypass(fn () => User::whereNull('tenant_id')->where('status', 'active')->get()->filter(fn (User $user) => $user->isSaasAdmin())->pluck('email')->filter()->unique()->take(10));
        $where = $row['source'] === 'web' ? 'en la web' : 'en el servidor';
        $body = '<p>Hay un error ' . $where . ($occurrences > 1 ? " que ya pasó {$occurrences} veces" : '') . ':</p><p><strong>' . e(Str::limit($row['message'], 300)) . '</strong></p>'
            . '<p>' . e(trim(($row['url'] ?? '') . ' ' . ($row['file'] ? "({$row['file']}:{$row['line']})" : ''))) . '</p><p>Míralo en la consola de la plataforma → Errores.</p>';

        foreach ($emails as $email) {
            PlatformMail::trySend($email, 'Error ' . $where . ': ' . Str::limit($row['message'], 60), $body);
        }
    }

    public static function sentryEndpoint(string $dsn): ?array
    {
        $parts = parse_url(trim($dsn));

        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host']) || empty($parts['user']) || !preg_match('#^/?(\d+)$#', (string) ($parts['path'] ?? ''), $m)) {
            return null;
        }

        return ['url' => "https://{$parts['host']}/api/{$m[1]}/store/", 'key' => $parts['user']];
    }

    private static function sentry(string $dsn, array $row): void
    {
        $endpoint = self::sentryEndpoint($dsn);

        if (!$endpoint) {
            return;
        }

        try {
            Http::timeout(3)->withHeaders([
                'X-Sentry-Auth' => 'Sentry sentry_version=7, sentry_client=agenda/1.0, sentry_key=' . $endpoint['key'],
            ])->post($endpoint['url'], [
                'event_id'  => str_replace('-', '', (string) Str::uuid()),
                'timestamp' => now()->toIso8601String(),
                'platform'  => $row['source'] === 'web' ? 'javascript' : 'php',
                'level'     => 'error',
                'logger'    => $row['source'],
                'message'   => Str::limit($row['message'], 1000),
                'culprit'   => $row['file'] ? "{$row['file']}:{$row['line']}" : $row['url'],
                'tags'      => ['source' => $row['source'], 'kind' => $row['kind']],
                'extra'     => ['url' => $row['url']] + $row['sample'],
            ]);
        } catch (\Throwable) {
        }
    }

    public static function list(array $filters = []): array
    {
        if (!self::ready()) {
            return ['items' => [], 'open' => 0];
        }

        return Rls::bypass(function () use ($filters) {
            $query = DB::table('error_logs as e')->leftJoin('tenants as t', 't.id', '=', 'e.tenant_id');

            if (($filters['state'] ?? 'open') === 'open') {
                $query->whereNull('e.resolved_at');
            } elseif ($filters['state'] === 'resolved') {
                $query->whereNotNull('e.resolved_at');
            }

            if (in_array($filters['source'] ?? null, ['server', 'web'], true)) {
                $query->where('e.source', $filters['source']);
            }

            if (!empty($filters['q'])) {
                $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $filters['q']) . '%';
                $query->where(fn ($q) => $q->where('e.message', 'ilike', $like)->orWhere('e.url', 'ilike', $like)->orWhere('e.file', 'ilike', $like));
            }

            return [
                'items' => $query->orderByDesc('e.last_seen_at')->limit(300)->get(['e.id', 'e.source', 'e.kind', 'e.message', 'e.file', 'e.line', 'e.url', 'e.occurrences', 'e.first_seen_at', 'e.last_seen_at', 'e.resolved_at', 't.name as tenant'])
                    ->map(fn ($row) => ['message' => Str::limit($row->message, 240)] + (array) $row)->values()->all(),
                'open'  => DB::table('error_logs')->whereNull('resolved_at')->count(),
            ];
        });
    }
}
