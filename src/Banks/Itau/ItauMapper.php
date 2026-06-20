<?php

namespace ApiBoleto\Banks\Itau;

use ApiBoleto\DTO\Boleto;
use ApiBoleto\DTO\BoletoResponse;
use ApiBoleto\DTO\Desconto;
use ApiBoleto\DTO\InstrucaoBoleto;
use ApiBoleto\Exceptions\BoletoException;

class ItauMapper
{
    private string $idBeneficiario;

    private string $codigoCarteira;

    private string $codigoEspecie;

    private string $tipoBoleto;

    private string $etapaProcesso;

    private bool $pixLegacyPayload;

    public function __construct(
        string $idBeneficiario,
        string $codigoCarteira = '109',
        string $codigoEspecie = '01',
        string $tipoBoleto = 'a vista',
        string $etapaProcesso = 'efetivacao',
        bool $pixLegacyPayload = false
    ) {
        $this->idBeneficiario = $idBeneficiario;
        $this->codigoCarteira = $codigoCarteira;
        $this->codigoEspecie = $codigoEspecie;
        $this->tipoBoleto = $tipoBoleto;
        $this->etapaProcesso = $etapaProcesso;
        $this->pixLegacyPayload = $pixLegacyPayload;
    }

    public function shouldUsePix(Boleto $boleto): bool
    {
        return !empty($boleto->dadosExtras['bolecodePix'])
            || !empty($boleto->dadosExtras['dados_qrcode'])
            || !empty($boleto->dadosExtras['chavePix'])
            || !empty($boleto->dadosExtras['chave_pix']);
    }

    public function setIdBeneficiario(string $idBeneficiario): void
    {
        $this->idBeneficiario = $idBeneficiario;
    }

    public function toApiPayload(Boleto $boleto): array
    {
        return [
            'data' => $this->buildData($boleto, false),
        ];
    }

    public function toPixPayload(Boleto $boleto): array
    {
        return $this->buildData($boleto, true);
    }

    public function toBoletoV1Payload(Boleto $boleto): array
    {
        $documento = preg_replace('/\D/', '', $boleto->pagador->documento) ?? '';
        $endereco = $this->splitEnderecoNumero($boleto->pagador->endereco);
        $codigoCarteira = $boleto->dadosExtras['codigo_carteira']
            ?? $boleto->dadosExtras['codigoCarteira']
            ?? $this->codigoCarteira;
        $codigoEspecie = $boleto->dadosExtras['codigo_especie']
            ?? $boleto->dadosExtras['codigoEspecie']
            ?? $this->mapTipoDocumento($boleto->tipoDocumento);

        $payload = [
            'beneficiario' => [
                'idBeneficiario' => $this->resolveIdBeneficiario($boleto),
            ],
            'tipoBoleto' => $boleto->dadosExtras['tipoBoleto']
                ?? $boleto->dadosExtras['tipo_boleto']
                ?? $this->tipoBoleto,
            'etapaProcessoBoleto' => $boleto->dadosExtras['etapaProcessoBoleto']
                ?? $boleto->dadosExtras['etapaProcesso']
                ?? $boleto->dadosExtras['etapa_processo_boleto']
                ?? $this->etapaProcesso,
            'codigoCanalOperacao' => $boleto->dadosExtras['codigoCanalOperacao']
                ?? $boleto->dadosExtras['codigo_canal_operacao']
                ?? 'API',
            'instrumentoCobranca' => $this->shouldUsePix($boleto) ? 'bolecode' : 'boleto',
            'formaEnvio' => $boleto->dadosExtras['formaEnvio'] ?? 'impressao',
            'pagador' => [
                'nomePagador' => $boleto->pagador->nome,
                'tipoPessoa' => strlen($documento) === 14 ? 'J' : 'F',
                'numeroDocumento' => $documento,
                'endereco' => [
                    'logradouro' => $endereco['logradouro'],
                    'numero' => $endereco['numero'],
                    'bairro' => $boleto->pagador->bairro,
                    'cidade' => $boleto->pagador->cidade,
                    'uf' => strtoupper($boleto->pagador->estado),
                    'cep' => preg_replace('/\D/', '', $boleto->pagador->cep) ?? '',
                ],
            ],
            'mensagensBoleto' => array_map(
                static fn($mensagem): array => ['mensagem' => (string) $mensagem],
                $boleto->mensagens
            ),
            'especie' => [
                'codigoEspecie' => $codigoEspecie,
            ],
            'isDescontoExpresso' => $this->normalizeBoolean($boleto->dadosExtras['desconto_expresso'] ?? false),
            'seuNumero' => $boleto->seuNumero,
            'dataEmissao' => $boleto->emissao,
            'dataVencimento' => $boleto->vencimento,
            'valor' => (float) $this->formatMoney($boleto->valor),
            'codigoCarteira' => $codigoCarteira,
            'nossoNumero' => $boleto->nossoNumero ?: ($boleto->dadosExtras['numero_nosso_numero'] ?? ''),
        ];

        $dataLimite = $boleto->dadosExtras['dataLimitePagamento']
            ?? $boleto->dadosExtras['data_limite_pagamento']
            ?? '';
        if ($dataLimite !== '') {
            $payload['dataLimitePagamento'] = $dataLimite;
        }

        $dadosQrcode = $this->buildDadosQrcodeV1($boleto);
        if ($dadosQrcode !== []) {
            $payload['dadosQrcode'] = $dadosQrcode;
        }

        $this->applyDescontoV1($payload, $boleto);
        $this->applyMultaV1($payload, $boleto);
        $this->applyJurosV1($payload, $boleto);

        if (isset($boleto->dadosExtras['boletoV1']) && is_array($boleto->dadosExtras['boletoV1'])) {
            $payload = $this->mergeIgnoringNull($payload, $boleto->dadosExtras['boletoV1']);
        }

        $payload['isDescontoExpresso'] = $this->normalizeBoolean($payload['isDescontoExpresso'] ?? false);

        return $payload;
    }

