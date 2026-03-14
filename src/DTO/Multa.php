<?php

namespace ApiBoleto\DTO;

class Multa
{
    /** @var string Percentual da multa */
    public string $percentual;

    /** @var int Quantidade de dias apos vencimento para aplicar multa */
    public int $diasAposVencimento;

    public function __construct(string $percentual, int $diasAposVencimento = 1)
    {
        $this->percentual = $percentual;
        $this->diasAposVencimento = $diasAposVencimento;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['percentual'] ?? '0',
            $data['diasAposVencimento'] ?? 1
        );
    }

    public function toArray(): array
    {
        return [
            'percentual' => $this->percentual,
            'diasAposVencimento' => $this->diasAposVencimento,
        ];
    }
}
