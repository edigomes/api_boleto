<?php

namespace ApiBoleto\Tests\Unit\Http;

use ApiBoleto\Exceptions\BoletoException;
use ApiBoleto\Http\CertificateHelper;
use PHPUnit\Framework\TestCase;

class CertificateHelperTest extends TestCase
{
    public function testResolveComPaths(): void
    {
        $helper = new CertificateHelper([
            'certFile'        => '/fake/cert.pem',
            'certKeyFile'     => '/fake/key.pem',
            'certKeyPassword' => 'senha123',
        ]);

        $config = $helper->toCertConfig();

        $this->assertSame('/fake/cert.pem', $config['certFile']);
        $this->assertSame('/fake/key.pem', $config['certKeyFile']);
        $this->assertSame('senha123', $config['certKeyPassword']);
        $this->assertSame('/fake/cert.pem', $helper->getCertFile());
        $this->assertSame('/fake/key.pem', $helper->getCertKeyFile());
    }

    public function testResolveComConteudoCriaTempFiles(): void
    {
        $certPem = "-----BEGIN CERTIFICATE-----\nFAKECERTDATA\n-----END CERTIFICATE-----";
        $keyPem = "-----BEGIN PRIVATE KEY-----\nFAKEKEYDATA\n-----END PRIVATE KEY-----";

        $helper = new CertificateHelper([
            'certContent'     => $certPem,
            'certKeyContent'  => $keyPem,
            'certKeyPassword' => '',
        ]);

        $config = $helper->toCertConfig();

        $this->assertFileExists($config['certFile']);
        $this->assertFileExists($config['certKeyFile']);
        $this->assertSame($certPem, file_get_contents($config['certFile']));
        $this->assertSame($keyPem, file_get_contents($config['certKeyFile']));

        $certPath = $config['certFile'];
        $keyPath = $config['certKeyFile'];

        $helper->cleanup();

        $this->assertFileDoesNotExist($certPath);
        $this->assertFileDoesNotExist($keyPath);
    }

    public function testDestructLimpaTempFiles(): void
    {
        $helper = new CertificateHelper([
            'certContent'    => 'CERT-DATA',
            'certKeyContent' => 'KEY-DATA',
        ]);

        $certPath = $helper->getCertFile();
        $keyPath = $helper->getCertKeyFile();

        $this->assertFileExists($certPath);
        $this->assertFileExists($keyPath);

        unset($helper);

        $this->assertFileDoesNotExist($certPath);
        $this->assertFileDoesNotExist($keyPath);
    }

    public function testTempFilesTemPermissao600(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Permissoes de arquivo nao se aplicam no Windows.');
        }

        $helper = new CertificateHelper([
            'certContent'    => 'CERT-DATA',
            'certKeyContent' => 'KEY-DATA',
        ]);

        $perms = fileperms($helper->getCertFile()) & 0777;
        $this->assertSame(0600, $perms);

        $helper->cleanup();
    }

    public function testPathsTemPrioridadeSobreContent(): void
    {
        $helper = new CertificateHelper([
            'certFile'       => '/path/cert.pem',
            'certKeyFile'    => '/path/key.pem',
            'certContent'    => 'CERT-DATA',
            'certKeyContent' => 'KEY-DATA',
        ]);

        $this->assertSame('/path/cert.pem', $helper->getCertFile());
        $this->assertSame('/path/key.pem', $helper->getCertKeyFile());
    }

    public function testSemNenhumCertificadoLancaException(): void
    {
        $this->expectException(BoletoException::class);
        $this->expectExceptionMessage('Certificado mTLS obrigatorio');

        new CertificateHelper([]);
    }

    public function testApenasContentParcialLancaException(): void
    {
        $this->expectException(BoletoException::class);
        $this->expectExceptionMessage('Certificado mTLS obrigatorio');

        new CertificateHelper([
            'certContent' => 'CERT-DATA',
        ]);
    }

    public function testCleanupDuploNaoLancaErro(): void
    {
        $helper = new CertificateHelper([
            'certContent'    => 'CERT-DATA',
            'certKeyContent' => 'KEY-DATA',
        ]);

        $helper->cleanup();
        $helper->cleanup();

        $this->assertTrue(true);
    }
}
