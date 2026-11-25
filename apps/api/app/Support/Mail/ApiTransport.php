<?php

namespace App\Support\Mail;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\MessageConverter;

class ApiTransport extends AbstractTransport
{
    public const SERVICES = [
        'resend' => 'Resend',
        'brevo'  => 'Brevo (API)',
        'ses'    => 'Amazon SES',
    ];

    public function __construct(private array $config)
    {
        parent::__construct();
    }

    public static function uses(?array $config): bool
    {
        return $config && isset(self::SERVICES[$config['service'] ?? '']) && ($config['transport'] ?? null) === 'api';
    }

    public static function mailer(array $config, string $security = 'tls'): array
    {
        if (self::uses($config)) {
            return [
                'transport' => 'mailapi',
                'service'   => $config['service'],
                'key'       => $config['password'] ?? null,
                'key_id'    => $config['username'] ?? null,
                'region'    => $config['region'] ?? 'us-east-1',
            ];
        }

        return [
            'transport' => 'smtp',
            'scheme'    => ($config['security'] ?? $security) === 'ssl' ? 'smtps' : 'smtp',
            'host'      => $config['host'],
            'port'      => (int) ($config['port'] ?? ($security === 'ssl' ? 465 : 587)),
            'username'  => $config['username'] ?? null,
            'password'  => $config['password'] ?? null,
            'timeout'   => 20,
        ];
    }

    public static function ready(?array $config): bool
    {
        return (bool) $config && !empty($config['from_address']) && (self::uses($config) ? !empty($config['password']) : !empty($config['host']));
    }

    public function __toString(): string
    {
        return 'mailapi://' . ($this->config['service'] ?? 'none');
    }

    protected function doSend(SentMessage $message): void
    {
        $email = MessageConverter::toEmail($message->getOriginalMessage());

        $response = match ($this->config['service'] ?? null) {
            'resend' => $this->resend($email),
            'brevo'  => $this->brevo($email),
            'ses'    => $this->ses($message),
            default  => throw new ApiMailException('Elige el servicio de correo.'),
        };

        if (!$response['ok']) {
            throw new ApiMailException($response['message']);
        }

        if (!empty($response['id'])) {
            $message->setMessageId((string) $response['id']);
        }
    }

    private static function format(Address $address): string
    {
        return $address->getName() !== '' ? sprintf('%s <%s>', str_replace(['<', '>', '"'], '', $address->getName()), $address->getAddress()) : $address->getAddress();
    }

    private static function attachments(Email $email, string $nameKey, string $contentKey): array
    {
        $files = [];

        foreach ($email->getAttachments() as $part) {
            $headers = $part->getPreparedHeaders();
            $file = [$nameKey => $part->getFilename() ?: 'archivo', $contentKey => base64_encode($part->getBody())];

            if ($nameKey === 'filename' && $headers->has('Content-ID')) {
                $file['content_id'] = trim((string) $headers->get('Content-ID')->getBodyAsString(), '<>');
            }

            $files[] = $file;
        }

        return $files;
    }

    private static function result($response, string $label, string $idKey): array
    {
        if ($response->successful()) {
            return ['ok' => true, 'id' => $response->json($idKey)];
        }

        $status = $response->status();
        $detail = (string) ($response->json('message') ?? $response->json('error.message') ?? '');

        return ['ok' => false, 'message' => match (true) {
            $status === 401 || $status === 403 => "{$label} no aceptó la clave (API key). Revísala en la configuración del correo.",
            $status === 422 && stripos($detail, 'domain') !== false => "{$label} dice que el dominio del remitente no está verificado: verifícalo en {$label}.",
            $status === 429 => "{$label} pidió esperar: se enviaron muchos correos seguidos.",
            default => "{$label} respondió con un error ({$status})" . ($detail !== '' ? ': ' . mb_substr($detail, 0, 200) : '.'),
        }];
    }

