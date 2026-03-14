<?php

namespace ApiBoleto\Storage;

use ApiBoleto\Contracts\TokenStorageInterface;

/**
 * Armazena tokens em arquivos no disco.
 * Cada chave gera um arquivo separado no diretorio configurado.
 */
class FileTokenStorage implements TokenStorageInterface
{
    /** @var string Diretorio base para armazenar os arquivos de token */
    private string $directory;

    /**
     * @param string $directory Diretorio onde os arquivos de token serao salvos.
     *                          Se nao existir, sera criado automaticamente.
     */
    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '/\\');
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $key): ?string
    {
        $filePath = $this->buildPath($key);

        if (!file_exists($filePath)) {
            return null;
        }

        $meta = $this->readMeta($filePath);
        if ($meta === null || $this->isExpired($meta)) {
            $this->delete($key);
            return null;
        }

        $token = file_get_contents($filePath . '.token');
        if ($token === false || $token === '') {
            return null;
        }

        return $token;
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, string $token, int $ttl): void
    {
        $this->ensureDirectory();

        $filePath = $this->buildPath($key);

        $meta = [
            'created_at' => time(),
            'ttl' => $ttl,
        ];

        file_put_contents($filePath, json_encode($meta));
        file_put_contents($filePath . '.token', $token);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key): void
    {
        $filePath = $this->buildPath($key);

        if (file_exists($filePath)) {
            unlink($filePath);
        }
        if (file_exists($filePath . '.token')) {
            unlink($filePath . '.token');
        }
    }

    private function buildPath(string $key): string
    {
        $safeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);
        return $this->directory . DIRECTORY_SEPARATOR . $safeKey;
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory)) {
            mkdir($this->directory, 0755, true);
        }
    }

    /**
     * @return array|null
     */
    private function readMeta(string $filePath): ?array
    {
        $contents = file_get_contents($filePath);
        if ($contents === false) {
            return null;
        }

        $meta = json_decode($contents, true);
        if (!is_array($meta) || !isset($meta['created_at'], $meta['ttl'])) {
            return null;
        }

        return $meta;
    }

    private function isExpired(array $meta): bool
    {
        return (time() - $meta['created_at']) > $meta['ttl'];
    }
}
