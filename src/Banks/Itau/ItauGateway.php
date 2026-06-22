<?php

namespace ApiBoleto\Banks\Itau;

use ApiBoleto\Config\ConfigSchema;
use ApiBoleto\Contracts\BankSetupInterface;
use ApiBoleto\Contracts\BoletoGatewayInterface;
use ApiBoleto\Contracts\ConfigurableGatewayInterface;
use ApiBoleto\Contracts\HttpClientInterface;
use ApiBoleto\Contracts\TokenStorageInterface;
use ApiBoleto\DTO\Boleto;
use ApiBoleto\DTO\BoletoResponse;
use ApiBoleto\DTO\InstrucaoBoleto;
use ApiBoleto\Exceptions\ApiException;
use ApiBoleto\Exceptions\BoletoException;
use ApiBoleto\Http\CertificateHelper;
use ApiBoleto\Http\CurlHttpClient;
use ApiBoleto\Pdf\BoletoPdfRenderer;
use ApiBoleto\Storage\FileTokenStorage;

class ItauGateway implements BoletoGatewayInterface, BankSetupInterface, ConfigurableGatewayInterface
{
    private const DEFAULT_TOKEN_URL = 'https://sts.itau.com.br/api/oauth/token';
    private const DEFAULT_API_BASE_URL = 'https://api.itau.com.br/cash_management/v2';
    private const DEFAULT_CONSULTA_BASE_URL = 'https://secure.api.cloud.itau.com.br/boletoscash/v2';
    private const DEFAULT_LISTA_BASE_URL = 'https://boleto.api.itau.com/boleto/v1';
    private const DEFAULT_BOLETO_V1_BASE_URL = 'https://boleto.api.itau.com/boleto/v1';
    private const DEFAULT_WEBHOOK_BASE_URL = 'https://boletos.cloud.itau.com.br/boletos/v3';
    private const DEFAULT_PIX_REGULATORIO_BASE_URL = 'https://pix-pj.api.itau.com/regulatorio-pix/v2';

    private ItauAuthenticator $authenticator;

    private ItauMapper $mapper;

    private HttpClientInterface $httpClient;

    private CertificateHelper $certificateHelper;

    private string $ambiente;

    private string $clientId;

    private string $idBeneficiario;

    private string $codigoCarteira;

    private string $apiBaseUrl;

    private string $consultaBaseUrl;

    private string $listaBaseUrl;

    private string $boletoV1BaseUrl;

    private string $webhookBaseUrl;

    private string $pixBaseUrl;

    private string $pixRegulatorioBaseUrl;

    private string $pixEndpointPath;

    private bool $pixLegacyPayload;

    private bool $usarApiBoletosV1;

    /** @var string[] */
    private array $webhookTiposNotificacoes;

