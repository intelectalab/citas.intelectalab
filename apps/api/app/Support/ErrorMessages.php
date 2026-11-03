<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class ErrorMessages
{
    public const BY_STATUS = [
        401 => 'Tu sesión terminó. Vuelve a iniciar sesión para continuar.',
        403 => 'No tienes permiso para hacer esto. Si lo necesitas, pídeselo al administrador de tu empresa.',
        404 => 'No encontramos lo que buscas. Puede que se haya eliminado o que el enlace esté mal.',
        405 => 'No se pudo completar la acción. Recarga la página e inténtalo de nuevo.',
        413 => 'El archivo o los datos que enviaste son demasiado grandes. Prueba con algo más liviano.',
        419 => 'La página estuvo abierta mucho tiempo. Recárgala e inténtalo de nuevo.',
        429 => 'Hiciste demasiados intentos seguidos. Espera un minuto y vuelve a intentarlo.',
        500 => 'Ocurrió un problema en el sistema y no se pudo completar la acción. Inténtalo de nuevo en unos minutos; si sigue pasando, avísale al administrador.',
        503 => 'El sistema está en mantenimiento. Vuelve a intentarlo en unos minutos.',
    ];

    public const OTHER_CLIENT_ERROR = 'No se pudo completar la acción. Revisa los datos e inténtalo de nuevo.';

    public const DATABASE_DOWN = 'No pudimos conectarnos con la base de datos, así que no se pudo completar la acción. Inténtalo de nuevo en unos minutos; si sigue pasando, avísale al administrador.';

    private const DATABASE_PATTERNS = [
        '/SQLSTATE\[08\d{3}\]/',
        '/SQLSTATE\[HY000\] \[2002\]/',
        '/Connection refused/i',
        '/could not connect to server/i',
        '/server closed the connection unexpectedly/i',
    ];

    private const DEFAULTS = [
        '/^$/',
        '/^Unauthenticated\.?$/i',
        '/^This action is unauthorized\.?$/i',
        '/^Too Many Attempts\.?$/i',
        '/^CSRF token mismatch\.?$/i',
        '/^No query results for model/i',
        '/^The route .* could not be found\.?$/i',
        '/^The [A-Z]+ method is not supported/i',
        '/^(Server Error|Not Found|Forbidden|Unauthorized|Method Not Allowed|Too Many Requests|Service Unavailable|Payload Too Large|Content Too Large|Page Expired|Bad Request|Conflict|Gone)\.?$/i',
    ];

    public static function apply(Response $response): Response
    {
        $status = $response->getStatusCode();

        if (! $response instanceof JsonResponse || $status < 400 || $status === 422) {
            return $response;
        }

        $data = $response->getData(true);
        $data = is_array($data) ? $data : [];
        $message = (string) ($data['message'] ?? '');

        if ($status >= 500 && $message !== '' && ! self::isDefault($message) && ! self::isTechnical($message, $data)) {
            return $response;
        }

        if ($status < 500 && ! self::isDefault($message)) {
            return $response;
        }

        $friendly = self::isDatabaseDown($message) ? self::DATABASE_DOWN : (self::BY_STATUS[$status] ?? ($status >= 500 ? self::BY_STATUS[500] : self::OTHER_CLIENT_ERROR));

        $clean = ['message' => $friendly] + array_intersect_key($data, array_flip(['code', 'errors']));

        if (config('app.debug') && $message !== '' && $message !== $friendly) {
            $clean['debug'] = ['message' => $message] + array_intersect_key($data, array_flip(['exception', 'file', 'line']));
        }

        return $response->setData($clean);
    }

    private static function isTechnical(string $message, array $data): bool
    {
        $exception = (string) ($data['exception'] ?? '');

        return ($exception !== '' && !str_contains($exception, 'HttpException')) || self::isDatabaseDown($message) || preg_match('/SQLSTATE|Exception|Stack trace|\.php|Call to |Undefined (index|variable|array key|method)/i', $message) === 1;
    }

    private static function isDatabaseDown(string $message): bool
    {
        foreach (self::DATABASE_PATTERNS as $pattern) {
            if (preg_match($pattern, $message)) {
                return true;
            }
        }

        return false;
    }

    private static function isDefault(string $message): bool
    {
        foreach (self::DEFAULTS as $pattern) {
            if (preg_match($pattern, trim($message))) {
                return true;
            }
        }

        return false;
    }
}
