<?php

namespace ApiBoleto\DTO;

class Boleto
{
    /** @var string Valor nominal do boleto (ex: "150.00") */
    public string $valor;

    /** @var string Data de vencimento (YYYY-MM-DD) */
    public string $vencimento;

    /** @var string Data de emissao (YYYY-MM-DD) */
    public string $emissao;

    /** @var string Nosso numero / codigo de identificacao (bankNumber no Santander) */
    public string $nossoNumero;

    /** @var string Seu numero / referencia do cliente (clientNumber no Santander) */
    public string $seuNumero;

    /** @var string Codigo do convenio com o banco */
    public string $codigoConvenio;

    /** @var string Tipo do documento (ex: DUPLICATA_MERCANTIL) */
    public string $tipoDocumento;

    /** @var Pagador */
    public Pagador $pagador;

    /** @var Beneficiario */
    public Beneficiario $beneficiario;

    /** @var Desconto|null */
    public ?Desconto $desconto;

    /** @var Multa|null */
    public ?Multa $multa;

    /** @var Juros|null */
    public ?Juros $juros;

    /** @var string[] Mensagens adicionais no boleto */
    public array $mensagens;

    /** @var string Tipo de protesto (ex: SEM_PROTESTO) */
    public string $tipoProtesto;

    /** @var int Dias para protesto */
    public int $diasProtesto;

    /** @var int Dias para baixa automatica */
    public int $diasBaixa;

    /** @var string Valor de deducao/abatimento */
    public string $valorDeducao;

    /** @var array Dados extras especificos de cada banco */
    public array $dadosExtras;

    public function __construct()
    {
        $this->valor = '0';
        $this->vencimento = '';
        $this->emissao = '';
        $this->nossoNumero = '';
        $this->seuNumero = '';
        $this->codigoConvenio = '';
        $this->tipoDocumento = '';
        $this->desconto = null;
        $this->multa = null;
        $this->juros = null;
        $this->mensagens = [];
        $this->tipoProtesto = 'SEM_PROTESTO';
        $this->diasProtesto = 0;
        $this->diasBaixa = 0;
        $this->valorDeducao = '0';
        $this->dadosExtras = [];
    }

    public static function fromArray(array $data): self
    {
        $boleto = new self();

        $boleto->valor = $data['valor'] ?? '0';
        $boleto->vencimento = $data['vencimento'] ?? '';
        $boleto->emissao = $data['emissao'] ?? '';
        $boleto->nossoNumero = $data['nossoNumero'] ?? '';
        $boleto->seuNumero = $data['seuNumero'] ?? '';
        $boleto->codigoConvenio = $data['codigoConvenio'] ?? '';
        $boleto->tipoDocumento = $data['tipoDocumento'] ?? '';
        $boleto->mensagens = $data['mensagens'] ?? [];
        $boleto->tipoProtesto = $data['tipoProtesto'] ?? 'SEM_PROTESTO';
        $boleto->diasProtesto = $data['diasProtesto'] ?? 0;
        $boleto->diasBaixa = $data['diasBaixa'] ?? 0;
        $boleto->valorDeducao = $data['valorDeducao'] ?? '0';
        $boleto->dadosExtras = $data['dadosExtras'] ?? [];

        if (isset($data['pagador'])) {
            $boleto->pagador = $data['pagador'] instanceof Pagador
                ? $data['pagador']
                : Pagador::fromArray($data['pagador']);
        } else {
            $boleto->pagador = new Pagador('', 'CPF', '', '', '', '', '', '');
        }

        if (isset($data['beneficiario'])) {
            $boleto->beneficiario = $data['beneficiario'] instanceof Beneficiario
                ? $data['beneficiario']
                : Beneficiario::fromArray($data['beneficiario']);
        } else {
            $boleto->beneficiario = new Beneficiario('', 'CPF', '');
        }

        if (isset($data['desconto'])) {
            $boleto->desconto = $data['desconto'] instanceof Desconto
                ? $data['desconto']
                : Desconto::fromArray($data['desconto']);
        }

        if (isset($data['multa'])) {
            $boleto->multa = $data['multa'] instanceof Multa
                ? $data['multa']
                : Multa::fromArray($data['multa']);
        }

        if (isset($data['juros'])) {
            $boleto->juros = $data['juros'] instanceof Juros
                ? $data['juros']
                : Juros::fromArray($data['juros']);
        }

        return $boleto;
    }

    public function toArray(): array
    {
        $result = [
            'valor' => $this->valor,
            'vencimento' => $this->vencimento,
            'emissao' => $this->emissao,
            'nossoNumero' => $this->nossoNumero,
            'seuNumero' => $this->seuNumero,
            'codigoConvenio' => $this->codigoConvenio,
            'tipoDocumento' => $this->tipoDocumento,
            'pagador' => $this->pagador->toArray(),
            'beneficiario' => $this->beneficiario->toArray(),
            'mensagens' => $this->mensagens,
            'tipoProtesto' => $this->tipoProtesto,
            'diasProtesto' => $this->diasProtesto,
            'diasBaixa' => $this->diasBaixa,
            'valorDeducao' => $this->valorDeducao,
            'dadosExtras' => $this->dadosExtras,
        ];

        if ($this->desconto !== null) {
            $result['desconto'] = $this->desconto->toArray();
        }
        if ($this->multa !== null) {
            $result['multa'] = $this->multa->toArray();
        }
        if ($this->juros !== null) {
            $result['juros'] = $this->juros->toArray();
        }

        return $result;
    }
}
