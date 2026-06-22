<?php

namespace ApiBoleto\Tests\Unit\Banks\Itau;

use ApiBoleto\Banks\Itau\ItauMapper;
use ApiBoleto\DTO\Boleto;
use ApiBoleto\DTO\InstrucaoBoleto;
use PHPUnit\Framework\TestCase;

class ItauMapperTest extends TestCase
{
    private ItauMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new ItauMapper('150000123450');
    }

    public function testToApiPayloadBoletoV2(): void
    {
        $payload = $this->mapper->toApiPayload($this->createBoleto());

        $this->assertSame('efetivacao', $payload['data']['etapa_processo_boleto']);
        $this->assertSame('API', $payload['data']['codigo_canal_operacao']);
        $this->assertSame('150000123450', $payload['data']['beneficiario']['id_beneficiario']);

        $dado = $payload['data']['dado_boleto'];
        $this->assertSame('boleto', $dado['descricao_instrumento_cobranca']);
        $this->assertSame('a vista', $dado['tipo_boleto']);
        $this->assertSame('109', $dado['codigo_carteira']);
        $this->assertSame('01', $dado['codigo_especie']);
        $this->assertSame('2026-03-12', $dado['data_emissao']);

        $this->assertSame('Joao da Silva', $dado['pagador']['pessoa']['nome_pessoa']);
        $this->assertSame('F', $dado['pagador']['pessoa']['tipo_pessoa']['codigo_tipo_pessoa']);
        $this->assertSame('12345678900', $dado['pagador']['pessoa']['tipo_pessoa']['numero_cadastro_pessoa_fisica']);
        $this->assertSame('01000000', $dado['pagador']['endereco']['numero_CEP']);

        $individual = $dado['dados_individuais_boleto'][0];
        $this->assertSame('00000001', $individual['numero_nosso_numero']);
        $this->assertSame('2026-05-01', $individual['data_vencimento']);
        $this->assertSame('00000000000015000', $individual['valor_titulo']);
        $this->assertSame('REF001', $individual['texto_seu_numero']);
    }

    public function testToPixPayloadBolecode(): void
    {
        $boleto = $this->createBoleto([
            'dadosExtras' => [
                'bolecodePix' => true,
                'chavePix' => '12345678000190',
                'id_location' => 123456789,
            ],
        ]);

        $payload = $this->mapper->toPixPayload($boleto);

        $this->assertArrayNotHasKey('data', $payload);
        $this->assertSame('boleto_pix', $payload['dado_boleto']['descricao_instrumento_cobranca']);
        $this->assertSame('150.00', $payload['dado_boleto']['valor_total_titulo']);
        $this->assertSame('150.00', $payload['dado_boleto']['dados_individuais_boleto'][0]['valor_titulo']);
        $this->assertSame('12345678000190', $payload['dados_qrcode']['chave']);
        $this->assertSame(123456789, $payload['dados_qrcode']['id_location']);
    }

    public function testToPixPayloadBolecodeFormatoColecaoPostman(): void
    {
        $mapper = new ItauMapper('150000123450', '109', '01', 'a vista', 'Simulacao', true);
        $boleto = $this->createBoleto([
            'valor' => '1.00',
            'dadosExtras' => [
                'bolecodePix' => true,
                'chavePix' => '12345678000190',
                'data_limite_pagamento' => '2026-05-01',
            ],
        ]);
        $boleto->mensagens = ['Mensagem teste'];

        $payload = $mapper->toPixPayload($boleto);

        $this->assertArrayNotHasKey('data', $payload);
        $this->assertArrayNotHasKey('codigo_canal_operacao', $payload);
        $this->assertSame('Simulacao', $payload['etapa_processo_boleto']);

        $dado = $payload['dado_boleto'];
        $this->assertArrayNotHasKey('descricao_instrumento_cobranca', $dado);
        $this->assertArrayNotHasKey('desconto_expresso', $dado);
        $this->assertSame('REF001', $dado['texto_seu_numero']);
        $this->assertSame('2026-03-12', $dado['data_emissao']);
        $this->assertSame('00000000000000100', $dado['valor_total_titulo']);
        $this->assertSame([['mensagem' => 'Mensagem teste']], $dado['lista_mensagem_cobranca']);

        $individual = $dado['dados_individuais_boleto'][0];
        $this->assertSame('00000000000000100', $individual['valor_titulo']);
        $this->assertArrayNotHasKey('lista_mensagens_cobranca', $individual);
        $this->assertSame('12345678000190', $payload['dados_qrcode']['chave']);
    }

    public function testToApiPayloadEnviaDescontoExpressoFalsePorPadrao(): void
    {
        $payload = $this->mapper->toApiPayload($this->createBoleto());

        $this->assertArrayHasKey('desconto_expresso', $payload['data']['dado_boleto']);
        $this->assertFalse($payload['data']['dado_boleto']['desconto_expresso']);
    }

    public function testToApiPayloadIgnoraNullEmSobrescritasDoItau(): void
    {
        $payload = $this->mapper->toApiPayload($this->createBoleto([
            'dadosExtras' => [
                'desconto_expresso' => null,
                'dado_boleto' => [
                    'desconto_expresso' => null,
                    'tipo_boleto' => null,
                    'pagador' => [
                        'pessoa' => [
                            'nome_pessoa' => null,
                        ],
                    ],
                    'dados_individuais_boleto' => [[
                        'data_vencimento' => null,
                    ]],
                ],
                'data' => [
                    'dado_boleto' => [
                        'codigo_especie' => null,
                        'desconto_expresso' => null,
                    ],
                ],
            ],
        ]));

        $dado = $payload['data']['dado_boleto'];

        $this->assertFalse($dado['desconto_expresso']);
        $this->assertSame('a vista', $dado['tipo_boleto']);
        $this->assertSame('01', $dado['codigo_especie']);
        $this->assertSame('Joao da Silva', $dado['pagador']['pessoa']['nome_pessoa']);
        $this->assertSame('2026-05-01', $dado['dados_individuais_boleto'][0]['data_vencimento']);
    }

    public function testToApiPayloadNormalizaDescontoExpressoStringFalse(): void
    {
        $payload = $this->mapper->toApiPayload($this->createBoleto([
            'dadosExtras' => [
                'desconto_expresso' => 'false',
            ],
        ]));

        $this->assertFalse($payload['data']['dado_boleto']['desconto_expresso']);
    }

    public function testToBoletoV1Payload(): void
    {
        $payload = $this->mapper->toBoletoV1Payload($this->createBoleto([
            'mensagens' => ['Teste API'],
            'dadosExtras' => [
                'dataLimitePagamento' => '2026-05-10',
            ],
        ]));

        $this->assertSame('150000123450', $payload['beneficiario']['idBeneficiario']);
        $this->assertSame('efetivacao', $payload['etapaProcessoBoleto']);
        $this->assertSame('boleto', $payload['instrumentoCobranca']);
        $this->assertSame('impressao', $payload['formaEnvio']);
        $this->assertSame('Joao da Silva', $payload['pagador']['nomePagador']);
        $this->assertSame('F', $payload['pagador']['tipoPessoa']);
        $this->assertSame('12345678900', $payload['pagador']['numeroDocumento']);
        $this->assertSame('Rua A', $payload['pagador']['endereco']['logradouro']);
        $this->assertSame('100', $payload['pagador']['endereco']['numero']);
        $this->assertSame('01', $payload['especie']['codigoEspecie']);
        $this->assertSame('REF001', $payload['seuNumero']);
        $this->assertSame(150.00, $payload['valor']);
        $this->assertSame('109', $payload['codigoCarteira']);
        $this->assertSame('00000001', $payload['nossoNumero']);
        $this->assertSame('2026-05-10', $payload['dataLimitePagamento']);
        $this->assertSame('Teste API', $payload['mensagensBoleto'][0]['mensagem']);
    }

    public function testToResponseMapeiaRetornoItau(): void
    {
        $apiData = [
            'data' => [
                'dado_boleto' => [
                    'valor_total_titulo' => '150.00',
                    'dados_individuais_boleto' => [[
                        'id_boleto_individual' => 'boleto-uuid',
                        'numero_nosso_numero' => '00000001',
                        'codigo_barras' => '34191234567890123456789012345678901234567890',
                        'numero_linha_digitavel' => '34101234567890123456789012345678901234567890123',
                        'data_vencimento' => '2026-05-01',
                        'valor_titulo' => '150.00',
                    ]],
                ],
                'dados_qrcode' => [
                    'emv' => '000201BRGOVBCBPIX',
                    'txid' => 'BL1234567890123456789012345678901',
                    'location' => 'https://api.itau.com.br/pix/qr/v2/123',
                ],
            ],
        ];

        $response = $this->mapper->toResponse($apiData);

        $this->assertSame('boleto-uuid', $response->id);
        $this->assertSame('00000001', $response->nossoNumero);
        $this->assertSame('34191234567890123456789012345678901234567890', $response->codigoBarras);
        $this->assertSame('34101234567890123456789012345678901234567890123', $response->linhaDigitavel);
        $this->assertSame('150.00', $response->valor);
        $this->assertSame('2026-05-01', $response->vencimento);
        $this->assertSame('000201BRGOVBCBPIX', $response->qrCodePix);
        $this->assertSame('BL1234567890123456789012345678901', $response->pixTxid);
        $this->assertSame('https://api.itau.com.br/pix/qr/v2/123', $response->qrCodeUrl);
    }

    public function testToResponseMapeiaRetornoBoletoV1(): void
    {
        $apiData = [
            'data' => [
                'idBoleto' => 'v1-uuid',
                'nossoNumero' => '12345678',
                'codigoBarras' => '34191234567890123456789012345678901234567890',
                'linhaDigitavel' => '34101234567890123456789012345678901234567890123',
                'valor' => 150.00,
                'dataVencimento' => '2026-05-01',
                'base64' => 'JVBERi0xLjQ=',
                'emv' => '000201BRGOVBCBPIX',
                'txid' => 'V1TXID123456789012345678901234',
                'location' => 'https://api.itau.com.br/pix/qr/v2/123',
            ],
        ];

        $response = $this->mapper->toResponse($apiData);

        $this->assertSame('v1-uuid', $response->id);
        $this->assertSame('12345678', $response->nossoNumero);
        $this->assertSame('34191234567890123456789012345678901234567890', $response->codigoBarras);
        $this->assertSame('34101234567890123456789012345678901234567890123', $response->linhaDigitavel);
        $this->assertSame('150', $response->valor);
        $this->assertSame('2026-05-01', $response->vencimento);
        $this->assertSame('JVBERi0xLjQ=', $response->pdfBase64);
        $this->assertSame('000201BRGOVBCBPIX', $response->qrCodePix);
        $this->assertSame('V1TXID123456789012345678901234', $response->pixTxid);
        $this->assertSame('https://api.itau.com.br/pix/qr/v2/123', $response->qrCodeUrl);
    }

    public function testToInstrucaoRequestValor(): void
    {
        $request = $this->mapper->toInstrucaoRequest(
            '81610015315310920000002',
            InstrucaoBoleto::alterarValor('250.00')
        );

        $this->assertSame('/boletos/81610015315310920000002/valor_nominal', $request['path']);
        $this->assertSame(['valor_titulo' => '250.00'], $request['body']);
    }

    public function testToInstrucaoRequestVencimento(): void
    {
        $request = $this->mapper->toInstrucaoRequest(
            '81610015315310920000002',
            InstrucaoBoleto::alterarVencimento('2026-06-15')
        );

        $this->assertSame('/boletos/81610015315310920000002/data_vencimento', $request['path']);
        $this->assertSame(['data_vencimento' => '2026-06-15'], $request['body']);
    }

    public function testWebhookPayload(): void
    {
        $payload = $this->mapper->toWebhookPayload([
            'webhookUrl' => 'https://app.test/webhook',
            'webhookClientId' => 'client-webhook',
            'webhookClientSecret' => 'secret-webhook',
            'webhookOauthUrl' => 'https://app.test/oauth/token',
            'webhookOauthScope' => 'boletos-notificacoes',
            'tiposNotificacoes' => ['BAIXA_EFETIVA', 'BAIXA_OPERACIONAL'],
        ]);

        $this->assertSame('150000123450', $payload['id_beneficiario']);
        $this->assertSame('https://app.test/webhook', $payload['webhook_url']);
        $this->assertSame('client-webhook', $payload['webhook_client_id']);
        $this->assertSame('secret-webhook', $payload['webhook_client_secret']);
        $this->assertSame('boletos-notificacoes', $payload['webhook_oauth_scope']);
        $this->assertSame(['BAIXA_EFETIVA', 'BAIXA_OPERACIONAL'], $payload['tipos_notificacoes']);
    }

    private function createBoleto(array $overrides = []): Boleto
    {
        $data = array_replace_recursive([
            'valor' => '150.00',
            'vencimento' => '2026-05-01',
            'emissao' => '2026-03-12',
            'nossoNumero' => '00000001',
            'seuNumero' => 'REF001',
            'tipoDocumento' => 'DUPLICATA_MERCANTIL',
            'pagador' => [
                'nome' => 'Joao da Silva',
                'tipoDocumento' => 'CPF',
                'documento' => '123.456.789-00',
                'endereco' => 'Rua A, 100',
                'bairro' => 'Centro',
                'cidade' => 'Sao Paulo',
                'estado' => 'SP',
                'cep' => '01000-000',
            ],
            'beneficiario' => [
                'nome' => 'Empresa LTDA',
                'tipoDocumento' => 'CNPJ',
                'documento' => '12345678000190',
            ],
        ], $overrides);

        return Boleto::fromArray($data);
    }
}
