<?php

namespace ApiBoleto\Banks\Itau;

use ApiBoleto\Contracts\AuthenticatorInterface;
use ApiBoleto\Contracts\HttpClientInterface;
use ApiBoleto\Contracts\TokenStorageInterface;
use ApiBoleto\Exceptions\AuthenticationException;

class ItauAuthenticator implements AuthenticatorInterface
{
    private const DEFAULT_TOKEN_TTL_SECONDS = 3000;

    private HttpClientInterface $httpClient;

    private TokenStorageInterface $tokenStorage;

    private string $tokenKey;

    private string $tokenUrl;

    private string $clientId;

    private string $clientSecret;

    private array $certConfig;

    private string $accessToken;

    public function __construct(
        HttpClientInterface $httpClient,
        TokenStorageInterface $tokenStorage,
        string $tokenKey,
        string $tokenUrl,
        string $clientId,
        string $clientSecret,
        array $certConfig
    ) {
        $this->httpClient = $httpClient;
        $this->tokenStorage = $tokenStorage;
        $this->tokenKey = $tokenKey;
        $this->tokenUrl = $tokenUrl;
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->certConfig = $certConfig;
        $this->accessToken = '';
    }

    public function authenticate(): string
    {
        try {
            $response = $this->httpClient->request('POST', $this->tokenUrl, [
                'headers' => [
                    'Content-Type: application/x-www-form-urlencoded',
                    'x-itau-flowID: ' . $this->uuid(),
                    'x-itau-correlationID: ' . $this->uuid(),
                ],
                'body' => [
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                ],
                'cert' => $this->certConfig,
            ]);
        } catch (\Throwable $e) {
            throw new AuthenticationException(
                'Falha na autenticacao com o Itau: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }

        $body = $response['body'] ?? [];

        if (!isset($body['access_token'])) {
            throw new AuthenticationException(
                'Resposta de autenticacao do Itau nao contem access_token'
            );
        }

        $this->accessToken = (string) $body['access_token'];
        $ttl = $this->resolveTtl($body);
        $this->tokenStorage->set($this->tokenKey, $this->accessToken, $ttl);

        return $this->accessToken;
    }

    public function getToken(): string
    {
        if ($this->accessToken !== '') {
            return $this->accessToken;
        }

        $cachedToken = $this->tokenStorage->get($this->tokenKey);
        if ($cachedToken !== null) {
            $this->accessToken = $cachedToken;
            return $this->accessToken;
        }

        return $this->authenticate();
    }

    public function isTokenExpired(): bool
    {
        return $this->tokenStorage->get($this->tokenKey) === null;
    }

    private function resolveTtl(array $body): int
    {
        $expiresIn = (int) ($body['expires_in'] ?? self::DEFAULT_TOKEN_TTL_SECONDS);

        if ($expiresIn <= 120) {
            return max(1, $expiresIn);
        }

        return $expiresIn - 60;
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
