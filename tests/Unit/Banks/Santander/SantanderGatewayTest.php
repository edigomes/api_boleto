<?php

namespace ApiBoleto\Tests\Unit\Banks\Santander;

use ApiBoleto\Banks\Santander\SantanderGateway;
use ApiBoleto\Contracts\BankSetupInterface;
use ApiBoleto\DTO\Boleto;
use ApiBoleto\DTO\BoletoResponse;
use ApiBoleto\DTO\InstrucaoBoleto;
use ApiBoleto\Exceptions\BoletoException;
use ApiBoleto\Tests\Fake\FakeHttpClient;
use ApiBoleto\Tests\Fake\FakeTokenStorage;
use PHPUnit\Framework\TestCase;

class SantanderGatewayTest extends TestCase
{
    private FakeHttpClient $fakeHttp;
    private FakeTokenStorage $tokenStorage;

    protected function setUp(): void
    {
        $this->fakeHttp = new FakeHttpClient();
        $this->tokenStorage = new FakeTokenStorage();
    }

    private function createGateway(string $workspaceId = 'ws-123', string $ambiente = 'producao'): SantanderGateway
    {
        return new SantanderGateway([
            'clientId'        => 'test-client-id',
            'clientSecret'    => 'test-client-secret',
            'certFile'        => '/fake/cert.pem',
            'certKeyFile'     => '/fake/key.pem',
            'certKeyPassword' => '',
            'tokenStorage'    => $this->tokenStorage,
            'tokenKey'        => 'test_santander_token',
            'workspaceId'     => $workspaceId,
            'ambiente'        => $ambiente,
            'httpClient'      => $this->fakeHttp,
        ]);
    }

    private function createBoleto(): Boleto
    {
        return Boleto::fromArray([
            'valor'          => '150.00',
            'vencimento'     => '2026-05-01',
            'emissao'        => '2026-03-12',
            'codigoConvenio' => '1234567',
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
    }

    private function enqueueAuthResponse(): void
    {
        $this->fakeHttp->addResponse(200, ['access_token' => 'fake-token-abc']);
    }

    public function testCriarBoleto(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(201, [
            'id'            => 'slip-001',
            'barCode'       => '12345678901234567890123456789012345678901234',
            'digitableLine' => '12345.67890 12345.678901 23456.789012 3 45678901234',
            'status'        => 'OPEN',
            'nominalValue'  => '150.00',
            'dueDate'       => '2026-05-01',
        ]);

        $gateway = $this->createGateway();
        $response = $gateway->criarBoleto($this->createBoleto());

        $this->assertInstanceOf(BoletoResponse::class, $response);
        $this->assertSame('slip-001', $response->id);
        $this->assertSame('OPEN', $response->status);
        $this->assertSame('150.00', $response->valor);
        $this->assertNotEmpty($response->codigoBarras);
        $this->assertNotEmpty($response->linhaDigitavel);

        $lastRequest = $this->fakeHttp->getLastRequest();
        $this->assertSame('POST', $lastRequest['method']);
        $this->assertStringContainsString('/workspaces/ws-123/bank_slips', $lastRequest['url']);
        $this->assertArrayHasKey('body', $lastRequest['options']);
    }

    public function testConsultarBoleto(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, [
            'id'            => 'slip-002',
            'status'        => 'PAID',
            'nominalValue'  => '200.00',
            'dueDate'       => '2026-04-01',
        ]);

        $gateway = $this->createGateway();
        $response = $gateway->consultarBoleto('slip-002');

        $this->assertSame('slip-002', $response->id);
        $this->assertSame('PAID', $response->status);

        $lastRequest = $this->fakeHttp->getLastRequest();
        $this->assertSame('GET', $lastRequest['method']);
        $this->assertStringContainsString('/bank_slips/slip-002', $lastRequest['url']);
    }

    public function testConsultarBoletosUsaEndpointBills(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, [
            '_content' => [
                ['id' => 's1', 'status' => 'OPEN', 'nominalValue' => '10.00'],
                ['id' => 's2', 'status' => 'PAID', 'nominalValue' => '20.00'],
            ],
        ]);

        $gateway = $this->createGateway();
        $responses = $gateway->consultarBoletos([
            'beneficiaryCode' => '1234567',
            'bankNumber'      => '1005',
        ]);

        $this->assertCount(2, $responses);
        $this->assertSame('s1', $responses[0]->id);
        $this->assertSame('s2', $responses[1]->id);

        $lastRequest = $this->fakeHttp->getLastRequest();
        $this->assertSame('GET', $lastRequest['method']);
        $this->assertStringContainsString('/bills', $lastRequest['url']);
        $this->assertStringNotContainsString('/workspaces/', $lastRequest['url']);
    }

