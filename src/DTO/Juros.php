<?php

namespace ApiBoleto\DTO;

class Juros
{
    /** @var string Percentual de juros ao mes */
    public string $percentual;

    public function __construct(string $percentual)
    {
        $this->percentual = $percentual;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            $data['percentual'] ?? '0'
        );
    }

    public function toArray(): array
    {
        return [
            'percentual' => $this->percentual,
        ];
    }
}
