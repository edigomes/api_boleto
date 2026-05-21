<?php

/**
 * Exemplo: Criar/validar boleto comum no Itau.
 *
 * Executar:
 *   php examples/itau_sample.php
 *
 * Por padrao usa ITAU_ETAPA_PROCESSO=validacao. Para emitir de verdade,
 * defina ITAU_ETAPA_PROCESSO=efetivacao antes de executar.
 *
 * Juros, multa e recebimento divergente sao opcionais neste smoke test:
 *   ITAU_JUROS_PERCENTUAL
 *   ITAU_MULTA_PERCENTUAL
 *   ITAU_RECEBIMENTO_DIVERGENTE
 *   ITAU_OUTPUT_PDF
 */

require __DIR__ . '/../vendor/autoload.php';

use ApiBoleto\BoletoManager;
use ApiBoleto\DTO\Boleto;
use ApiBoleto\Exceptions\ApiException;
use ApiBoleto\Exceptions\AuthenticationException;
use ApiBoleto\Exceptions\BoletoException;
use ApiBoleto\Pdf\BoletoPdfRenderer;

$config = require __DIR__ . '/itau_config.php';

try {
    $manager = new BoletoManager();
    $gateway = $manager->banco('itau', $config);

    $nossoNumero = itau_example_env('ITAU_NOSSO_NUMERO', str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT));
    $timezone = new DateTimeZone(itau_example_env('ITAU_TIMEZONE', 'America/Sao_Paulo'));
    $today = new DateTimeImmutable('now', $timezone);
    $emissao = itau_example_env('ITAU_DATA_EMISSAO', $today->format('Y-m-d'));
    $vencimento = itau_example_env('ITAU_DATA_VENCIMENTO', $today->modify('+30 days')->format('Y-m-d'));

    $dadosExtras = [
        'texto_uso_beneficiario' => itau_example_env('ITAU_TEXTO_USO_BENEFICIARIO', 'Teste API'),
        'data_limite_pagamento'  => itau_example_env('ITAU_DATA_LIMITE_PAGAMENTO', $vencimento),
        'desconto_expresso' => false,
    ];

    $recebimentoDivergente = itau_example_env('ITAU_RECEBIMENTO_DIVERGENTE');
    if ($recebimentoDivergente !== '') {
        $dadosExtras['recebimento_divergente'] = [
            'codigo_tipo_autorizacao' => $recebimentoDivergente,
        ];
    }

    $boletoData = [
        'valor'          => itau_example_env('ITAU_VALOR_BOLETO', '50.00'),
        'vencimento'     => $vencimento,
        'emissao'        => $emissao,
        'nossoNumero'    => $nossoNumero,
        'seuNumero'      => itau_example_env('ITAU_SEU_NUMERO', 'TESTE' . substr($nossoNumero, -5)),
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
        'mensagens' => [
            itau_example_env('ITAU_MENSAGEM', 'Boleto de teste via API'),
        ],
        'dadosExtras' => $dadosExtras,
    ];

    $multaPercentual = itau_example_env('ITAU_MULTA_PERCENTUAL');
    if ($multaPercentual !== '') {
        $boletoData['multa'] = [
            'percentual' => $multaPercentual,
            'diasAposVencimento' => (int) itau_example_env('ITAU_MULTA_DIAS', '1'),
        ];
    }

    $jurosPercentual = itau_example_env('ITAU_JUROS_PERCENTUAL');
    if ($jurosPercentual !== '') {
        $boletoData['juros'] = [
            'percentual' => $jurosPercentual,
        ];
    }

    $boleto = Boleto::fromArray($boletoData);

    echo "=== Itau - boleto comum ===\n";
    echo "Etapa:       {$config['etapaProcesso']}\n";
    echo "Nosso Num.:  {$boleto->nossoNumero}\n";
    echo "Vencimento:  {$boleto->vencimento}\n\n";

    $response = $gateway->criarBoleto($boleto);

    echo "ID:              {$response->id}\n";
    echo "Nosso Numero:    {$response->nossoNumero}\n";
    echo "Codigo Barras:   {$response->codigoBarras}\n";
    echo "Linha Digitavel: {$response->linhaDigitavel}\n";
    echo "Status:          {$response->status}\n";
    echo "Valor:           {$response->valor}\n";
    echo "Vencimento:      {$response->vencimento}\n\n";

    echo "=== Dados originais da API ===\n";
    echo json_encode($response->dadosOriginais, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

    $pdfPath = itau_example_env('ITAU_OUTPUT_PDF');
    if ($pdfPath !== '') {
        $pdf = (new BoletoPdfRenderer())->render($response, [
            'bankName' => 'Banco Itau S.A.',
            'bankCode' => '341',
        ]);

        file_put_contents($pdfPath, $pdf);
        echo "\nPDF salvo em: {$pdfPath} (" . strlen($pdf) . " bytes)\n";
    }
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
