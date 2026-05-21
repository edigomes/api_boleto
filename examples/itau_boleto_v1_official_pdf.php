<?php

/**
 * Exemplo: criar/validar boleto pela API Boletos v1 do Itau.
 *
 * Esta API e a unica especificacao local que declara retorno `base64`
 * ("Boleto em Base64 (PDF ou HTML)"). Use em validacao para testar sem
 * efetivar a cobranca:
 *
 *   php examples/itau_boleto_v1_official_pdf.php
 *
 * Variaveis esperadas:
 *   ITAU_CLIENT_ID
 *   ITAU_CLIENT_SECRET ou ITAU_CLIENT_SECRET_FILE
 *   ITAU_ID_BENEFICIARIO
 *   ITAU_CERT_FILE
 *   ITAU_CERT_KEY_FILE
 *   ITAU_CERT_KEY_PASSWORD
 *   ITAU_OUTPUT_PDF
 */

require __DIR__ . '/../vendor/autoload.php';

use ApiBoleto\Banks\Itau\ItauAuthenticator;
use ApiBoleto\Exceptions\ApiException;
use ApiBoleto\Exceptions\AuthenticationException;
use ApiBoleto\Http\CurlHttpClient;
use ApiBoleto\Storage\FileTokenStorage;

if (!function_exists('itau_v1_env')) {
    function itau_v1_env(string $key, string $default = ''): string
    {
        $value = getenv($key);

        return $value === false ? $default : $value;
    }
}

if (!function_exists('itau_v1_uuid')) {
    function itau_v1_uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}

$clientSecret = itau_v1_env('ITAU_CLIENT_SECRET');
$clientSecretFile = itau_v1_env('ITAU_CLIENT_SECRET_FILE');
if ($clientSecret === '' && $clientSecretFile !== '' && file_exists($clientSecretFile)) {
    $clientSecret = trim((string) file_get_contents($clientSecretFile));
}

$clientId = itau_v1_env('ITAU_CLIENT_ID');
$idBeneficiario = itau_v1_env('ITAU_ID_BENEFICIARIO');
$certFile = itau_v1_env('ITAU_CERT_FILE');
$certKeyFile = itau_v1_env('ITAU_CERT_KEY_FILE');

foreach ([
    'ITAU_CLIENT_ID' => $clientId,
    'ITAU_CLIENT_SECRET/ITAU_CLIENT_SECRET_FILE' => $clientSecret,
    'ITAU_ID_BENEFICIARIO' => $idBeneficiario,
    'ITAU_CERT_FILE' => $certFile,
    'ITAU_CERT_KEY_FILE' => $certKeyFile,
] as $name => $value) {
    if ($value === '') {
        echo "ERRO: informe {$name}.\n";
        exit(1);
    }
}

$cert = [
    'certFile' => $certFile,
    'certKeyFile' => $certKeyFile,
    'certKeyPassword' => itau_v1_env('ITAU_CERT_KEY_PASSWORD'),
];

$tokenPath = itau_v1_env('ITAU_TOKEN_PATH', sys_get_temp_dir());
$http = new CurlHttpClient(30, 60, false);
$auth = new ItauAuthenticator(
    $http,
    new FileTokenStorage($tokenPath),
    'itau_token_producao_' . $idBeneficiario . '_boleto_v1',
    itau_v1_env('ITAU_TOKEN_URL', 'https://sts.itau.com.br/api/oauth/token'),
    $clientId,
    $clientSecret,
    $cert
);

$timezone = new DateTimeZone(itau_v1_env('ITAU_TIMEZONE', 'America/Sao_Paulo'));
$today = new DateTimeImmutable('now', $timezone);
$nossoNumero = itau_v1_env('ITAU_NOSSO_NUMERO', str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT));
$vencimento = itau_v1_env('ITAU_DATA_VENCIMENTO', $today->modify('+30 days')->format('Y-m-d'));
$pagadorDocumento = preg_replace('/\D/', '', itau_v1_env('ITAU_PAGADOR_DOCUMENTO', '12345678909')) ?: '12345678909';

