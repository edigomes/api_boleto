<?php

namespace ApiBoleto\DTO;

class Pagador
{
    /** @var string */
    public string $nome;

    /** @var string CPF ou CNPJ */
    public string $tipoDocumento;

    /** @var string */
    public string $documento;

    /** @var string */
    public string $endereco;

    /** @var string */
    public string $bairro;

    /** @var string */
    public string $cidade;

    /** @var string */
    public string $estado;

    /** @var string */
    public string $cep;

    public function __construct(
        string $nome,
        string $tipoDocumento,
        string $documento,
        string $endereco,
        string $bairro,
        string $cidade,
        string $estado,
        string $cep
    ) {
        $this->nome = $nome;
        $this->tipoDocumento = $tipoDocumento;
        $this->documento = $documento;
        $this->endereco = $endereco;
        $this->bairro = $bairro;
        $this->cidade = $cidade;
        $this->estado = $estado;
        $this->cep = $cep;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['nome'] ?? '',
            $data['tipoDocumento'] ?? 'CPF',
            $data['documento'] ?? '',
            $data['endereco'] ?? '',
            $data['bairro'] ?? '',
            $data['cidade'] ?? '',
            $data['estado'] ?? '',
            $data['cep'] ?? ''
        );
    }

    public function toArray(): array
    {
        return [
            'nome' => $this->nome,
            'tipoDocumento' => $this->tipoDocumento,
            'documento' => $this->documento,
            'endereco' => $this->endereco,
            'bairro' => $this->bairro,
            'cidade' => $this->cidade,
            'estado' => $this->estado,
            'cep' => $this->cep,
        ];
    }
}
