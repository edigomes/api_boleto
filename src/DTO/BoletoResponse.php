<?php

namespace ApiBoleto\DTO;

class BoletoResponse
{
    /** @var string ID do boleto no banco */
    public string $id;

    /** @var string Nosso numero atribuido pelo banco */
    public string $nossoNumero;

    /** @var string Codigo de barras (bolecode) */
    public string $codigoBarras;

    /** @var string Linha digitavel (bolecode) */
    public string $linhaDigitavel;

    /** @var string Status do boleto no banco */
    public string $status;

    /** @var string Valor nominal */
    public string $valor;

    /** @var string Data de vencimento */
    public string $vencimento;

    /** @var string URL ou path do PDF, se disponivel */
    public string $urlPdf;

    /** @var string Payload do QR Code PIX (copia e cola), quando disponivel */
    public string $qrCodePix;

    /** @var string URL da imagem do QR Code PIX, quando disponivel */
    public string $qrCodeUrl;

    /** @var array Dados originais retornados pela API do banco */
    public array $dadosOriginais;

    public function __construct()
    {
        $this->id = '';
        $this->nossoNumero = '';
        $this->codigoBarras = '';
        $this->linhaDigitavel = '';
        $this->status = '';
        $this->valor = '';
        $this->vencimento = '';
        $this->urlPdf = '';
        $this->qrCodePix = '';
        $this->qrCodeUrl = '';
        $this->dadosOriginais = [];
    }

    public static function fromArray(array $data): self
    {
        $response = new self();

        $response->id = (string)($data['id'] ?? '');
        $response->nossoNumero = (string)($data['nossoNumero'] ?? '');
        $response->codigoBarras = (string)($data['codigoBarras'] ?? '');
        $response->linhaDigitavel = (string)($data['linhaDigitavel'] ?? '');
        $response->status = (string)($data['status'] ?? '');
        $response->valor = (string)($data['valor'] ?? '');
        $response->vencimento = (string)($data['vencimento'] ?? '');
        $response->urlPdf = (string)($data['urlPdf'] ?? '');
        $response->qrCodePix = (string)($data['qrCodePix'] ?? '');
        $response->qrCodeUrl = (string)($data['qrCodeUrl'] ?? '');
        $response->dadosOriginais = $data['dadosOriginais'] ?? [];

        return $response;
    }

    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'nossoNumero'    => $this->nossoNumero,
            'codigoBarras'   => $this->codigoBarras,
            'linhaDigitavel' => $this->linhaDigitavel,
            'status'         => $this->status,
            'valor'          => $this->valor,
            'vencimento'     => $this->vencimento,
            'urlPdf'         => $this->urlPdf,
            'qrCodePix'      => $this->qrCodePix,
            'qrCodeUrl'      => $this->qrCodeUrl,
            'dadosOriginais' => $this->dadosOriginais,
        ];
    }
}