$payload = [
    'beneficiario' => [
        'idBeneficiario' => $idBeneficiario,
    ],
    'tipoBoleto' => itau_v1_env('ITAU_TIPO_BOLETO', 'a vista'),
    'etapaProcessoBoleto' => itau_v1_env('ITAU_ETAPA_PROCESSO', 'validacao'),
    'codigoCanalOperacao' => 'API',
    'instrumentoCobranca' => itau_v1_env('ITAU_INSTRUMENTO_COBRANCA', 'boleto'),
    'formaEnvio' => itau_v1_env('ITAU_FORMA_ENVIO', 'impressao'),
    'pagador' => [
        'nomePagador' => itau_v1_env('ITAU_PAGADOR_NOME', 'Cliente Teste'),
        'tipoPessoa' => strlen($pagadorDocumento) === 14 ? 'J' : 'F',
        'numeroDocumento' => $pagadorDocumento,
        'endereco' => [
            'logradouro' => itau_v1_env('ITAU_PAGADOR_ENDERECO', 'Rua Teste'),
            'numero' => itau_v1_env('ITAU_PAGADOR_NUMERO', '100'),
            'bairro' => itau_v1_env('ITAU_PAGADOR_BAIRRO', 'Centro'),
            'cidade' => itau_v1_env('ITAU_PAGADOR_CIDADE', 'Sao Paulo'),
            'uf' => strtoupper(itau_v1_env('ITAU_PAGADOR_ESTADO', 'SP')),
            'cep' => preg_replace('/\D/', '', itau_v1_env('ITAU_PAGADOR_CEP', '01310100')),
        ],
    ],
    'mensagensBoleto' => [
        ['mensagem' => itau_v1_env('ITAU_MENSAGEM', 'Boleto de teste via API')],
    ],
    'especie' => [
        'codigoEspecie' => itau_v1_env('ITAU_CODIGO_ESPECIE', '01'),
    ],
    'isDescontoExpresso' => false,
    'seuNumero' => itau_v1_env('ITAU_SEU_NUMERO', 'TESTE' . substr($nossoNumero, -5)),
    'dataEmissao' => itau_v1_env('ITAU_DATA_EMISSAO', $today->format('Y-m-d')),
    'dataVencimento' => $vencimento,
    'dataLimitePagamento' => itau_v1_env('ITAU_DATA_LIMITE_PAGAMENTO', $vencimento),
    'valor' => (float) str_replace(',', '.', itau_v1_env('ITAU_VALOR_BOLETO', '10.00')),
    'codigoCarteira' => itau_v1_env('ITAU_CODIGO_CARTEIRA', '109'),
    'nossoNumero' => $nossoNumero,
];

$chavePix = itau_v1_env('ITAU_CHAVE_PIX');
if ($chavePix !== '') {
    $payload['instrumentoCobranca'] = 'bolecode';
    $payload['dadosQrcode'] = [
        'chave' => $chavePix,
        'tipoCobranca' => itau_v1_env('ITAU_PIX_TIPO_COBRANCA', 'cob'),
    ];
}

try {
    $token = $auth->authenticate();
    $response = $http->request('POST', rtrim(itau_v1_env('ITAU_BOLETO_V1_BASE_URL', 'https://boleto.api.itau.com/boleto/v1'), '/') . '/boletos', [
        'headers' => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
            'x-itau-apikey: ' . $clientId,
            'x-itau-correlationID: ' . itau_v1_uuid(),
            'x-itau-flowID: ' . itau_v1_uuid(),
        ],
        'cert' => $cert,
        'body' => $payload,
    ]);

    $body = $response['body'] ?? [];
    $data = $body['data'] ?? $body;
    $base64 = is_array($data) ? (string) ($data['base64'] ?? '') : '';

    echo "=== Itau - API Boletos v1 ===\n";
    echo 'HTTP: ' . ($response['statusCode'] ?? '') . "\n";
    echo "Etapa: {$payload['etapaProcessoBoleto']}\n";
    echo "Nosso Numero: {$nossoNumero}\n";
    echo 'ID Boleto: ' . (is_array($data) ? (string) ($data['idBoleto'] ?? '') : '') . "\n";
    echo 'Codigo Barras: ' . (is_array($data) ? (string) ($data['codigoBarras'] ?? '') : '') . "\n";
    echo 'Linha Digitavel: ' . (is_array($data) ? (string) ($data['linhaDigitavel'] ?? '') : '') . "\n";
    echo 'Base64 oficial: ' . ($base64 !== '' ? 'sim' : 'nao') . "\n";

    if ($base64 !== '') {
        $decoded = base64_decode($base64, true);
        if ($decoded === false) {
            echo "Base64 retornado nao pode ser decodificado.\n";
            exit(1);
        }

        $defaultPath = strncmp($decoded, '%PDF', 4) === 0
            ? __DIR__ . '/../utils/Itau/certs/boleto_itau_v1_official.pdf'
            : __DIR__ . '/../utils/Itau/certs/boleto_itau_v1_official.html';
        $outputPath = itau_v1_env('ITAU_OUTPUT_PDF', $defaultPath);

        file_put_contents($outputPath, $decoded);
        echo 'Arquivo salvo em: ' . $outputPath . ' (' . strlen($decoded) . " bytes)\n";
    }
} catch (AuthenticationException $e) {
    echo "ERRO DE AUTENTICACAO: {$e->getMessage()}\n";
} catch (ApiException $e) {
    echo "ERRO DA API [{$e->getStatusCode()}]: {$e->getMessage()}\n";
    echo 'Detalhes: ' . json_encode($e->getResponseBody(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) {
    echo "ERRO INESPERADO: {$e->getMessage()}\n";
}
