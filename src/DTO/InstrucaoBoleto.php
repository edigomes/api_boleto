<?php

namespace ApiBoleto\DTO;

/**
 * DTO generico para instrucoes de alteracao de boleto.
 *
 * Cada campo preenchido sera uma instrucao enviada ao banco.
 * Campos vazios/null sao ignorados (nao altera o que nao foi informado).
 */
class InstrucaoBoleto
{
    /** @var string Novo vencimento (YYYY-MM-DD) */
    public string $vencimento = '';

    /** @var string Novo valor nominal (ex: "100.00") */
    public string $valor = '';

    /** @var string Novo "seu numero" / referencia do cliente */
    public string $seuNumero = '';

    /** @var string Valor de abatimento/deducao */
    public string $valorDeducao = '';

    /** @var string Percentual de multa */
    public string $percentualMulta = '';

    /** @var string Data de inicio da multa (YYYY-MM-DD) */
    public string $dataMulta = '';

    /** @var int Dias para protesto (0 = nao alterar) */
    public int $diasProtesto = 0;

    /** @var int Dias para baixa automatica (0 = nao alterar) */
    public int $diasBaixa = 0;

    /** @var string Tipo de aceite de pagamento (ex: VALOR, PERCENTUAL) */
    public string $tipoValorPagamento = '';

    /** @var string Valor/percentual minimo aceito */
    public string $valorMinimo = '';

    /** @var string Valor/percentual maximo aceito */
    public string $valorMaximo = '';

    /** @var string Codigo do participante */
    public string $codigoParticipante = '';

    /** @var Desconto|null Novos dados de desconto */
    public ?Desconto $desconto = null;

    /** @var string Operacao especifica do banco (ex: BAIXAR no Santander) */
    public string $operacao = '';

    /** @var array Dados extras especificos de cada banco */
    public array $dadosExtras = [];

    public static function fromArray(array $data): self
    {
        $instrucao = new self();

        $instrucao->vencimento = $data['vencimento'] ?? '';
        $instrucao->valor = $data['valor'] ?? '';
        $instrucao->seuNumero = $data['seuNumero'] ?? '';
        $instrucao->valorDeducao = $data['valorDeducao'] ?? '';
        $instrucao->percentualMulta = $data['percentualMulta'] ?? '';
        $instrucao->dataMulta = $data['dataMulta'] ?? '';
        $instrucao->diasProtesto = $data['diasProtesto'] ?? 0;
        $instrucao->diasBaixa = $data['diasBaixa'] ?? 0;
        $instrucao->tipoValorPagamento = $data['tipoValorPagamento'] ?? '';
        $instrucao->valorMinimo = $data['valorMinimo'] ?? '';
        $instrucao->valorMaximo = $data['valorMaximo'] ?? '';
        $instrucao->codigoParticipante = $data['codigoParticipante'] ?? '';
        $instrucao->operacao = $data['operacao'] ?? '';
        $instrucao->dadosExtras = $data['dadosExtras'] ?? [];

        if (isset($data['desconto'])) {
            $instrucao->desconto = $data['desconto'] instanceof Desconto
                ? $data['desconto']
                : Desconto::fromArray($data['desconto']);
        }

        return $instrucao;
    }

    /**
     * Cria instrucao de baixa (cancelamento).
     */
    public static function baixar(): self
    {
        $instrucao = new self();
        $instrucao->operacao = 'BAIXAR';
        return $instrucao;
    }

    /**
     * Cria instrucao de alteracao de vencimento.
     */
    public static function alterarVencimento(string $novoVencimento): self
    {
        $instrucao = new self();
        $instrucao->vencimento = $novoVencimento;
        return $instrucao;
    }

    /**
     * Cria instrucao de alteracao de valor.
     */
    public static function alterarValor(string $novoValor): self
    {
        $instrucao = new self();
        $instrucao->valor = $novoValor;
        return $instrucao;
    }
}
