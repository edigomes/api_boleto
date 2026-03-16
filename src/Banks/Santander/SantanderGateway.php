<?php

namespace ApiBoleto\Banks\Santander;

use ApiBoleto\Config\ConfigSchema;
use ApiBoleto\Contracts\BankSetupInterface;
use ApiBoleto\Contracts\BoletoGatewayInterface;
use ApiBoleto\Contracts\ConfigurableGatewayInterface;
use ApiBoleto\Contracts\HttpClientInterface;
use ApiBoleto\Contracts\TokenStorageInterface;
use ApiBoleto\DTO\Boleto;
use ApiBoleto\DTO\BoletoResponse;
use ApiBoleto\DTO\InstrucaoBoleto;
use ApiBoleto\Exceptions\BoletoException;
use ApiBoleto\Http\CertificateHelper;
use ApiBoleto\Http\CurlHttpClient;
use ApiBoleto\Storage\FileTokenStorage;

class SantanderGateway implements BoletoGatewayInterface, BankSetupInterface, ConfigurableGatewayInterface
{
    private const BASE_URLS = [
        'producao' => 'https://trust-open.api.santander.com.br',
        'sandbox'  => 'https://trust-sandbox.api.santander.com.br',
    ];

    private const API_PATH = '/collection_bill_management/v2';

    /** @var SantanderAuthenticator */
    private SantanderAuthenticator $authenticator;

    /** @var SantanderMapper */
    private SantanderMapper $mapper;

    /** @var HttpClientInterface */
    private HttpClientInterface $httpClient;

    /** @var string */
    private string $baseUrl;

    /** @var string 'producao' ou 'sandbox' */
    private string $ambiente;

    /** @var string */
    private string $clientId;

    /** @var string */
    private string $workspaceId;

    /** @var CertificateHelper */
    private CertificateHelper $certificateHelper;

    public function __construct(array $config)
    {
        $this->validateConfig($config);

        $this->ambiente = $this->resolveAmbiente($config);
        $this->baseUrl = rtrim($config['baseUrl'] ?? self::BASE_URLS[$this->ambiente], '/');
        $this->clientId = $config['clientId'];
        $this->workspaceId = $config['workspaceId'] ?? '';

        $this->certificateHelper = new CertificateHelper($config);

        $this->httpClient = $config['httpClient'] ?? new CurlHttpClient();
        $this->mapper = new SantanderMapper($this->ambiente);

        $tokenStorage = $this->resolveTokenStorage($config);
        $defaultTokenKey = 'santander_token_' . $this->ambiente;
        $tokenKey = $config['tokenKey'] ?? $defaultTokenKey;

        $this->authenticator = new SantanderAuthenticator(
            $this->httpClient,
            $tokenStorage,
            $tokenKey,
            $this->baseUrl,
            $this->clientId,
            $config['clientSecret'],
            $this->certificateHelper->toCertConfig()
        );
    }

    /**
     * Retorna o ambiente atual ('producao' ou 'sandbox').
     */
    public function getAmbiente(): string
    {
        return $this->ambiente;
    }

    /**
     * {@inheritdoc}
     */
    public function criarBoleto(Boleto $boleto): BoletoResponse
    {
        $this->requireWorkspaceId();

        $payload = $this->mapper->toApiPayload($boleto);
        $url = $this->buildUrl("/workspaces/{$this->workspaceId}/bank_slips");

        $response = $this->authenticatedRequest('POST', $url, $payload);

        return $this->mapper->toResponse($response['body'] ?? []);
    }

    /**
     * {@inheritdoc}
     */
    public function consultarBoleto(string $identificador): BoletoResponse
    {
        $this->requireWorkspaceId();

        if (empty(trim($identificador))) {
            throw new BoletoException(
                'Identificador do boleto e obrigatorio para consulta. '
                . 'A resposta de criacao pode nao ter retornado o ID (comum em sandbox).'
            );
        }

        $url = $this->buildUrl("/workspaces/{$this->workspaceId}/bank_slips/{$identificador}");

        $response = $this->authenticatedRequest('GET', $url);

        return $this->mapper->toResponse($response['body'] ?? []);
    }