    private function resend(Email $email): array
    {
        try {
            $response = Http::withToken((string) $this->config['key'])->acceptJson()->asJson()->timeout(20)
                ->post(rtrim((string) config('services.mail_api.resend_url'), '/') . '/emails', array_filter([
                    'from'        => self::format($email->getFrom()[0]),
                    'to'          => array_map([self::class, 'format'], $email->getTo()),
                    'subject'     => (string) $email->getSubject(),
                    'html'        => $email->getHtmlBody(),
                    'text'        => $email->getTextBody(),
                    'reply_to'    => $email->getReplyTo() ? array_map(fn ($a) => $a->getAddress(), $email->getReplyTo()) : null,
                    'attachments' => self::attachments($email, 'filename', 'content') ?: null,
                ]));
        } catch (ConnectionException $e) {
            return ['ok' => false, 'message' => 'No se pudo conectar con Resend.'];
        }

        return self::result($response, 'Resend', 'id');
    }

    private function brevo(Email $email): array
    {
        $from = $email->getFrom()[0];

        try {
            $response = Http::withHeaders(['api-key' => (string) $this->config['key']])->acceptJson()->asJson()->timeout(20)
                ->post(rtrim((string) config('services.mail_api.brevo_url'), '/') . '/v3/smtp/email', array_filter([
                    'sender'      => array_filter(['email' => $from->getAddress(), 'name' => $from->getName() ?: null]),
                    'to'          => array_map(fn ($a) => array_filter(['email' => $a->getAddress(), 'name' => $a->getName() ?: null]), $email->getTo()),
                    'subject'     => (string) $email->getSubject(),
                    'htmlContent' => $email->getHtmlBody(),
                    'textContent' => $email->getTextBody(),
                    'replyTo'     => $email->getReplyTo() ? ['email' => $email->getReplyTo()[0]->getAddress()] : null,
                    'attachment'  => self::attachments($email, 'name', 'content') ?: null,
                ]));
        } catch (ConnectionException $e) {
            return ['ok' => false, 'message' => 'No se pudo conectar con Brevo.'];
        }

        return self::result($response, 'Brevo', 'messageId');
    }

    private function ses(SentMessage $message): array
    {
        $region = (string) ($this->config['region'] ?? 'us-east-1');
        $body = json_encode(['Content' => ['Raw' => ['Data' => base64_encode($message->toString())]]]);
        $url = "https://email.{$region}.amazonaws.com/v2/email/outbound-emails";

        try {
            $response = Http::withHeaders(SesSigner::headers('POST', $url, $body, (string) ($this->config['key_id'] ?? ''), (string) $this->config['key'], $region))
                ->withBody($body, 'application/json')->timeout(20)->post($url);
        } catch (ConnectionException $e) {
            return ['ok' => false, 'message' => 'No se pudo conectar con Amazon SES.'];
        }

        return self::result($response, 'Amazon SES', 'MessageId');
    }

    public static function check(array $config): array
    {
        $label = self::SERVICES[$config['service'] ?? ''] ?? 'El servicio';

        try {
            $response = match ($config['service'] ?? null) {
                'resend' => Http::withToken((string) ($config['password'] ?? ''))->acceptJson()->timeout(15)->get(rtrim((string) config('services.mail_api.resend_url'), '/') . '/domains'),
                'brevo'  => Http::withHeaders(['api-key' => (string) ($config['password'] ?? '')])->acceptJson()->timeout(15)->get(rtrim((string) config('services.mail_api.brevo_url'), '/') . '/v3/account'),
                'ses'    => (function () use ($config) {
                    $region = (string) ($config['region'] ?? 'us-east-1');
                    $url = "https://email.{$region}.amazonaws.com/v2/email/account";

                    return Http::withHeaders(SesSigner::headers('GET', $url, '', (string) ($config['username'] ?? ''), (string) ($config['password'] ?? ''), $region))->timeout(15)->get($url);
                })(),
                default  => null,
            };
        } catch (ConnectionException $e) {
            return ['ok' => false, 'message' => "No se pudo conectar con {$label}."];
        }

        if (!$response) {
            return ['ok' => false, 'message' => 'Elige el servicio de correo.'];
        }

        if (($config['service'] ?? null) === 'resend' && $response->status() === 401 && str_contains((string) $response->json('name'), 'restricted')) {
            return ['ok' => true, 'message' => 'Resend reconoce la clave (solo puede enviar).'];
        }

        $result = self::result($response, $label, 'id');

        return ['ok' => $result['ok'], 'message' => $result['ok'] ? "{$label} reconoce la clave." : $result['message']];
    }
}
