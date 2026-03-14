<?php

namespace ApiBoleto\Tests\Unit;

use ApiBoleto\Banks\Santander\SantanderGateway;
use ApiBoleto\BoletoManager;
use ApiBoleto\Contracts\BoletoGatewayInterface;
use ApiBoleto\DTO\Boleto;
use ApiBoleto\DTO\BoletoResponse;
use ApiBoleto\DTO\InstrucaoBoleto;
use ApiBoleto\Exceptions\BoletoException;
use PHPUnit\Framework\TestCase;

class BoletoManagerTest extends TestCase
{
    public function testBancosDisponiveisIncluiSantander(): void
    {
        $manager = new BoletoManager();
        $bancos = $manager->bancosDisponiveis();

        $this->assertContains('santander', $bancos);
    }

    public function testBancoSantanderRetornaInstancia(): void
    {
        $manager = new BoletoManager();
        $gateway = $manager->banco('santander', [
            'clientId'     => 'test',
            'clientSecret' => 'test',
            'certFile'     => '/fake/cert.pem',
            'certKeyFile'  => '/fake/key.pem',
            'tokenPath'    => sys_get_temp_dir() . '/test_token_manager_' . uniqid(),
            'workspaceId'  => 'ws-test',
        ]);

        $this->assertInstanceOf(BoletoGatewayInterface::class, $gateway);
        $this->assertInstanceOf(SantanderGateway::class, $gateway);
    }

    public function testBancoNaoRegistradoLancaException(): void
    {
        $this->expectException(BoletoException::class);
        $this->expectExceptionMessage('nao registrado');

        $manager = new BoletoManager();
        $manager->banco('banco_ficticio', []);
    }

    public function testRegistrarBancoCustomizado(): void
    {
        $manager = new BoletoManager();
        $manager->registrarBanco('teste', FakeGateway::class);

        $bancos = $manager->bancosDisponiveis();
        $this->assertContains('teste', $bancos);

        $gateway = $manager->banco('teste', ['param' => 'value']);
        $this->assertInstanceOf(BoletoGatewayInterface::class, $gateway);
        $this->assertInstanceOf(FakeGateway::class, $gateway);
    }

    public function testBancoNomeCaseInsensitive(): void
    {
        $manager = new BoletoManager();
        $manager->registrarBanco('MeuBanco', FakeGateway::class);

        $gateway = $manager->banco('MEUBANCO', ['x' => '1']);
        $this->assertInstanceOf(FakeGateway::class, $gateway);
    }

    public function testBancoRetornaInstanciaCacheada(): void
    {
        $manager = new BoletoManager();
        $manager->registrarBanco('fake', FakeGateway::class);

        $g1 = $manager->banco('fake', ['init' => true]);
        $g2 = $manager->banco('fake');

        $this->assertSame($g1, $g2);
    }

    public function testBancoComNovaConfigRecriaInstancia(): void
    {
        $manager = new BoletoManager();
        $manager->registrarBanco('fake', FakeGateway::class);

        $g1 = $manager->banco('fake', ['v' => '1']);
        $g2 = $manager->banco('fake', ['v' => '2']);

        $this->assertNotSame($g1, $g2);
    }
}

/**
 * Gateway fake para testes do BoletoManager.
 */
class FakeGateway implements BoletoGatewayInterface
{
    /** @var array */
    public array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function criarBoleto(Boleto $boleto): BoletoResponse
    {
        return new BoletoResponse();
    }

    public function consultarBoleto(string $identificador): BoletoResponse
    {
        return new BoletoResponse();
    }

    public function consultarBoletos(array $filtros = []): array
    {
        return [];
    }

    public function alterarBoleto(string $identificador, InstrucaoBoleto $instrucao): BoletoResponse
    {
        return new BoletoResponse();
    }

    public function cancelarBoleto(string $identificador, ?InstrucaoBoleto $instrucao = null): bool
    {
        return true;
    }

    public function gerarPdf(string $identificador, string $payerDocumentNumber = ''): string
    {
        return '';
    }

    public function downloadPdf(string $identificador, string $payerDocumentNumber = ''): string
    {
        return '';
    }
}