    public function __construct(array $config)
    {
        $this->validateConfig($config);

        $this->ambiente = $this->resolveAmbiente($config);
        $this->clientId = $config['clientId'];
        $this->idBeneficiario = $config['idBeneficiario'] ?? $config['id_beneficiario'];
        $this->codigoCarteira = $config['codigoCarteira'] ?? '109';
        $this->apiBaseUrl = rtrim($config['apiBaseUrl'] ?? $config['baseUrl'] ?? self::DEFAULT_API_BASE_URL, '/');
        $this->consultaBaseUrl = rtrim($config['consultaBaseUrl'] ?? self::DEFAULT_CONSULTA_BASE_URL, '/');
        $this->listaBaseUrl = rtrim($config['listaBaseUrl'] ?? self::DEFAULT_LISTA_BASE_URL, '/');
        $this->boletoV1BaseUrl = rtrim($config['boletoV1BaseUrl'] ?? self::DEFAULT_BOLETO_V1_BASE_URL, '/');
        $this->webhookBaseUrl = rtrim(
            $config['boletosV3BaseUrl'] ?? $config['webhookBaseUrl'] ?? self::DEFAULT_WEBHOOK_BASE_URL,
            '/'
        );
        $this->pixBaseUrl = rtrim($config['pixBaseUrl'] ?? '', '/');
        $this->pixRegulatorioBaseUrl = rtrim(
            $config['pixRegulatorioBaseUrl']
                ?? $config['pixWebhookBaseUrl']
                ?? self::DEFAULT_PIX_REGULATORIO_BASE_URL,
            '/'
        );
        $this->pixLegacyPayload = (bool) (
            $config['pixLegacyPayload']
            ?? $config['bolecodeLegacyPayload']
            ?? false
        );
        $this->pixEndpointPath = $this->normalizeEndpointPath(
            $config['pixEndpointPath'] ?? ($this->pixLegacyPayload ? '/boletos_pix' : '/boletos-pix')
        );
        if ($this->pixEndpointPath === '/boletos_pix') {
            $this->pixLegacyPayload = true;
        }
        $this->usarApiBoletosV1 = (bool) ($config['usarApiBoletosV1'] ?? $config['useBoletoV1'] ?? false);
        $this->webhookTiposNotificacoes = $this->resolveWebhookTiposNotificacoes($config);

        $this->certificateHelper = new CertificateHelper($config);
        $this->httpClient = $config['httpClient'] ?? new CurlHttpClient();
        $this->mapper = new ItauMapper(
            $this->idBeneficiario,
            $this->codigoCarteira,
            $config['codigoEspecie'] ?? '01',
            $config['tipoBoleto'] ?? 'a vista',
            $config['etapaProcesso'] ?? 'efetivacao',
            $this->pixLegacyPayload
        );

        $tokenStorage = $this->resolveTokenStorage($config);
        $tokenKey = $config['tokenKey'] ?? 'itau_token_' . $this->ambiente . '_' . $this->idBeneficiario;

        $this->authenticator = new ItauAuthenticator(
            $this->httpClient,
            $tokenStorage,
            $tokenKey,
            $config['tokenUrl'] ?? self::DEFAULT_TOKEN_URL,
            $this->clientId,
            $config['clientSecret'],
            $this->certificateHelper->toCertConfig()
        );
    }

    public function getAmbiente(): string
    {
        return $this->ambiente;
    }

    public function criarBoleto(Boleto $boleto): BoletoResponse
    {
        if ($this->usarApiBoletosV1) {
            $response = $this->authenticatedRequest(
                'POST',
                $this->boletoV1BaseUrl . '/boletos',
                $this->mapper->toBoletoV1Payload($boleto)
            );

            return $this->mapper->toResponse($response['body'] ?? []);
        }

        if ($this->mapper->shouldUsePix($boleto)) {
            if ($this->pixBaseUrl === '') {
                throw new BoletoException(
                    "pixBaseUrl e obrigatorio para emissao de Bolecode Pix no Itau."
                );
            }

            $response = $this->authenticatedRequest(
                'POST',
                $this->pixBaseUrl . $this->pixEndpointPath,
                $this->mapper->toPixPayload($boleto),
                [],
                true
            );

            return $this->mapper->toResponse($response['body'] ?? []);
        }

        $response = $this->authenticatedRequest(
            'POST',
            $this->apiBaseUrl . '/boletos',
            $this->mapper->toApiPayload($boleto)
        );

        return $this->mapper->toResponse($response['body'] ?? []);
    }

    public function consultarBoleto(string $identificador): BoletoResponse
    {
        if (trim($identificador) === '') {
            throw new BoletoException('Identificador do boleto e obrigatorio para consulta no Itau.');
        }

        $query = $this->buildConsultaQuery($identificador);
        $response = $this->authenticatedRequest('GET', $this->consultaBaseUrl . '/boletos', [], $query);

        return $this->mapper->toResponse($response['body'] ?? []);
    }

