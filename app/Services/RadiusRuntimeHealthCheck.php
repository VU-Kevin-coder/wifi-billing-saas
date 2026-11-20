<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RadiusRuntimeHealthCheck
{
    public function available(): bool
    {
        if (! RadiusReadiness::available()) {
            return false;
        }

        $bridgeUrl = (string) config('radius.bridge_health_url');
        if ($bridgeUrl !== 'http://radius-policy-bridge:8080/health') {
            return false;
        }

        try {
            $bridge = Http::timeout(2)->get($bridgeUrl);
            if (! $bridge->successful() || trim($bridge->body()) !== 'ok') {
                return false;
            }
        } catch (\Illuminate\Http\Client\ConnectionException) {
            Log::warning('RADIUS policy bridge readiness probe failed.');

            return false;
        }

        return $this->radiusServerResponds();
    }

    private function radiusServerResponds(): bool
    {
        $secret = (string) config('radius.health_secret');
        $host = (string) config('radius.health_host');
        $port = (int) config('radius.health_port');
        if (! preg_match('/^[a-f0-9]{64,}$/i', $secret)
            || $host !== 'app'
            || $port < 1 || $port > 65535) {
            return false;
        }

        $socket = @stream_socket_client("udp://{$host}:{$port}", $errorCode, $errorMessage, 2);
        if ($socket === false) {
            Log::warning('FreeRADIUS readiness socket could not be opened.', [
                'error_code' => $errorCode,
            ]);

            return false;
        }

        try {
            stream_set_timeout($socket, 2);
            $identifier = random_int(0, 255);
            $requestAuthenticator = random_bytes(16);
            $username = 'wifi-billing-healthcheck';
            $attribute = pack('CC', 1, strlen($username) + 2).$username;
            $length = 20 + strlen($attribute);
            $request = pack('CCn', 1, $identifier, $length).$requestAuthenticator.$attribute;

            if (fwrite($socket, $request) !== strlen($request)) {
                return false;
            }

            $response = fread($socket, 4096);
            if (! is_string($response) || strlen($response) < 20) {
                return false;
            }

            $header = unpack('Ccode/Cidentifier/nlength', substr($response, 0, 4));
            if (! is_array($header)
                || $header['code'] !== 3
                || $header['identifier'] !== $identifier
                || $header['length'] !== strlen($response)) {
                return false;
            }

            $responseAuthenticator = md5(
                substr($response, 0, 4).$requestAuthenticator.substr($response, 20).$secret,
                true,
            );

            return hash_equals($responseAuthenticator, substr($response, 4, 16));
        } catch (\Throwable $exception) {
            Log::warning('FreeRADIUS readiness probe failed.', [
                'exception' => $exception::class,
            ]);

            return false;
        } finally {
            fclose($socket);
        }
    }
}
