<?php

namespace ApiBoleto\Tests\Unit\Config;

use ApiBoleto\Config\ConfigSchema;
use ApiBoleto\Exceptions\BoletoException;
use PHPUnit\Framework\TestCase;

class ConfigSchemaTest extends TestCase
{
    public function testValidaCamposObrigatorios(): void
    {
        $schema = ConfigSchema::create('TestBank')
            ->required('clientId', 'string', 'Client ID')
            ->required('apiKey', 'string', 'API Key');

        $this->expectException(BoletoException::class);
        $this->expectExceptionMessage('clientId');

        $schema->validate(['apiKey' => 'abc']);
    }

    public function testValidaCamposObrigatoriosVazios(): void
    {
        $schema = ConfigSchema::create('TestBank')
            ->required('clientId', 'string', 'Client ID');

        $this->expectException(BoletoException::class);
        $this->expectExceptionMessage('clientId');

        $schema->validate(['clientId' => '']);
    }

    public function testValidaOneOfGroupPrimeiroSet(): void
    {
        $schema = ConfigSchema::create('TestBank')
            ->required('id', 'string', 'ID')
            ->requireOneOf('cert', [
                ['certFile', 'certKeyFile'],
                ['certContent', 'certKeyContent'],
            ], 'Certificado obrigatorio');

        $schema->validate([
            'id'          => 'test',
            'certFile'    => '/path/cert.pem',
            'certKeyFile' => '/path/key.pem',
        ]);

        $this->assertTrue(true);
    }

    public function testValidaOneOfGroupSegundoSet(): void
    {
        $schema = ConfigSchema::create('TestBank')
            ->required('id', 'string', 'ID')
            ->requireOneOf('cert', [
                ['certFile', 'certKeyFile'],
                ['certContent', 'certKeyContent'],
            ], 'Certificado obrigatorio');

        $schema->validate([
            'id'             => 'test',
            'certContent'    => 'PEM-DATA',
            'certKeyContent' => 'KEY-DATA',
        ]);

        $this->assertTrue(true);
    }

    public function testFalhaOneOfGroupNenhumSetCompleto(): void
    {
        $schema = ConfigSchema::create('TestBank')
            ->required('id', 'string', 'ID')
            ->requireOneOf('cert', [
                ['certFile', 'certKeyFile'],
                ['certContent', 'certKeyContent'],
            ], 'Certificado obrigatorio');

        $this->expectException(BoletoException::class);
        $this->expectExceptionMessage('Certificado obrigatorio');

        $schema->validate([
            'id'       => 'test',
            'certFile' => '/path/cert.pem',
        ]);
    }

    public function testValidaTipoString(): void
    {
        $schema = ConfigSchema::create('TestBank')
            ->required('name', 'string', 'Nome');

        $this->expectException(BoletoException::class);
        $this->expectExceptionMessage("tipo 'string'");

        $schema->validate(['name' => 123]);
    }

    public function testValidaTipoInt(): void
    {
        $schema = ConfigSchema::create('TestBank')
            ->required('count', 'int', 'Contagem');

        $this->expectException(BoletoException::class);
        $this->expectExceptionMessage("tipo 'int'");

        $schema->validate(['count' => 'abc']);
    }

    public function testValidaTipoBool(): void
    {
        $schema = ConfigSchema::create('TestBank')
            ->required('active', 'bool', 'Ativo');

        $this->expectException(BoletoException::class);
        $this->expectExceptionMessage("tipo 'bool'");

        $schema->validate(['active' => 'yes']);
    }

    public function testValidaTipoArray(): void
    {
        $schema = ConfigSchema::create('TestBank')
            ->required('items', 'array', 'Itens');

        $this->expectException(BoletoException::class);
        $this->expectExceptionMessage("tipo 'array'");

        $schema->validate(['items' => 'not-array']);
    }

    public function testCamposOpcionaisNaoFalham(): void
    {
        $schema = ConfigSchema::create('TestBank')
            ->required('id', 'string', 'ID')
            ->optional('ambiente', 'string', 'producao', 'Ambiente')
            ->optional('debug', 'bool', false, 'Debug');

        $schema->validate(['id' => 'test']);

        $this->assertTrue(true);
    }