    /**
     * {@inheritdoc}
     *
     * Consulta detalhada via /bills. Filtros aceitos:
     *   'beneficiaryCode' => string (obrigatorio)
     *   'bankNumber'      => string (consulta por Nosso Numero)
     *   'clientNumber'    => string (consulta por Seu Numero, requer dueDate e nominalValue)
     *   'dueDate'         => string
     *   'nominalValue'    => string
     */
    public function consultarBoletos(array $filtros = []): array
    {
        $url = $this->buildUrl('/bills');

        $response = $this->authenticatedRequest('GET', $url, [], $filtros);

        $items = $response['body'] ?? [];

        if (isset($items['_content'])) {
            $items = $items['_content'];
        }

        if (!is_array($items) || !isset($items[0])) {
            return !empty($items) ? [$this->mapper->toResponse($items)] : [];
        }

        return $this->mapper->toResponseList($items);
    }

    /**
     * Consulta detalhada por tipo. Usa /bills/{beneficiaryCode}.{bankNumber}?tipoConsulta={tipo}
     *
     * @param string $beneficiaryCode Codigo do convenio
     * @param string $bankNumber Nosso numero
     * @param string $tipoConsulta default|duplicate|bankslip|registry|settlement
     */
    public function consultarBoletoDetalhado(
        string $beneficiaryCode,
        string $bankNumber,
        string $tipoConsulta = 'default'
    ): BoletoResponse {
        $billId = "{$beneficiaryCode}.{$bankNumber}";
        $url = $this->buildUrl("/bills/{$billId}");

        $response = $this->authenticatedRequest('GET', $url, [], [
            'tipoConsulta' => $tipoConsulta,
        ]);

        return $this->mapper->toResponse($response['body'] ?? []);
    }

    /**
     * {@inheritdoc}
     *
     * Envia instrucoes via PATCH /workspaces/{wsId}/bank_slips.
     * O $identificador deve ser "covenantCode,bankNumber" ex: "794760,35"
     */
    public function alterarBoleto(string $identificador, InstrucaoBoleto $instrucao): BoletoResponse
    {
        $this->requireWorkspaceId();

        $parts = explode(',', $identificador);
        if (count($parts) !== 2) {
            throw new BoletoException(
                'Para alterar boleto no Santander, o identificador deve ser "covenantCode,bankNumber". '
                . 'Exemplo: "794760,35"'
            );
        }

        $covenantCode = trim($parts[0]);
        $bankNumber = trim($parts[1]);

        $payload = $this->mapper->toInstrucaoPayload($covenantCode, $bankNumber, $instrucao);
        $url = $this->buildUrl("/workspaces/{$this->workspaceId}/bank_slips");

        $response = $this->authenticatedRequest('PATCH', $url, $payload);

        return $this->mapper->toResponse($response['body'] ?? []);
    }

    /**
     * {@inheritdoc}
     *
     * Cancela (baixa) um boleto via instrucao BAIXAR.
     * O $identificador deve ser no formato "covenantCode,bankNumber" ex: "3567206,1014"
     *
     * Se $instrucao for passada, mescla os dados dela e forca operacao BAIXAR.
     */
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

    /**
     * {@inheritdoc}
     *
     * Gera link do PDF do boleto via POST /bills/{billId}/bank_slips.
     *
     * O identificador pode ser:
     *   - Composto: "{bankNumber}.{covenantCode}" ex: "033.0794760"
     *   - Linha digitavel: "03399079417600000000000003301017814170000000100"
     *
     * IMPORTANTE: Use os valores ORIGINAIS com zeros a esquerda,
     * nao os da resposta da API que podem vir sem zeros.
     *
     * @param string $identificador {bankNumber}.{covenantCode} ou linha digitavel
     * @param string $payerDocumentNumber CPF/CNPJ do pagador (obrigatorio pela API)
     * @return string URL do PDF gerado
     */
    public function gerarPdf(string $identificador, string $payerDocumentNumber = ''): string
    {
        $url = $this->buildUrl("/bills/{$identificador}/bank_slips");

        $body = [];
        if (!empty($payerDocumentNumber)) {
            $body['payerDocumentNumber'] = (int) preg_replace('/\D/', '', $payerDocumentNumber);
        }

        $response = $this->authenticatedRequest('POST', $url, $body);

        $data = $response['body'] ?? [];

        return (string) ($data['link'] ?? $data['pdfLink'] ?? $data['pdfUrl'] ?? $data['url'] ?? '');
    }

