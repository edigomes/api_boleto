<?php

namespace ApiBoleto\Tests\Unit\Banks\Itau;

use ApiBoleto\Banks\Itau\ItauGateway;
use ApiBoleto\Contracts\BankSetupInterface;
use ApiBoleto\DTO\Boleto;
use ApiBoleto\DTO\BoletoResponse;
use ApiBoleto\DTO\InstrucaoBoleto;
use ApiBoleto\Exceptions\BoletoException;
use ApiBoleto\Tests\Fake\FakeHttpClient;
use ApiBoleto\Tests\Fake\FakeTokenStorage;
use PHPUnit\Framework\TestCase;

class ItauGatewayTest extends TestCase
{
    private FakeHttpClient $fakeHttp;

    private FakeTokenStorage $tokenStorage;

    protected function setUp(): void
    {
        $this->fakeHttp = new FakeHttpClient();
        $this->tokenStorage = new FakeTokenStorage();
    }

    public function testCriarBoleto(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(201, [
            'data' => [
                'dado_boleto' => [
                    'dados_individuais_boleto' => [[
                        'id_boleto_individual' => 'itau-001',
                        'numero_nosso_numero' => '00000001',
                        'codigo_barras' => '34191234567890123456789012345678901234567890',
                        'numero_linha_digitavel' => '34101234567890123456789012345678901234567890123',
                        'data_vencimento' => '2026-05-01',
                        'valor_titulo' => '150.00',
                    ]],
                ],
            ],
        ]);

        $gateway = $this->createGateway();
        $response = $gateway->criarBoleto($this->createBoleto());

        $this->assertInstanceOf(BoletoResponse::class, $response);
        $this->assertSame('itau-001', $response->id);
        $this->assertSame('00000001', $response->nossoNumero);
        $this->assertNotEmpty($response->codigoBarras);
        $this->assertNotEmpty($response->linhaDigitavel);

        $lastRequest = $this->fakeHttp->getLastRequest();
        $this->assertSame('POST', $lastRequest['method']);
        $this->assertSame('https://api.itau.com.br/cash_management/v2/boletos', $lastRequest['url']);
        $this->assertSame('150000123450', $lastRequest['options']['body']['data']['beneficiario']['id_beneficiario']);
        $this->assertSame('00000000000015000', $lastRequest['options']['body']['data']['dado_boleto']['dados_individuais_boleto'][0]['valor_titulo']);
    }

    public function testCriarBolecodePix(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, [
            'dados_qrcode' => [
                'emv' => '000201BRGOVBCBPIX',
                'location' => 'https://api.itau.com.br/pix/qr/v2/123',
            ],
            'dado_boleto' => [
                'dados_individuais_boleto' => [[
                    'id_boleto_individual' => 'pix-001',
                    'numero_nosso_numero' => '00000001',
                    'valor_titulo' => '150.00',
                ]],
            ],
        ]);

        $gateway = $this->createGateway([
            'pixBaseUrl' => 'https://pix-pj.example.com/recebimentos-pix/v1',
        ]);
        $boleto = $this->createBoleto([
            'dadosExtras' => [
                'bolecodePix' => true,
                'chavePix' => '12345678000190',
            ],
        ]);
        $response = $gateway->criarBoleto($boleto);

        $this->assertSame('pix-001', $response->id);
        $this->assertSame('000201BRGOVBCBPIX', $response->qrCodePix);

