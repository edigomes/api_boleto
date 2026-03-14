<?php

namespace ApiBoleto\Contracts;

/**
 * Abstrai o armazenamento de tokens de autenticacao.
 *
 * Permite diferentes backends (arquivo, Redis, cache Laravel, banco de dados, etc.)
 * Essencial para ambientes multi-tenant onde cada tenant tem seu proprio token.
 */
interface TokenStorageInterface
{
    /**
     * Recupera o token armazenado para a chave informada.
     *
     * @param string $key Identificador unico (ex: "santander_token_tenant_42")
     * @return string|null Token ou null se nao existir/expirado
     */
    public function get(string $key): ?string;

    /**
     * Armazena um token com TTL em segundos.
     *
     * @param string $key
     * @param string $token
     * @param int $ttl Tempo de vida em segundos
     */
    public function set(string $key, string $token, int $ttl): void;

    /**
     * Remove o token armazenado.
     *
     * @param string $key
     */
    public function delete(string $key): void;
}
