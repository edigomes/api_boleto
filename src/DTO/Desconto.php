<?php

namespace ApiBoleto\DTO;

class Desconto
{
    /** @var string Ex: VALOR_DATA_FIXA, PERCENTUAL_DATA_FIXA */
    public string $tipo;

    /** @var array|null ['valor' => float, 'dataLimite' => string] */
    public ?array $desconto1;

    /** @var array|null */
    public ?array $desconto2;

    /** @var array|null */
    public ?array $desconto3;

    public function __construct(
        string $tipo,
        ?array $desconto1 = null,
        ?array $desconto2 = null,
        ?array $desconto3 = null
    ) {
        $this->tipo = $tipo;
        $this->desconto1 = $desconto1;
        $this->desconto2 = $desconto2;
        $this->desconto3 = $desconto3;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['tipo'] ?? '',
            $data['desconto1'] ?? null,
            $data['desconto2'] ?? null,
            $data['desconto3'] ?? null
        );
    }

    public function toArray(): array
    {
        $result = ['tipo' => $this->tipo];

        if ($this->desconto1 !== null) {
            $result['desconto1'] = $this->desconto1;
        }
        if ($this->desconto2 !== null) {
            $result['desconto2'] = $this->desconto2;
        }
        if ($this->desconto3 !== null) {
            $result['desconto3'] = $this->desconto3;
        }

        return $result;
    }
}