    /**
     * {@inheritdoc}
     */
    public function downloadPdf(string $identificador, string $payerDocumentNumber = ''): string
    {
        $url = $this->gerarPdf($identificador, $payerDocumentNumber);

        if (empty($url)) {
            throw new BoletoException('Nao foi possivel obter a URL do PDF.');
        }

        return $this->downloadFromUrl($url);
    }

    // -- BankSetupInterface (Workspace + Webhook) --

    /**
     * {@inheritdoc}
     *
     * No Santander, o setup cria um Workspace com convenio e webhook.
     *
     * Parametros aceitos:
     *   'covenantCode'  => string (obrigatorio) Codigo do convenio
     *   'webhookUrl'    => string (opcional) URL do webhook para notificacoes
     *   'description'   => string (opcional) Descricao do workspace
     *   'boleto_webhook' => bool (opcional, default true) Ativar webhook para boletos
     *   'pix_webhook'    => bool (opcional, default false) Ativar webhook para PIX
     *
     * Retorna os dados do workspace criado + seta automaticamente o workspaceId.
     */
    public function setup(array $params): array
    {
        if (empty($params['covenantCode'])) {
            throw new BoletoException("Parametro 'covenantCode' e obrigatorio para o setup do Santander.");
        }

        $workspaceData = [
            'type' => 'BILLING',
            'description' => $params['description'] ?? 'Workspace de Cobranca',
            'covenants' => [['code' => $params['covenantCode']]],
        ];

        if (!empty($params['webhookUrl'])) {
            $workspaceData['webhookURL'] = $params['webhookUrl'];
        }

        $workspaceData['bankSlipBillingWebhookActive'] = $params['boleto_webhook'] ?? true;
        $workspaceData['pixBillingWebhookActive'] = $params['pix_webhook'] ?? false;

        $result = $this->criarWorkspace($workspaceData);

        if (!empty($result['id'])) {
            $this->workspaceId = $result['id'];
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function consultarSetup(?string $id = null): array
    {
        if ($id !== null) {
            return $this->consultarWorkspace($id);
        }

        return $this->listarWorkspaces();
    }

    /**
     * {@inheritdoc}
     *
     * Atualiza webhook e configuracoes de um workspace existente.
     */
    public function atualizarSetup(string $id, array $params): array
    {
        $url = $this->buildUrl("/workspaces/{$id}");

        $response = $this->authenticatedRequest('PATCH', $url, $params);

        return $response['body'] ?? [];
    }

    /**
     * {@inheritdoc}
     */
    public function isConfigurado(): bool
    {
        return !empty($this->workspaceId);
    }

    // -- Metodos Santander-especificos (workspace management) --

    /**
     * Cria um workspace no Santander (payload raw).
     */
    public function criarWorkspace(array $workspaceData): array
    {
        $url = $this->buildUrl('/workspaces');

        $response = $this->authenticatedRequest('POST', $url, $workspaceData);

        return $response['body'] ?? [];
    }

    /**
     * Lista todos os workspaces.
     */
    public function listarWorkspaces(): array
    {
        $url = $this->buildUrl('/workspaces');

        $response = $this->authenticatedRequest('GET', $url);

        return $response['body'] ?? [];
    }

    /**
     * Consulta um workspace pelo ID.
     */
    public function consultarWorkspace(string $workspaceId): array
    {
        $url = $this->buildUrl("/workspaces/{$workspaceId}");

        $response = $this->authenticatedRequest('GET', $url);

        return $response['body'] ?? [];
    }

    /**
     * Define o workspace ID para operacoes de boleto.
     */
    public function setWorkspaceId(string $workspaceId): void
    {
        $this->workspaceId = $workspaceId;
    }

    // -- Metodos privados --

    private function authenticatedRequest(
        string $method,
        string $url,
        array $body = [],
        array $query = [],
        bool $rawResponse = false
    ): array {
        $token = $this->authenticator->getToken();

        $options = [
            'headers' => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $token,
                'X-Application-Key: ' . $this->clientId,
            ],
            'cert' => $this->certificateHelper->toCertConfig(),
            'rawResponse' => $rawResponse,
        ];

        if (!empty($body)) {
            $options['body'] = $body;
        }

        if (!empty($query)) {
            $options['query'] = $query;
        }

        return $this->httpClient->request($method, $url, $options);
    }

    /**
     * Baixa o conteudo binario de uma URL (ex: link do PDF).
     */
    private function downloadFromUrl(string $url): string
    {
        $response = $this->httpClient->request('GET', $url, [
            'rawResponse' => true,
        ]);

        return $response['rawBody'] ?? '';
    }

    private function buildUrl(string $path): string
    {
        return $this->baseUrl . self::API_PATH . $path;
    }

    private function requireWorkspaceId(): void
    {
        if (empty($this->workspaceId)) {
            throw new BoletoException(
                'workspaceId e obrigatorio para operacoes de boleto no Santander. '
                . 'Informe na configuracao ou use setWorkspaceId().'
            );
        }
    }

    /**
     * Resolve o TokenStorage: aceita instancia direta ou cria FileTokenStorage a partir de tokenPath.
     */
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

    /**
     * Resolve o ambiente a partir do config.
     * Aceita: 'producao', 'sandbox', 'PRODUCAO', 'SANDBOX', 'homologacao'.
     */
    private function resolveAmbiente(array $config): string
    {
        $ambiente = strtolower($config['ambiente'] ?? 'producao');

        $aliases = [
            'producao'     => 'producao',
            'production'   => 'producao',
            'prod'         => 'producao',
            'sandbox'      => 'sandbox',
            'homologacao'  => 'sandbox',
            'homologation' => 'sandbox',
            'staging'      => 'sandbox',
            'dev'          => 'sandbox',
        ];

        if (!isset($aliases[$ambiente])) {
            throw new BoletoException(
                "Ambiente '{$ambiente}' invalido. Use 'producao' ou 'sandbox'."
            );
        }

        return $aliases[$ambiente];
    }

    /**
     * Retorna o schema de configuracao do Santander.
     */
    public static function configSchema(): ConfigSchema
    {
        return ConfigSchema::create('Santander')
            ->required('clientId', 'string', 'Client ID da aplicacao no Santander Developer')
            ->required('clientSecret', 'string', 'Client Secret da aplicacao')
            ->requireOneOf('certificado', [
                ['certFile', 'certKeyFile'],
                ['certContent', 'certKeyContent'],
            ], "Certificado mTLS obrigatorio. Informe 'certFile'+'certKeyFile' (paths) ou 'certContent'+'certKeyContent' (conteudo PEM).")
            ->requireOneOf('tokenStorage', [
                ['tokenStorage'],
                ['tokenPath'],
            ], "Armazenamento de token obrigatorio. Informe 'tokenStorage' (instancia de TokenStorageInterface) ou 'tokenPath' (diretorio).")
            ->optional('certKeyPassword', 'string', '', 'Senha da chave privada do certificado')
            ->optional('ambiente', 'string', 'producao', 'Ambiente: producao, sandbox, homologacao')
            ->optional('workspaceId', 'string', '', 'ID do workspace (obrigatorio para operacoes de boleto)')
            ->optional('tokenKey', 'string', null, 'Chave unica para armazenar o token (default: santander_token_{ambiente})')
            ->optional('baseUrl', 'string', null, 'URL base customizada (sobrescreve ambiente)')
            ->optional('httpClient', 'mixed', null, 'Instancia de HttpClientInterface customizada');
    }

    private function validateConfig(array $config): void
    {
        self::configSchema()->validate($config);
    }
}
