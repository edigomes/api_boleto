<?php

/**
 * Configuracao compartilhada para os exemplos do Itau.
 *
 * Preferencialmente preencha por variaveis de ambiente:
 *
 *   ITAU_CLIENT_ID
 *   ITAU_CLIENT_SECRET
 *   ITAU_ID_BENEFICIARIO
 *   ITAU_CERT_FILE
 *   ITAU_CERT_KEY_FILE
 *   ITAU_CERT_KEY_PASSWORD
 *   ITAU_TIMEZONE
 *
 * O exemplo de boleto comum usa ITAU_ETAPA_PROCESSO=validacao por padrao.
 * Para efetivar a emissao, use ITAU_ETAPA_PROCESSO=efetivacao.
 */

if (!function_exists('itau_example_env')) {
    function itau_example_env(string $key, string $default = ''): string
    {
        $value = getenv($key);

        return $value === false ? $default : $value;
    }
}

if (!function_exists('itau_example_bool_env')) {
    function itau_example_bool_env(string $key, bool $default = false): bool
    {
        $value = getenv($key);
        if ($value === false || $value === '') {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'sim', 'yes', 'on'], true);
    }
}

$config = [
    'clientId'        => itau_example_env('ITAU_CLIENT_ID'),
    'clientSecret'    => itau_example_env('ITAU_CLIENT_SECRET'),
    'idBeneficiario'  => itau_example_env('ITAU_ID_BENEFICIARIO'),
    'certFile'        => itau_example_env('ITAU_CERT_FILE'),
    'certKeyFile'     => itau_example_env('ITAU_CERT_KEY_FILE'),
    'certKeyPassword' => itau_example_env('ITAU_CERT_KEY_PASSWORD'),
    'tokenPath'       => itau_example_env('ITAU_TOKEN_PATH', __DIR__ . '/itau_token'),
    'ambiente'        => itau_example_env('ITAU_AMBIENTE', 'producao'),
    'codigoCarteira'  => itau_example_env('ITAU_CODIGO_CARTEIRA', '109'),
    'codigoEspecie'   => itau_example_env('ITAU_CODIGO_ESPECIE', '01'),
    'tipoBoleto'      => itau_example_env('ITAU_TIPO_BOLETO', 'a vista'),
    'etapaProcesso'   => itau_example_env('ITAU_ETAPA_PROCESSO', 'validacao'),
    'usarApiBoletosV1' => itau_example_bool_env('ITAU_USAR_API_BOLETOS_V1', false),
    'pixLegacyPayload' => itau_example_bool_env('ITAU_PIX_LEGACY_PAYLOAD', false),
];

$optionalUrls = [
    'tokenUrl'        => itau_example_env('ITAU_TOKEN_URL'),
    'apiBaseUrl'      => itau_example_env('ITAU_API_BASE_URL'),
    'consultaBaseUrl' => itau_example_env('ITAU_CONSULTA_BASE_URL'),
    'listaBaseUrl'    => itau_example_env('ITAU_LISTA_BASE_URL'),
    'boletoV1BaseUrl' => itau_example_env('ITAU_BOLETO_V1_BASE_URL'),
    'webhookBaseUrl'  => itau_example_env('ITAU_WEBHOOK_BASE_URL'),
    'boletosV3BaseUrl' => itau_example_env('ITAU_BOLETOS_V3_BASE_URL'),
    'pixBaseUrl'      => itau_example_env('ITAU_PIX_BASE_URL'),
    'pixEndpointPath' => itau_example_env('ITAU_PIX_ENDPOINT_PATH'),
];

foreach ($optionalUrls as $key => $value) {
    if ($value !== '') {
        $config[$key] = $value;
    }
}

return $config;
