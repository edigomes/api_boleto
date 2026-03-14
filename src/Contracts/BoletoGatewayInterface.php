<?php

namespace ApiBoleto\Contracts;

use ApiBoleto\DTO\Boleto;
use ApiBoleto\DTO\BoletoResponse;
use ApiBoleto\DTO\InstrucaoBoleto;

interface BoletoGatewayInterface
{
    /**
     * Registra um novo boleto no banco.
     *
     * @param Boleto $boleto
     * @return BoletoResponse
     */
    public function criarBoleto(Boleto $boleto): BoletoResponse;

    /**
     * Consulta um boleto pelo identificador (nosso numero ou ID do banco).
     *
     * @param string $identificador
     * @return BoletoResponse
     */
    public function consultarBoleto(string $identificador): BoletoResponse;

    /**
     * Lista boletos com filtros opcionais.
     *
     * @param array $filtros
     * @return BoletoResponse[]
     */
    public function consultarBoletos(array $filtros = []): array;

    /**
     * Envia instrucoes de alteracao para um boleto existente.
     *
     * @param string $identificador Identificador do boleto (formato varia por banco)
     * @param InstrucaoBoleto $instrucao Instrucao de alteracao
     * @return BoletoResponse
     */
    public function alterarBoleto(string $identificador, InstrucaoBoleto $instrucao): BoletoResponse;

    /**
     * Cancela/baixa um boleto.
     *
     * Se nenhuma instrucao for passada, usa operacao padrao de baixa do banco.
     * Use InstrucaoBoleto para enviar dados extras junto com o cancelamento.
     *
     * @param string $identificador Identificador do boleto (formato varia por banco)
     * @param InstrucaoBoleto|null $instrucao Instrucao adicional (opcional)
     * @return bool
     */
    public function cancelarBoleto(string $identificador, ?InstrucaoBoleto $instrucao = null): bool;

    /**
     * Gera/recupera a URL do PDF do boleto.
     *
     * @param string $identificador Identificador do boleto (formato varia por banco)
     * @param string $payerDocumentNumber CPF/CNPJ do pagador (quando exigido pelo banco)
     * @return string URL do PDF
     */
    public function gerarPdf(string $identificador, string $payerDocumentNumber = ''): string;

    /**
     * Gera e baixa o conteudo binario do PDF do boleto.
     *
     * @param string $identificador Identificador do boleto (formato varia por banco)
     * @param string $payerDocumentNumber CPF/CNPJ do pagador (quando exigido pelo banco)
     * @return string Conteudo binario do PDF
     */
    public function downloadPdf(string $identificador, string $payerDocumentNumber = ''): string;
}
