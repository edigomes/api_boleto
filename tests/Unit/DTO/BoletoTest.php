<?php

namespace ApiBoleto\Tests\Unit\DTO;

use ApiBoleto\DTO\Beneficiario;
use ApiBoleto\DTO\Boleto;
use ApiBoleto\DTO\BoletoResponse;
use ApiBoleto\DTO\Desconto;
use ApiBoleto\DTO\Juros;
use ApiBoleto\DTO\Multa;
use ApiBoleto\DTO\Pagador;
use PHPUnit\Framework\TestCase;

class BoletoTest extends TestCase
{
    public function testFromArrayComDadosCompletos(): void
    {
        $data = [
            'valor'          => '250.50',
            'vencimento'     => '2026-05-01',
            'emissao'        => '2026-03-12',
            'nossoNumero'    => 'ABC123',
            'seuNumero'      => 'SEU456',
            'codigoConvenio' => '9999999',
            'tipoDocumento'  => 'DUPLICATA_MERCANTIL',
            'mensagens'      => ['Msg 1', 'Msg 2'],
            'tipoProtesto'   => 'PROTESTAR',
            'diasProtesto'   => 5,
            'diasBaixa'      => 30,
            'valorDeducao'   => '10.00',
            'dadosExtras'    => ['environment' => 'PRODUCAO'],
            'pagador' => [
                'nome'          => 'Joao Silva',
                'tipoDocumento' => 'CPF',
                'documento'     => '12345678900',
                'endereco'      => 'Rua A, 1',
                'bairro'        => 'Centro',
                'cidade'        => 'SP',
                'estado'        => 'SP',
                'cep'           => '01000-000',
            ],
            'beneficiario' => [
                'nome'          => 'Empresa X',
                'tipoDocumento' => 'CNPJ',
                'documento'     => '12345678000100',
            ],
            'desconto' => [
                'tipo'      => 'VALOR_DATA_FIXA',
                'desconto1' => ['valor' => 5.0, 'dataLimite' => '2026-04-20'],
            ],
            'multa' => [
                'percentual'        => '2.00',
                'diasAposVencimento' => 1,
            ],
            'juros' => [
                'percentual' => '1.00',
            ],
        ];

        $boleto = Boleto::fromArray($data);

        $this->assertSame('250.50', $boleto->valor);
        $this->assertSame('2026-05-01', $boleto->vencimento);
        $this->assertSame('2026-03-12', $boleto->emissao);
        $this->assertSame('ABC123', $boleto->nossoNumero);
        $this->assertSame('SEU456', $boleto->seuNumero);
        $this->assertSame('9999999', $boleto->codigoConvenio);
        $this->assertSame('DUPLICATA_MERCANTIL', $boleto->tipoDocumento);
        $this->assertSame(['Msg 1', 'Msg 2'], $boleto->mensagens);
        $this->assertSame('PROTESTAR', $boleto->tipoProtesto);
        $this->assertSame(5, $boleto->diasProtesto);
        $this->assertSame(30, $boleto->diasBaixa);
        $this->assertSame('10.00', $boleto->valorDeducao);

        $this->assertInstanceOf(Pagador::class, $boleto->pagador);
        $this->assertSame('Joao Silva', $boleto->pagador->nome);
        $this->assertSame('12345678900', $boleto->pagador->documento);

        $this->assertInstanceOf(Beneficiario::class, $boleto->beneficiario);
        $this->assertSame('Empresa X', $boleto->beneficiario->nome);
        $this->assertSame('CNPJ', $boleto->beneficiario->tipoDocumento);

        $this->assertInstanceOf(Desconto::class, $boleto->desconto);
        $this->assertSame('VALOR_DATA_FIXA', $boleto->desconto->tipo);

        $this->assertInstanceOf(Multa::class, $boleto->multa);
        $this->assertSame('2.00', $boleto->multa->percentual);

        $this->assertInstanceOf(Juros::class, $boleto->juros);
        $this->assertSame('1.00', $boleto->juros->percentual);
    }