    public function toResponse(array $apiData): BoletoResponse
    {
        $original = $apiData;
        if (isset($apiData['data']) && is_array($apiData['data']) && isset($apiData['data'][0])) {
            $apiData = $apiData['data'][0];
        }

        $data = $apiData['data'] ?? $apiData;
        $dadoBoleto = $data['dado_boleto'] ?? $apiData['dado_boleto'] ?? [];
        $individual = $this->firstItem($dadoBoleto['dados_individuais_boleto'] ?? []);
        $qrcode = $data['dados_qrcode'] ?? $apiData['dados_qrcode'] ?? [];

        $response = new BoletoResponse();
        $response->id = (string) $this->firstNonEmpty([
            $apiData['id'] ?? null,
            $data['id'] ?? null,
            $apiData['id_boleto'] ?? null,
            $data['id_boleto'] ?? null,
            $apiData['idBoleto'] ?? null,
            $data['idBoleto'] ?? null,
            $individual['id_boleto_individual'] ?? null,
            $individual['id_boleto'] ?? null,
        ]);
        $response->nossoNumero = (string) $this->firstNonEmpty([
            $individual['numero_nosso_numero'] ?? null,
            $data['numero_nosso_numero'] ?? null,
            $apiData['numero_nosso_numero'] ?? null,
            $apiData['nossoNumero'] ?? null,
            $data['nossoNumero'] ?? null,
        ]);
        $response->codigoBarras = (string) $this->firstNonEmpty([
            $individual['codigo_barras'] ?? null,
            $apiData['codigo_barras'] ?? null,
            $apiData['codigoBarras'] ?? null,
            $data['codigoBarras'] ?? null,
            $apiData['barCode'] ?? null,
        ]);
        $response->linhaDigitavel = (string) $this->firstNonEmpty([
            $individual['numero_linha_digitavel'] ?? null,
            $apiData['numero_linha_digitavel'] ?? null,
            $apiData['linha_digitavel'] ?? null,
            $apiData['linhaDigitavel'] ?? null,
            $data['linhaDigitavel'] ?? null,
            $apiData['digitableLine'] ?? null,
        ]);
        $response->status = (string) $this->firstNonEmpty([
            $apiData['status'] ?? null,
            $data['status'] ?? null,
            $apiData['situacao'] ?? null,
            $data['situacao'] ?? null,
            isset($apiData['codigo']) && (string) $apiData['codigo'] === '202' ? 'PROCESSING' : null,
        ]);
        $response->valor = (string) $this->firstNonEmpty([
            $individual['valor_titulo'] ?? null,
            $dadoBoleto['valor_total_titulo'] ?? null,
            $apiData['valor_titulo'] ?? null,
            $apiData['valor'] ?? null,
            $data['valor'] ?? null,
        ]);
        $response->vencimento = (string) $this->firstNonEmpty([
            $individual['data_vencimento'] ?? null,
            $apiData['data_vencimento'] ?? null,
            $apiData['dataVencimento'] ?? null,
            $data['dataVencimento'] ?? null,
            $apiData['vencimento'] ?? null,
        ]);
        $response->urlPdf = (string) $this->firstNonEmpty([
            $apiData['url_pdf'] ?? null,
            $apiData['urlPdf'] ?? null,
            $apiData['pdfUrl'] ?? null,
            $individual['url_pdf'] ?? null,
        ]);
        $response->pdfBase64 = (string) $this->firstNonEmpty([
            $apiData['base64'] ?? null,
            $data['base64'] ?? null,
            $apiData['pdfBase64'] ?? null,
            $data['pdfBase64'] ?? null,
        ]);
        $response->qrCodePix = (string) $this->firstNonEmpty([
            $qrcode['emv'] ?? null,
            $apiData['emv'] ?? null,
            $data['emv'] ?? null,
            $qrcode['pixCopiaECola'] ?? null,
            $apiData['pixCopiaECola'] ?? null,
            $apiData['qrCodePix'] ?? null,
        ]);
        $response->qrCodeUrl = (string) $this->firstNonEmpty([
            $qrcode['location'] ?? null,
            $apiData['location'] ?? null,
            $data['location'] ?? null,
            $apiData['qrCodeUrl'] ?? null,
            $apiData['url_qrcode'] ?? null,
        ]);
        $response->dadosOriginais = $original;

        return $response;
    }