    public function testConsultarBoletoDetalhadoUsaEndpointBillsComTipo(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, [
            'id'           => 'detail-001',
            'status'       => 'OPEN',
            'nominalValue' => '100.00',
        ]);

        $gateway = $this->createGateway();
        $response = $gateway->consultarBoletoDetalhado('3567206', '1005', 'bankslip');

        $this->assertSame('detail-001', $response->id);

        $lastRequest = $this->fakeHttp->getLastRequest();
        $this->assertSame('GET', $lastRequest['method']);
        $this->assertStringContainsString('/bills/3567206.1005', $lastRequest['url']);
    }

    public function testAlterarBoletoComInstrucao(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, [
            'id'     => 'slip-003',
            'status' => 'UPDATED',
        ]);

        $gateway = $this->createGateway();
        $instrucao = InstrucaoBoleto::alterarVencimento('2026-06-01');
        $response = $gateway->alterarBoleto('1234567,033', $instrucao);

        $this->assertSame('slip-003', $response->id);

        $lastRequest = $this->fakeHttp->getLastRequest();
        $this->assertSame('PATCH', $lastRequest['method']);
        $this->assertSame('1234567', $lastRequest['options']['body']['covenantCode']);
        $this->assertSame('033', $lastRequest['options']['body']['bankNumber']);
        $this->assertSame('ALTER_DUE_DATE', $lastRequest['options']['body']['operation']);
        $this->assertSame('2026-06-01', $lastRequest['options']['body']['dueDate']);
    }

    public function testAlterarBoletoComInstrucaoCompleta(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, [
            'id'     => 'slip-004',
            'status' => 'UPDATED',
        ]);

        $gateway = $this->createGateway();
        $instrucao = InstrucaoBoleto::fromArray([
            'vencimento'      => '2026-07-01',
            'valor'           => '300.00',
            'percentualMulta' => '2.00',
            'dataMulta'       => '2026-07-02',
            'diasProtesto'    => 30,
            'diasBaixa'       => 90,
            'operacao'        => 'ALTER_DUE_DATE',
        ]);
        $response = $gateway->alterarBoleto('7654321,055', $instrucao);

        $this->assertSame('slip-004', $response->id);

        $body = $this->fakeHttp->getLastRequest()['options']['body'];
        $this->assertSame('7654321', $body['covenantCode']);
        $this->assertSame('055', $body['bankNumber']);
        $this->assertSame('ALTER_DUE_DATE', $body['operation']);
        $this->assertSame('2026-07-01', $body['dueDate']);
        $this->assertSame('300.00', $body['nominalValue']);
        $this->assertSame('2.00', $body['finePercentage']);
        $this->assertSame('2026-07-02', $body['fineDate']);
        $this->assertSame('30', $body['protestQuantityDays']);
        $this->assertSame('90', $body['writeOffQuantityDays']);
    }

    public function testAlterarBoletoFormatoInvalidoLancaException(): void
    {
        $this->expectException(BoletoException::class);
        $this->expectExceptionMessage('covenantCode,bankNumber');

        $gateway = $this->createGateway();
        $gateway->alterarBoleto('identificador-invalido', InstrucaoBoleto::alterarVencimento('2026-06-01'));
    }

    public function testCancelarBoleto(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, ['status' => 'CANCELLED']);

        $gateway = $this->createGateway();
        $result = $gateway->cancelarBoleto('3567206,1014');

        $this->assertTrue($result);

        $lastRequest = $this->fakeHttp->getLastRequest();
        $this->assertSame('PATCH', $lastRequest['method']);
        $this->assertSame('BAIXAR', $lastRequest['options']['body']['operation']);
        $this->assertSame('3567206', $lastRequest['options']['body']['covenantCode']);
        $this->assertSame('1014', $lastRequest['options']['body']['bankNumber']);
    }

    public function testCancelarBoletoComInstrucaoExtra(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, ['status' => 'CANCELLED']);

        $gateway = $this->createGateway();
        $instrucao = InstrucaoBoleto::fromArray([
            'seuNumero'   => 'REF-001',
            'dadosExtras' => ['participantCode' => 'PART-555'],
        ]);
        $result = $gateway->cancelarBoleto('3567206,1014', $instrucao);

        $this->assertTrue($result);

        $body = $this->fakeHttp->getLastRequest()['options']['body'];
        $this->assertSame('BAIXAR', $body['operation']);
        $this->assertSame('3567206', $body['covenantCode']);
        $this->assertSame('1014', $body['bankNumber']);
        $this->assertSame('REF-001', $body['clientNumber']);
        $this->assertSame('PART-555', $body['participantCode']);
    }

    public function testCancelarBoletoFormatoInvalidoLancaException(): void
    {
        $this->expectException(BoletoException::class);
        $this->expectExceptionMessage('covenantCode,bankNumber');

        $gateway = $this->createGateway();
        $gateway->cancelarBoleto('identificador-invalido');
    }

    public function testAlterarBoletoInfereOperacaoValor(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, ['id' => 'slip-v', 'status' => 'UPDATED']);

        $gateway = $this->createGateway();
        $instrucao = InstrucaoBoleto::alterarValor('500.00');
        $gateway->alterarBoleto('111,222', $instrucao);

        $body = $this->fakeHttp->getLastRequest()['options']['body'];
        $this->assertSame('ALTER_NOMINAL_VALUE', $body['operation']);
        $this->assertSame('500.00', $body['nominalValue']);
    }

    public function testAlterarBoletoComDadosExtras(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, ['id' => 'slip-e', 'status' => 'UPDATED']);

        $gateway = $this->createGateway();
        $instrucao = InstrucaoBoleto::fromArray([
            'vencimento'  => '2026-09-01',
            'dadosExtras' => ['participantCode' => 'PART-999'],
        ]);
        $gateway->alterarBoleto('111,222', $instrucao);

        $body = $this->fakeHttp->getLastRequest()['options']['body'];
        $this->assertSame('PART-999', $body['participantCode']);
        $this->assertSame('2026-09-01', $body['dueDate']);
    }

    public function testGerarPdfRetornaUrl(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, [
            'link' => 'https://cft.santander.com.br/external/yk_ext/final/abc123',
        ]);

        $gateway = $this->createGateway();
        $pdfUrl = $gateway->gerarPdf('033.0794760', '11672771471');

        $this->assertSame('https://cft.santander.com.br/external/yk_ext/final/abc123', $pdfUrl);

        $lastRequest = $this->fakeHttp->getLastRequest();
        $this->assertSame('POST', $lastRequest['method']);
        $this->assertStringContainsString('/bills/033.0794760/bank_slips', $lastRequest['url']);
        $this->assertSame(11672771471, $lastRequest['options']['body']['payerDocumentNumber']);
    }

    public function testGerarPdfComDigitableLine(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, [
            'link' => 'https://cft.santander.com.br/external/yk_ext/final/def456',
        ]);

        $gateway = $this->createGateway();
        $pdfUrl = $gateway->gerarPdf('03399356782060000000201234501011693970000000100', '12345678900');

        $this->assertSame('https://cft.santander.com.br/external/yk_ext/final/def456', $pdfUrl);

        $lastRequest = $this->fakeHttp->getLastRequest();
        $this->assertStringContainsString('/bills/03399356782060000000201234501011693970000000100/bank_slips', $lastRequest['url']);
    }

    public function testDownloadPdfBaixaBinario(): void
    {
        $this->enqueueAuthResponse();
        // gerarPdf retorna a URL
        $this->fakeHttp->addResponse(200, [
            'link' => 'https://cft.santander.com.br/external/yk_ext/final/abc123',
        ]);
        // downloadFromUrl baixa o binario
        $this->fakeHttp->addResponse(200, null, '%PDF-1.4 conteudo-binario');

        $gateway = $this->createGateway();
        $binary = $gateway->downloadPdf('033.0794760', '11672771471');

        $this->assertStringContainsString('%PDF', $binary);
        $this->assertSame(3, $this->fakeHttp->getRequestCount());

        $lastRequest = $this->fakeHttp->getLastRequest();
        $this->assertSame('GET', $lastRequest['method']);
        $this->assertStringContainsString('cft.santander.com.br', $lastRequest['url']);
    }

    public function testErroSemWorkspaceId(): void
    {
        $this->expectException(BoletoException::class);
        $this->expectExceptionMessage('workspaceId');

        $gateway = $this->createGateway('');
        $gateway->criarBoleto($this->createBoleto());
    }

    public function testErroConfigObrigatoria(): void
    {
        $this->expectException(BoletoException::class);
        $this->expectExceptionMessage('clientId');

        new SantanderGateway([
            'clientSecret' => 'x',
            'certFile'     => 'x',
            'certKeyFile'  => 'x',
            'tokenStorage' => $this->tokenStorage,
        ]);
    }

    public function testErroSemCertificadoLancaException(): void
    {
        $this->expectException(BoletoException::class);
        $this->expectExceptionMessage('Certificado mTLS obrigatorio');

        new SantanderGateway([
            'clientId'     => 'x',
            'clientSecret' => 'x',
            'tokenStorage' => $this->tokenStorage,
        ]);
    }

    public function testCriarGatewayComCertContent(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, ['id' => 'slip-content', 'status' => 'OPEN']);

        $gateway = new SantanderGateway([
            'clientId'       => 'test-client-id',
            'clientSecret'   => 'test-client-secret',
            'certContent'    => '-----BEGIN CERTIFICATE-----\nFAKE\n-----END CERTIFICATE-----',
            'certKeyContent' => '-----BEGIN PRIVATE KEY-----\nFAKE\n-----END PRIVATE KEY-----',
            'tokenStorage'   => $this->tokenStorage,
            'workspaceId'    => 'ws-content',
            'httpClient'     => $this->fakeHttp,
        ]);

        $response = $gateway->consultarBoleto('slip-content');
        $this->assertSame('slip-content', $response->id);
    }

    public function testErroSemTokenStorageNemTokenPath(): void
    {
        $this->expectException(BoletoException::class);
        $this->expectExceptionMessage('tokenStorage');

        new SantanderGateway([
            'clientId'     => 'x',
            'clientSecret' => 'x',
            'certFile'     => 'x',
            'certKeyFile'  => 'x',
        ]);
    }

    public function testTokenArmazenadoNoStorage(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, ['id' => 'slip-z', 'status' => 'OPEN']);

        $gateway = $this->createGateway();
        $gateway->consultarBoleto('slip-z');

        $stored = $this->tokenStorage->get('test_santander_token');
        $this->assertSame('fake-token-abc', $stored);
    }

    public function testCriarWorkspace(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(201, [
            'id'          => 'ws-new-001',
            'type'        => 'BILLING',
            'description' => 'Test',
        ]);

        $gateway = $this->createGateway();
        $result = $gateway->criarWorkspace([
            'type'        => 'BILLING',
            'description' => 'Test',
            'covenants'   => [['code' => '1234567']],
        ]);

        $this->assertSame('ws-new-001', $result['id']);

        $lastRequest = $this->fakeHttp->getLastRequest();
        $this->assertSame('POST', $lastRequest['method']);
        $this->assertStringContainsString('/workspaces', $lastRequest['url']);
    }

    public function testSetWorkspaceId(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, ['id' => 'slip-x', 'status' => 'OPEN']);

        $gateway = $this->createGateway('');

        $gateway->setWorkspaceId('ws-dynamic');
        $response = $gateway->consultarBoleto('slip-x');

        $this->assertSame('slip-x', $response->id);

        $lastRequest = $this->fakeHttp->getLastRequest();
        $this->assertStringContainsString('/workspaces/ws-dynamic/', $lastRequest['url']);
    }

    // -- Testes do BankSetupInterface --

    public function testImplementaBankSetupInterface(): void
    {
        $gateway = $this->createGateway();
        $this->assertInstanceOf(BankSetupInterface::class, $gateway);
    }

    public function testSetupCriaWorkspaceESetaId(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(201, [
            'id'          => 'ws-setup-001',
            'type'        => 'BILLING',
            'description' => 'Workspace de Cobranca',
        ]);

        $gateway = $this->createGateway('');
        $this->assertFalse($gateway->isConfigurado());

        $result = $gateway->setup([
            'covenantCode'  => '1234567',
            'webhookUrl'    => 'https://meu-app.com/webhook',
            'description'   => 'Meu Workspace',
            'boleto_webhook' => true,
            'pix_webhook'    => true,
        ]);

        $this->assertSame('ws-setup-001', $result['id']);
        $this->assertTrue($gateway->isConfigurado());

        $lastRequest = $this->fakeHttp->getLastRequest();
        $this->assertSame('POST', $lastRequest['method']);
        $body = $lastRequest['options']['body'];
        $this->assertSame('BILLING', $body['type']);
        $this->assertSame('Meu Workspace', $body['description']);
        $this->assertSame([['code' => '1234567']], $body['covenants']);
        $this->assertSame('https://meu-app.com/webhook', $body['webhookURL']);
        $this->assertTrue($body['bankSlipBillingWebhookActive']);
        $this->assertTrue($body['pixBillingWebhookActive']);
    }

    public function testSetupSemCovenantCodeLancaException(): void
    {
        $this->expectException(BoletoException::class);
        $this->expectExceptionMessage('covenantCode');

        $gateway = $this->createGateway('');
        $gateway->setup([]);
    }

    public function testSetupComDefaultsMinimos(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(201, [
            'id'   => 'ws-min-001',
            'type' => 'BILLING',
        ]);

        $gateway = $this->createGateway('');
        $result = $gateway->setup([
            'covenantCode' => '9999999',
        ]);

        $this->assertSame('ws-min-001', $result['id']);

        $body = $this->fakeHttp->getLastRequest()['options']['body'];
        $this->assertSame('Workspace de Cobranca', $body['description']);
        $this->assertTrue($body['bankSlipBillingWebhookActive']);
        $this->assertFalse($body['pixBillingWebhookActive']);
        $this->assertArrayNotHasKey('webhookURL', $body);
    }

    public function testConsultarSetupPorId(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, [
            'id'          => 'ws-abc',
            'type'        => 'BILLING',
            'description' => 'Test WS',
        ]);

        $gateway = $this->createGateway();
        $result = $gateway->consultarSetup('ws-abc');

        $this->assertSame('ws-abc', $result['id']);

        $lastRequest = $this->fakeHttp->getLastRequest();
        $this->assertSame('GET', $lastRequest['method']);
        $this->assertStringContainsString('/workspaces/ws-abc', $lastRequest['url']);
    }

    public function testConsultarSetupListaTodos(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, [
            ['id' => 'ws-1'],
            ['id' => 'ws-2'],
        ]);

        $gateway = $this->createGateway();
        $result = $gateway->consultarSetup(null);

        $this->assertCount(2, $result);

        $lastRequest = $this->fakeHttp->getLastRequest();
        $this->assertSame('GET', $lastRequest['method']);
        $this->assertStringContainsString('/workspaces', $lastRequest['url']);
    }

    public function testAtualizarSetup(): void
    {
        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, [
            'id'         => 'ws-upd',
            'webhookURL' => 'https://novo-webhook.com',
        ]);

        $gateway = $this->createGateway();
        $result = $gateway->atualizarSetup('ws-upd', [
            'webhookURL' => 'https://novo-webhook.com',
        ]);

        $this->assertSame('ws-upd', $result['id']);

        $lastRequest = $this->fakeHttp->getLastRequest();
        $this->assertSame('PATCH', $lastRequest['method']);
        $this->assertStringContainsString('/workspaces/ws-upd', $lastRequest['url']);
    }

    public function testIsConfigurado(): void
    {
        $gateway = $this->createGateway('');
        $this->assertFalse($gateway->isConfigurado());

        $gateway->setWorkspaceId('ws-123');
        $this->assertTrue($gateway->isConfigurado());
    }

    public function testSetupDepoisOperaBoleto(): void
    {
        // Auth
        $this->enqueueAuthResponse();
        // Setup response
        $this->fakeHttp->addResponse(201, ['id' => 'ws-flow-001', 'type' => 'BILLING']);
        // Criar boleto response
        $this->fakeHttp->addResponse(201, [
            'id'            => 'slip-flow',
            'barCode'       => '99999999999',
            'digitableLine' => '99999.99999',
            'status'        => 'OPEN',
            'nominalValue'  => '150.00',
        ]);

        $gateway = $this->createGateway('');

        $gateway->setup(['covenantCode' => '7777777']);

        $response = $gateway->criarBoleto($this->createBoleto());
        $this->assertSame('slip-flow', $response->id);

        $lastRequest = $this->fakeHttp->getLastRequest();
        $this->assertStringContainsString('/workspaces/ws-flow-001/bank_slips', $lastRequest['url']);
    }

    // -- Testes de ambiente (producao / sandbox) --

    public function testAmbienteDefaultProducao(): void
    {
        $gateway = $this->createGateway();
        $this->assertSame('producao', $gateway->getAmbiente());
    }

    public function testAmbienteSandbox(): void
    {
        $gateway = $this->createGateway('ws-123', 'sandbox');
        $this->assertSame('sandbox', $gateway->getAmbiente());
    }

    public function testAmbienteSandboxUsaUrlCorreta(): void
    {
        $gatewaySandbox = new SantanderGateway([
            'clientId'        => 'test-client-id',
            'clientSecret'    => 'test-client-secret',
            'certFile'        => '/fake/cert.pem',
            'certKeyFile'     => '/fake/key.pem',
            'tokenStorage'    => $this->tokenStorage,
            'workspaceId'     => 'ws-sandbox',
            'ambiente'        => 'sandbox',
            'httpClient'      => $this->fakeHttp,
        ]);

        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, ['id' => 'slip-sb', 'status' => 'OPEN']);

        $gatewaySandbox->consultarBoleto('slip-sb');

        $lastRequest = $this->fakeHttp->getLastRequest();
        $this->assertStringContainsString('trust-sandbox.api.santander.com.br', $lastRequest['url']);
    }

    public function testAmbienteProducaoUsaUrlCorreta(): void
    {
        $gatewayProd = new SantanderGateway([
            'clientId'        => 'test-client-id',
            'clientSecret'    => 'test-client-secret',
            'certFile'        => '/fake/cert.pem',
            'certKeyFile'     => '/fake/key.pem',
            'tokenStorage'    => $this->tokenStorage,
            'workspaceId'     => 'ws-prod',
            'ambiente'        => 'producao',
            'httpClient'      => $this->fakeHttp,
        ]);

        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, ['id' => 'slip-pd', 'status' => 'OPEN']);

        $gatewayProd->consultarBoleto('slip-pd');

        $lastRequest = $this->fakeHttp->getLastRequest();
        $this->assertStringContainsString('trust-open.api.santander.com.br', $lastRequest['url']);
    }

    public function testAmbienteAliasHomologacao(): void
    {
        $gateway = new SantanderGateway([
            'clientId'        => 'test-client-id',
            'clientSecret'    => 'test-client-secret',
            'certFile'        => '/fake/cert.pem',
            'certKeyFile'     => '/fake/key.pem',
            'tokenStorage'    => $this->tokenStorage,
            'ambiente'        => 'homologacao',
            'httpClient'      => $this->fakeHttp,
        ]);

        $this->assertSame('sandbox', $gateway->getAmbiente());
    }

    public function testAmbienteInvalidoLancaException(): void
    {
        $this->expectException(BoletoException::class);
        $this->expectExceptionMessage('invalido');

        new SantanderGateway([
            'clientId'        => 'test-client-id',
            'clientSecret'    => 'test-client-secret',
            'certFile'        => '/fake/cert.pem',
            'certKeyFile'     => '/fake/key.pem',
            'tokenStorage'    => $this->tokenStorage,
            'ambiente'        => 'naoexiste',
            'httpClient'      => $this->fakeHttp,
        ]);
    }

    public function testBaseUrlCustomSobrescreveAmbiente(): void
    {
        $gateway = new SantanderGateway([
            'clientId'        => 'test-client-id',
            'clientSecret'    => 'test-client-secret',
            'certFile'        => '/fake/cert.pem',
            'certKeyFile'     => '/fake/key.pem',
            'tokenStorage'    => $this->tokenStorage,
            'workspaceId'     => 'ws-custom',
            'ambiente'        => 'sandbox',
            'baseUrl'         => 'https://custom-api.example.com',
            'httpClient'      => $this->fakeHttp,
        ]);

        $this->enqueueAuthResponse();
        $this->fakeHttp->addResponse(200, ['id' => 'slip-c', 'status' => 'OPEN']);

        $gateway->consultarBoleto('slip-c');

        $lastRequest = $this->fakeHttp->getLastRequest();
        $this->assertStringContainsString('custom-api.example.com', $lastRequest['url']);
        $this->assertSame('sandbox', $gateway->getAmbiente());
    }

    public function testTokenKeyIsoladoPorAmbiente(): void
    {
        $sharedStorage = new FakeTokenStorage();
        $sharedHttp = new FakeHttpClient();

        $sharedHttp->addResponse(200, ['access_token' => 'token-prod']);
        $sharedHttp->addResponse(200, ['id' => 'slip-p', 'status' => 'OPEN']);
        $sharedHttp->addResponse(200, ['access_token' => 'token-sandbox']);
        $sharedHttp->addResponse(200, ['id' => 'slip-s', 'status' => 'OPEN']);

        $gatewayProd = new SantanderGateway([
            'clientId'        => 'test-client-id',
            'clientSecret'    => 'test-client-secret',
            'certFile'        => '/fake/cert.pem',
            'certKeyFile'     => '/fake/key.pem',
            'tokenStorage'    => $sharedStorage,
            'workspaceId'     => 'ws-1',
            'ambiente'        => 'producao',
            'httpClient'      => $sharedHttp,
        ]);
        $gatewayProd->consultarBoleto('slip-p');

        $gatewaySandbox = new SantanderGateway([
            'clientId'        => 'test-client-id',
            'clientSecret'    => 'test-client-secret',
            'certFile'        => '/fake/cert.pem',
            'certKeyFile'     => '/fake/key.pem',
            'tokenStorage'    => $sharedStorage,
            'workspaceId'     => 'ws-2',
            'ambiente'        => 'sandbox',
            'httpClient'      => $sharedHttp,
        ]);
        $gatewaySandbox->consultarBoleto('slip-s');

        $this->assertSame('token-prod', $sharedStorage->get('santander_token_producao'));
        $this->assertSame('token-sandbox', $sharedStorage->get('santander_token_sandbox'));
    }
}