        $lastRequest = $this->fakeHttp->getLastRequest();
        $this->assertSame('POST', $lastRequest['method']);
        $this->assertSame('https://pix-pj.example.com/recebimentos-pix/v1/boletos-pix', $lastRequest['url']);
        $this->assertArrayNotHasKey('data', $lastRequest['options']['body']);
        $this->assertSame('boleto_pix', $lastRequest['options']['body']['dado_boleto']['descricao_instrumento_cobranca']);
        $this->assertTrue($this->hasHeader($lastRequest['options']['headers'], 'auth: Bearer fake-token-itau'));
    }

    public function testCriarBolecodePixComEndpointEFormatoDaColecaoPostman(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, [
            'dados_qrcode' => [
                'emv' => '000201BRGOVBCBPIX',
            ],
            'dado_boleto' => [
                'dados_individuais_boleto' => [[
                    'id_boleto_individual' => 'pix-collection-001',
                    'numero_nosso_numero' => '00000001',
                    'valor_titulo' => '00000000000015000',
                ]],
            ],
        ]);

        $gateway = $this->createGateway([
            'etapaProcesso' => 'Simulacao',
            'pixBaseUrl' => 'https://secure.api.itau/pix_recebimentos_conciliacoes/v2',
            'pixEndpointPath' => '/boletos_pix',
            'pixLegacyPayload' => true,
        ]);
        $boleto = $this->createBoleto([
            'dadosExtras' => [
                'bolecodePix' => true,
                'chavePix' => '12345678000190',
            ],
        ]);

        $gateway->criarBoleto($boleto);

        $lastRequest = $this->fakeHttp->getLastRequest();
        $this->assertSame('POST', $lastRequest['method']);
        $this->assertSame(
            'https://secure.api.itau/pix_recebimentos_conciliacoes/v2/boletos_pix',
            $lastRequest['url']
        );
        $this->assertArrayNotHasKey('codigo_canal_operacao', $lastRequest['options']['body']);
        $this->assertSame('Simulacao', $lastRequest['options']['body']['etapa_processo_boleto']);
        $this->assertArrayNotHasKey(
            'descricao_instrumento_cobranca',
            $lastRequest['options']['body']['dado_boleto']
        );
        $this->assertSame(
            '00000000000015000',
            $lastRequest['options']['body']['dado_boleto']['valor_total_titulo']
        );
    }

    public function testCriarBolecodePixLegadoFormataPercentuaisSemPonto(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, [
            'dados_qrcode' => [
                'emv' => '000201BRGOVBCBPIX',
            ],
            'dado_boleto' => [
                'dados_individuais_boleto' => [[
                    'id_boleto_individual' => 'pix-percent-001',
                    'numero_nosso_numero' => '00000001',
                    'valor_titulo' => '00000000000015000',
                ]],
            ],
        ]);

        $gateway = $this->createGateway([
            'pixBaseUrl' => 'https://secure.api.itau/pix_recebimentos_conciliacoes/v2',
            'pixEndpointPath' => '/boletos_pix',
            'pixLegacyPayload' => true,
        ]);
        $boleto = $this->createBoleto([
            'juros' => [
                'percentual' => '1',
            ],
            'multa' => [
                'percentual' => '2',
                'diasAposVencimento' => 1,
            ],
            'dadosExtras' => [
                'bolecodePix' => true,
                'chavePix' => '12345678000190',
            ],
        ]);

        $gateway->criarBoleto($boleto);

        $body = $this->fakeHttp->getLastRequest()['options']['body'];
        $this->assertSame('000000100000', $body['dado_boleto']['juros']['percentual_juros']);
        $this->assertSame('000000200000', $body['dado_boleto']['multa']['percentual_multa']);
    }

    public function testCriarBolecodePixComEndpointLegadoForcaPayloadLegado(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, [
            'dados_qrcode' => [
                'emv' => '000201BRGOVBCBPIX',
            ],
            'dado_boleto' => [
                'dados_individuais_boleto' => [[
                    'id_boleto_individual' => 'pix-endpoint-legacy-001',
                    'numero_nosso_numero' => '00000001',
                    'valor_titulo' => '00000000000015000',
                ]],
            ],
        ]);

        $gateway = $this->createGateway([
            'pixBaseUrl' => 'https://secure.api.itau/pix_recebimentos_conciliacoes/v2',
            'pixEndpointPath' => '/boletos_pix',
        ]);
        $boleto = $this->createBoleto([
            'juros' => [
                'percentual' => '1',
            ],
            'multa' => [
                'percentual' => '2',
            ],
            'dadosExtras' => [
                'bolecodePix' => true,
                'chavePix' => '12345678000190',
            ],
        ]);

        $gateway->criarBoleto($boleto);

        $body = $this->fakeHttp->getLastRequest()['options']['body'];
        $this->assertSame('https://secure.api.itau/pix_recebimentos_conciliacoes/v2/boletos_pix', $this->fakeHttp->getLastRequest()['url']);
        $this->assertArrayNotHasKey('codigo_canal_operacao', $body);
        $this->assertSame('000000100000', $body['dado_boleto']['juros']['percentual_juros']);
        $this->assertSame('000000200000', $body['dado_boleto']['multa']['percentual_multa']);
    }

    public function testCriarBoletoUsandoApiBoletosV1(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, [
            'data' => [
                'idBoleto' => 'v1-001',
                'nossoNumero' => '00000001',
                'codigoBarras' => '34191234567890123456789012345678901234567890',
                'linhaDigitavel' => '34101234567890123456789012345678901234567890123',
                'base64' => 'JVBERi0xLjQ=',
            ],
        ]);

        $gateway = $this->createGateway(['usarApiBoletosV1' => true]);
        $response = $gateway->criarBoleto($this->createBoleto());

        $this->assertSame('v1-001', $response->id);
        $this->assertSame('JVBERi0xLjQ=', $response->pdfBase64);

        $lastRequest = $this->fakeHttp->getLastRequest();
        $this->assertSame('POST', $lastRequest['method']);
        $this->assertSame('https://boleto.api.itau.com/boleto/v1/boletos', $lastRequest['url']);
        $this->assertSame('150000123450', $lastRequest['options']['body']['beneficiario']['idBeneficiario']);
        $this->assertSame('boleto', $lastRequest['options']['body']['instrumentoCobranca']);
    }

    public function testBolecodePixSemBaseUrlLancaException(): void
    {
        $this->expectException(BoletoException::class);
        $this->expectExceptionMessage('pixBaseUrl');

        $gateway = $this->createGateway();
        $boleto = $this->createBoleto([
            'dadosExtras' => ['bolecodePix' => true, 'chavePix' => '12345678000190'],
        ]);

        $gateway->criarBoleto($boleto);
    }

    public function testConsultarBoleto(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, [
            'data' => [
                'dado_boleto' => [
                    'dados_individuais_boleto' => [[
                        'id_boleto_individual' => 'itau-002',
                        'numero_nosso_numero' => '00000002',
                    ]],
                ],
            ],
        ]);

        $gateway = $this->createGateway();
        $response = $gateway->consultarBoleto('00000002');

        $this->assertSame('itau-002', $response->id);

        $lastRequest = $this->fakeHttp->getLastRequest();
        $this->assertSame('GET', $lastRequest['method']);
        $this->assertSame('https://secure.api.cloud.itau.com.br/boletoscash/v2/boletos', $lastRequest['url']);
        $this->assertSame('150000123450', $lastRequest['options']['query']['id_beneficiario']);
        $this->assertSame('109', $lastRequest['options']['query']['codigo_carteira']);
        $this->assertSame('00000002', $lastRequest['options']['query']['nosso_numero']);
        $this->assertSame('specific', $lastRequest['options']['query']['view']);
    }

    public function testConsultarBoletoComIdentificadorCompletoEDataInclusao(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, [
            'data' => [[
                'dado_boleto' => [
                    'dados_individuais_boleto' => [[
                        'id_boleto_individual' => 'itau-specific',
                        'numero_nosso_numero' => '00000002',
                    ]],
                ],
            ]],
        ]);

        $gateway = $this->createGateway();
        $response = $gateway->consultarBoleto('150000123450,109,00000002,2026-03-12');

        $this->assertSame('itau-specific', $response->id);

        $lastRequest = $this->fakeHttp->getLastRequest();
        $this->assertSame('150000123450', $lastRequest['options']['query']['id_beneficiario']);
        $this->assertSame('109', $lastRequest['options']['query']['codigo_carteira']);
        $this->assertSame('00000002', $lastRequest['options']['query']['nosso_numero']);
        $this->assertSame('2026-03-12', $lastRequest['options']['query']['data_inclusao']);
    }

    public function testConsultarBoletosMapeiaRespostaPaginadaDoItau(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, [
            'data' => [[
                'dado_boleto' => [
                    'dados_individuais_boleto' => [[
                        'id_boleto_individual' => 'itau-list',
                        'numero_nosso_numero' => '00000003',
                    ]],
                ],
            ]],
            'page' => ['total_elements' => 1],
        ]);

        $gateway = $this->createGateway();
        $responses = $gateway->consultarBoletos([
            'nossoNumero' => '00000003',
            'dataInclusao' => '2026-03-12',
        ]);

        $this->assertCount(1, $responses);
        $this->assertSame('itau-list', $responses[0]->id);

        $lastRequest = $this->fakeHttp->getLastRequest();
        $this->assertSame('2026-03-12', $lastRequest['options']['query']['data_inclusao']);
    }

    public function testDownloadPdfRenderizaPdfLocalQuandoConsultaNaoRetornaUrl(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, [
            'data' => [
                'beneficiario' => [
                    'id_beneficiario' => '150000123450',
                    'nome_cobranca' => 'Empresa LTDA',
                ],
                'dado_boleto' => [
                    'codigo_carteira' => '109',
                    'pagador' => [
                        'pessoa' => [
                            'nome_pessoa' => 'Joao da Silva',
                            'tipo_pessoa' => [
                                'codigo_tipo_pessoa' => 'F',
                                'numero_cadastro_pessoa_fisica' => '12345678900',
                            ],
                        ],
                    ],
                    'dados_individuais_boleto' => [[
                        'numero_nosso_numero' => '00000002',
                        'codigo_barras' => '34191234567890123456789012345678901234567890',
                        'numero_linha_digitavel' => '34101234567890123456789012345678901234567890123',
                        'data_vencimento' => '2026-05-01',
                        'valor_titulo' => '00000000000015000',
                    ]],
                ],
            ],
        ]);

        $gateway = $this->createGateway();
        $pdf = $gateway->downloadPdf('00000002');

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('34101.23456 78901.234567 89012.345678 9 01234567890123', $pdf);
    }

    public function testDownloadPdfBaixaUrlQuandoConsultaRetornaUrl(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, [
            'data' => [
                'dado_boleto' => [
                    'dados_individuais_boleto' => [[
                        'numero_nosso_numero' => '00000002',
                        'url_pdf' => 'https://itau.example.com/boleto.pdf',
                    ]],
                ],
            ],
        ]);
        $this->fakeHttp->addResponse(200, null, '%PDF-1.4 itau');

        $gateway = $this->createGateway();
        $pdf = $gateway->downloadPdf('00000002');

        $this->assertSame('%PDF-1.4 itau', $pdf);
        $lastRequest = $this->fakeHttp->getLastRequest();
        $this->assertSame('GET', $lastRequest['method']);
        $this->assertSame('https://itau.example.com/boleto.pdf', $lastRequest['url']);
        $this->assertTrue($lastRequest['options']['rawResponse']);
    }

    public function testDownloadPdfUsaBase64OficialQuandoConsultaRetorna(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, [
            'data' => [
                'idBoleto' => 'v1-001',
                'nossoNumero' => '00000002',
                'base64' => base64_encode('%PDF-1.4 oficial'),
            ],
        ]);

        $gateway = $this->createGateway();
        $pdf = $gateway->downloadPdf('00000002');

        $this->assertSame('%PDF-1.4 oficial', $pdf);
    }

    public function testAlterarBoletoValor(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, ['id' => 'alterado', 'status' => 'UPDATED']);

        $gateway = $this->createGateway();
        $response = $gateway->alterarBoleto(
            '81610015315310920000002',
            InstrucaoBoleto::alterarValor('250.00')
        );

        $this->assertSame('alterado', $response->id);

        $lastRequest = $this->fakeHttp->getLastRequest();
        $this->assertSame('PATCH', $lastRequest['method']);
        $this->assertSame(
            'https://api.itau.com.br/cash_management/v2/boletos/81610015315310920000002/valor_nominal',
            $lastRequest['url']
        );
        $this->assertSame(['valor_titulo' => '250.00'], $lastRequest['options']['body']);
    }

    public function testAlterarBoletoAceitaIdentificadorSeparadoPorVirgula(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, ['id' => 'alterado', 'status' => 'UPDATED']);

        $gateway = $this->createGateway();
        $gateway->alterarBoleto(
            '150000123450,109,00000002',
            InstrucaoBoleto::alterarVencimento('2026-06-01')
        );

        $lastRequest = $this->fakeHttp->getLastRequest();
        $this->assertSame(
            'https://api.itau.com.br/cash_management/v2/boletos/15000012345010900000002/data_vencimento',
            $lastRequest['url']
        );
    }

    public function testAlterarBoletoAceitaNossoNumeroSimples(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, ['id' => 'alterado', 'status' => 'UPDATED']);

        $gateway = $this->createGateway();
        $gateway->alterarBoleto('00000002', InstrucaoBoleto::alterarVencimento('2026-06-01'));

        $lastRequest = $this->fakeHttp->getLastRequest();
        $this->assertSame(
            'https://api.itau.com.br/cash_management/v2/boletos/15000012345010900000002/data_vencimento',
            $lastRequest['url']
        );
    }

    public function testCancelarBoleto(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(202, ['codigo' => '202', 'mensagem' => 'Aceito']);

        $gateway = $this->createGateway();
        $this->assertTrue($gateway->cancelarBoleto('81610015315310920000002'));

        $lastRequest = $this->fakeHttp->getLastRequest();
        $this->assertSame('PATCH', $lastRequest['method']);
        $this->assertSame(
            'https://api.itau.com.br/cash_management/v2/boletos/81610015315310920000002/baixa',
            $lastRequest['url']
        );
        $this->assertSame([], $lastRequest['options']['body']);
    }

    public function testSetupWebhook(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(201, [
            'data' => ['id_notificacao_boletos' => 'webhook-001'],
        ]);

        $gateway = $this->createGateway();
        $this->assertInstanceOf(BankSetupInterface::class, $gateway);

        $result = $gateway->setup([
            'webhookUrl' => 'https://app.test/webhook',
            'webhookClientId' => 'client-webhook',
            'webhookClientSecret' => 'secret-webhook',
            'webhookOauthUrl' => 'https://app.test/oauth/token',
        ]);

        $this->assertSame('webhook-001', $result['data']['id_notificacao_boletos']);

        $lastRequest = $this->fakeHttp->getLastRequest();
        $this->assertSame('POST', $lastRequest['method']);
        $this->assertSame('https://boletos.cloud.itau.com.br/boletos/v3/notificacoes_boletos', $lastRequest['url']);
        $this->assertSame('https://app.test/webhook', $lastRequest['options']['body']['data']['webhook_url']);
        $this->assertSame('150000123450', $lastRequest['options']['body']['data']['id_beneficiario']);
        $this->assertTrue($this->hasHeader($lastRequest['options']['headers'], 'auth: Bearer fake-token-itau'));
    }

    public function testConsultarSetupConsultaTiposDeNotificacao(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, [
            'data' => [[
                'id_notificacao_boleto' => '15000012345001',
                'tipo_notificacao' => 'BAIXA_EFETIVA',
            ]],
        ]);
        $this->fakeHttp->addResponse(200, [
            'data' => [[
                'id_notificacao_boleto' => '15000012345002',
                'tipo_notificacao' => 'BAIXA_OPERACIONAL',
            ]],
        ]);

        $gateway = $this->createGateway();
        $result = $gateway->consultarSetup();

        $this->assertCount(2, $result['data']);
        $this->assertSame('BAIXA_EFETIVA', $result['data'][0]['tipo_notificacao']);
        $this->assertSame('BAIXA_OPERACIONAL', $result['data'][1]['tipo_notificacao']);

        $history = $this->fakeHttp->getRequestHistory();
        $this->assertSame('GET', $history[1]['method']);
        $this->assertSame('GET', $history[2]['method']);
        $this->assertSame('150000123450', $history[1]['options']['query']['id_beneficiario']);
        $this->assertSame('BAIXA_EFETIVA', $history[1]['options']['query']['tipo_notificacao']);
        $this->assertSame('BAIXA_OPERACIONAL', $history[2]['options']['query']['tipo_notificacao']);
        $this->assertTrue($this->hasHeader($history[1]['options']['headers'], 'auth: Bearer fake-token-itau'));
        $this->assertTrue($this->hasHeader($history[2]['options']['headers'], 'auth: Bearer fake-token-itau'));
    }

    public function testConsultarSetupTrata404ComoNaoConfigurado(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(404);
        $this->fakeHttp->addResponse(404);

        $gateway = $this->createGateway();
        $result = $gateway->consultarSetup();

        $this->assertSame(['data' => []], $result);
    }

    public function testConsultarFrancesasDerivaAgenciaContaDacDoBeneficiario(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, ['data' => [['id_francesa' => '150000012345']]]);

        $gateway = $this->createGateway(['idBeneficiario' => '150000012345']);
        $result = $gateway->consultarFrancesas(['mesReferencia' => '052026']);

        $this->assertSame('150000012345', $result['data'][0]['id_francesa']);

        $lastRequest = $this->fakeHttp->getLastRequest();
        $this->assertSame('GET', $lastRequest['method']);
        $this->assertSame('https://boletos.cloud.itau.com.br/boletos/v3/francesas', $lastRequest['url']);
        $this->assertSame('1500', $lastRequest['options']['query']['agencia']);
        $this->assertSame('0001234', $lastRequest['options']['query']['conta']);
        $this->assertSame('5', $lastRequest['options']['query']['dac']);
        $this->assertSame('052026', $lastRequest['options']['query']['mes_referencia']);
    }

    public function testConsultarMovimentacoesFrancesa(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, ['data' => [['nosso_numero' => '00000002']]]);

        $gateway = $this->createGateway();
        $result = $gateway->consultarMovimentacoesFrancesa('150000012345', [
            'data' => '2026-05-21',
            'nossoNumero' => '00000002',
            'tipoCobranca' => 'boleto',
        ]);

        $this->assertSame('00000002', $result['data'][0]['nosso_numero']);

        $lastRequest = $this->fakeHttp->getLastRequest();
        $this->assertSame('GET', $lastRequest['method']);
        $this->assertSame(
            'https://boletos.cloud.itau.com.br/boletos/v3/francesas/150000012345/movimentacoes',
            $lastRequest['url']
        );
        $this->assertSame('2026-05-21', $lastRequest['options']['query']['data']);
        $this->assertSame('00000002', $lastRequest['options']['query']['nosso_numero']);
        $this->assertSame('boleto', $lastRequest['options']['query']['tipo_cobranca']);
    }

    public function testConsultarMovimentacoesResumidasFrancesa(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, ['data' => ['resumida_cobranca' => []]]);

        $gateway = $this->createGateway();
        $result = $gateway->consultarMovimentacoesResumidasFrancesa('150000012345', [
            'data' => '2026-05-21',
        ]);

        $this->assertArrayHasKey('resumida_cobranca', $result['data']);

        $lastRequest = $this->fakeHttp->getLastRequest();
        $this->assertSame('GET', $lastRequest['method']);
        $this->assertSame(
            'https://boletos.cloud.itau.com.br/boletos/v3/francesas/150000012345/movimentacoes_resumidas',
            $lastRequest['url']
        );
        $this->assertSame('2026-05-21', $lastRequest['options']['query']['data']);
    }

    public function testConfigObrigatoria(): void
    {
        $this->expectException(BoletoException::class);
        $this->expectExceptionMessage('clientId');

        new ItauGateway([
            'clientSecret' => 'secret',
            'idBeneficiario' => '150000123450',
            'certFile' => '/fake/cert.pem',
            'certKeyFile' => '/fake/key.pem',
            'tokenStorage' => $this->tokenStorage,
            'httpClient' => $this->fakeHttp,
        ]);
    }

    public function testTokenArmazenado(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, [
            'data' => [
                'dado_boleto' => [
                    'dados_individuais_boleto' => [[
                        'id_boleto_individual' => 'itau-token',
                    ]],
                ],
            ],
        ]);

        $gateway = $this->createGateway();
        $gateway->consultarBoleto('00000001');

        $this->assertSame('fake-token-itau', $this->tokenStorage->get('test_itau_token'));
    }

    private function createGateway(array $overrides = []): ItauGateway
    {
        return new ItauGateway(array_replace([
            'clientId' => 'test-client-id',
            'clientSecret' => 'test-client-secret',
            'idBeneficiario' => '150000123450',
            'certFile' => '/fake/cert.pem',
            'certKeyFile' => '/fake/key.pem',
            'tokenStorage' => $this->tokenStorage,
            'tokenKey' => 'test_itau_token',
            'httpClient' => $this->fakeHttp,
        ], $overrides));
    }

    private function createBoleto(array $overrides = []): Boleto
    {
        return Boleto::fromArray(array_replace_recursive([
            'valor' => '150.00',
            'vencimento' => '2026-05-01',
            'emissao' => '2026-03-12',
            'nossoNumero' => '00000001',
            'seuNumero' => 'REF001',
            'tipoDocumento' => 'DUPLICATA_MERCANTIL',
            'pagador' => [
                'nome' => 'Joao da Silva',
                'tipoDocumento' => 'CPF',
                'documento' => '12345678900',
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
        ], $overrides));
    }

    private function enqueueAuthResponse(): void
    {
        $this->fakeHttp->addResponse(200, [
            'access_token' => 'fake-token-itau',
            'expires_in' => 3600,
        ]);
    }

    private function hasHeader(array $headers, string $expected): bool
    {
        foreach ($headers as $header) {
            if ($header === $expected) {
                return true;
            }
        }

        return false;
    }
}