    public function consultarBoletos(array $filtros = []): array
    {
        if (isset($filtros['nossoNumero']) || isset($filtros['nosso_numero'])) {
            $query = $this->normalizeConsultaFilters($filtros);
            $response = $this->authenticatedRequest('GET', $this->consultaBaseUrl . '/boletos', [], $query);

            return $this->mapper->toResponseList($response['body'] ?? []);
        }

        $query = $this->normalizeListFilters($filtros);
        $response = $this->authenticatedRequest('GET', $this->listaBaseUrl . '/boletos', [], $query);

        return $this->mapper->toResponseList($response['body'] ?? []);
    }

    public function alterarBoleto(string $identificador, InstrucaoBoleto $instrucao): BoletoResponse
    {
        if (trim($identificador) === '') {
            throw new BoletoException('id_boleto e obrigatorio para alteracao no Itau.');
        }

        $idBoleto = $this->normalizeIdBoleto($identificador);
        $request = $this->mapper->toInstrucaoRequest($idBoleto, $instrucao);
        $response = $this->authenticatedRequest(
            'PATCH',
            $this->apiBaseUrl . $request['path'],
            $request['body']
        );

        return $this->mapper->toResponse($response['body'] ?? []);
    }

    public function cancelarBoleto(string $identificador, ?InstrucaoBoleto $instrucao = null): bool
    {
        if ($instrucao === null) {
            $instrucao = InstrucaoBoleto::baixar();
        } else {
            $instrucao->operacao = 'BAIXAR';
        }

        $this->alterarBoleto($identificador, $instrucao);

        return true;
    }

    public function gerarPdf(string $identificador, string $payerDocumentNumber = ''): string
    {
        $response = $this->consultarBoleto($identificador);

        if ($response->urlPdf === '') {
            throw new BoletoException(
                'O Itau nao retornou URL de PDF para este boleto. Use downloadPdf() '
                . 'para gerar um PDF local a partir dos dados retornados pela API.'
            );
        }

        return $response->urlPdf;
    }

    public function downloadPdf(string $identificador, string $payerDocumentNumber = ''): string
    {
        $boleto = $this->consultarBoleto($identificador);

        if ($boleto->pdfBase64 !== '') {
            $decoded = base64_decode($this->normalizeBase64($boleto->pdfBase64), true);
            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }
        }

        if ($boleto->urlPdf === '') {
            return (new BoletoPdfRenderer())->render($boleto, [
                'bankName' => 'Banco Itau S.A.',
                'bankCode' => '341',
            ]);
        }

        $response = $this->httpClient->request('GET', $boleto->urlPdf, ['rawResponse' => true]);

