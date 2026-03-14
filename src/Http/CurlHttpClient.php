<?php

namespace ApiBoleto\Http;

use ApiBoleto\Contracts\HttpClientInterface;
use ApiBoleto\Exceptions\ApiException;

class CurlHttpClient implements HttpClientInterface
{
    /** @var int Timeout da conexao em segundos */
    private int $connectTimeout;

    /** @var int Timeout total da requisicao em segundos */
    private int $timeout;

    /** @var bool Habilita output verbose do cURL para debug */
    private bool $verbose;

    public function __construct(int $connectTimeout = 30, int $timeout = 60, bool $verbose = false)
    {
        $this->connectTimeout = $connectTimeout;
        $this->timeout = $timeout;
        $this->verbose = $verbose;
    }

    /**
     * {@inheritdoc}
     */
    public function request(string $method, string $url, array $options = []): array
    {
        $headers = $options['headers'] ?? [];
        $body = $options['body'] ?? [];
        $query = $options['query'] ?? [];
        $cert = $options['cert'] ?? [];
        $rawResponse = $options['rawResponse'] ?? false;

        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $this->connectTimeout);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);

        if ($this->verbose) {
            curl_setopt($ch, CURLOPT_VERBOSE, true);
            curl_setopt($ch, CURLOPT_STDERR, fopen('php://stderr', 'w'));
            fwrite(STDERR, "\n[CurlHttpClient] {$method} {$url}\n");
        }

        $this->applyMethod($ch, $method, $body, $headers);
        $this->applyCertificate($ch, $cert);
        $this->applyHeaders($ch, $headers);

        $rawBody = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);

        curl_close($ch);

        if ($curlErrno !== 0) {
            throw new ApiException(
                "Erro cURL: {$curlError}",
                0,
                ['curl_errno' => $curlErrno, 'curl_error' => $curlError]
            );
        }

        if ($rawResponse) {
            return [
                'statusCode' => $httpCode,
                'body' => null,
                'rawBody' => $rawBody,
            ];
        }

        $decodedBody = json_decode($rawBody, true);

        if ($httpCode >= 400) {
            $message = $this->extractErrorMessage($decodedBody, $rawBody, $httpCode);
            throw new ApiException($message, $httpCode, $decodedBody ?? []);
        }

        return [
            'statusCode' => $httpCode,
            'body' => $decodedBody,
            'rawBody' => $rawBody,
        ];
    }

    /**
     * @param resource $ch
     */
    private function applyMethod($ch, string $method, array $body, array &$headers): void
    {
        $method = strtoupper($method);

        switch ($method) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                $this->applyBody($ch, $body, $headers);
                break;

            case 'PATCH':
            case 'PUT':
            case 'DELETE':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
                if (!empty($body)) {
                    $this->applyBody($ch, $body, $headers);
                }
                break;

            case 'GET':
            default:
                curl_setopt($ch, CURLOPT_HTTPGET, true);
                break;
        }
    }

    /**
     * @param resource $ch
     */
    private function applyBody($ch, array $body, array &$headers): void
    {
        $isJson = $this->hasJsonContentType($headers);

        if ($isJson) {
            $encoded = json_encode($body);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $encoded);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
        }
    }

    /**
     * @param resource $ch
     */
    private function applyCertificate($ch, array $cert): void
    {
        if (empty($cert)) {
            return;
        }
        if (!empty($cert['certFile'])) {
            curl_setopt($ch, CURLOPT_SSLCERT, $cert['certFile']);
        }

        if (!empty($cert['certKeyFile'])) {
            curl_setopt($ch, CURLOPT_SSLKEY, $cert['certKeyFile']);
        }

        if (!empty($cert['certKeyPassword'])) {
            curl_setopt($ch, CURLOPT_SSLKEYPASSWD, $cert['certKeyPassword']);
        }

        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    }

    /**
     * @param resource $ch
     */
    private function applyHeaders($ch, array $headers): void
    {
        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }
    }

    private function hasJsonContentType(array $headers): bool
    {
        foreach ($headers as $header) {
            if (stripos($header, 'Content-Type: application/json') !== false) {
                return true;
            }
        }
        return false;
    }

    private function extractErrorMessage($decodedBody, string $rawBody, int $httpCode): string
    {
        if (is_array($decodedBody)) {
            if (isset($decodedBody['error_description'])) {
                return (string) $decodedBody['error_description'];
            }
            if (isset($decodedBody['message'])) {
                return (string) $decodedBody['message'];
            }
            if (isset($decodedBody['error'])) {
                return (string) $decodedBody['error'];
            }
        }

        return "Requisicao falhou com status HTTP {$httpCode}: {$rawBody}";
    }
}