    public function toResponseList(array $apiData): array
    {
        $items = $apiData['data']['boletos']
            ?? (isset($apiData['data']) && is_array($apiData['data']) && isset($apiData['data'][0]) ? $apiData['data'] : null)
            ?? $apiData['boletos']
            ?? $apiData['data']['items']
            ?? $apiData['items']
            ?? $apiData['_content']
            ?? $apiData;

        if (!is_array($items)) {
            return [];
        }

        if ($items !== [] && !isset($items[0])) {
            return [$this->toResponse($items)];
        }

        $responses = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $responses[] = $this->toResponse($item);
            }
        }

        return $responses;
    }

    public function toInstrucaoRequest(string $identificador, InstrucaoBoleto $instrucao): array
    {
        $operacao = $this->normalizeOperacao($instrucao->operacao ?: $this->inferOperacao($instrucao));

        if ($operacao === '') {
            throw new BoletoException(
                'Nao foi possivel inferir a operacao Itau. Informe InstrucaoBoleto::$operacao '
                . 'ou preencha um campo suportado.'
            );
        }

        $body = $this->buildInstrucaoBody($operacao, $instrucao);

        if (isset($instrucao->dadosExtras['body']) && is_array($instrucao->dadosExtras['body'])) {
            $body = $this->mergeIgnoringNull($body, $instrucao->dadosExtras['body']);
        }

        return [
            'path' => '/boletos/' . rawurlencode($identificador) . '/' . $operacao,
            'body' => $body,
            'operacao' => $operacao,
        ];
    }

    public function toWebhookPayload(array $params): array
    {
        $payload = [
            'id_beneficiario' => $params['idBeneficiario'] ?? $params['id_beneficiario'] ?? $this->idBeneficiario,
            'webhook_url' => $params['webhookUrl'] ?? $params['webhook_url'] ?? '',
            'webhook_client_id' => $params['webhookClientId'] ?? $params['webhook_client_id'] ?? '',
            'webhook_client_secret' => $params['webhookClientSecret'] ?? $params['webhook_client_secret'] ?? '',
            'webhook_oauth_url' => $params['webhookOauthUrl'] ?? $params['webhook_oauth_url'] ?? '',
            'webhook_oauth_scope' => $params['webhookOauthScope'] ?? $params['webhook_oauth_scope'] ?? '',
            'valor_minimo' => $params['valorMinimo'] ?? $params['valor_minimo'] ?? 0.01,
            'tipo_notificacao' => $params['tipoNotificacao'] ?? $params['tipo_notificacao'] ?? 'BAIXA_OPERACIONAL',
        ];

        if (isset($params['tiposNotificacoes'])) {
            $payload['tipos_notificacoes'] = $params['tiposNotificacoes'];
        } elseif (isset($params['tipos_notificacoes'])) {
            $payload['tipos_notificacoes'] = $params['tipos_notificacoes'];
        }

        return $payload;
    }

    private function buildData(Boleto $boleto, bool $pix): array
    {
        $data = [
            'etapa_processo_boleto' => $boleto->dadosExtras['etapa_processo_boleto']
                ?? $boleto->dadosExtras['etapaProcesso']
                ?? $this->etapaProcesso,
            'beneficiario' => [
                'id_beneficiario' => $this->resolveIdBeneficiario($boleto),
            ],
            'dado_boleto' => $this->buildDadoBoleto($boleto, $pix),
        ];

        if (!$pix || !$this->pixLegacyPayload) {
            $data['codigo_canal_operacao'] = $boleto->dadosExtras['codigo_canal_operacao'] ?? 'API';
        }

        $dadosQrcode = $this->buildDadosQrcode($boleto);
        if ($pix && $dadosQrcode !== []) {
            $data['dados_qrcode'] = $dadosQrcode;
        }

        if (isset($boleto->dadosExtras['data']) && is_array($boleto->dadosExtras['data'])) {
            $data = $this->mergeIgnoringNull($data, $boleto->dadosExtras['data']);
            if (isset($data['dado_boleto']['desconto_expresso'])) {
                $data['dado_boleto']['desconto_expresso'] = $this->normalizeBoolean($data['dado_boleto']['desconto_expresso']);
            }
        }

        return $data;
    }

    private function buildDadoBoleto(Boleto $boleto, bool $pix): array
    {
        $dado = [
            'tipo_boleto' => $boleto->dadosExtras['tipo_boleto'] ?? $this->tipoBoleto,
            'codigo_carteira' => $boleto->dadosExtras['codigo_carteira']
                ?? $boleto->dadosExtras['codigoCarteira']
                ?? $this->codigoCarteira,
            'codigo_especie' => $boleto->dadosExtras['codigo_especie']
                ?? $boleto->dadosExtras['codigoEspecie']
                ?? $this->mapTipoDocumento($boleto->tipoDocumento),
            'pagador' => $this->mapPagador($boleto),
            'dados_individuais_boleto' => [
                $this->mapDadosIndividuais($boleto, $pix),
            ],
        ];

        if (!$pix || !$this->pixLegacyPayload) {
            $dado['descricao_instrumento_cobranca'] = $pix ? 'boleto_pix' : 'boleto';
            $dado['desconto_expresso'] = $this->normalizeBoolean($boleto->dadosExtras['desconto_expresso'] ?? false);
        }

        if ($this->pixLegacyPayload && $boleto->seuNumero !== '') {
            $dado['texto_seu_numero'] = $boleto->seuNumero;
        }

        if ((!$pix || $this->pixLegacyPayload) && $boleto->emissao !== '') {
            $dado['data_emissao'] = $boleto->emissao;
        }

        if ($pix) {
            $dado['valor_total_titulo'] = $this->pixLegacyPayload
                ? $this->formatLegacyMoney($boleto->valor)
                : $this->formatMoney($boleto->valor);
        }

        if ($boleto->valorDeducao !== '' && $boleto->valorDeducao !== '0') {
            $dado['valor_abatimento'] = $pix && !$this->pixLegacyPayload
                ? $this->formatMoney($boleto->valorDeducao)
                : $this->formatLegacyMoney($boleto->valorDeducao);
        }

        $this->applyDesconto($dado, $boleto, $pix && !$this->pixLegacyPayload);
        $this->applyMulta($dado, $boleto);
        $this->applyJuros($dado, $boleto);
        $this->applyInstrucaoCobranca($dado, $boleto);
        if ($pix && $this->pixLegacyPayload && !empty($boleto->mensagens)) {
            $dado['lista_mensagem_cobranca'] = array_map(
                static fn($mensagem): array => ['mensagem' => (string) $mensagem],
                $boleto->mensagens
            );
        }

        if (isset($boleto->dadosExtras['recebimento_divergente'])) {
            $dado['recebimento_divergente'] = $boleto->dadosExtras['recebimento_divergente'];
        }

        if (!$this->pixLegacyPayload && array_key_exists('desconto_expresso', $boleto->dadosExtras)) {
            $dado['desconto_expresso'] = $this->normalizeBoolean($boleto->dadosExtras['desconto_expresso']);
        }

        if (isset($boleto->dadosExtras['dado_boleto']) && is_array($boleto->dadosExtras['dado_boleto'])) {
            $dado = $this->mergeIgnoringNull($dado, $boleto->dadosExtras['dado_boleto']);
        }

        if (!$pix || !$this->pixLegacyPayload) {
            $dado['desconto_expresso'] = $this->normalizeBoolean($dado['desconto_expresso'] ?? false);
        } else {
            unset($dado['descricao_instrumento_cobranca'], $dado['desconto_expresso']);
        }

        return $dado;
    }

    private function mapDadosIndividuais(Boleto $boleto, bool $pix): array
    {
        $dados = [
            'numero_nosso_numero' => $boleto->nossoNumero ?: ($boleto->dadosExtras['numero_nosso_numero'] ?? ''),
            'data_vencimento' => $boleto->vencimento,
            'valor_titulo' => $pix && !$this->pixLegacyPayload
                ? $this->formatMoney($boleto->valor)
                : $this->formatLegacyMoney($boleto->valor),
        ];

        if ($boleto->seuNumero !== '') {
            $dados['texto_seu_numero'] = $boleto->seuNumero;
        }

        if (!empty($boleto->dadosExtras['texto_uso_beneficiario'])) {
            $dados['texto_uso_beneficiario'] = $boleto->dadosExtras['texto_uso_beneficiario'];
        }

        $dataLimite = $boleto->dadosExtras['data_limite_pagamento']
            ?? $boleto->dadosExtras['dataLimitePagamento']
            ?? '';
        if ($dataLimite !== '') {
            $dados['data_limite_pagamento'] = $dataLimite;
        }

        if (!empty($boleto->mensagens) && (!$pix || !$this->pixLegacyPayload)) {
            $dados['lista_mensagens_cobranca'] = array_map(
                static fn($mensagem): array => ['mensagem' => (string) $mensagem],
                $boleto->mensagens
            );
        }

        return $dados;
    }

    private function mapPagador(Boleto $boleto): array
    {
        $documento = preg_replace('/\D/', '', $boleto->pagador->documento);
        $tipoPessoa = $this->mapTipoPessoa($boleto->pagador->tipoDocumento, $documento);

        return [
            'pessoa' => [
                'nome_pessoa' => $boleto->pagador->nome,
                'tipo_pessoa' => $tipoPessoa,
            ],
            'endereco' => [
                'nome_logradouro' => $boleto->pagador->endereco,
                'nome_bairro' => $boleto->pagador->bairro,
                'nome_cidade' => $boleto->pagador->cidade,
                'sigla_UF' => strtoupper($boleto->pagador->estado),
                'numero_CEP' => preg_replace('/\D/', '', $boleto->pagador->cep),
            ],
        ];
    }

    private function mapTipoPessoa(string $tipoDocumento, string $documento): array
    {
        $tipo = strtoupper($tipoDocumento);

        if ($tipo === 'CNPJ' || strlen($documento) === 14) {
            return [
                'codigo_tipo_pessoa' => 'J',
                'numero_cadastro_nacional_pessoa_juridica' => $documento,
            ];
        }

        return [
            'codigo_tipo_pessoa' => 'F',
            'numero_cadastro_pessoa_fisica' => $documento,
        ];
    }

    private function buildDadosQrcode(Boleto $boleto): array
    {
        if (isset($boleto->dadosExtras['dados_qrcode']) && is_array($boleto->dadosExtras['dados_qrcode'])) {
            return $boleto->dadosExtras['dados_qrcode'];
        }

        $chave = $boleto->dadosExtras['chavePix'] ?? $boleto->dadosExtras['chave_pix'] ?? '';
        if ($chave === '') {
            return [];
        }

        $dados = ['chave' => $chave];

        if (isset($boleto->dadosExtras['id_location'])) {
            $dados['id_location'] = $boleto->dadosExtras['id_location'];
        }
        if (isset($boleto->dadosExtras['tipo_cobranca'])) {
            $dados['tipo_cobranca'] = $boleto->dadosExtras['tipo_cobranca'];
        }

        return $dados;
    }

    private function buildDadosQrcodeV1(Boleto $boleto): array
    {
        if (isset($boleto->dadosExtras['dadosQrcode']) && is_array($boleto->dadosExtras['dadosQrcode'])) {
            return $boleto->dadosExtras['dadosQrcode'];
        }

        $legacy = $this->buildDadosQrcode($boleto);
        if ($legacy === []) {
            return [];
        }

        $dados = [];
        if (isset($legacy['chave'])) {
            $dados['chave'] = $legacy['chave'];
        }
        if (isset($legacy['id_location'])) {
            $dados['idLocation'] = (string) $legacy['id_location'];
        }
        if (isset($legacy['tipo_cobranca'])) {
            $dados['tipoCobranca'] = $legacy['tipo_cobranca'];
        }

        return $dados;
    }

    private function applyDesconto(array &$dado, Boleto $boleto, bool $pix): void
    {
        if ($boleto->desconto === null) {
            return;
        }

        $dado['desconto'] = $this->mapDesconto($boleto->desconto, $pix);
    }

    private function mapDesconto(Desconto $desconto, bool $pix = true): array
    {
        $codigo = $this->mapTipoDesconto($desconto->tipo);
        $payload = ['codigo_tipo_desconto' => $codigo];
        $items = [];

        foreach ([$desconto->desconto1, $desconto->desconto2, $desconto->desconto3] as $item) {
            if ($item === null) {
                continue;
            }

            $mapped = [];
            if (!empty($item['dataLimite'])) {
                $mapped['data_desconto'] = $item['dataLimite'];
            }
            if (isset($item['valor'])) {
                $field = $codigo === '02' || $codigo === '90'
                    ? 'percentual_desconto'
                    : 'valor_desconto';
                $mapped[$field] = $field === 'percentual_desconto'
                    ? $this->formatBoletoPercent((string) $item['valor'])
                    : ($pix ? $this->formatMoney((string) $item['valor']) : $this->formatLegacyMoney((string) $item['valor']));
            }
            $items[] = $mapped;
        }

        if ($items !== []) {
            $payload['descontos'] = $items;
        }

        return $payload;
    }

    private function applyMulta(array &$dado, Boleto $boleto): void
    {
        if ($boleto->multa === null) {
            return;
        }

        $dado['multa'] = [
            'codigo_tipo_multa' => $boleto->dadosExtras['codigo_tipo_multa'] ?? '02',
            'quantidade_dias_multa' => $boleto->multa->diasAposVencimento,
            'percentual_multa' => $this->formatBoletoPercent($boleto->multa->percentual),
        ];
    }

    private function applyJuros(array &$dado, Boleto $boleto): void
    {
        if ($boleto->juros === null) {
            return;
        }

        $dado['juros'] = [
            'codigo_tipo_juros' => $boleto->dadosExtras['codigo_tipo_juros'] ?? '90',
            'quantidade_dias_juros' => $boleto->dadosExtras['quantidade_dias_juros'] ?? 1,
            'percentual_juros' => $this->formatBoletoPercent($boleto->juros->percentual),
        ];
    }

    private function applyDescontoV1(array &$payload, Boleto $boleto): void
    {
        if ($boleto->desconto === null) {
            return;
        }

        $codigo = $this->mapTipoDesconto($boleto->desconto->tipo);
        $detalhes = [];

        foreach ([$boleto->desconto->desconto1, $boleto->desconto->desconto2, $boleto->desconto->desconto3] as $item) {
            if ($item === null) {
                continue;
            }

            $detalhe = [];
            if (!empty($item['dataLimite'])) {
                $detalhe['dataDesconto'] = $item['dataLimite'];
            }
            if (isset($item['valor'])) {
                $field = $codigo === '02' || $codigo === '90'
                    ? 'percentualDesconto'
                    : 'valorDesconto';
                $detalhe[$field] = $field === 'percentualDesconto'
                    ? $this->formatPercent((string) $item['valor'])
                    : $this->formatMoney((string) $item['valor']);
            }
            $detalhes[] = $detalhe;
        }

        $payload['desconto'] = [
            'codigoTipoDesconto' => $codigo,
            'detalhes' => $detalhes,
        ];
    }

    private function applyMultaV1(array &$payload, Boleto $boleto): void
    {
        if ($boleto->multa === null) {
            return;
        }

        $payload['multa'] = [
            'codigoTipoMulta' => $boleto->dadosExtras['codigoTipoMulta']
                ?? $boleto->dadosExtras['codigo_tipo_multa']
                ?? '02',
            'quantidadeDiasMulta' => $boleto->multa->diasAposVencimento,
            'percentualMulta' => $this->formatPercent($boleto->multa->percentual),
        ];
    }

    private function applyJurosV1(array &$payload, Boleto $boleto): void
    {
        if ($boleto->juros === null) {
            return;
        }

        $payload['juros'] = [
            'codigoTipoJuros' => $boleto->dadosExtras['codigoTipoJuros']
                ?? $boleto->dadosExtras['codigo_tipo_juros']
                ?? '90',
            'quantidadeDiasJuros' => (int) ($boleto->dadosExtras['quantidadeDiasJuros']
                ?? $boleto->dadosExtras['quantidade_dias_juros']
                ?? 1),
            'percentualJuros' => $this->formatPercent($boleto->juros->percentual),
        ];
    }

    private function applyInstrucaoCobranca(array &$dado, Boleto $boleto): void
    {
        if (isset($boleto->dadosExtras['instrucao_cobranca'])) {
            $dado['instrucao_cobranca'] = $boleto->dadosExtras['instrucao_cobranca'];
            return;
        }

        if ($boleto->diasProtesto > 0) {
            $dado['instrucao_cobranca'] = [[
                'codigo_instrucao_cobranca' => '1',
                'quantidade_dias_apos_vencimento' => $boleto->diasProtesto,
                'dia_util' => false,
            ]];
        }
    }

    private function buildInstrucaoBody(string $operacao, InstrucaoBoleto $instrucao): array
    {
        switch ($operacao) {
            case 'baixa':
                return [];
            case 'valor_nominal':
                return ['valor_titulo' => $this->formatMoney($instrucao->valor)];
            case 'data_vencimento':
                return ['data_vencimento' => $instrucao->vencimento];
            case 'seu_numero':
                return ['texto_seu_numero' => $instrucao->seuNumero];
            case 'abatimento':
                return ['valor_abatimento' => $this->formatMoney($instrucao->valorDeducao)];
            case 'multa':
                return [
                    'multa' => [
                        'codigo_tipo_multa' => $instrucao->dadosExtras['codigo_tipo_multa'] ?? '02',
                        'quantidade_dias_multa' => $instrucao->dadosExtras['quantidade_dias_multa'] ?? 1,
                        'percentual_multa' => $this->formatPercent($instrucao->percentualMulta),
                    ],
                ];
            case 'juros':
                if (isset($instrucao->dadosExtras['juros']) && is_array($instrucao->dadosExtras['juros'])) {
                    return ['juros' => $instrucao->dadosExtras['juros']];
                }
                return ['juros' => $instrucao->dadosExtras];
            case 'desconto':
                if ($instrucao->desconto === null) {
                    return ['desconto' => $instrucao->dadosExtras['desconto'] ?? []];
                }
                return ['desconto' => $this->mapDesconto($instrucao->desconto, true)];
            case 'protesto':
                return [
                    'protesto' => [
                        'codigo_tipo_protesto' => $instrucao->dadosExtras['codigo_tipo_protesto'] ?? 1,
                        'quantidade_dias_protesto' => $instrucao->diasProtesto,
                    ],
                ];
            case 'data_limite_pagamento':
                return [
                    'data_limite_pagamento' => $instrucao->dadosExtras['data_limite_pagamento']
                        ?? $instrucao->dadosExtras['dataLimitePagamento']
                        ?? '',
                ];
            case 'negativacao':
                return ['negativacao' => $instrucao->dadosExtras['negativacao'] ?? $instrucao->dadosExtras];
            case 'pagador':
                return ['pagador' => $instrucao->dadosExtras['pagador'] ?? $instrucao->dadosExtras];
            case 'recebimento_divergente':
                return [
                    'recebimento_divergente' => [
                        'codigo_tipo_autorizacao' => $instrucao->dadosExtras['codigo_tipo_autorizacao'] ?? '02',
                        'codigo_tipo_recebimento' => $instrucao->tipoValorPagamento ?: ($instrucao->dadosExtras['codigo_tipo_recebimento'] ?? 'V'),
                        'valor_minimo' => $instrucao->valorMinimo,
                        'valor_maximo' => $instrucao->valorMaximo,
                    ],
                ];
        }

        return $instrucao->dadosExtras;
    }

    private function inferOperacao(InstrucaoBoleto $instrucao): string
    {
        if ($instrucao->valor !== '') {
            return 'valor_nominal';
        }
        if ($instrucao->vencimento !== '') {
            return 'data_vencimento';
        }
        if ($instrucao->seuNumero !== '') {
            return 'seu_numero';
        }
        if ($instrucao->valorDeducao !== '') {
            return 'abatimento';
        }
        if ($instrucao->percentualMulta !== '' || $instrucao->dataMulta !== '') {
            return 'multa';
        }
        if ($instrucao->desconto !== null) {
            return 'desconto';
        }
        if ($instrucao->diasProtesto > 0) {
            return 'protesto';
        }
        if ($instrucao->tipoValorPagamento !== '' || $instrucao->valorMinimo !== '' || $instrucao->valorMaximo !== '') {
            return 'recebimento_divergente';
        }
        if (isset($instrucao->dadosExtras['operacao'])) {
            return (string) $instrucao->dadosExtras['operacao'];
        }

        return '';
    }

    private function normalizeOperacao(string $operacao): string
    {
        $key = strtolower(trim($operacao));
        $key = str_replace('-', '_', $key);

        $map = [
            'baixar' => 'baixa',
            'baixa' => 'baixa',
            'cancelar' => 'baixa',
            'valor' => 'valor_nominal',
            'valor_nominal' => 'valor_nominal',
            'alter_nominal_value' => 'valor_nominal',
            'alterar_valor' => 'valor_nominal',
            'vencimento' => 'data_vencimento',
            'data_vencimento' => 'data_vencimento',
            'alter_due_date' => 'data_vencimento',
            'alterar_vencimento' => 'data_vencimento',
            'juros' => 'juros',
            'multa' => 'multa',
            'desconto' => 'desconto',
            'abatimento' => 'abatimento',
            'protesto' => 'protesto',
            'protestar' => 'protesto',
            'seu_numero' => 'seu_numero',
            'data_limite_pagamento' => 'data_limite_pagamento',
            'negativacao' => 'negativacao',
            'pagador' => 'pagador',
            'recebimento_divergente' => 'recebimento_divergente',
        ];

        return $map[$key] ?? $key;
    }

    private function resolveIdBeneficiario(Boleto $boleto): string
    {
        return $boleto->dadosExtras['id_beneficiario']
            ?? $boleto->dadosExtras['idBeneficiario']
            ?? ($boleto->codigoConvenio ?: $this->idBeneficiario);
    }

    private function splitEnderecoNumero(string $endereco): array
    {
        $clean = trim($endereco);
        if ($clean === '') {
            return ['logradouro' => '', 'numero' => ''];
        }

        if (preg_match('/^(.+?)[, ]+([0-9A-Za-z\\-\\/]+)$/', $clean, $matches)) {
            return [
                'logradouro' => trim($matches[1]),
                'numero' => trim($matches[2]),
            ];
        }

        return ['logradouro' => $clean, 'numero' => ''];
    }

    private function mapTipoDocumento(string $tipo): string
    {
        $map = [
            'DUPLICATA_MERCANTIL' => '01',
            'NOTA_PROMISSORIA' => '02',
            'RECIBO' => '05',
            'CONTRATO' => '06',
            'DUPLICATA_SERVICO' => '08',
            'FATURA' => '17',
            'BOLETO_PROPOSTA' => '18',
            'OUTROS' => '99',
        ];

        if ($tipo === '') {
            return $this->codigoEspecie;
        }

        return $map[strtoupper($tipo)] ?? $tipo;
    }

    private function mapTipoDesconto(string $tipo): string
    {
        $map = [
            'SEM_DESCONTO' => '00',
            'ISENTO' => '00',
            'VALOR_DATA_FIXA' => '01',
            'PERCENTUAL_DATA_FIXA' => '02',
            'PERCENTUAL_ANTECIPACAO' => '90',
            'VALOR_ANTECIPACAO' => '91',
        ];

        return $map[strtoupper($tipo)] ?? $tipo;
    }

    private function formatLegacyMoney(string $value): string
    {
        $decimal = $this->formatMoney($value);
        $digits = str_replace('.', '', $decimal);

        return str_pad($digits, 17, '0', STR_PAD_LEFT);
    }

    private function formatMoney(string $value): string
    {
        $normalized = str_replace(',', '.', trim($value));

        if ($normalized === '') {
            $normalized = '0';
        }

        return number_format((float) $normalized, 2, '.', '');
    }

    private function formatPercent(string $value): string
    {
        $normalized = str_replace(',', '.', trim($value));

        if ($normalized === '') {
            $normalized = '0';
        }

        return number_format((float) $normalized, 5, '.', '');
    }

    private function formatBoletoPercent(string $value): string
    {
        if (!$this->pixLegacyPayload) {
            return $this->formatPercent($value);
        }

        return $this->formatLegacyPercent($value);
    }

    private function formatLegacyPercent(string $value): string
    {
        $decimal = $this->formatPercent($value);
        $digits = str_replace('.', '', $decimal);

        return str_pad($digits, 12, '0', STR_PAD_LEFT);
    }

    private function firstItem(array $items): array
    {
        if ($items === []) {
            return [];
        }

        if (isset($items[0]) && is_array($items[0])) {
            return $items[0];
        }

        return $items;
    }

    private function firstNonEmpty(array $values)
    {
        foreach ($values as $value) {
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function mergeIgnoringNull(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if ($value === null) {
                continue;
            }

            if (isset($base[$key]) && is_array($base[$key]) && is_array($value)) {
                $base[$key] = $this->mergeIgnoringNull($base[$key], $value);
                continue;
            }

            $base[$key] = $value;
        }

        return $base;
    }

    private function normalizeBoolean($value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));
            if (in_array($normalized, ['false', '0', 'n', 'nao', 'no'], true)) {
                return false;
            }
            if (in_array($normalized, ['true', '1', 's', 'sim', 'yes'], true)) {
                return true;
            }
        }

        return (bool) $value;
    }
}
