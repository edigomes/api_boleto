<?php

namespace ApiBoleto\Tests\Unit\DTO;

use ApiBoleto\DTO\Desconto;
use ApiBoleto\DTO\InstrucaoBoleto;
use PHPUnit\Framework\TestCase;

class InstrucaoBoletoTest extends TestCase
{
    public function testFromArrayPreencheCampos(): void
    {
        $instrucao = InstrucaoBoleto::fromArray([
            'vencimento'         => '2026-07-01',
            'valor'              => '300.00',
            'seuNumero'          => 'REF-999',
            'valorDeducao'       => '10.00',
            'percentualMulta'    => '2.00',
            'dataMulta'          => '2026-07-02',
            'diasProtesto'       => 30,
            'diasBaixa'          => 90,
            'tipoValorPagamento' => 'VALOR',
            'valorMinimo'        => '250.00',
            'valorMaximo'        => '350.00',
            'codigoParticipante' => 'PART-123',
            'operacao'           => 'ALTER_DUE_DATE',
            'dadosExtras'        => ['customField' => 'abc'],
            'desconto'           => [
                'tipo'      => 'ISENTO',
                'desconto1' => ['valor' => '10.00', 'dataLimite' => '2026-06-25'],
            ],
        ]);

        $this->assertSame('2026-07-01', $instrucao->vencimento);
        $this->assertSame('300.00', $instrucao->valor);
        $this->assertSame('REF-999', $instrucao->seuNumero);
        $this->assertSame('10.00', $instrucao->valorDeducao);
        $this->assertSame('2.00', $instrucao->percentualMulta);
        $this->assertSame('2026-07-02', $instrucao->dataMulta);
        $this->assertSame(30, $instrucao->diasProtesto);
        $this->assertSame(90, $instrucao->diasBaixa);
        $this->assertSame('VALOR', $instrucao->tipoValorPagamento);
        $this->assertSame('250.00', $instrucao->valorMinimo);
        $this->assertSame('350.00', $instrucao->valorMaximo);
        $this->assertSame('PART-123', $instrucao->codigoParticipante);
        $this->assertSame('ALTER_DUE_DATE', $instrucao->operacao);
        $this->assertSame(['customField' => 'abc'], $instrucao->dadosExtras);
        $this->assertInstanceOf(Desconto::class, $instrucao->desconto);
        $this->assertSame('ISENTO', $instrucao->desconto->tipo);
    }

    public function testFromArrayDescontoComoInstancia(): void
    {
        $desconto = new Desconto('VALOR_DATA_FIXA');
        $instrucao = InstrucaoBoleto::fromArray([
            'desconto' => $desconto,
        ]);

        $this->assertSame($desconto, $instrucao->desconto);
    }

    public function testBaixar(): void
    {
        $instrucao = InstrucaoBoleto::baixar();

        $this->assertSame('BAIXAR', $instrucao->operacao);
        $this->assertSame('', $instrucao->vencimento);
        $this->assertSame('', $instrucao->valor);
    }

    public function testAlterarVencimento(): void
    {
        $instrucao = InstrucaoBoleto::alterarVencimento('2026-12-31');

        $this->assertSame('2026-12-31', $instrucao->vencimento);
        $this->assertSame('', $instrucao->operacao);
        $this->assertSame('', $instrucao->valor);
    }

    public function testAlterarValor(): void
    {
        $instrucao = InstrucaoBoleto::alterarValor('999.99');

        $this->assertSame('999.99', $instrucao->valor);
        $this->assertSame('', $instrucao->operacao);
        $this->assertSame('', $instrucao->vencimento);
    }

    public function testDefaultValues(): void
    {
        $instrucao = new InstrucaoBoleto();

        $this->assertSame('', $instrucao->vencimento);
        $this->assertSame('', $instrucao->valor);
        $this->assertSame('', $instrucao->seuNumero);
        $this->assertSame('', $instrucao->valorDeducao);
        $this->assertSame('', $instrucao->percentualMulta);
        $this->assertSame('', $instrucao->dataMulta);
        $this->assertSame(0, $instrucao->diasProtesto);
        $this->assertSame(0, $instrucao->diasBaixa);
        $this->assertSame('', $instrucao->tipoValorPagamento);
        $this->assertSame('', $instrucao->valorMinimo);
        $this->assertSame('', $instrucao->valorMaximo);
        $this->assertSame('', $instrucao->codigoParticipante);
        $this->assertNull($instrucao->desconto);
        $this->assertSame('', $instrucao->operacao);
        $this->assertSame([], $instrucao->dadosExtras);
    }
}
