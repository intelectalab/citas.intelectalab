<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class Idempotency
{
    public const HEADER = 'Idempotency-Key';

    public const HOURS = 24;

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('idempotency_keys');
    }

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header(self::HEADER);

        if ($key === null || $key === '' || $request->method() !== 'POST' || !self::ready()) {
            return $next($request);
        }

        if (!is_string($key) || !preg_match('/^[A-Za-z0-9_-]{8,100}$/', $key)) {
            return response()->json(['message' => 'La clave para no repetir la operación no es válida. Recarga la página e inténtalo de nuevo.', 'code' => 'IDEMPOTENCY_KEY_INVALID'], 400);
        }

        [$tenantId, $scope] = $this->scope($request);
        $hash = $this->fingerprint($request);

        $this->purgeExpired();

        $inserted = DB::table('idempotency_keys')->insertOrIgnore([
            'id'           => (string) Str::uuid(),
            'tenant_id'    => $tenantId,
            'scope'        => $scope,
            'key'          => $key,
            'method'       => $request->method(),
            'path'         => mb_substr('/' . ltrim($request->path(), '/'), 0, 255),
            'request_hash' => $hash,
            'expires_at'   => now()->addHours(self::HOURS),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        if (!$inserted) {
            return $this->previous($scope, $key, $hash);
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $this->forget($scope, $key);

            throw $e;
        }

        $this->remember($scope, $key, $response);

        return $response;
    }

    private function previous(string $scope, string $key, string $hash): Response
    {
        $row = DB::table('idempotency_keys')->where('scope', $scope)->where('key', $key)->first();

        if (!$row) {
            return response()->json(['message' => 'No pudimos comprobar si esta operación ya se hizo. Inténtalo de nuevo.', 'code' => 'IDEMPOTENCY_IN_PROGRESS'], 409);
        }

        if (!hash_equals($row->request_hash, $hash)) {
            return response()->json(['message' => 'Esta operación ya se envió antes con otros datos. Recarga la página y vuelve a intentarlo.', 'code' => 'IDEMPOTENCY_MISMATCH'], 409);
        }

        if ($row->response_status === null) {
            return response()->json(['message' => 'Todavía estamos guardando esta operación. Espera unos segundos y revisa si ya quedó registrada.', 'code' => 'IDEMPOTENCY_IN_PROGRESS'], 409);
        }

        try {
            $body = Crypt::decryptString((string) $row->response_body);
        } catch (\Throwable) {
            $this->forget($scope, $key);

            return response()->json(['message' => 'No pudimos recuperar la respuesta anterior. Inténtalo de nuevo.', 'code' => 'IDEMPOTENCY_IN_PROGRESS'], 409);
        }

        return response($body, (int) $row->response_status)
            ->header('Content-Type', $row->response_type ?: 'application/json')
            ->header('Idempotent-Replayed', 'true');
    }

    private function remember(string $scope, string $key, Response $response): void
    {
        $status = $response->getStatusCode();
        $type = (string) $response->headers->get('Content-Type');
        $content = $response->getContent();

        if ($status < 200 || $status >= 300 || !str_contains($type, 'json') || !is_string($content)) {
            $this->forget($scope, $key);

            return;
        }

        DB::table('idempotency_keys')->where('scope', $scope)->where('key', $key)->update([
            'response_status' => $status,
            'response_type'   => mb_substr($type, 0, 120),
            'response_body'   => Crypt::encryptString($content),
            'updated_at'      => now(),
        ]);
    }

    private function forget(string $scope, string $key): void
    {
        try {
            DB::table('idempotency_keys')->where('scope', $scope)->where('key', $key)->whereNull('response_status')->delete();
        } catch (\Throwable) {
        }
    }

    private function purgeExpired(): void
    {
        if (random_int(1, 50) === 1) {
            DB::table('idempotency_keys')->where('expires_at', '<', now())->delete();
        }
    }

    private function scope(Request $request): array
    {
        $apiKey = $request->attributes->get('api_key');

        if ($apiKey) {
            return [$apiKey->tenant_id, 'api:' . $apiKey->id];
        }

        $user = $request->user('sanctum') ?? $request->user();

        if ($user) {
            $kind = $user instanceof \App\Models\Customer ? 'customer' : 'user';

            return [$user->tenant_id ?? null, $kind . ':' . $user->getAuthIdentifier()];
        }

        return [null, 'guest'];
    }

    private function fingerprint(Request $request): string
    {
        $input = $this->normalize($request->all());

        return hash('sha256', $request->method() . ' /' . ltrim($request->path(), '/') . ' ' . json_encode($input, JSON_UNESCAPED_UNICODE));
    }

    private function normalize(mixed $value): mixed
    {
        if ($value instanceof UploadedFile) {
            return 'file:' . ($value->isValid() ? hash_file('sha256', $value->getRealPath()) : $value->getClientOriginalName());
        }

        if (is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value);
            }

            return array_map(fn ($item) => $this->normalize($item), $value);
        }

        return $value;
    }
}
