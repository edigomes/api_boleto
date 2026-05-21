<?php

/**
 * Exemplo: consultar Francesa/movimentacoes na API Boletos v3 do Itau.
 *
 * Executar:
 *   php examples/itau_francesas.php
 *
 * Variaveis opcionais:
 *   ITAU_MES_REFERENCIA       mmyyyy, para listar francesas
 *   ITAU_ID_FRANCESA          agencia(4)+conta(7)+dac(1)
 *   ITAU_DATA_MOVIMENTACAO    yyyy-MM-dd, para movimentacoes/resumo
 */

require __DIR__ . '/../vendor/autoload.php';

use ApiBoleto\BoletoManager;
use ApiBoleto\Exceptions\ApiException;
use ApiBoleto\Exceptions\AuthenticationException;
use ApiBoleto\Exceptions\BoletoException;

$config = require __DIR__ . '/itau_config.php';

try {
    $manager = new BoletoManager();
    $gateway = $manager->banco('itau', $config);

    echo "=== Itau - Francesas v3 ===\n";
    $francesas = $gateway->consultarFrancesas([
        'mesReferencia' => itau_example_env('ITAU_MES_REFERENCIA', date('mY')),
    ]);
    echo json_encode($francesas, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

    $idFrancesa = itau_example_env('ITAU_ID_FRANCESA', $config['idBeneficiario']);
    $dataMovimentacao = itau_example_env('ITAU_DATA_MOVIMENTACAO');
    if ($dataMovimentacao !== '') {
        echo "\n=== Movimentacoes ===\n";
        $movimentacoes = $gateway->consultarMovimentacoesFrancesa($idFrancesa, [
            'data' => $dataMovimentacao,
            'nossoNumero' => itau_example_env('ITAU_NOSSO_NUMERO'),
            'seuNumero' => itau_example_env('ITAU_SEU_NUMERO'),
            'numeroCarteira' => itau_example_env('ITAU_CODIGO_CARTEIRA'),
        ]);
        echo json_encode($movimentacoes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

        echo "\n=== Movimentacoes resumidas ===\n";
        $resumo = $gateway->consultarMovimentacoesResumidasFrancesa($idFrancesa, [
            'data' => $dataMovimentacao,
        ]);
        echo json_encode($resumo, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
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