    public function testFromArrayComDefaults(): void
    {
        $boleto = Boleto::fromArray([]);

        $this->assertSame('0', $boleto->valor);
        $this->assertSame('', $boleto->vencimento);
        $this->assertSame('SEM_PROTESTO', $boleto->tipoProtesto);
        $this->assertSame(0, $boleto->diasProtesto);
        $this->assertSame(0, $boleto->diasBaixa);
        $this->assertSame('0', $boleto->valorDeducao);
        $this->assertSame([], $boleto->mensagens);
        $this->assertNull($boleto->desconto);
        $this->assertNull($boleto->multa);
        $this->assertNull($boleto->juros);
        $this->assertInstanceOf(Pagador::class, $boleto->pagador);
        $this->assertInstanceOf(Beneficiario::class, $boleto->beneficiario);
    }

    public function testToArrayRoundTrip(): void
    {
        $data = [
            'valor'          => '100.00',
            'vencimento'     => '2026-06-01',
            'emissao'        => '2026-03-12',
            'nossoNumero'    => 'N123',
            'seuNumero'      => 'SN789',
            'codigoConvenio' => '7777',
            'tipoDocumento'  => 'RECIBO',
            'mensagens'      => ['Teste'],
            'tipoProtesto'   => 'SEM_PROTESTO',
            'diasProtesto'   => 0,
            'diasBaixa'      => 15,
            'valorDeducao'   => '0',
            'dadosExtras'    => [],
            'pagador' => [
                'nome'          => 'Maria',
                'tipoDocumento' => 'CPF',
                'documento'     => '99988877766',
                'endereco'      => 'Rua B',
                'bairro'        => 'Bairro',
                'cidade'        => 'RJ',
                'estado'        => 'RJ',
                'cep'           => '20000-000',
            ],
            'beneficiario' => [
                'nome'          => 'Loja Y',
                'tipoDocumento' => 'CNPJ',
                'documento'     => '00111222000133',
            ],
        ];

        $boleto = Boleto::fromArray($data);
        $array = $boleto->toArray();

        $this->assertSame('100.00', $array['valor']);
        $this->assertSame('2026-06-01', $array['vencimento']);
        $this->assertSame('Maria', $array['pagador']['nome']);
        $this->assertSame('Loja Y', $array['beneficiario']['nome']);
        $this->assertArrayNotHasKey('desconto', $array);
        $this->assertArrayNotHasKey('multa', $array);
        $this->assertArrayNotHasKey('juros', $array);
    }

    public function testFromArrayComInstanciasDeDtoExistentes(): void
    {
        $pagador = new Pagador('Jose', 'CPF', '111', 'Rua', 'B', 'C', 'SP', '00000');
        $beneficiario = new Beneficiario('Emp', 'CNPJ', '222');
        $multa = new Multa('5.00', 2);

        $boleto = Boleto::fromArray([
            'valor'        => '50.00',
            'pagador'      => $pagador,
            'beneficiario' => $beneficiario,
            'multa'        => $multa,
        ]);

        $this->assertSame($pagador, $boleto->pagador);
        $this->assertSame($beneficiario, $boleto->beneficiario);
        $this->assertSame($multa, $boleto->multa);
    }

    public function testBoletoResponseFromArray(): void
    {
        $data = [
            'id'             => 'abc-123',
            'nossoNumero'    => 'N999',
            'codigoBarras'   => '23793.38128 60000.000003 00000.000400 1 84340000010000',
            'linhaDigitavel' => '23793381286000000000300000004001843400001000',
            'status'         => 'OPEN',
            'valor'          => '100.00',
            'vencimento'     => '2026-04-15',
            'urlPdf'         => 'https://example.com/boleto.pdf',
            'dadosOriginais' => ['extra' => true],
        ];

        $response = BoletoResponse::fromArray($data);

        $this->assertSame('abc-123', $response->id);
        $this->assertSame('N999', $response->nossoNumero);
        $this->assertSame('OPEN', $response->status);
        $this->assertSame('100.00', $response->valor);
        $this->assertSame(['extra' => true], $response->dadosOriginais);
    }

    public function testPagadorFromArrayEToArray(): void
    {
        $pagador = Pagador::fromArray([
            'nome'          => 'Carlos',
            'tipoDocumento' => 'CNPJ',
            'documento'     => '12345678000100',
            'endereco'      => 'Av Brasil',
            'bairro'        => 'Centro',
            'cidade'        => 'BH',
            'estado'        => 'MG',
            'cep'           => '30000-000',
        ]);

        $this->assertSame('Carlos', $pagador->nome);
        $this->assertSame('CNPJ', $pagador->tipoDocumento);

        $array = $pagador->toArray();
        $this->assertSame('Carlos', $array['nome']);
        $this->assertSame('30000-000', $array['cep']);
    }
}
