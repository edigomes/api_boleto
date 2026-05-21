<?php

/**
 * Exemplo: cadastrar/consultar webhook de boletos no Itau.
 *
 * A API do Itau exige que o endpoint do ERP tenha OAuth proprio. O Itau chama
 * ITAU_WEBHOOK_OAUTH_URL para obter token usando client_id/client_secret e, em
 * seguida, envia a notificacao para ITAU_WEBHOOK_URL.
 *
 * Variaveis principais:
 *   ITAU_WEBHOOK_ACTION=setup|consultar|atualizar|excluir
 *   ITAU_WEBHOOK_URL=https://erp.exemplo.com/webhooks/itau/boletos
 *   ITAU_WEBHOOK_CLIENT_ID=...
 *   ITAU_WEBHOOK_CLIENT_SECRET=...
 *   ITAU_WEBHOOK_OAUTH_URL=https://erp.exemplo.com/oauth/token
 *   ITAU_WEBHOOK_OAUTH_SCOPE=boletos-notificacoes
 *   ITAU_WEBHOOK_TIPOS=BAIXA_EFETIVA,BAIXA_OPERACIONAL
 *   ITAU_WEBHOOK_ID=... (para consultar/atualizar/excluir um cadastro)
 */

require __DIR__ . '/../vendor/autoload.php';

use ApiBoleto\BoletoManager;
use ApiBoleto\Exceptions\ApiException;
use ApiBoleto\Exceptions\BoletoException;

$config = require __DIR__ . '/itau_config.php';

try {
    $gateway = (new BoletoManager())->banco('itau', $config);
    $action = strtolower(itau_example_env('ITAU_WEBHOOK_ACTION', 'setup'));
    $id = itau_example_env('ITAU_WEBHOOK_ID');

    $tipos = array_values(array_filter(array_map(
        'trim',
        explode(',', itau_example_env('ITAU_WEBHOOK_TIPOS', 'BAIXA_EFETIVA,BAIXA_OPERACIONAL'))
    )));

    $payload = [
        'webhookUrl' => itau_example_env('ITAU_WEBHOOK_URL'),
        'webhookClientId' => itau_example_env('ITAU_WEBHOOK_CLIENT_ID'),
        'webhookClientSecret' => itau_example_env('ITAU_WEBHOOK_CLIENT_SECRET'),
        'webhookOauthUrl' => itau_example_env('ITAU_WEBHOOK_OAUTH_URL'),
        'webhookOauthScope' => itau_example_env('ITAU_WEBHOOK_OAUTH_SCOPE'),
        'valorMinimo' => (float) itau_example_env('ITAU_WEBHOOK_VALOR_MINIMO', '0.01'),
        'tiposNotificacoes' => $tipos,
    ];

    switch ($action) {
        case 'consultar':
            $result = $gateway->consultarSetup($id !== '' ? $id : null);
            break;
        case 'atualizar':
            if ($id === '') {
                throw new BoletoException('Informe ITAU_WEBHOOK_ID para atualizar.');
            }
            $result = $gateway->atualizarSetup($id, ['data' => array_filter($payload)]);
            break;
        case 'excluir':
            if ($id === '') {
                throw new BoletoException('Informe ITAU_WEBHOOK_ID para excluir.');
            }
            $result = ['excluido' => $gateway->excluirWebhook($id)];
            break;
        case 'setup':
        default:
            $result = $gateway->setup($payload);
            break;
    }

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (ApiException $e) {
    echo "ERRO DA API [{$e->getStatusCode()}]: {$e->getMessage()}\n";
    echo json_encode($e->getResponseBody(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $e) {
    echo 'ERRO: ' . $e->getMessage() . PHP_EOL;
}
