<?php

namespace ApiBoleto\Tests\Fake;

use ApiBoleto\Contracts\TokenStorageInterface;

class FakeTokenStorage implements TokenStorageInterface
{
    /** @var array<string, array{token: string, expires_at: int}> */
    private array $tokens = [];

    public function get(string $key): ?string
    {
        if (!isset($this->tokens[$key])) {
            return null;
        }

        if (time() > $this->tokens[$key]['expires_at']) {
            unset($this->tokens[$key]);
            return null;
        }

        return $this->tokens[$key]['token'];
    }

    public function set(string $key, string $token, int $ttl): void
    {
        $this->tokens[$key] = [
            'token' => $token,
            'expires_at' => time() + $ttl,
        ];
    }

    public function delete(string $key): void
    {
        unset($this->tokens[$key]);
    }

    /**
     * Retorna todos os tokens armazenados (para debug/assertions).
     */
    public function all(): array
    {
        return $this->tokens;
    }
}
