<?php

namespace ApiBoleto\Http;

use ApiBoleto\Exceptions\BoletoException;

/**
 * Resolve certificados mTLS para caminhos de arquivo compativeis com cURL.
 *
 * Aceita duas formas de configuracao:
 *   - Paths: 'certFile' e 'certKeyFile' (caminhos existentes no disco)
 *   - Conteudo: 'certContent' e 'certKeyContent' (PEM em string/binario)
 *
 * Quando recebe conteudo em string, cria arquivos temporarios seguros
 * e os limpa automaticamente ao destruir a instancia.
 */
class CertificateHelper
{
    /** @var string Caminho do certificado (final, compativel com cURL) */
    private string $certFile = '';

    /** @var string Caminho da chave privada (final, compativel com cURL) */
    private string $certKeyFile = '';

    /** @var string Senha da chave privada */
    private string $certKeyPassword = '';

    /** @var string[] Caminhos de arquivos temporarios criados (para limpeza) */
    private array $tempFiles = [];

    public function __construct(array $config)
    {
        $this->certKeyPassword = $config['certKeyPassword'] ?? '';
        $this->resolve($config);
    }

    /**
     * Retorna o array de cert config pronto para o CurlHttpClient.
     */
    public function toCertConfig(): array
    {
        return [
            'certFile'        => $this->certFile,
            'certKeyFile'     => $this->certKeyFile,
            'certKeyPassword' => $this->certKeyPassword,
        ];
    }

    /**
     * Retorna o caminho do certificado.
     */
    public function getCertFile(): string
    {
        return $this->certFile;
    }

    /**
     * Retorna o caminho da chave privada.
     */
    public function getCertKeyFile(): string
    {
        return $this->certKeyFile;
    }

    /**
     * Remove os arquivos temporarios criados.
     * Chamado automaticamente no __destruct, mas pode ser chamado manualmente.
     */
    public function cleanup(): void
    {
        foreach ($this->tempFiles as $file) {
            if (file_exists($file)) {
                @unlink($file);
            }
        }
        $this->tempFiles = [];
    }

    public function __destruct()
    {
        $this->cleanup();
    }

    private function resolve(array $config): void
    {
        $hasPaths = !empty($config['certFile']) && !empty($config['certKeyFile']);
        $hasContent = !empty($config['certContent']) && !empty($config['certKeyContent']);

        if (!$hasPaths && !$hasContent) {
            throw new BoletoException(
                "Certificado mTLS obrigatorio. Informe 'certFile'+'certKeyFile' (paths) "
                . "ou 'certContent'+'certKeyContent' (conteudo PEM em string)."
            );
        }

        if ($hasPaths) {
            $this->certFile = $config['certFile'];
            $this->certKeyFile = $config['certKeyFile'];
            return;
        }

        $this->certFile = $this->createTempFile($config['certContent'], 'cert');
        $this->certKeyFile = $this->createTempFile($config['certKeyContent'], 'key');
    }

    private function createTempFile(string $content, string $prefix): string
    {
        $tmpDir = sys_get_temp_dir();
        $path = tempnam($tmpDir, "apiboleto_{$prefix}_");

        if ($path === false) {
            throw new BoletoException(
                "Nao foi possivel criar arquivo temporario para certificado em '{$tmpDir}'."
            );
        }

        $written = file_put_contents($path, $content);
        if ($written === false) {
            @unlink($path);
            throw new BoletoException(
                "Nao foi possivel escrever certificado no arquivo temporario '{$path}'."
            );
        }

        chmod($path, 0600);
        $this->tempFiles[] = $path;

        return $path;
    }
}
