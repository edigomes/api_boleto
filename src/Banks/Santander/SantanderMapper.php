<?php

namespace ApiBoleto\Banks\Santander;

use ApiBoleto\DTO\Boleto;
use ApiBoleto\DTO\BoletoResponse;
use ApiBoleto\DTO\InstrucaoBoleto;

class SantanderMapper
{
    private const ENVIRONMENT_MAP = [
        'producao' => 'PRODUCAO',
        'sandbox'  => 'HOMOLOGACAO',
    ];

    /** @var string */
    private string $ambiente;

    public function __construct(string $ambiente = 'producao')
    {
        $this->ambiente = $ambiente;
    }

    /**
     * Converte o DTO Boleto para o payload esperado pela API do Santander.
     */
    public function toApiPayload(Boleto $boleto): array
    {
        $bankNumber = $boleto->nossoNumero ?: ($boleto->dadosExtras['bankNumber'] ?? '');
        $clientNumber = $boleto->seuNumero ?: ($boleto->dadosExtras['clientNumber'] ?? '');
        $nsuCode = $boleto->dadosExtras['nsuCode'] ?? $bankNumber;
        $nsuDate = $boleto->dadosExtras['nsuDate'] ?? $boleto->emissao;

        $payload = [
            'environment' => self::ENVIRONMENT_MAP[$this->ambiente] ?? 'PRODUCAO',
            'nsuCode' => $nsuCode,
            'nsuDate' => $nsuDate,
            'covenantCode' => $boleto->codigoConvenio,
            'bankNumber' => $bankNumber,
            'clientNumber' => $clientNumber,
            'dueDate' => $boleto->vencimento,
            'issueDate' => $boleto->emissao,
            'nominalValue' => $boleto->valor,
            'documentKind' => $this->mapTipoDocumento($boleto->tipoDocumento),
            'payer' => $this->mapPagador($boleto),
            'beneficiary' => $this->mapBeneficiario($boleto),
        ];

        if (!empty($boleto->dadosExtras['participantCode'])) {
            $payload['participantCode'] = $boleto->dadosExtras['participantCode'];
        }

        $this->applyDesconto($payload, $boleto);
        $this->applyMulta($payload, $boleto);
        $this->applyJuros($payload, $boleto);

        if ($boleto->valorDeducao !== '0' && $boleto->valorDeducao !== '') {
            $payload['deductionValue'] = $boleto->valorDeducao;
        }

        $payload['protestType'] = $this->mapProtesto($boleto->tipoProtesto);

        if ($boleto->diasProtesto > 0) {
            $payload['protestQuantityDays'] = (string) $boleto->diasProtesto;
        }

        if ($boleto->diasBaixa > 0) {
            $payload['writeOffQuantityDays'] = (string) $boleto->diasBaixa;
        }

        if (!empty($boleto->mensagens)) {
            $payload['messages'] = $boleto->mensagens;
        }

        $payload['paymentType'] = 'REGISTRO';

        $this->applyDadosExtras($payload, $boleto);

        return $payload;
    }

    /**
     * Converte a resposta da API do Santander para o DTO BoletoResponse.
     */
    public function toResponse(array $apiData): BoletoResponse
    {
        $response = new BoletoResponse();

        $response->id = (string) ($apiData['id'] ?? $apiData['bankSlipId'] ?? '');

        if (empty($response->id)) {
            $response->id = $this->buildCompositeId($apiData);
        }

        $response->nossoNumero = (string) ($apiData['bankNumber'] ?? $apiData['participantCode'] ?? '');
        $response->codigoBarras = (string) ($apiData['barCode'] ?? $apiData['barcode'] ?? '');
        $response->linhaDigitavel = (string) ($apiData['digitableLine'] ?? $apiData['digitableLineCode'] ?? '');
        $response->status = (string) ($apiData['status'] ?? $apiData['bankSlipStatus'] ?? '');
        $response->valor = (string) ($apiData['nominalValue'] ?? $apiData['entryValue'] ?? $apiData['totalValue'] ?? '');
        $response->vencimento = (string) ($apiData['dueDate'] ?? '');
        $response->urlPdf = (string) ($apiData['pdfUrl'] ?? $apiData['pdfLink'] ?? '');
        $response->qrCodePix = (string) ($apiData['qrCodePix'] ?? $apiData['emvqrcps'] ?? $apiData['pixQrCode'] ?? '');
        $response->qrCodeUrl = (string) ($apiData['qrCodeUrl'] ?? $apiData['qrCodeImageUrl'] ?? $apiData['pixQrCodeUrl'] ?? '');
        $response->dadosOriginais = $apiData;

        return $response;
    }

    /**
     * Constroi o ID composto para consulta SONDA: {nsuCode}.{nsuDate}.{envLetter}.{covenantCode}.{bankNumber}
     */
    private function buildCompositeId(array $apiData): string
    {
        $nsuCode = $apiData['nsuCode'] ?? '';
        $nsuDate = $apiData['nsuDate'] ?? '';
        $env = substr($apiData['environment'] ?? 'P', 0, 1);
        $covenant = $apiData['covenantCode'] ?? '';
        $bankNum = $apiData['bankNumber'] ?? '';

        if ($nsuCode !== '' && $nsuDate !== '' && $covenant !== '' && $bankNum !== '') {
            return "{$nsuCode}.{$nsuDate}.{$env}.{$covenant}.{$bankNum}";
        }

        return (string) ($nsuCode ?: '');
    }

