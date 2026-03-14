<?php

namespace ApiBoleto\Banks\Santander;

use ApiBoleto\Contracts\AuthenticatorInterface;
use ApiBoleto\Contracts\HttpClientInterface;
use ApiBoleto\Contracts\TokenStorageInterface;
use ApiBoleto\Exceptions\AuthenticationException;

class SantanderAuthenticator implements AuthenticatorInterface
{
    private const TOKEN_TTL_SECONDS = 720;

    /** @var HttpClientInterface */
    private HttpClientInterface $httpClient;

    /** @var TokenStorageInterface */
    private TokenStorageInterface $tokenStorage;

    /** @var string Chave unica para armazenar o token (ex: "santander_tenant_42") */
    private string $tokenKey;

    /** @var string */
    private string $baseUrl;

    /** @var string */
    private string $clientId;

    /** @var string */
    private string $clientSecret;

    /** @var array Configuracao do certificado mTLS */
    private array $certConfig;

    /** @var string Token em memoria */
    private string $accessToken;

    public function __construct(
        HttpClientInterface $httpClient,
        TokenStorageInterface $tokenStorage,
        string $tokenKey,
        string $baseUrl,
        string $clientId,
        string $clientSecret,
        array $certConfig
    ) {
        $this->httpClient = $httpClient;
        $this->tokenStorage = $tokenStorage;
        $this->tokenKey = $tokenKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->certConfig = $certConfig;
        $this->accessToken = '';
    }

    /**
     * {@inheritdoc}
     */
    public function authenticate(): string
    {
        $url = $this->baseUrl . '/auth/oauth/v2/token';

        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => ['Content-Type: application/x-www-form-urlencoded'],
                'body' => [
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    'grant_type' => 'client_credentials',
                ],
                'cert' => $this->certConfig,
            ]);
        } catch (\Throwable $e) {
            throw new AuthenticationException(
                'Falha na autenticacao com o Santander: ' . $e->getMessage(),
                (int) $e->getCode(),
                $e
            );
        }

        $body = $response['body'] ?? [];

        if (!isset($body['access_token'])) {
            throw new AuthenticationException(
                'Resposta de autenticacao do Santander nao contem access_token'
            );
        }

        $this->accessToken = $body['access_token'];
        $this->tokenStorage->set($this->tokenKey, $this->accessToken, self::TOKEN_TTL_SECONDS);

        return $this->accessToken;
    }

    /**
     * {@inheritdoc}
     */
    public function getToken(): string
    {
        if (!empty($this->accessToken)) {
            return $this->accessToken;
        }

        $cachedToken = $this->tokenStorage->get($this->tokenKey);
        if ($cachedToken !== null) {
            $this->accessToken = $cachedToken;
            return $this->accessToken;
        }

        return $this->authenticate();
    }

    /**
     * {@inheritdoc}
     */
    public function isTokenExpired(): bool
    {
        return $this->tokenStorage->get($this->tokenKey) === null;
    }
}
