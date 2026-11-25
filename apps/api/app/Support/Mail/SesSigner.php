<?php

namespace App\Support\Mail;

class SesSigner
{
    public static function headers(string $method, string $url, string $body, string $keyId, string $secret, string $region, ?int $time = null): array
    {
        $parts = parse_url($url);
        $host = (string) $parts['host'];
        $path = $parts['path'] ?? '/';
        $time ??= time();
        $amzDate = gmdate('Ymd\THis\Z', $time);
        $date = gmdate('Ymd', $time);
        $payloadHash = hash('sha256', $body);
        $canonicalHeaders = "host:{$host}\nx-amz-content-sha256:{$payloadHash}\nx-amz-date:{$amzDate}\n";
        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';
        $canonical = implode("\n", [$method, $path, $parts['query'] ?? '', $canonicalHeaders, $signedHeaders, $payloadHash]);
        $scope = "{$date}/{$region}/ses/aws4_request";
        $toSign = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonical)]);

        $key = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', 'ses', hash_hmac('sha256', $region, hash_hmac('sha256', $date, 'AWS4' . $secret, true), true), true), true);
        $signature = hash_hmac('sha256', $toSign, $key);

        return [
            'X-Amz-Date'           => $amzDate,
            'X-Amz-Content-Sha256' => $payloadHash,
            'Authorization'        => "AWS4-HMAC-SHA256 Credential={$keyId}/{$scope}, SignedHeaders={$signedHeaders}, Signature={$signature}",
        ];
    }
}