    /**
     * Converte InstrucaoBoleto para o payload de instrucoes PATCH do Santander.
     *
     * @param string $covenantCode Codigo do convenio
     * @param string $bankNumber Nosso numero
     * @param InstrucaoBoleto $instrucao Instrucao generica
     * @return array Payload pronto para envio ao Santander
     */
    public function toInstrucaoPayload(
        string $covenantCode,
        string $bankNumber,
        InstrucaoBoleto $instrucao
    ): array {
        $payload = [
            'covenantCode' => $covenantCode,
            'bankNumber'   => $bankNumber,
        ];

        $this->applyInstrucaoFields($payload, $instrucao);

        if (!empty($instrucao->operacao)) {
            $payload['operation'] = $instrucao->operacao;
        } elseif (empty($payload['operation'])) {
            $payload['operation'] = $this->inferOperation($instrucao);
        }

        foreach ($instrucao->dadosExtras as $key => $value) {
            $payload[$key] = $value;
        }

        return $payload;
    }

    private function applyInstrucaoFields(array &$payload, InstrucaoBoleto $instrucao): void
    {
        if ($instrucao->vencimento !== '') {
            $payload['dueDate'] = $instrucao->vencimento;
        }

        if ($instrucao->valor !== '') {
            $payload['nominalValue'] = $instrucao->valor;
        }

        if ($instrucao->seuNumero !== '') {
            $payload['clientNumber'] = $instrucao->seuNumero;
        }

        if ($instrucao->valorDeducao !== '') {
            $payload['deductionValue'] = $instrucao->valorDeducao;
        }

        if ($instrucao->percentualMulta !== '') {
            $payload['finePercentage'] = $instrucao->percentualMulta;
        }

        if ($instrucao->dataMulta !== '') {
            $payload['fineDate'] = $instrucao->dataMulta;
        }

        if ($instrucao->diasProtesto > 0) {
            $payload['protestQuantityDays'] = (string) $instrucao->diasProtesto;
        }

        if ($instrucao->diasBaixa > 0) {
            $payload['writeOffQuantityDays'] = (string) $instrucao->diasBaixa;
        }

        if ($instrucao->tipoValorPagamento !== '') {
            $payload['paymentValueType'] = $instrucao->tipoValorPagamento;
        }

        if ($instrucao->valorMinimo !== '') {
            $payload['minValueOrPercentage'] = $instrucao->valorMinimo;
        }

        if ($instrucao->valorMaximo !== '') {
            $payload['maxValueOrPercentage'] = $instrucao->valorMaximo;
        }

        if ($instrucao->codigoParticipante !== '') {
            $payload['participantCode'] = $instrucao->codigoParticipante;
        }

        if ($instrucao->desconto !== null) {
            $this->applyInstrucaoDesconto($payload, $instrucao);
        }
    }

    private function applyInstrucaoDesconto(array &$payload, InstrucaoBoleto $instrucao): void
    {
        $desconto = $instrucao->desconto;
        $discount = ['type' => $this->mapTipoDesconto($desconto->tipo)];

        if ($desconto->desconto1 !== null) {
            $discount['discountOne'] = [
                'value' => $desconto->desconto1['valor'] ?? 0,
                'limitDate' => $desconto->desconto1['dataLimite'] ?? '',
            ];
        }

        if ($desconto->desconto2 !== null) {
            $discount['discountTwo'] = [
                'value' => $desconto->desconto2['valor'] ?? 0,
                'limitDate' => $desconto->desconto2['dataLimite'] ?? '',
            ];
        }

        if ($desconto->desconto3 !== null) {
            $discount['discountThree'] = [
                'value' => $desconto->desconto3['valor'] ?? 0,
                'limitDate' => $desconto->desconto3['dataLimite'] ?? '',
            ];
        }

        $payload['discount'] = $discount;
    }

    /**
     * Infere a operacao com base nos campos preenchidos da instrucao.
     */
    private function inferOperation(InstrucaoBoleto $instrucao): string
    {
        if ($instrucao->vencimento !== '') {
            return 'ALTER_DUE_DATE';
        }

        if ($instrucao->valor !== '') {
            return 'ALTER_NOMINAL_VALUE';
        }

        if ($instrucao->desconto !== null) {
            return 'ALTER_DISCOUNT';
        }

        if ($instrucao->percentualMulta !== '') {
            return 'ALTER_FINE';
        }

        if ($instrucao->diasProtesto > 0) {
            return 'ALTER_PROTEST';
        }

        if ($instrucao->diasBaixa > 0) {
            return 'ALTER_WRITE_OFF';
        }

        if ($instrucao->valorDeducao !== '') {
            return 'ALTER_DEDUCTION';
        }

        return '';
    }

