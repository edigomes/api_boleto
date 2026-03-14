<?php

namespace ApiBoleto\Contracts;

interface AuthenticatorInterface
{
    /**
     * Realiza a autenticacao na API e retorna o access token.
     *
     * @return string
     */
    public function authenticate(): string;

    /**
     * Retorna o token em cache ou re-autentica se expirado.
     *
     * @return string
     */
    public function getToken(): string;

    /**
     * Verifica se o token atual esta expirado.
     *
     * @return bool
     */
    public function isTokenExpired(): bool;
}
