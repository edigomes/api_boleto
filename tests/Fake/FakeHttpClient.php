<?php

namespace ApiBoleto\Tests\Fake;

use ApiBoleto\Contracts\HttpClientInterface;
use ApiBoleto\Exceptions\ApiException;

class FakeHttpClient implements HttpClientInterface
{
    /** @var array[] Fila de respostas pre-configuradas */
    private array $responseQueue = [];

    /** @var array[] Historico de requests feitas */
    private array $requestHistory = [];

    /**
     * Enfileira uma resposta que sera retornada na proxima chamada a request().
     */
    public function addResponse(int $statusCode, ?array $body = null, string $rawBody = ''): void
    {
        if ($rawBody === '' && $body !== null) {
            $rawBody = json_encode($body);
        }

        $this->responseQueue[] = [
            'statusCode' => $statusCode,
            'body' => $body,
            'rawBody' => $rawBody,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function request(string $method, string $url, array $options = []): array
    {
        $this->requestHistory[] = [
            'method' => $method,
            'url' => $url,
            'options' => $options,
        ];

        if (empty($this->responseQueue)) {
            throw new \RuntimeException('FakeHttpClient: nenhuma resposta enfileirada para esta request.');
        }

        $response = array_shift($this->responseQueue);
        $statusCode = $response['statusCode'];

        if ($statusCode >= 400) {
            throw new ApiException(
                "Fake error HTTP {$statusCode}",
                $statusCode,
                $response['body'] ?? []
            );
        }

        $rawResponse = $options['rawResponse'] ?? false;
        if ($rawResponse) {
            return [
                'statusCode' => $statusCode,
                'body' => null,
                'rawBody' => $response['rawBody'],
            ];
        }

        return $response;
    }

    /**
     * Retorna o historico de todas as requests feitas.
     *
     * @return array[]
     */
    public function getRequestHistory(): array
    {
        return $this->requestHistory;
    }

    /**
     * Retorna a ultima request feita.
     */
    public function getLastRequest(): ?array
    {
        if (empty($this->requestHistory)) {
            return null;
        }
        return end($this->requestHistory);
    }

    /**
     * Retorna quantas requests foram feitas.
     */
    public function getRequestCount(): int
    {
        return count($this->requestHistory);
    }

    /**
     * Limpa o historico e a fila de respostas.
     */
    public function reset(): void
    {
        $this->responseQueue = [];
        $this->requestHistory = [];
    }
}