    /**
     * Converte uma lista de resultados da API em array de BoletoResponse.
     *
     * @return BoletoResponse[]
     */
    public function toResponseList(array $apiDataList): array
    {
        $responses = [];
        foreach ($apiDataList as $item) {
            $responses[] = $this->toResponse($item);
        }
        return $responses;
    }

    private function mapPagador(Boleto $boleto): array
    {
        $pagador = $boleto->pagador;
        return [
            'name' => $pagador->nome,
            'documentType' => $this->mapTipoDocumentoPessoa($pagador->tipoDocumento),
            'documentNumber' => $pagador->documento,
            'address' => $pagador->endereco,
            'neighborhood' => $pagador->bairro,
            'city' => $pagador->cidade,
            'state' => $pagador->estado,
            'zipCode' => $this->normalizeCep($pagador->cep),
        ];
    }

    /**
     * Normaliza o CEP para o formato esperado pelo Santander: 99999-999.
     * Aceita tanto "99999999" quanto "99999-999".
     */
    private function normalizeCep(string $cep): string
    {
        $digits = preg_replace('/\D/', '', $cep);

        if (strlen($digits) === 8) {
            return substr($digits, 0, 5) . '-' . substr($digits, 5);
        }

        return $cep;
    }

    private function mapBeneficiario(Boleto $boleto): array
    {
        $beneficiario = $boleto->beneficiario;
        return [
            'name' => $beneficiario->nome,
            'documentType' => $this->mapTipoDocumentoPessoa($beneficiario->tipoDocumento),
            'documentNumber' => $beneficiario->documento,
        ];
    }

    private function applyDesconto(array &$payload, Boleto $boleto): void
    {
        if ($boleto->desconto === null) {
            return;
        }

        $desconto = $boleto->desconto;
        $discount = ['type' => $this->mapTipoDesconto($desconto->tipo)];

        if ($desconto->desconto1 !== null) {
            $discount['discountOne'] = [
                'value' => $desconto->desconto1['valor'] ?? 0,
                'limitDate' => $desconto->desconto1['dataLimite'] ?? '',
            ];
        }

        if ($desconto->desconto2 !== null) {
            $discount['discountTwo'] = [
                'value' => $desconto->desconto2['valor'] ?? 0,
                'limitDate' => $desconto->desconto2['dataLimite'] ?? '',
            ];
        }

        if ($desconto->desconto3 !== null) {
            $discount['discountThree'] = [
                'value' => $desconto->desconto3['valor'] ?? 0,
                'limitDate' => $desconto->desconto3['dataLimite'] ?? '',
            ];
        }

        $payload['discount'] = $discount;
    }

    private function applyMulta(array &$payload, Boleto $boleto): void
    {
        if ($boleto->multa === null) {
            return;
        }

        $payload['finePercentage'] = $boleto->multa->percentual;
        $payload['fineQuantityDays'] = (string) $boleto->multa->diasAposVencimento;
    }

    private function applyJuros(array &$payload, Boleto $boleto): void
    {
        if ($boleto->juros === null) {
            return;
        }

        $payload['interestPercentage'] = $boleto->juros->percentual;
    }

    /**
     * Campos extras que sao passados diretamente ao payload do Santander.
     * Util para campos especificos como paymentType, sharing, key, txId, etc.
     */
    private function applyDadosExtras(array &$payload, Boleto $boleto): void
    {
        $camposDirectos = [
            'paymentType',
            'parcelsQuantity',
            'valueType',
            'minValueOrPercentage',
            'maxValueOrPercentage',
            'iofPercentage',
            'sharing',
            'key',
            'txId',
        ];

        foreach ($camposDirectos as $campo) {
            if (isset($boleto->dadosExtras[$campo])) {
                $payload[$campo] = $boleto->dadosExtras[$campo];
            }
        }
    }

    private function mapTipoDocumento(string $tipo): string
    {
        $map = [
            'DUPLICATA_MERCANTIL' => 'DUPLICATA_MERCANTIL',
            'NOTA_PROMISSORIA'    => 'NOTA_PROMISSORIA',
            'RECIBO'              => 'RECIBO',
            'FATURA'              => 'FATURA',
            'OUTROS'              => 'OUTROS',
        ];

        if (empty($tipo)) {
            return 'DUPLICATA_MERCANTIL';
        }

        return $map[$tipo] ?? $tipo;
    }

    private function mapTipoDocumentoPessoa(string $tipo): string
    {
        $tipo = strtoupper($tipo);
        if ($tipo === 'CPF' || $tipo === 'CNPJ') {
            return $tipo;
        }
        return 'CPF';
    }

    private function mapTipoDesconto(string $tipo): string
    {
        $map = [
            'VALOR_DATA_FIXA' => 'VALOR_DATA_FIXA',
            'PERCENTUAL_DATA_FIXA' => 'PERCENTUAL_DATA_FIXA',
        ];

        return $map[$tipo] ?? $tipo;
    }

    private function mapProtesto(string $tipo): string
    {
        $map = [
            'SEM_PROTESTO' => 'SEM_PROTESTO',
            'PROTESTAR' => 'PROTESTAR',
            'DEVOLVER' => 'DEVOLVER',
        ];

        return $map[$tipo] ?? $tipo;
    }
}
