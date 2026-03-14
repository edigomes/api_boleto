<?php

namespace ApiBoleto\Tests\Unit\Storage;

use ApiBoleto\Storage\FileTokenStorage;
use PHPUnit\Framework\TestCase;

class FileTokenStorageTest extends TestCase
{
    private string $tempDir;
    private FileTokenStorage $storage;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/api_boleto_test_' . uniqid();
        mkdir($this->tempDir, 0755, true);
        $this->storage = new FileTokenStorage($this->tempDir);
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                unlink($file);
            }
        }
        if (is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
    }

    public function testSetAndGet(): void
    {
        $this->storage->set('tenant_1_token', 'abc123', 3600);
        $result = $this->storage->get('tenant_1_token');

        $this->assertSame('abc123', $result);
    }

    public function testGetRetornaNullQuandoNaoExiste(): void
    {
        $this->assertNull($this->storage->get('nao_existe'));
    }

    public function testDelete(): void
    {
        $this->storage->set('token_del', 'xyz', 3600);
        $this->assertSame('xyz', $this->storage->get('token_del'));

        $this->storage->delete('token_del');
        $this->assertNull($this->storage->get('token_del'));
    }

    public function testTokenExpirado(): void
    {
        $this->storage->set('token_exp', 'old-token', 1);

        sleep(2);

        $this->assertNull($this->storage->get('token_exp'));
    }

    public function testMultiplosTokensIsolados(): void
    {
        $this->storage->set('tenant_A', 'token-A', 3600);
        $this->storage->set('tenant_B', 'token-B', 3600);

        $this->assertSame('token-A', $this->storage->get('tenant_A'));
        $this->assertSame('token-B', $this->storage->get('tenant_B'));

        $this->storage->delete('tenant_A');

        $this->assertNull($this->storage->get('tenant_A'));
        $this->assertSame('token-B', $this->storage->get('tenant_B'));
    }

    public function testSobrescreveTokenExistente(): void
    {
        $this->storage->set('tk', 'v1', 3600);
        $this->assertSame('v1', $this->storage->get('tk'));

        $this->storage->set('tk', 'v2', 3600);
        $this->assertSame('v2', $this->storage->get('tk'));
    }

    public function testCriaDiretorioAutomaticamente(): void
    {
        $newDir = $this->tempDir . '/sub/dir';
        $storage = new FileTokenStorage($newDir);
        $storage->set('auto', 'created', 3600);

        $this->assertSame('created', $storage->get('auto'));

        // cleanup
        $files = glob($newDir . '/*');
        if ($files !== false) {
            foreach ($files as $f) {
                unlink($f);
            }
        }
        rmdir($newDir);
        rmdir($this->tempDir . '/sub');
    }
}