        return $response['rawBody'] ?? '';
    }

    public function setup(array $params): array
    {
        $payload = ['data' => $this->mapper->toWebhookPayload($params)];

        if (empty($payload['data']['webhook_url'])) {
            throw new BoletoException("Parametro 'webhookUrl' e obrigatorio para setup de webhook Itau.");
        }

        $response = $this->authenticatedRequest(
            'POST',
            $this->webhookBaseUrl . '/notificacoes_boletos',
            $payload,
            [],
            true
        );

        return $response['body'] ?? [];
    }

    public function consultarSetup(?string $id = null): array
    {
        $url = $this->webhookBaseUrl . '/notificacoes_boletos';

        if ($id !== null) {
            $url .= '/' . rawurlencode($id);
            $response = $this->authenticatedRequest('GET', $url, [], [], true);

            return $response['body'] ?? [];
        }

        $setups = [];
        foreach ($this->webhookTiposNotificacoes as $tipoNotificacao) {
            try {
                $response = $this->authenticatedRequest('GET', $url, [], [
                    'id_beneficiario' => $this->idBeneficiario,
                    'tipo_notificacao' => $tipoNotificacao,
                ], true);
            } catch (ApiException $exception) {
                if ($exception->getStatusCode() === 404) {
                    continue;
                }

                throw $exception;
            }

            $setups = array_merge($setups, $this->extractSetupItems($response['body'] ?? []));
        }

        return ['data' => $this->deduplicateSetupItems($setups)];
    }

    public function atualizarSetup(string $id, array $params): array
    {
        $response = $this->authenticatedRequest(
            'PATCH',
            $this->webhookBaseUrl . '/notificacoes_boletos/' . rawurlencode($id),
            $params,
            [],
            true
        );

        return $response['body'] ?? [];
    }

    public function isConfigurado(): bool
    {
        return $this->idBeneficiario !== '';
    }

    public function excluirWebhook(string $id): bool
    {
        $this->authenticatedRequest(
            'DELETE',
            $this->webhookBaseUrl . '/notificacoes_boletos/' . rawurlencode($id),
            [],
            [],
            true
        );

        return true;
    }

    public function setupPixWebhook(string $chave, string $webhookUrl): array
    {
        $chave = trim($chave);
        $webhookUrl = rtrim(trim($webhookUrl), '/');

        if ($chave === '') {
            throw new BoletoException("Parametro 'chave' e obrigatorio para setup de webhook Pix Itau.");
        }

        if ($webhookUrl === '') {
            throw new BoletoException("Parametro 'webhookUrl' e obrigatorio para setup de webhook Pix Itau.");
        }

        $response = $this->authenticatedRequest(
            'PUT',
            $this->pixRegulatorioBaseUrl . '/webhook/' . rawurlencode($chave),
            ['webhookUrl' => $webhookUrl],
            [],
            true
        );

        return $response['body'] ?? [];
    }

    public function consultarPixWebhook(?string $chave = null, array $filtros = []): array
    {
        $url = $this->pixRegulatorioBaseUrl . '/webhook';

        if ($chave !== null && trim($chave) !== '') {
            $url .= '/' . rawurlencode(trim($chave));
        }

        $response = $this->authenticatedRequest(
            'GET',
            $url,
            [],
            $this->normalizePixWebhookFilters($filtros),
            true
        );

        return $response['body'] ?? [];
    }

    public function excluirPixWebhook(string $chave): bool
    {
        $chave = trim($chave);
        if ($chave === '') {
            throw new BoletoException("Parametro 'chave' e obrigatorio para excluir webhook Pix Itau.");
        }

        $this->authenticatedRequest(
            'DELETE',
            $this->pixRegulatorioBaseUrl . '/webhook/' . rawurlencode($chave),
            [],
            [],
            true
        );

        return true;
    }

    public function consultarPixRecebidos(array $filtros): array
    {
        $response = $this->authenticatedRequest(
            'GET',
            $this->pixRegulatorioBaseUrl . '/pix',
            [],
            $this->normalizePixRecebidosFilters($filtros),
            true
        );

        return $response['body'] ?? [];
    }

    public function consultarPixRecebido(string $endToEndId): array
    {
        $endToEndId = trim($endToEndId);
        if ($endToEndId === '') {
            throw new BoletoException("Parametro 'endToEndId' e obrigatorio para consultar Pix Itau.");
        }

        $response = $this->authenticatedRequest(
            'GET',
            $this->pixRegulatorioBaseUrl . '/pix/' . rawurlencode($endToEndId),
            [],
            [],
            true
        );

        return $response['body'] ?? [];
    }

    public function consultarFrancesas(array $filtros = []): array
    {
        $response = $this->authenticatedRequest(
            'GET',
            $this->webhookBaseUrl . '/francesas',
            [],
            $this->normalizeFrancesaFilters($filtros),
            true
        );

        return $response['body'] ?? [];
    }

    public function consultarMovimentacoesFrancesa(string $idFrancesa, array $filtros): array
    {
        $idFrancesa = trim($idFrancesa);
        if ($idFrancesa === '') {
            throw new BoletoException('id_francesa e obrigatorio para consulta de movimentacoes no Itau.');
        }

        $response = $this->authenticatedRequest(
            'GET',
            $this->webhookBaseUrl . '/francesas/' . rawurlencode($idFrancesa) . '/movimentacoes',
            [],
            $this->normalizeMovimentacaoFrancesaFilters($filtros),
            true
        );

        return $response['body'] ?? [];
    }

    public function consultarMovimentacoesResumidasFrancesa(string $idFrancesa, array $filtros): array
    {
        $idFrancesa = trim($idFrancesa);
        if ($idFrancesa === '') {
            throw new BoletoException('id_francesa e obrigatorio para consulta resumida de movimentacoes no Itau.');
        }

        $response = $this->authenticatedRequest(
            'GET',
            $this->webhookBaseUrl . '/francesas/' . rawurlencode($idFrancesa) . '/movimentacoes_resumidas',
            [],
            $this->normalizeMovimentacaoFrancesaResumoFilters($filtros),
            true
        );

        return $response['body'] ?? [];
    }

    public function setIdBeneficiario(string $idBeneficiario): void
    {
        $this->idBeneficiario = $idBeneficiario;
        $this->mapper->setIdBeneficiario($idBeneficiario);
    }

    private function authenticatedRequest(
        string $method,
        string $url,
        array $body = [],
        array $query = [],
        bool $pixHeaders = false
    ): array {
        $token = $this->authenticator->getToken();
        $correlationId = $this->uuid();

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
            'x-itau-apikey: ' . $this->clientId,
            'x-itau-correlationID: ' . $correlationId,
            'x-itau-flowID: ' . $this->uuid(),
        ];

        if ($pixHeaders) {
            $headers[] = 'auth: Bearer ' . $token;
        }

        $options = [
            'headers' => $headers,
            'cert' => $this->certificateHelper->toCertConfig(),
        ];

        if (!empty($body) || in_array(strtoupper($method), ['POST', 'PATCH', 'PUT'], true)) {
            $options['body'] = $body;
        }

        if (!empty($query)) {
            $options['query'] = $query;
        }

        return $this->httpClient->request($method, $url, $options);
    }

    private function buildConsultaQuery(string $identificador): array
    {
        $parts = array_map('trim', explode(',', $identificador));

        if (count($parts) >= 3) {
            $query = [
                'id_beneficiario' => $parts[0],
                'codigo_carteira' => $parts[1],
                'nosso_numero' => $parts[2],
                'view' => 'specific',
            ];

            if (isset($parts[3]) && $parts[3] !== '') {
                $query['data_inclusao'] = $parts[3];
            }

            return $query;
        }

        return [
            'id_beneficiario' => $this->idBeneficiario,
            'codigo_carteira' => $this->codigoCarteira,
            'nosso_numero' => $identificador,
            'view' => 'specific',
        ];
    }

    private function normalizeIdBoleto(string $identificador): string
    {
        $clean = trim($identificador);
        $parts = array_map('trim', explode(',', $clean));

        if (count($parts) >= 3) {
            return $parts[0] . $parts[1] . $parts[2];
        }

        $digits = preg_replace('/\D/', '', $clean) ?? '';
        if (strlen($digits) >= 23) {
            return $digits;
        }

        return $this->idBeneficiario . $this->codigoCarteira . $digits;
    }

    private function normalizeConsultaFilters(array $filtros): array
    {
        return array_filter([
            'id_beneficiario' => $filtros['id_beneficiario'] ?? $filtros['idBeneficiario'] ?? $this->idBeneficiario,
            'codigo_carteira' => $filtros['codigo_carteira'] ?? $filtros['codigoCarteira'] ?? $this->codigoCarteira,
            'nosso_numero' => $filtros['nosso_numero'] ?? $filtros['nossoNumero'] ?? null,
            'data_inclusao' => $filtros['data_inclusao'] ?? $filtros['dataInclusao'] ?? null,
            'view' => $filtros['view'] ?? 'specific',
        ], static fn($value): bool => $value !== null && $value !== '');
    }

    private function normalizeListFilters(array $filtros): array
    {
        $query = $filtros;

        if (!isset($query['idBeneficiario']) && !isset($query['id_beneficiario'])) {
            $query['idBeneficiario'] = $this->idBeneficiario;
        }

        if (isset($query['id_beneficiario'])) {
            $query['idBeneficiario'] = $query['id_beneficiario'];
            unset($query['id_beneficiario']);
        }

        return $query;
    }

    private function normalizeFrancesaFilters(array $filtros): array
    {
        $agencia = (string) ($filtros['agencia'] ?? '');
        $conta = (string) ($filtros['conta'] ?? '');
        $dac = (string) ($filtros['dac'] ?? '');

        if (($agencia === '' || $conta === '' || $dac === '') && strlen($this->idBeneficiario) >= 12) {
            $agencia = $agencia ?: substr($this->idBeneficiario, 0, 4);
            $conta = $conta ?: substr($this->idBeneficiario, 4, 7);
            $dac = $dac ?: substr($this->idBeneficiario, 11, 1);
        }

        if ($agencia === '' || $conta === '' || $dac === '') {
            throw new BoletoException(
                "Informe 'agencia', 'conta' e 'dac' para consultar francesas no Itau."
            );
        }

        return array_filter([
            'agencia' => $agencia,
            'conta' => $conta,
            'dac' => $dac,
            'mes_referencia' => $filtros['mes_referencia'] ?? $filtros['mesReferencia'] ?? null,
            'data' => $filtros['data'] ?? null,
        ], static fn($value): bool => $value !== null && $value !== '');
    }

    private function normalizeMovimentacaoFrancesaFilters(array $filtros): array
    {
        $query = $this->normalizeMovimentacaoFrancesaResumoFilters($filtros);

        $query = array_merge($query, array_filter([
            'tipo_cobranca' => $filtros['tipo_cobranca'] ?? $filtros['tipoCobranca'] ?? null,
            'tipo_movimentacao' => $filtros['tipo_movimentacao'] ?? $filtros['tipoMovimentacao'] ?? null,
            'nosso_numero' => $filtros['nosso_numero'] ?? $filtros['nossoNumero'] ?? null,
            'seu_numero' => $filtros['seu_numero'] ?? $filtros['seuNumero'] ?? null,
            'numero_carteira' => $filtros['numero_carteira'] ?? $filtros['numeroCarteira'] ?? null,
            'nome_pagador' => $filtros['nome_pagador'] ?? $filtros['nomePagador'] ?? null,
        ], static fn($value): bool => $value !== null && $value !== ''));

        return $query;
    }

    private function normalizeMovimentacaoFrancesaResumoFilters(array $filtros): array
    {
        $data = (string) ($filtros['data'] ?? '');
        if ($data === '') {
            throw new BoletoException("Informe 'data' para consultar movimentacoes de francesa no Itau.");
        }

        return ['data' => $data];
    }

    private function normalizePixWebhookFilters(array $filtros): array
    {
        return array_filter([
            'inicio' => $filtros['inicio'] ?? null,
            'fim' => $filtros['fim'] ?? null,
            'paginacao.paginaAtual' => $filtros['paginaAtual'] ?? $filtros['paginacao.paginaAtual'] ?? null,
            'paginacao.itensPorPagina' => $filtros['itensPorPagina'] ?? $filtros['paginacao.itensPorPagina'] ?? null,
        ], static fn($value): bool => $value !== null && $value !== '');
    }

    private function normalizePixRecebidosFilters(array $filtros): array
    {
        foreach (['inicio', 'fim'] as $field) {
            if (empty($filtros[$field])) {
                throw new BoletoException("Informe '{$field}' para consultar Pix recebidos no Itau.");
            }
        }

        return array_filter([
            'inicio' => $filtros['inicio'],
            'fim' => $filtros['fim'],
            'txidPresente' => $filtros['txidPresente'] ?? $filtros['txid_presente'] ?? null,
            'devolucaoPresente' => $filtros['devolucaoPresente'] ?? $filtros['devolucao_presente'] ?? null,
            'cpf' => $filtros['cpf'] ?? null,
            'cnpj' => $filtros['cnpj'] ?? null,
            'paginacao.paginaAtual' => $filtros['paginaAtual'] ?? $filtros['paginacao.paginaAtual'] ?? null,
            'paginacao.itensPorPagina' => $filtros['itensPorPagina'] ?? $filtros['paginacao.itensPorPagina'] ?? null,
        ], static fn($value): bool => $value !== null && $value !== '');
    }

    private function resolveTokenStorage(array $config): TokenStorageInterface
    {
        if (isset($config['tokenStorage']) && $config['tokenStorage'] instanceof TokenStorageInterface) {
            return $config['tokenStorage'];
        }

        if (!empty($config['tokenPath'])) {
            $dir = dirname($config['tokenPath']);
            return new FileTokenStorage($dir !== '.' ? $dir : sys_get_temp_dir());
        }

        return new FileTokenStorage(sys_get_temp_dir());
    }

    private function resolveAmbiente(array $config): string
    {
        $ambiente = strtolower($config['ambiente'] ?? 'producao');
        $aliases = [
            'producao' => 'producao',
            'production' => 'producao',
            'prod' => 'producao',
            'sandbox' => 'sandbox',
            'homologacao' => 'sandbox',
            'homologation' => 'sandbox',
            'dev' => 'sandbox',
        ];

        if (!isset($aliases[$ambiente])) {
            throw new BoletoException("Ambiente '{$ambiente}' invalido. Use 'producao' ou 'sandbox'.");
        }

        return $aliases[$ambiente];
    }

    public static function configSchema(): ConfigSchema
    {
        return ConfigSchema::create('Itau')
            ->required('clientId', 'string', 'Client ID / credencial Itau')
            ->required('clientSecret', 'string', 'Client Secret Itau')
            ->requireOneOf('beneficiario', [
                ['idBeneficiario'],
                ['id_beneficiario'],
            ], "ID do beneficiario obrigatorio. Informe 'idBeneficiario' ou 'id_beneficiario'.")
            ->requireOneOf('certificado', [
                ['certFile', 'certKeyFile'],
                ['certContent', 'certKeyContent'],
            ], "Certificado mTLS obrigatorio. Informe 'certFile'+'certKeyFile' ou 'certContent'+'certKeyContent'.")
            ->requireOneOf('tokenStorage', [
                ['tokenStorage'],
                ['tokenPath'],
            ], "Armazenamento de token obrigatorio. Informe 'tokenStorage' ou 'tokenPath'.")
            ->optional('certKeyPassword', 'string', '', 'Senha da chave privada do certificado')
            ->optional('ambiente', 'string', 'producao', 'Ambiente: producao ou sandbox')
            ->optional('codigoCarteira', 'string', '109', 'Codigo da carteira Itau')
            ->optional('codigoEspecie', 'string', '01', 'Codigo da especie do titulo')
            ->optional('tipoBoleto', 'string', 'a vista', 'Tipo do boleto')
            ->optional('etapaProcesso', 'string', 'efetivacao', 'Etapa: validacao/simulacao/efetivacao')
            ->optional('tokenUrl', 'string', self::DEFAULT_TOKEN_URL, 'URL de obtencao do access_token')
            ->optional('apiBaseUrl', 'string', self::DEFAULT_API_BASE_URL, 'Base URL boletos/instrucoes')
            ->optional('consultaBaseUrl', 'string', self::DEFAULT_CONSULTA_BASE_URL, 'Base URL consulta detalhe')
            ->optional('listaBaseUrl', 'string', self::DEFAULT_LISTA_BASE_URL, 'Base URL listagem')
            ->optional('boletoV1BaseUrl', 'string', self::DEFAULT_BOLETO_V1_BASE_URL, 'Base URL API Boletos v1')
            ->optional('usarApiBoletosV1', 'bool', false, 'Usa API Boletos v1 para criar boletos')
            ->optional('useBoletoV1', 'bool', false, 'Alias para usarApiBoletosV1')
            ->optional('webhookBaseUrl', 'string', self::DEFAULT_WEBHOOK_BASE_URL, 'Base URL webhook boletos v3')
            ->optional('boletosV3BaseUrl', 'string', self::DEFAULT_WEBHOOK_BASE_URL, 'Base URL API Boletos v3')
            ->optional('pixBaseUrl', 'string', '', 'Base URL Bolecode Pix')
            ->optional('pixRegulatorioBaseUrl', 'string', self::DEFAULT_PIX_REGULATORIO_BASE_URL, 'Base URL regulatorio Pix')
            ->optional('pixWebhookBaseUrl', 'string', self::DEFAULT_PIX_REGULATORIO_BASE_URL, 'Alias para pixRegulatorioBaseUrl')
            ->optional('pixEndpointPath', 'string', '/boletos-pix', 'Path de emissao Bolecode Pix')
            ->optional('pixLegacyPayload', 'bool', false, 'Usa payload Bolecode legado/oficial da colecao Postman')
            ->optional('bolecodeLegacyPayload', 'bool', false, 'Alias para pixLegacyPayload')
            ->optional('tiposNotificacoes', 'mixed', ['BAIXA_EFETIVA', 'BAIXA_OPERACIONAL'], 'Tipos de notificacao do webhook')
            ->optional('tipos_notificacoes', 'mixed', ['BAIXA_EFETIVA', 'BAIXA_OPERACIONAL'], 'Alias para tiposNotificacoes')
            ->optional('tokenKey', 'string', null, 'Chave unica para armazenar token')
            ->optional('baseUrl', 'string', null, 'Alias para apiBaseUrl')
            ->optional('httpClient', 'mixed', null, 'Instancia de HttpClientInterface customizada');
    }

    private function validateConfig(array $config): void
    {
        self::configSchema()->validate($config);
    }

    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    private function normalizeEndpointPath(string $path): string
    {
        $path = trim($path);
        if ($path === '') {
            return '/boletos-pix';
        }

        return '/' . ltrim($path, '/');
    }

    private function normalizeBase64(string $base64): string
    {
        $trimmed = trim($base64);
        if (strpos($trimmed, ',') !== false && preg_match('/^data:[^,]+,(.+)$/', $trimmed, $matches)) {
            return $matches[1];
        }

        return $trimmed;
    }

    /**
     * @return string[]
     */
    private function resolveWebhookTiposNotificacoes(array $config): array
    {
        $tipos = $config['tiposNotificacoes']
            ?? $config['tipos_notificacoes']
            ?? ['BAIXA_EFETIVA', 'BAIXA_OPERACIONAL'];

        if (is_string($tipos)) {
            $tipos = explode(',', $tipos);
        }

        if (!is_array($tipos)) {
            $tipos = [];
        }

        $normalized = [];
        foreach ($tipos as $tipo) {
            $tipo = strtoupper(trim((string) $tipo));
            if ($tipo !== '') {
                $normalized[] = $tipo;
            }
        }

        return array_values(array_unique($normalized)) ?: ['BAIXA_EFETIVA', 'BAIXA_OPERACIONAL'];
    }

    private function extractSetupItems(array $body): array
    {
        if (empty($body)) {
            return [];
        }

        $data = $body['data'] ?? $body;
        if (!is_array($data)) {
            return [];
        }

        if (isset($data['id_notificacao_boleto']) || isset($data['id_notificacao_boletos'])) {
            return [$data];
        }

        return array_values(array_filter($data, static fn($item): bool => is_array($item)));
    }

    private function deduplicateSetupItems(array $items): array
    {
        $indexed = [];
        foreach ($items as $item) {
            $key = $item['id_notificacao_boleto']
                ?? $item['id_notificacao_boletos']
                ?? md5(json_encode($item) ?: serialize($item));
            $indexed[$key] = $item;
        }

        return array_values($indexed);
    }
}
