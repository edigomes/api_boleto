<?php

namespace ApiBoleto\Tests\Unit\Banks\Santander;

use ApiBoleto\Banks\Santander\SantanderMapper;
use ApiBoleto\DTO\Boleto;
use ApiBoleto\DTO\BoletoResponse;
use ApiBoleto\DTO\Desconto;
use ApiBoleto\DTO\InstrucaoBoleto;
use PHPUnit\Framework\TestCase;

class SantanderMapperTest extends TestCase
{
    private SantanderMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new SantanderMapper('producao');
    }

    public function testToApiPayloadCamposBasicos(): void
    {
        $boleto = Boleto::fromArray([
            'valor'          => '200.00',
            'vencimento'     => '2026-05-01',
            'emissao'        => '2026-03-12',
            'nossoNumero'    => '033',
            'seuNumero'      => 'CLI123',
            'codigoConvenio' => '7654321',
            'tipoDocumento'  => 'DUPLICATA_MERCANTIL',
            'pagador' => [
                'nome'          => 'Joao',
                'tipoDocumento' => 'CPF',
                'documento'     => '12345678900',
                'endereco'      => 'Rua A',
                'bairro'        => 'Centro',
                'cidade'        => 'SP',
                'estado'        => 'SP',
                'cep'           => '01000-000',
            ],
            'beneficiario' => [
                'nome'          => 'Empresa',
                'tipoDocumento' => 'CNPJ',
                'documento'     => '12345678000100',
            ],
        ]);

        $payload = $this->mapper->toApiPayload($boleto);

        $this->assertSame('PRODUCAO', $payload['environment']);
        $this->assertSame('7654321', $payload['covenantCode']);
        $this->assertSame('033', $payload['bankNumber']);
        $this->assertSame('CLI123', $payload['clientNumber']);
        $this->assertSame('033', $payload['nsuCode']);
        $this->assertSame('2026-03-12', $payload['nsuDate']);
        $this->assertSame('2026-05-01', $payload['dueDate']);
        $this->assertSame('2026-03-12', $payload['issueDate']);
        $this->assertSame('200.00', $payload['nominalValue']);
        $this->assertSame('DUPLICATA_MERCANTIL', $payload['documentKind']);

        $this->assertSame('Joao', $payload['payer']['name']);
        $this->assertSame('CPF', $payload['payer']['documentType']);
        $this->assertSame('12345678900', $payload['payer']['documentNumber']);

        $this->assertSame('Empresa', $payload['beneficiary']['name']);
        $this->assertSame('CNPJ', $payload['beneficiary']['documentType']);

        $this->assertSame('REGISTRO', $payload['paymentType']);
        $this->assertArrayNotHasKey('participantCode', $payload);
    }

    public function testNormalizaCepSemTraco(): void
    {
        $boleto = Boleto::fromArray([
            'valor'        => '100.00',
            'vencimento'   => '2026-05-01',
            'emissao'      => '2026-03-12',
            'pagador'      => [
                'nome' => 'Joao',
                'documento' => '111',
                'cep' => '01310100',
            ],
            'beneficiario' => ['nome' => 'Empresa', 'documento' => '222'],
        ]);

        $payload = $this->mapper->toApiPayload($boleto);

        $this->assertSame('01310-100', $payload['payer']['zipCode']);
    }

    public function testCepJaFormatadoNaoAltera(): void
    {
        $boleto = Boleto::fromArray([
            'valor'        => '100.00',
            'vencimento'   => '2026-05-01',
            'emissao'      => '2026-03-12',
            'pagador'      => [
                'nome' => 'Joao',
                'documento' => '111',
                'cep' => '01310-100',
            ],
            'beneficiario' => ['nome' => 'Empresa', 'documento' => '222'],
        ]);

        $payload = $this->mapper->toApiPayload($boleto);

        $this->assertSame('01310-100', $payload['payer']['zipCode']);
    }

    public function testTipoDocumentoVazioUsaDuplicataMercantil(): void
    {
        $boleto = Boleto::fromArray([
            'valor'        => '100.00',
            'vencimento'   => '2026-05-01',
            'emissao'      => '2026-03-12',
            'pagador'      => ['nome' => 'Joao', 'documento' => '111'],
            'beneficiario' => ['nome' => 'Empresa', 'documento' => '222'],
        ]);

        $payload = $this->mapper->toApiPayload($boleto);

        $this->assertSame('DUPLICATA_MERCANTIL', $payload['documentKind']);
    }

    public function testTipoDocumentoExplicitoNaoSobrescreve(): void
    {
        $boleto = Boleto::fromArray([
            'valor'          => '100.00',
            'vencimento'     => '2026-05-01',
            'emissao'        => '2026-03-12',
            'tipoDocumento'  => 'RECIBO',
            'pagador'        => ['nome' => 'Joao', 'documento' => '111'],
            'beneficiario'   => ['nome' => 'Empresa', 'documento' => '222'],
        ]);

        $payload = $this->mapper->toApiPayload($boleto);

        $this->assertSame('RECIBO', $payload['documentKind']);
    }

    public function testPaymentTypeDefaultRegistro(): void
    {
        $boleto = Boleto::fromArray([
            'valor'        => '100.00',
            'vencimento'   => '2026-05-01',
            'emissao'      => '2026-03-12',
            'pagador'      => ['nome' => 'Joao', 'documento' => '111'],
            'beneficiario' => ['nome' => 'Empresa', 'documento' => '222'],
        ]);

        $payload = $this->mapper->toApiPayload($boleto);

        $this->assertSame('REGISTRO', $payload['paymentType']);
    }

    public function testPaymentTypePodeSobrescreverViaDadosExtras(): void
    {
        $boleto = Boleto::fromArray([
            'valor'        => '100.00',
            'vencimento'   => '2026-05-01',
            'emissao'      => '2026-03-12',
            'pagador'      => ['nome' => 'Joao', 'documento' => '111'],
            'beneficiario' => ['nome' => 'Empresa', 'documento' => '222'],
            'dadosExtras'  => ['paymentType' => 'PARCELADO'],
        ]);

        $payload = $this->mapper->toApiPayload($boleto);

        $this->assertSame('PARCELADO', $payload['paymentType']);
    }

    public function testToApiPayloadDadosExtrasSobrescrevemCampos(): void
    {
        $boleto = Boleto::fromArray([
            'valor'          => '100.00',
            'vencimento'     => '2026-05-01',
            'emissao'        => '2026-03-12',
            'nossoNumero'    => '033',
            'seuNumero'      => 'MEU-REF',
            'pagador'        => ['nome' => 'X', 'documento' => '111'],
            'beneficiario'   => ['nome' => 'Y', 'documento' => '222'],
            'dadosExtras'    => [
                'bankNumber'      => '099',
                'clientNumber'    => 'OUTRO-REF',
                'nsuCode'         => 'NSU999',
                'participantCode' => 'PART-CUSTOM',
            ],
        ]);

        $payload = $this->mapper->toApiPayload($boleto);

        // nossoNumero tem prioridade sobre dadosExtras.bankNumber
        $this->assertSame('033', $payload['bankNumber']);
        // seuNumero tem prioridade sobre dadosExtras.clientNumber
        $this->assertSame('MEU-REF', $payload['clientNumber']);
        // nsuCode sobrescrito via dadosExtras
        $this->assertSame('NSU999', $payload['nsuCode']);
        // participantCode via dadosExtras
        $this->assertSame('PART-CUSTOM', $payload['participantCode']);
    }

    public function testToApiPayloadFallbackDadosExtrasQuandoDtoVazio(): void
    {
        $boleto = Boleto::fromArray([
            'valor'        => '100.00',
            'vencimento'   => '2026-05-01',
            'emissao'      => '2026-03-12',
            'pagador'      => ['nome' => 'X', 'documento' => '111'],
            'beneficiario' => ['nome' => 'Y', 'documento' => '222'],
            'dadosExtras'  => [
                'bankNumber'   => '099',
                'clientNumber' => 'CLI-EXTRA',
            ],
        ]);

        $payload = $this->mapper->toApiPayload($boleto);

        // Sem nossoNumero, usa dadosExtras.bankNumber
        $this->assertSame('099', $payload['bankNumber']);
        // Sem seuNumero, usa dadosExtras.clientNumber
        $this->assertSame('CLI-EXTRA', $payload['clientNumber']);
    }

    public function testToApiPayloadComMultaEJuros(): void
    {
        $boleto = Boleto::fromArray([
            'valor'      => '100.00',
            'vencimento' => '2026-05-01',
            'emissao'    => '2026-03-12',
            'pagador'    => ['nome' => 'X', 'documento' => '111'],
            'beneficiario' => ['nome' => 'Y', 'documento' => '222'],
            'multa' => [
                'percentual'        => '2.50',
                'diasAposVencimento' => 3,
            ],
            'juros' => [
                'percentual' => '1.00',
            ],
        ]);

        $payload = $this->mapper->toApiPayload($boleto);

        $this->assertSame('2.50', $payload['finePercentage']);
        $this->assertSame('3', $payload['fineQuantityDays']);
        $this->assertSame('1.00', $payload['interestPercentage']);
    }

    public function testToApiPayloadComDesconto(): void
    {
        $boleto = Boleto::fromArray([
            'valor'      => '100.00',
            'vencimento' => '2026-05-01',
            'emissao'    => '2026-03-12',
            'pagador'    => ['nome' => 'X', 'documento' => '111'],
            'beneficiario' => ['nome' => 'Y', 'documento' => '222'],
            'desconto' => [
                'tipo'      => 'VALOR_DATA_FIXA',
                'desconto1' => ['valor' => 5.50, 'dataLimite' => '2026-04-20'],
                'desconto2' => ['valor' => 3.00, 'dataLimite' => '2026-04-25'],
            ],
        ]);

        $payload = $this->mapper->toApiPayload($boleto);

        $this->assertArrayHasKey('discount', $payload);
        $this->assertSame('VALOR_DATA_FIXA', $payload['discount']['type']);
        $this->assertSame(5.50, $payload['discount']['discountOne']['value']);
        $this->assertSame('2026-04-20', $payload['discount']['discountOne']['limitDate']);
        $this->assertSame(3.00, $payload['discount']['discountTwo']['value']);
        $this->assertArrayNotHasKey('discountThree', $payload['discount']);
    }

    public function testToApiPayloadComMensagensEProtesto(): void
    {
        $boleto = Boleto::fromArray([
            'valor'        => '100.00',
            'vencimento'   => '2026-05-01',
            'emissao'      => '2026-03-12',
            'pagador'      => ['nome' => 'X', 'documento' => '111'],
            'beneficiario' => ['nome' => 'Y', 'documento' => '222'],
            'mensagens'    => ['Msg1', 'Msg2'],
            'tipoProtesto' => 'PROTESTAR',
            'diasProtesto' => 10,
            'diasBaixa'    => 60,
        ]);

        $payload = $this->mapper->toApiPayload($boleto);

        $this->assertSame(['Msg1', 'Msg2'], $payload['messages']);
        $this->assertSame('PROTESTAR', $payload['protestType']);
        $this->assertSame('10', $payload['protestQuantityDays']);
        $this->assertSame('60', $payload['writeOffQuantityDays']);
    }

    public function testToApiPayloadComDadosExtrasDirectos(): void
    {
        $boleto = Boleto::fromArray([
            'valor'        => '100.00',
            'vencimento'   => '2026-05-01',
            'emissao'      => '2026-03-12',
            'pagador'      => ['nome' => 'X', 'documento' => '111'],
            'beneficiario' => ['nome' => 'Y', 'documento' => '222'],
            'dadosExtras'  => [
                'paymentType'  => 'REGISTRO',
                'txId'         => 'tx123abc',
                'iofPercentage' => '1.50',
            ],
        ]);

        $payload = $this->mapper->toApiPayload($boleto);

        $this->assertSame('REGISTRO', $payload['paymentType']);
        $this->assertSame('tx123abc', $payload['txId']);
        $this->assertSame('1.50', $payload['iofPercentage']);
    }

    public function testToResponseMapeiaCorretamente(): void
    {
        $apiData = [
            'id'              => 'slip-uuid-123',
            'participantCode' => 'NOSSONUM001',
            'barCode'         => '23793381286000000000300000004001843400001000',
            'digitableLine'   => '23793.38128 60000.000003 00000.000400 1 84340000010000',
            'status'          => 'OPEN',
            'nominalValue'    => '150.00',
            'dueDate'         => '2026-05-01',
            'pdfUrl'          => 'https://api.santander.com.br/pdf/123',
        ];

        $response = $this->mapper->toResponse($apiData);

        $this->assertInstanceOf(BoletoResponse::class, $response);
        $this->assertSame('slip-uuid-123', $response->id);
        $this->assertSame('NOSSONUM001', $response->nossoNumero);
        $this->assertSame('23793381286000000000300000004001843400001000', $response->codigoBarras);
        $this->assertSame('23793.38128 60000.000003 00000.000400 1 84340000010000', $response->linhaDigitavel);
        $this->assertSame('OPEN', $response->status);
        $this->assertSame('150.00', $response->valor);
        $this->assertSame('2026-05-01', $response->vencimento);
        $this->assertSame($apiData, $response->dadosOriginais);
    }

    public function testToResponseComCamposAlternativos(): void
    {
        $apiData = [
            'bankSlipId'     => 'alt-id-456',
            'bankNumber'     => 'ALT-NUM',
            'bankSlipStatus' => 'PAID',
            'entryValue'     => '300.00',
        ];

        $response = $this->mapper->toResponse($apiData);

        $this->assertSame('alt-id-456', $response->id);
        $this->assertSame('ALT-NUM', $response->nossoNumero);
        $this->assertSame('PAID', $response->status);
        $this->assertSame('300.00', $response->valor);
    }

    public function testToResponseConstroiCompositeIdQuandoSemIdExplicito(): void
    {
        $apiData = [
            'nsuCode'       => '033',
            'nsuDate'       => '2026-03-14',
            'environment'   => 'PRODUCAO',
            'covenantCode'  => '794760',
            'bankNumber'    => '33',
            'barCode'       => '03398141700000001009079476000000000000330101',
            'digitableLine' => '03399079417600000000000003301017814170000000100',
            'nominalValue'  => '1.00',
            'dueDate'       => '2026-04-15',
        ];

        $response = $this->mapper->toResponse($apiData);

        $this->assertSame('033.2026-03-14.P.794760.33', $response->id);
        $this->assertSame('33', $response->nossoNumero);
    }

    public function testToResponseUsaIdExplicitoQuandoPresente(): void
    {
        $apiData = [
            'id'           => 'explicit-uuid-123',
            'nsuCode'      => '033',
            'nsuDate'      => '2026-03-14',
            'covenantCode' => '794760',
            'bankNumber'   => '33',
        ];

        $response = $this->mapper->toResponse($apiData);

        $this->assertSame('explicit-uuid-123', $response->id);
    }

    public function testToResponseList(): void
    {
        $list = [
            ['id' => '1', 'status' => 'OPEN', 'nominalValue' => '10.00'],
            ['id' => '2', 'status' => 'PAID', 'nominalValue' => '20.00'],
            ['id' => '3', 'status' => 'CANCELLED', 'nominalValue' => '30.00'],
        ];

        $responses = $this->mapper->toResponseList($list);

        $this->assertCount(3, $responses);
        $this->assertSame('1', $responses[0]->id);
        $this->assertSame('PAID', $responses[1]->status);
        $this->assertSame('30.00', $responses[2]->valor);
    }

    // -- Testes de toInstrucaoPayload --

    public function testToInstrucaoPayloadVencimento(): void
    {
        $instrucao = InstrucaoBoleto::alterarVencimento('2026-08-01');
        $payload = $this->mapper->toInstrucaoPayload('794760', '35', $instrucao);

        $this->assertSame('794760', $payload['covenantCode']);
        $this->assertSame('35', $payload['bankNumber']);
        $this->assertSame('2026-08-01', $payload['dueDate']);
        $this->assertSame('ALTER_DUE_DATE', $payload['operation']);
    }

    public function testToInstrucaoPayloadValor(): void
    {
        $instrucao = InstrucaoBoleto::alterarValor('500.00');
        $payload = $this->mapper->toInstrucaoPayload('794760', '35', $instrucao);

        $this->assertSame('500.00', $payload['nominalValue']);
        $this->assertSame('ALTER_NOMINAL_VALUE', $payload['operation']);
    }

    public function testToInstrucaoPayloadBaixar(): void
    {
        $instrucao = InstrucaoBoleto::baixar();
        $payload = $this->mapper->toInstrucaoPayload('794760', '35', $instrucao);

        $this->assertSame('BAIXAR', $payload['operation']);
        $this->assertArrayNotHasKey('dueDate', $payload);
        $this->assertArrayNotHasKey('nominalValue', $payload);
    }

    public function testToInstrucaoPayloadCompleta(): void
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
            'desconto'           => [
                'tipo'      => 'ISENTO',
                'desconto1' => ['valor' => '10.00', 'dataLimite' => '2026-06-25'],
            ],
        ]);

        $payload = $this->mapper->toInstrucaoPayload('794760', '35', $instrucao);

        $this->assertSame('794760', $payload['covenantCode']);
        $this->assertSame('35', $payload['bankNumber']);
        $this->assertSame('ALTER_DUE_DATE', $payload['operation']);
        $this->assertSame('2026-07-01', $payload['dueDate']);
        $this->assertSame('300.00', $payload['nominalValue']);
        $this->assertSame('REF-999', $payload['clientNumber']);
        $this->assertSame('10.00', $payload['deductionValue']);
        $this->assertSame('2.00', $payload['finePercentage']);
        $this->assertSame('2026-07-02', $payload['fineDate']);
        $this->assertSame('30', $payload['protestQuantityDays']);
        $this->assertSame('90', $payload['writeOffQuantityDays']);
        $this->assertSame('VALOR', $payload['paymentValueType']);
        $this->assertSame('250.00', $payload['minValueOrPercentage']);
        $this->assertSame('350.00', $payload['maxValueOrPercentage']);
        $this->assertSame('PART-123', $payload['participantCode']);
        $this->assertArrayHasKey('discount', $payload);
        $this->assertSame('ISENTO', $payload['discount']['type']);
    }

    public function testToInstrucaoPayloadComDadosExtras(): void
    {
        $instrucao = InstrucaoBoleto::fromArray([
            'vencimento'  => '2026-08-01',
            'dadosExtras' => ['participantCode' => 'CUSTOM-PART'],
        ]);

        $payload = $this->mapper->toInstrucaoPayload('794760', '35', $instrucao);

        $this->assertSame('CUSTOM-PART', $payload['participantCode']);
    }

    public function testToApiPayloadEnvironmentSandbox(): void
    {
        $mapperSandbox = new SantanderMapper('sandbox');

        $boleto = Boleto::fromArray([
            'valor'      => '100.00',
            'vencimento' => '2026-05-01',
            'emissao'    => '2026-03-12',
            'pagador'    => ['nome' => 'X', 'documento' => '111'],
            'beneficiario' => ['nome' => 'Y', 'documento' => '222'],
        ]);

        $payload = $mapperSandbox->toApiPayload($boleto);

        $this->assertSame('HOMOLOGACAO', $payload['environment']);
    }

    public function testToApiPayloadEnvironmentProducao(): void
    {
        $mapperProd = new SantanderMapper('producao');

        $boleto = Boleto::fromArray([
            'valor'      => '100.00',
            'vencimento' => '2026-05-01',
            'emissao'    => '2026-03-12',
            'pagador'    => ['nome' => 'X', 'documento' => '111'],
            'beneficiario' => ['nome' => 'Y', 'documento' => '222'],
        ]);

        $payload = $mapperProd->toApiPayload($boleto);

        $this->assertSame('PRODUCAO', $payload['environment']);
    }
}
