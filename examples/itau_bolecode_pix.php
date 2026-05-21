<?php

/**
 * Exemplo: Criar/simular Bolecode Pix no Itau.
 *
 * Executar:
 *   php examples/itau_bolecode_pix.php
 *
 * Requer:
 *   ITAU_PIX_BASE_URL  Base URL Bolecode/Pix informada pelo Itau
 *   ITAU_CHAVE_PIX     Chave DICT do beneficiario
 *
 * Se estiver usando a colecao "API - Bolecode" do Itau, configure:
 *   ITAU_PIX_BASE_URL=https://secure.api.itau/pix_recebimentos_conciliacoes/v2
 *   ITAU_PIX_ENDPOINT_PATH=/boletos_pix
 *   ITAU_PIX_LEGACY_PAYLOAD=true
 *   ITAU_PIX_ETAPA_PROCESSO=Simulacao
 *
 * Para emitir de verdade, use a etapa/orientacao confirmada pelo Itau antes
 * de trocar a simulacao para efetivacao.
 */

require __DIR__ . '/../vendor/autoload.php';

use ApiBoleto\BoletoManager;
use ApiBoleto\DTO\Boleto;
use ApiBoleto\Exceptions\ApiException;
use ApiBoleto\Exceptions\AuthenticationException;
use ApiBoleto\Exceptions\BoletoException;

$config = require __DIR__ . '/itau_config.php';
$config['etapaProcesso'] = itau_example_env('ITAU_PIX_ETAPA_PROCESSO', 'simulacao');

if (empty($config['pixBaseUrl'])) {
    echo "ERRO: informe ITAU_PIX_BASE_URL com a base Bolecode/Pix do Itau.\n";
    exit(1);
}

$chavePix = itau_example_env('ITAU_CHAVE_PIX');
if ($chavePix === '') {
    echo "ERRO: informe ITAU_CHAVE_PIX com a chave DICT do beneficiario.\n";
    exit(1);
}

try {
    $manager = new BoletoManager();
    $gateway = $manager->banco('itau', $config);

    $nossoNumero = itau_example_env('ITAU_NOSSO_NUMERO', str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT));
    $vencimento = itau_example_env('ITAU_DATA_VENCIMENTO', date('Y-m-d', strtotime('+30 days')));

    $boleto = Boleto::fromArray([
        'valor'          => itau_example_env('ITAU_VALOR_BOLETO', '50.00'),
        'vencimento'     => $vencimento,
        'emissao'        => itau_example_env('ITAU_DATA_EMISSAO', date('Y-m-d')),
        'nossoNumero'    => $nossoNumero,
        'seuNumero'      => itau_example_env('ITAU_SEU_NUMERO', 'PIX' . substr($nossoNumero, -6)),
        'codigoConvenio' => $config['idBeneficiario'],
        'tipoDocumento'  => itau_example_env('ITAU_TIPO_DOCUMENTO', 'DUPLICATA_MERCANTIL'),
        'pagador' => [
            'nome'          => itau_example_env('ITAU_PAGADOR_NOME', 'Cliente Teste'),
            'tipoDocumento' => itau_example_env('ITAU_PAGADOR_TIPO_DOCUMENTO', 'CPF'),
            'documento'     => itau_example_env('ITAU_PAGADOR_DOCUMENTO', '12345678909'),
            'endereco'      => itau_example_env('ITAU_PAGADOR_ENDERECO', 'Rua Teste, 100'),
            'bairro'        => itau_example_env('ITAU_PAGADOR_BAIRRO', 'Centro'),
            'cidade'        => itau_example_env('ITAU_PAGADOR_CIDADE', 'Sao Paulo'),
            'estado'        => itau_example_env('ITAU_PAGADOR_ESTADO', 'SP'),
            'cep'           => itau_example_env('ITAU_PAGADOR_CEP', '01310100'),
        ],
        'beneficiario' => [
            'nome'          => itau_example_env('ITAU_BENEFICIARIO_NOME', 'Empresa Teste LTDA'),
            'tipoDocumento' => itau_example_env('ITAU_BENEFICIARIO_TIPO_DOCUMENTO', 'CNPJ'),
            'documento'     => itau_example_env('ITAU_BENEFICIARIO_DOCUMENTO', '12345678000190'),
        ],
        'dadosExtras' => [
            'bolecodePix' => true,
            'chavePix' => $chavePix,
            'tipo_cobranca' => itau_example_env('ITAU_PIX_TIPO_COBRANCA', 'cob'),
            'data_limite_pagamento' => itau_example_env('ITAU_DATA_LIMITE_PAGAMENTO', $vencimento),
        ],
    ]);

    echo "=== Itau - Bolecode Pix ===\n";
    echo "Etapa:       {$config['etapaProcesso']}\n";
    echo "Nosso Num.:  {$boleto->nossoNumero}\n";
    echo "Vencimento:  {$boleto->vencimento}\n\n";

    $response = $gateway->criarBoleto($boleto);

    echo "ID:              {$response->id}\n";
    echo "Nosso Numero:    {$response->nossoNumero}\n";
    echo "Codigo Barras:   {$response->codigoBarras}\n";
    echo "Linha Digitavel: {$response->linhaDigitavel}\n";
    echo "Pix copia/cola:  {$response->qrCodePix}\n";
    echo "QR Location:     {$response->qrCodeUrl}\n\n";

    echo "=== Dados originais da API ===\n";
    echo json_encode($response->dadosOriginais, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} catch (AuthenticationException $e) {
    echo "ERRO DE AUTENTICACAO: {$e->getMessage()}\n";
} catch (ApiException $e) {
    echo "ERRO DA API [{$e->getStatusCode()}]: {$e->getMessage()}\n";
    echo "Detalhes: " . json_encode($e->getResponseBody(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} catch (BoletoException $e) {
    echo "ERRO: {$e->getMessage()}\n";
} catch (Throwable $e) {
    echo "ERRO INESPERADO: {$e->getMessage()}\n";
}
