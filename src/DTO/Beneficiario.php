<?php

namespace ApiBoleto\DTO;

class Beneficiario
{
    /** @var string */
    public string $nome;

    /** @var string CPF ou CNPJ */
    public string $tipoDocumento;

    /** @var string */
    public string $documento;

    public function __construct(string $nome, string $tipoDocumento, string $documento)
    {
        $this->nome = $nome;
        $this->tipoDocumento = $tipoDocumento;
        $this->documento = $documento;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['nome'] ?? '',
            $data['tipoDocumento'] ?? 'CPF',
            $data['documento'] ?? ''
        );
    }

    public function toArray(): array
    {
        return [
            'nome' => $this->nome,
            'tipoDocumento' => $this->tipoDocumento,
            'documento' => $this->documento,
        ];
    }
}
