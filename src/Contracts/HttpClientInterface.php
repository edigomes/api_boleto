<?php

namespace ApiBoleto\Contracts;

interface HttpClientInterface
{
    /**
     * Executa uma requisicao HTTP.
     *
     * @param string $method GET, POST, PATCH, PUT, DELETE
     * @param string $url
     * @param array $options [
     *     'headers' => [],
     *     'body'    => [],
     *     'query'   => [],
     *     'cert'    => ['certFile' => '', 'certKeyFile' => '', 'certKeyPassword' => ''],
     *     'rawResponse' => false,
     * ]
     * @return array ['statusCode' => int, 'body' => mixed, 'rawBody' => string]
     */
    public function request(string $method, string $url, array $options = []): array;
}