    public function testCampoOpcionalComTipoErradoFalha(): void
    {
        $schema = ConfigSchema::create('TestBank')
            ->required('id', 'string', 'ID')
            ->optional('timeout', 'int', 30, 'Timeout');

        $this->expectException(BoletoException::class);
        $this->expectExceptionMessage("tipo 'int'");

        $schema->validate(['id' => 'test', 'timeout' => 'abc']);
    }

    public function testMultiplosErrosSaoAgregados(): void
    {
        $schema = ConfigSchema::create('TestBank')
            ->required('clientId', 'string', 'Client ID')
            ->required('clientSecret', 'string', 'Client Secret')
            ->requireOneOf('cert', [
                ['certFile', 'certKeyFile'],
            ], 'Certificado obrigatorio');

        try {
            $schema->validate([]);
            $this->fail('Deveria ter lancado BoletoException');
        } catch (BoletoException $e) {
            $this->assertStringContainsString('clientId', $e->getMessage());
            $this->assertStringContainsString('clientSecret', $e->getMessage());
            $this->assertStringContainsString('Certificado obrigatorio', $e->getMessage());
        }
    }

    public function testMensagemErroContemNomeDoBanco(): void
    {
        $schema = ConfigSchema::create('MeuBanco')
            ->required('key', 'string', 'Key');

        try {
            $schema->validate([]);
            $this->fail('Deveria ter lancado BoletoException');
        } catch (BoletoException $e) {
            $this->assertStringContainsString('MeuBanco', $e->getMessage());
        }
    }

    public function testDescribeRetornaTodosCampos(): void
    {
        $schema = ConfigSchema::create('TestBank')
            ->required('clientId', 'string', 'Client ID')
            ->optional('ambiente', 'string', 'producao', 'Ambiente')
            ->requireOneOf('cert', [
                ['certFile', 'certKeyFile'],
            ], 'Certificado');

        $desc = $schema->describe();

        $this->assertArrayHasKey('clientId', $desc);
        $this->assertTrue($desc['clientId']['required']);
        $this->assertSame('string', $desc['clientId']['type']);
        $this->assertSame('Client ID', $desc['clientId']['label']);

        $this->assertArrayHasKey('ambiente', $desc);
        $this->assertFalse($desc['ambiente']['required']);
        $this->assertSame('producao', $desc['ambiente']['default']);

        $this->assertArrayHasKey('certFile', $desc);
        $this->assertArrayHasKey('certKeyFile', $desc);
    }

    public function testGetters(): void
    {
        $schema = ConfigSchema::create('TestBank')
            ->required('a', 'string', 'A')
            ->optional('b', 'string', '', 'B')
            ->requireOneOf('group1', [['x', 'y']], 'Msg');

        $this->assertSame('TestBank', $schema->getBanco());
        $this->assertSame(['a'], $schema->getRequiredFields());
        $this->assertSame(['b'], $schema->getOptionalFields());
        $this->assertSame(['group1'], $schema->getOneOfGroups());
    }

    public function testConfigValidaComSucesso(): void
    {
        $schema = ConfigSchema::create('TestBank')
            ->required('id', 'string', 'ID')
            ->required('secret', 'string', 'Secret')
            ->requireOneOf('cert', [
                ['certFile', 'certKeyFile'],
                ['certContent', 'certKeyContent'],
            ], 'Certificado obrigatorio')
            ->optional('ambiente', 'string', 'producao', 'Ambiente');

        $schema->validate([
            'id'          => 'my-id',
            'secret'      => 'my-secret',
            'certFile'    => '/path/cert.pem',
            'certKeyFile' => '/path/key.pem',
            'ambiente'    => 'sandbox',
        ]);

        $this->assertTrue(true);
    }

    public function testTipoMixedAceitaQualquerValor(): void
    {
        $schema = ConfigSchema::create('TestBank')
            ->required('data', 'mixed', 'Qualquer dado');

        $schema->validate(['data' => 'string']);
        $schema->validate(['data' => 123]);
        $schema->validate(['data' => ['array']]);
        $schema->validate(['data' => new \stdClass()]);

        $this->assertTrue(true);
    }
}
