# ApiBoleto - Biblioteca PHP para Boletos Bancários

Biblioteca PHP unificada para emissão, consulta, alteração e cancelamento de boletos bancários via APIs de múltiplos bancos brasileiros. Interface única independente do banco, com suporte a bolecode (código de barras / linha digitável) e geração de PDF.

## Requisitos

- PHP >= 7.4
- Extensões: `curl`, `json`, `openssl`

## Instalação

```bash
composer require api-boleto/api-boleto
```

Ou clone o repositório e instale as dependências:

```bash
git clone <repo-url>
cd api_boleto
composer install
```

## Bancos Suportados

| Banco      | Status       |
|------------|--------------|
| Santander  | Implementado |
| Itau       | Implementado |

## Uso Rápido

```php
<?php

require 'vendor/autoload.php';

use ApiBoleto\BoletoManager;
use ApiBoleto\DTO\Boleto;

$manager = new BoletoManager();

$gateway = $manager->banco('santander', [
    'clientId'        => 'seu-client-id',
    'clientSecret'    => 'seu-client-secret',
    'certFile'        => '/caminho/para/certificado.pem',
    'certKeyFile'     => '/caminho/para/chave-privada.pem',
    'certKeyPassword' => 'senha-da-chave',
    'tokenPath'       => '/caminho/para/armazenar/token',
    'workspaceId'     => 'id-do-workspace',
    'ambiente'        => 'sandbox', // ou 'producao'
]);

$boleto = Boleto::fromArray([
    'valor'          => '150.00',
    'vencimento'     => '2026-04-15',
    'emissao'        => '2026-03-12',
    'nossoNumero'    => '033',
    'seuNumero'      => 'REF-001',
    'codigoConvenio' => '1234567',
    'tipoDocumento'  => 'DUPLICATA_MERCANTIL',
    'pagador' => [
        'nome'           => 'João da Silva',
        'tipoDocumento'  => 'CPF',
        'documento'      => '12345678900',
        'endereco'       => 'Rua XV de Maio, 100',
        'bairro'         => 'Centro',
        'cidade'         => 'São Paulo',
        'estado'         => 'SP',
        'cep'            => '01000-000',
    ],
    'beneficiario' => [
        'nome'          => 'Empresa LTDA',
        'tipoDocumento' => 'CNPJ',
        'documento'     => '12345678000100',
    ],
]);

$response = $gateway->criarBoleto($boleto);

echo $response->linhaDigitavel;  // Linha digitável (bolecode)
echo $response->codigoBarras;    // Código de barras
echo $response->id;              // ID do boleto no banco

// PIX QR Code (disponível quando a chave PIX é informada na criação)
if ($response->qrCodePix) {
    echo $response->qrCodePix; // Payload EMV (copia e cola)
    echo $response->qrCodeUrl; // URL da imagem do QR Code
}
```

## Operações Disponíveis

### Criar Boleto

```php
$response = $gateway->criarBoleto($boleto);

echo $response->id;              // ID do boleto no banco
echo $response->nossoNumero;     // Nosso número
echo $response->codigoBarras;    // Código de barras
echo $response->linhaDigitavel;  // Linha digitável (bolecode)
echo $response->status;          // Status (ex: OPEN)
echo $response->valor;           // Valor nominal
echo $response->vencimento;      // Data de vencimento
echo $response->urlPdf;          // URL do PDF (se retornada na criação)

// PIX QR Code — preenchido quando a chave PIX é enviada na criação
if ($response->qrCodePix) {
    echo $response->qrCodePix; // Payload EMV "copia e cola"
    echo $response->qrCodeUrl; // URL da imagem do QR Code
}
```

Para incluir PIX QR Code no boleto, passe a chave PIX em `dadosExtras` ao criar:

```php
$boleto = Boleto::fromArray([
    // ... campos padrão ...
    'dadosExtras' => [
        'key' => [
            'type'    => 'CNPJ',        // CPF, CNPJ, EMAIL, EVP (chave aleatória), PHONE
            'dictKey' => '12345678000100',
        ],
    ],
]);
```

### Consultar Boleto

```php
$response = $gateway->consultarBoleto($identificador);
```

### Listar Boletos

```php
$boletos = $gateway->consultarBoletos(['beneficiaryCode' => '1234567', 'bankNumber' => '033']);
```

### Alterar Boleto (Instruções)

```php
use ApiBoleto\DTO\InstrucaoBoleto;

// Atalho: alterar vencimento
$instrucao = InstrucaoBoleto::alterarVencimento('2026-06-15');
$response = $gateway->alterarBoleto($identificador, $instrucao);

// Atalho: alterar valor
$instrucao = InstrucaoBoleto::alterarValor('250.00');
$response = $gateway->alterarBoleto($identificador, $instrucao);

// Instrução completa via fromArray
$instrucao = InstrucaoBoleto::fromArray([
    'vencimento'      => '2026-07-01',
    'valor'           => '300.00',
    'percentualMulta' => '2.00',
    'dataMulta'       => '2026-07-02',
    'diasProtesto'    => 30,
    'diasBaixa'       => 90,
    'desconto'        => [
        'tipo'      => 'ISENTO',
        'desconto1' => ['valor' => '10.00', 'dataLimite' => '2026-06-25'],
    ],
]);
$response = $gateway->alterarBoleto($identificador, $instrucao);
```

### Cancelar Boleto (Baixa)

```php
// Simples
$cancelado = $gateway->cancelarBoleto($identificador);

// Com dados extras
$instrucao = InstrucaoBoleto::fromArray([
    'dadosExtras' => ['participantCode' => '123456'],
]);
$cancelado = $gateway->cancelarBoleto($identificador, $instrucao);
```

### Gerar PDF

```php
// Obter URL do PDF
$pdfUrl = $gateway->gerarPdf($identificadorPdf, '12345678900');

// Ou baixar o binário direto
$pdfBinario = $gateway->downloadPdf($identificadorPdf, '12345678900');
file_put_contents('boleto.pdf', $pdfBinario);
```

## Campos do BoletoResponse

Todos os métodos que retornam um `BoletoResponse` preenchem os seguintes campos:

| Campo            | Tipo   | Descrição                                                           |
|------------------|--------|---------------------------------------------------------------------|
| `id`             | string | Identificador do boleto retornado pelo banco                        |
| `nossoNumero`    | string | Nosso número atribuído pelo banco                                   |
| `codigoBarras`   | string | Código de barras (bolecode)                                         |
| `linhaDigitavel` | string | Linha digitável (bolecode)                                          |
| `status`         | string | Status do boleto no banco (ex: `OPEN`, `LIQUIDADO`)                 |
| `valor`          | string | Valor nominal do boleto                                             |
| `vencimento`     | string | Data de vencimento (YYYY-MM-DD)                                     |
| `urlPdf`         | string | URL do PDF, quando retornada pelo banco na criação/consulta         |
| `pdfBase64`      | string | PDF/HTML em Base64, quando retornado pelo banco                     |
| `qrCodePix`      | string | Payload EMV do QR Code PIX ("copia e cola") — quando disponível     |
| `qrCodeUrl`      | string | URL da imagem do QR Code PIX — quando disponível                    |
| `dadosOriginais` | array  | Resposta bruta da API do banco (útil para campos não mapeados)      |

> `qrCodePix` e `qrCodeUrl` são preenchidos automaticamente quando a API do banco retorna dados PIX. No Santander, isso ocorre quando a `key` é enviada em `dadosExtras` na criação do boleto.

## Campos do InstrucaoBoleto

O `InstrucaoBoleto` é o DTO genérico usado para alterar e cancelar boletos em qualquer banco. Preencha apenas os campos que deseja alterar — campos vazios/null são ignorados.

| Campo DTO             | Tipo     | Descrição                                 |
|-----------------------|----------|-------------------------------------------|
| `vencimento`          | string   | Novo vencimento (YYYY-MM-DD)              |
| `valor`               | string   | Novo valor nominal (ex: "100.00")         |
| `seuNumero`           | string   | Nova referência do cliente                |
| `valorDeducao`        | string   | Valor de abatimento/dedução               |
| `percentualMulta`     | string   | Percentual de multa                       |
| `dataMulta`           | string   | Data de início da multa (YYYY-MM-DD)      |
| `diasProtesto`        | int      | Dias para protesto (0 = não alterar)      |
| `diasBaixa`           | int      | Dias para baixa automática (0 = não alt.) |
| `tipoValorPagamento`  | string   | Tipo aceite (VALOR, PERCENTUAL)           |
| `valorMinimo`         | string   | Valor/percentual mínimo aceito            |
| `valorMaximo`         | string   | Valor/percentual máximo aceito            |
| `codigoParticipante`  | string   | Código do participante                    |
| `desconto`            | Desconto | Novos dados de desconto (até 3 faixas)    |
| `operacao`            | string   | Operação específica do banco (opcional)   |
| `dadosExtras`         | array    | Campos específicos de cada banco          |

**Atalhos disponíveis:**

```php
InstrucaoBoleto::alterarVencimento('2026-06-15'); // infere operação automaticamente
InstrucaoBoleto::alterarValor('500.00');           // infere operação automaticamente
InstrucaoBoleto::baixar();                         // operação de cancelamento
```

Se `operacao` não for informada, a lib infere automaticamente com base nos campos preenchidos.

## Identificadores por Banco

Cada banco usa um formato diferente de identificador dependendo da operação. A tabela abaixo documenta o formato esperado em cada método.

### Santander

| Método              | Formato do Identificador                         | Exemplo                                                   |
|---------------------|--------------------------------------------------|-----------------------------------------------------------|
| `consultarBoleto`   | ID retornado na criação (ou composite ID)        | `"033.2026-03-14.P.794760.33"`                            |
| `consultarBoletos`  | Filtros via array (não usa identificador)         | `['beneficiaryCode' => '794760', 'bankNumber' => '033']`  |
| `alterarBoleto`     | `"covenantCode,bankNumber"`                      | `"794760,35"`                                             |
| `cancelarBoleto`    | `"covenantCode,bankNumber"`                      | `"794760,35"`                                             |
| `gerarPdf`          | `"{bankNumber}.{covenantCode}"` ou linha digitável | `"033.0794760"`                                          |
| `downloadPdf`       | Mesmo formato de `gerarPdf`                      | `"033.0794760"`                                           |

**Observações importantes (Santander):**

- **`alterarBoleto` / `cancelarBoleto`**: O identificador é `"covenantCode,bankNumber"` separados por vírgula. O `covenantCode` é o código do convênio e o `bankNumber` é o nosso número.
- **`gerarPdf` / `downloadPdf`**: Use os valores **originais com zeros à esquerda** (ex: `033`, `0794760`), não os valores truncados que a API pode retornar.
- **`consultarBoleto`**: Aceita o ID composto retornado na criação no formato `{nsuCode}.{nsuDate}.{envLetter}.{covenantCode}.{bankNumber}`.
- **Segundo parâmetro do PDF**: `payerDocumentNumber` (CPF/CNPJ do pagador) é obrigatório na API do Santander.

### PDF local no Santander

O Santander normalmente retorna uma URL oficial no endpoint de PDF. Se essa URL vier vazia, `downloadPdf()` tenta consultar os dados do boleto e renderizar localmente usando o layout Santander:

```php
$pdfBinario = $gateway->downloadPdf('033.0794760', '12345678900');
file_put_contents('boleto_santander.pdf', $pdfBinario);
```

Para esse fallback local, use `bankNumber.covenantCode` ou `covenantCode,bankNumber`, porque a lib precisa consultar o boleto detalhado antes de montar o PDF.

Se voce acabou de criar/consultar o boleto e ja tem o `BoletoResponse`, tambem pode renderizar direto:

```php
use ApiBoleto\Pdf\BoletoPdfRenderer;

$pdfBinario = (new BoletoPdfRenderer())->render($response, [
    'bankName' => 'Banco Santander S.A.',
    'bankCode' => '033',
]);
```

O renderer Santander inclui o QR Code PIX quando a API retorna imagem em base64 ou quando `qrCodePix`/`emvqrcps` traz o payload EMV ("copia e cola"). O logo padrao fica em `resources/logos/banks/santander.png`; para sobrescrever, informe `logoPath`.

## Campos Extras (Específicos do Banco)

Cada banco pode ter campos específicos. Use `dadosExtras` no DTO `Boleto` para campos de criação, e `dadosExtras` no `InstrucaoBoleto` para campos de alteração/cancelamento:

```php
// Na criação
$boleto = Boleto::fromArray([
    'valor'      => '100.00',
    'vencimento' => '2026-04-15',
    // ... campos padrão ...
    'dadosExtras' => [
        // PIX QR Code: informe a chave PIX do beneficiário
        // Tipos aceitos: CPF, CNPJ, EMAIL, EVP (chave aleatória), PHONE
        'key' => [
            'type'    => 'CNPJ',
            'dictKey' => '12345678000100',
        ],
    ],
]);

// Na alteração
$instrucao = InstrucaoBoleto::fromArray([
    'vencimento'  => '2026-08-01',
    'dadosExtras' => [
        'participantCode' => '123456',
    ],
]);
```

## Santander - Configuração

### Ambientes

| Ambiente     | Aliases aceitos                                      |
|--------------|------------------------------------------------------|
| Produção     | `producao`, `production`, `prod`                     |
| Sandbox      | `sandbox`, `homologacao`, `homologation`, `staging`, `dev` |

### Configuração completa

```php
$config = [
    'clientId'        => 'seu-client-id',        // obrigatório
    'clientSecret'    => 'seu-client-secret',     // obrigatório
    'certFile'        => '/caminho/cert.pem',     // obrigatório* (mTLS via path)
    'certKeyFile'     => '/caminho/key.pem',      // obrigatório* (mTLS via path)
    'certKeyPassword' => '',                      // opcional
    'ambiente'        => 'sandbox',               // default: 'producao'
    'workspaceId'     => 'ws-id',                 // obrigatório para operações de boleto
    'tokenPath'       => '/caminho/tokens/',      // ou use 'tokenStorage' abaixo
    'tokenStorage'    => $minhaInstancia,          // implementação de TokenStorageInterface
    'tokenKey'        => 'chave_customizada',     // opcional (default: santander_token_{ambiente})
];
```

### Certificados mTLS - Duas Formas

A lib aceita certificados mTLS de **duas formas**. Informe uma delas:

**Opção 1: Caminhos de arquivo** (para quando os `.pem` estão no disco)

```php
$config = [
    // ...
    'certFile'        => '/caminho/para/certificado.pem',
    'certKeyFile'     => '/caminho/para/chave-privada.pem',
    'certKeyPassword' => 'senha-opcional',
];
```

**Opção 2: Conteúdo em string** (para quando os certificados estão no S3, banco de dados, variáveis de ambiente, etc.)

```php
$config = [
    // ...
    'certContent'     => $certPemString,   // conteúdo PEM do certificado
    'certKeyContent'  => $keyPemString,    // conteúdo PEM da chave privada
    'certKeyPassword' => 'senha-opcional',
];
```

Quando você usa `certContent` / `certKeyContent`, a lib cria automaticamente arquivos temporários seguros (permissão `0600`) que são limpos quando o gateway é destruído. Ideal para **Laravel multi-tenant** onde cada tenant tem seu certificado em banco/S3:

```php
// Exemplo: Laravel multi-tenant com certificados do S3
$tenant = Tenant::current();

$gateway = $manager->banco('santander', [
    'clientId'       => $tenant->santander_client_id,
    'clientSecret'   => $tenant->santander_client_secret,
    'certContent'    => Storage::disk('s3')->get($tenant->cert_path),
    'certKeyContent' => Storage::disk('s3')->get($tenant->key_path),
    'tokenStorage'   => app(TokenStorageInterface::class),
    'tokenKey'       => "santander_{$tenant->id}_sandbox",
    'workspaceId'    => $tenant->santander_workspace_id,
    'ambiente'       => 'sandbox',
]);
```

### Workspace Management

O Santander exige a criação de um workspace antes de operar boletos:

```php
/** @var \ApiBoleto\Banks\Santander\SantanderGateway $gateway */

// Setup rápido (cria workspace + configura webhook)
$result = $gateway->setup([
    'covenantCode'   => '1234567',
    'webhookUrl'     => 'https://seu-dominio.com/webhook/',
    'boleto_webhook' => true,
    'pix_webhook'    => false,
]);

// Ou gerenciar manualmente
$workspace = $gateway->criarWorkspace([
    'type'        => 'BILLING',
    'description' => 'Workspace de Cobrança',
    'covenants'   => [['code' => '1234567']],
]);

$gateway->setWorkspaceId($workspace['id']);

// Listar / consultar
$workspaces = $gateway->listarWorkspaces();
$workspace = $gateway->consultarWorkspace('workspace-id');
```

## Itau - Certificados e Producao

Esta secao e o checklist pratico para o agente que vai implementar o Itau no ERP.

### O que a lib precisa

Para criar, consultar ou alterar boletos no Itau, configure um gateway por cliente/tenant com:

- `clientId`: credencial da aplicacao no Itau.
- `clientSecret`: segredo da aplicacao no Itau.
- `idBeneficiario`: identificador do beneficiario/conta usado nas APIs de cobranca.
- `certFile` + `certKeyFile`: certificado cliente e chave privada em arquivos, ou `certContent` + `certKeyContent` quando vierem de S3, banco, vault etc.
- `certKeyPassword`: senha da chave privada, quando houver.
- `tokenStorage` ou `tokenPath`: cache do token OAuth.
- `tokenKey`: chave unica por cliente/tenant. Em ERP multi-cliente, nao reutilize a mesma chave para clientes diferentes.

Exemplo de configuracao por tenant:

```php
use ApiBoleto\BoletoManager;
use ApiBoleto\Contracts\TokenStorageInterface;

$manager = new BoletoManager();

$gateway = $manager->banco('itau', [
    'clientId'        => $tenant->itau_client_id,
    'clientSecret'    => $tenant->itau_client_secret,
    'idBeneficiario'  => $tenant->itau_id_beneficiario,
    'certFile'        => $tenant->itau_cert_path,
    'certKeyFile'     => $tenant->itau_key_path,
    'certKeyPassword' => $tenant->itau_key_password ?: '',
    'tokenStorage'    => app(TokenStorageInterface::class),
    'tokenKey'        => "itau_{$tenant->id}_{$tenant->itau_id_beneficiario}_producao",
    'ambiente'        => 'producao',
    'codigoCarteira'  => $tenant->itau_codigo_carteira ?: '109',
    'codigoEspecie'   => $tenant->itau_codigo_especie ?: '01',
    'tipoBoleto'      => 'a vista',

    // Use validacao/simulacao nos primeiros testes. Troque para efetivacao
    // somente quando o cliente estiver pronto para emitir em producao.
    'etapaProcesso'   => 'validacao',
]);
```

Para Bolecode Pix, tambem informe `pixBaseUrl` com a base da API de Pix contratada/liberada pelo Itau:

```php
'pixBaseUrl' => 'https://.../recebimentos-pix/v1',
```

Se a especificacao recebida for a colecao `API - Bolecode.postman_collection`, use o formato dela:

```php
'pixBaseUrl'        => 'https://secure.api.itau/pix_recebimentos_conciliacoes/v2',
'pixEndpointPath'   => '/boletos_pix',
'pixLegacyPayload'  => true,
'etapaProcesso'     => 'Simulacao',
```

O Bolecode fica intencionalmente separado do boleto comum. Enquanto o cliente nao tiver a API `Bolecode Pix / recebimentos-pix/v1` liberada, use apenas o fluxo normal de boleto. Quando o Itau liberar, o ERP nao precisa mudar a emissao comum: basta enviar `dadosExtras['bolecodePix'] = true`, `dadosExtras['chavePix']` e configurar `pixBaseUrl`.

### PDF no Itau

Pelas APIs liberadas neste cliente, nao ha endpoint de PDF/impressao pronto:

- `API Emissao, Instrucao e Consulta de boletos` (`cash_management/v2`) emite, consulta e instrui boletos, mas retorna dados de impressao (`numero_linha_digitavel`, `codigo_barras`, beneficiario, pagador, vencimento e valor).
- `API Boletos (v3) - Francesa e Webhook` consulta francesas/movimentacoes e cadastra webhooks; ela nao gera PDF de boleto.
- A especificacao `API Boletos v1` tem campo `base64` descrito como PDF/HTML, mas esta rota precisa estar explicitamente liberada no contrato/credencial. Para este cliente, o POST nessa rota retornou `403 explicit deny`, entao nao e a rota contratada agora.

Por isso a lib usa o retorno oficial se o banco trouxer `urlPdf` ou `pdfBase64`; caso contrario, gera PDF localmente seguindo o layout Febraban/Itau da ficha de compensacao:

```php
$pdfBinario = $gateway->downloadPdf('123400123451,109,43977574');
file_put_contents('boleto_itau.pdf', $pdfBinario);
```

Se voce acabou de criar o boleto e ja tem o `BoletoResponse`, tambem pode renderizar direto, sem consultar de novo:

```php
use ApiBoleto\Pdf\BoletoPdfRenderer;

$response = $gateway->criarBoleto($boleto);

$pdfBinario = (new BoletoPdfRenderer())->render($response, [
    'bankName' => 'Banco Itau S.A.',
    'bankCode' => '341',
]);

file_put_contents('boleto_itau.pdf', $pdfBinario);
```

Logos do renderer interno ficam em `resources/logos/banks/`. O Itau usa `resources/logos/banks/itau.png` automaticamente quando `bankCode` for `341`. Para outro banco, adicione o PNG nessa pasta seguindo o mapa interno do renderer ou informe manualmente:

```php
$pdfBinario = (new BoletoPdfRenderer())->render($response, [
    'bankCode' => '341',
    'logoPath' => __DIR__ . '/../resources/logos/banks/itau.png',
]);
```

Se quiser desabilitar logo e renderizar apenas texto no cabecalho, use `'logoPath' => false`.

No exemplo `examples/itau_sample.php`, defina `ITAU_OUTPUT_PDF` para salvar o PDF junto com o teste.

Sobre QR Code: boleto comum nao tem QR Code. QR aparece apenas em Bolecode Pix, quando a API Pix/Bolecode retornar dados de QR (`dados_qrcode.base64`, `emv`, `location`). O renderizador local inclui a imagem quando o Itau devolve `dados_qrcode.base64`.

### Mapa das APIs Itau

Os arquivos de especificacao do Itau parecem sobrepor nomes, mas na pratica ficam assim:

| Spec/local | Base URL | Uso na lib |
|------------|----------|------------|
| `API Boletos - Emissao e Instrucao` / `cash_management/v2` | `https://api.itau.com.br/cash_management/v2` | Emissao de boleto comum e instrucoes: baixa, vencimento, valor, juros, multa, desconto etc. |
| `boletoscash/v2` do email de boas-vindas | `https://secure.api.cloud.itau.com.br/boletoscash/v2` | Consulta liberada para este cliente. A rota `GET /boletos` em `cash_management/v2` pode existir na spec, mas pode retornar 403 se nao estiver liberada na credencial. |
| `Bolecode Pix` / `recebimentos-pix/v1` ou `pix_recebimentos_conciliacoes/v2/boletos_pix` | URL Pix informada pelo Itau | Emissao de boleto + Pix, quando o produto Pix estiver contratado/liberado. Na colecao `API - Bolecode`, configure `pixEndpointPath=/boletos_pix` e `pixLegacyPayload=true`. |
| `API Boletos (v3)` / Francesa e Webhook | `https://boletos.cloud.itau.com.br/boletos/v3` | Webhooks/notificacoes e consultas de francesa/movimentacoes. Nao emite boleto e nao gera PDF. |
| `API Boletos v1` | `https://boleto.../boleto/v1` | Pode retornar `base64` (PDF/HTML) em alguns contratos. Use somente se o Itau liberar explicitamente esta API para o cliente. |

O `idBeneficiario` normalmente vem da agencia/conta liberada pelo Itau:

```text
Agencia/conta: 1234/12345-1
idBeneficiario: 123400123451
Formato: agencia(4) + 00 + conta(5) + dac(1)
```

### Webhook Itau

A lib implementa o cadastro de webhook da `API Boletos (v3)` pelo mesmo contrato de setup usado pelos bancos configuraveis.

Antes de chamar o setup, o ERP precisa ter:

- Uma URL HTTPS publica para receber notificacoes, ex: `https://erp.exemplo.com/webhooks/itau/boletos`.
- Um endpoint OAuth proprio do ERP, ex: `https://erp.exemplo.com/oauth/token`.
- Um `client_id`, `client_secret` e, se usado, `scope` aceitos por esse OAuth do ERP.
- Persistencia do payload bruto recebido, para idempotencia e auditoria.

Fluxo do webhook Itau:

1. O ERP chama `$gateway->setup(...)` para cadastrar a URL no Itau.
2. Quando houver uma movimentacao, o Itau chama o `webhookOauthUrl` do ERP usando `webhookClientId` e `webhookClientSecret`.
3. O ERP devolve um token.
4. O Itau chama `webhookUrl` com a notificacao do boleto.
5. O ERP processa a notificacao e, se precisar de detalhe/conciliacao, consulta o boleto ou as movimentacoes/francesas.

Setup:

```php
$result = $gateway->setup([
    'webhookUrl' => 'https://erp.exemplo.com/webhooks/itau/boletos',
    'webhookClientId' => 'client-do-erp',
    'webhookClientSecret' => 'secret-do-erp',
    'webhookOauthUrl' => 'https://erp.exemplo.com/oauth/token',
    'webhookOauthScope' => 'boletos-notificacoes',
    'tiposNotificacoes' => ['BAIXA_EFETIVA', 'BAIXA_OPERACIONAL'],
    'valorMinimo' => 0.01,
]);
```

Tipos de notificacao comuns:

- `BAIXA_EFETIVA`
- `BAIXA_OPERACIONAL`

Operacoes auxiliares:

```php
$gateway->consultarSetup();        // lista webhooks do id_beneficiario
$gateway->consultarSetup($id);     // consulta um webhook
$gateway->atualizarSetup($id, ['data' => [...]]); // atualiza cadastro
$gateway->excluirWebhook($id);     // remove cadastro
```

O exemplo `examples/itau_webhook_setup.php` mostra o uso por variaveis de ambiente para o ERP.

Principais diferencas para o Santander:

| Item | Santander | Itau |
|------|-----------|------|
| O que o `setup()` cria | Workspace de cobranca com convenio e flags de webhook | Cadastro de notificacao em `/boletos/v3/notificacoes_boletos` |
| Campo principal | `covenantCode`, `webhookUrl`, `boleto_webhook`, `pix_webhook` | `idBeneficiario`, `webhookUrl`, `webhookClientId`, `webhookClientSecret`, `webhookOauthUrl` |
| OAuth para receber webhook | Nao e configurado no setup da lib | Obrigatorio: o ERP fornece endpoint OAuth para o Itau buscar token |
| Boleto e Pix | Flags separadas no workspace (`bankSlipBillingWebhookActive`, `pixBillingWebhookActive`) | Esta API v3 cobre notificacoes de boletos; Bolecode/Pix depende da API Pix liberada |
| Alterar cadastro | `atualizarSetup($workspaceId, ...)` | `atualizarSetup($idNotificacao, ['data' => ...])` |
| Remover cadastro | Remocao/gestao de workspace Santander | `excluirWebhook($idNotificacao)` |

Para o agente do ERP: reaproveite a interface `setup()`, mas trate o Itau como cadastro de notificacao e nao como criacao de workspace. Tambem implemente o OAuth inbound do ERP antes de cadastrar o webhook no Itau.

### Consulta, PDF depois, alteracao e baixa

Em `validacao`, o Itau devolve linha digitavel/codigo de barras, mas nao necessariamente grava o boleto para consulta posterior. Para consultar depois, emita em `efetivacao`.

Consulta por nosso numero usando o `idBeneficiario` e carteira da configuracao:

```php
$boleto = $gateway->consultarBoleto('37734327');
```

Consulta explicitando beneficiario, carteira, nosso numero e, opcionalmente, data de inclusao:

```php
$boleto = $gateway->consultarBoleto('123400123451,109,37734327,2026-05-20');
```

Listagem/consulta por filtros:

```php
$boletos = $gateway->consultarBoletos([
    'idBeneficiario' => '123400123451',
    'codigoCarteira' => '109',
    'nossoNumero' => '37734327',
    'dataInclusao' => '2026-05-20',
    'view' => 'specific',
]);
```

Gerar PDF depois da consulta:

```php
$pdf = $gateway->downloadPdf('123400123451,109,37734327,2026-05-20');
file_put_contents('boleto_itau.pdf', $pdf);
```

Para instrucoes, o Itau usa `id_boleto` no path. A lib aceita estes formatos:

- `12340012345110937734327` (`idBeneficiario + carteira + nossoNumero`)
- `123400123451,109,37734327`
- `37734327` (usa `idBeneficiario` e `codigoCarteira` da configuracao)

Alterar vencimento:

```php
use ApiBoleto\DTO\InstrucaoBoleto;

$response = $gateway->alterarBoleto(
    '123400123451,109,37734327',
    InstrucaoBoleto::alterarVencimento('2026-07-10')
);
```

Alterar valor nominal:

```php
$response = $gateway->alterarBoleto(
    '123400123451,109,37734327',
    InstrucaoBoleto::alterarValor('120.00')
);
```

Baixar/cancelar boleto:

```php
$ok = $gateway->cancelarBoleto('123400123451,109,37734327');
```

Observacao operacional: o Itau pode recusar baixa no mesmo dia da inclusao do titulo com a mensagem `Atualizacao nao permitida na mesma data de inclusao do titulo`. Nesse caso, tente novamente no dia util seguinte.

### Francesa e movimentacoes v3

Essas consultas usam a API `Boletos (v3) - Francesa e Webhook` liberada no email. Elas servem para posicao/conciliacao, nao para gerar boleto ou PDF.

```php
$francesas = $gateway->consultarFrancesas([
    'mesReferencia' => '052026', // mmyyyy
]);

$movimentacoes = $gateway->consultarMovimentacoesFrancesa('123400123451', [
    'data' => '2026-05-21',
    'nossoNumero' => '37734327',
    'tipoCobranca' => 'boleto',
]);

$resumo = $gateway->consultarMovimentacoesResumidasFrancesa('123400123451', [
    'data' => '2026-05-21',
]);
```

### Qual certificado usar em producao

Existem dois cenarios.

**1. O cliente ja tem um `.pfx` aceito pelo Itau**

Use o `.pfx` do cliente. Nesse caso nao precisa gerar CSR nem esperar o Itau emitir outro certificado. Extraia uma vez:

- certificado publico/client certificate: `cliente_itau.crt`
- chave privada: `cliente_itau.key`
- senha da chave, se a chave ficar protegida

Depois configure a lib com `certFile`, `certKeyFile` e `certKeyPassword`.

Importante: um `.pfx` vindo direto da Certisign/e-CNPJ so resolve se ele ja estiver cadastrado, vinculado ou aceito pelo Itau para a aplicacao/cliente usado no `clientId`. Se o Itau ainda nao reconhece aquele certificado para a API, apenas ter o `.pfx` nao basta.

Comandos para extrair no Windows:

```powershell
$openssl = "C:\Program Files\Git\mingw64\bin\openssl.exe"
$pfx = "C:\certs\cliente.pfx"
$cert = "C:\certs\cliente_itau.crt"
$key = "C:\certs\cliente_itau.key"
$env:PFX_PASS = (Get-Content -Raw "C:\certs\senha_pfx.txt").Trim()

& $openssl pkcs12 -legacy -in $pfx -clcerts -nokeys -out $cert -passin env:PFX_PASS
& $openssl pkcs12 -legacy -in $pfx -nocerts -out $key -passin env:PFX_PASS -passout env:PFX_PASS

Remove-Item Env:\PFX_PASS -ErrorAction SilentlyContinue
```

O `-legacy` e necessario quando o PFX usa cifra antiga.

**2. O cliente/app ainda nao tem certificado aceito pelo Itau**

Nesse caso o par chave/certificado precisa ser criado para o processo do Itau:

1. Gere a chave privada localmente.
2. Gere o CSR a partir dessa chave.
3. Envie o CSR para o Itau pelo fluxo indicado por eles, normalmente usando o token/chave temporaria enviada por email.
4. O Itau devolve ou libera o certificado `.crt`.
5. Use o `.crt` do Itau junto com a `.key` que voce gerou localmente.

O Itau nao deve gerar a sua chave privada. A chave privada fica com o cliente/ERP. O que o Itau envia por email costuma ser um token/chave temporaria para autorizar ou localizar o pedido de certificado, nao a chave privada usada no mTLS.

Exemplo de CSR:

```powershell
$openssl = "C:\Program Files\Git\mingw64\bin\openssl.exe"

& $openssl req -new -newkey rsa:2048 -nodes -sha512 `
  -keyout "cliente_itau.key" `
  -out "cliente_itau.csr" `
  -subj "/CN=CLIENT_ID/OU=NOME_DA_EMPRESA/L=CIDADE/ST=UF/C=BR"
```

Se a chave for gerada sem senha por causa do `-nodes`, proteja o arquivo com permissao do sistema operacional ou regrave a chave com senha antes de colocar em producao.

### Regras para o ERP

- Nunca commite `.pfx`, `.p12`, `.key`, `.crt`, senhas, planilhas `Token.xlsx`, tokens OAuth ou dumps de credenciais.
- Armazene certificado, chave e senha por cliente/tenant em local seguro: vault, S3 privado com KMS, banco criptografado ou pasta fora do webroot com permissao restrita.
- Use `tokenKey` unico por cliente/tenant e ambiente, por exemplo `itau_123_456789_producao`.
- Comece os testes com `etapaProcesso => 'validacao'` ou `simulacao`; use `efetivacao` somente para emitir de verdade.
- Para boleto com Pix, alem da chave Pix no DTO, configure `pixBaseUrl`.
- Os certificados locais gerados para este cliente ficam documentados em `utils/Itau/README_CERTIFICADOS.md` e estao ignorados pelo Git.

## Schema de Configuração

Cada banco define um `ConfigSchema` que descreve e valida seus campos de configuração. Isso permite consultar programaticamente quais campos são necessários antes de instanciar o gateway.

### Consultar schema de um banco

```php
$manager = new BoletoManager();

// Obter schema sem instanciar o gateway
$schema = $manager->configSchema('santander');

// Ver todos os campos com tipo, obrigatoriedade e descrição
$campos = $schema->describe();
foreach ($campos as $nome => $info) {
    echo "{$nome}: {$info['label']}";
    echo $info['required'] ? ' (obrigatório)' : ' (opcional)';
    echo "\n";
}
```

### Schema do Santander

| Campo | Tipo | Obrigatório | Default | Descrição |
|-------|------|:-----------:|---------|-----------|
| `clientId` | string | Sim | - | Client ID da aplicação no Santander Developer |
| `clientSecret` | string | Sim | - | Client Secret da aplicação |
| `certFile` | string | * | - | Caminho do certificado PEM |
| `certKeyFile` | string | * | - | Caminho da chave privada PEM |
| `certContent` | string | * | - | Conteúdo PEM do certificado (alternativa) |
| `certKeyContent` | string | * | - | Conteúdo PEM da chave privada (alternativa) |
| `tokenStorage` | TokenStorageInterface | ** | - | Instância de armazenamento de tokens |
| `tokenPath` | string | ** | - | Diretório para armazenar tokens (alternativa) |
| `certKeyPassword` | string | Não | `''` | Senha da chave privada |
| `ambiente` | string | Não | `'producao'` | Ambiente (producao/sandbox) |
| `workspaceId` | string | Não | `''` | ID do workspace |
| `tokenKey` | string | Não | auto | Chave única do token |
| `baseUrl` | string | Não | auto | URL base customizada |
| `httpClient` | mixed | Não | CurlHttpClient | Cliente HTTP customizado |

\* Informe `certFile`+`certKeyFile` **ou** `certContent`+`certKeyContent`
\*\* Informe `tokenStorage` **ou** `tokenPath`

### Validação automática

A validação acontece automaticamente ao instanciar o gateway. Erros são agregados em uma única exceção:

```php
try {
    $gateway = $manager->banco('santander', []);
} catch (BoletoException $e) {
    echo $e->getMessage();
    // "Configuracao invalida para o gateway Santander:
    //  - Campo 'clientId' e obrigatorio (Client ID da aplicacao no Santander Developer).
    //  - Campo 'clientSecret' e obrigatorio (Client Secret da aplicacao).
    //  - Certificado mTLS obrigatorio. Informe 'certFile'+'certKeyFile' (paths) ou ...
    //  - Armazenamento de token obrigatorio. Informe 'tokenStorage' ou 'tokenPath'."
}
```

## Adicionando Novos Bancos

Para adicionar suporte a um novo banco, implemente `BoletoGatewayInterface` e opcionalmente `ConfigurableGatewayInterface`:

```php
use ApiBoleto\Config\ConfigSchema;
use ApiBoleto\Contracts\BoletoGatewayInterface;
use ApiBoleto\Contracts\ConfigurableGatewayInterface;
use ApiBoleto\DTO\Boleto;
use ApiBoleto\DTO\BoletoResponse;
use ApiBoleto\DTO\InstrucaoBoleto;

class MeuBancoGateway implements BoletoGatewayInterface, ConfigurableGatewayInterface
{
    public function __construct(array $config)
    {
        self::configSchema()->validate($config);
        // ...
    }

    public static function configSchema(): ConfigSchema
    {
        return ConfigSchema::create('MeuBanco')
            ->required('apiKey', 'string', 'Chave de API')
            ->required('agencia', 'string', 'Numero da agencia')
            ->optional('ambiente', 'string', 'producao', 'Ambiente');
    }

    public function criarBoleto(Boleto $boleto): BoletoResponse { /* ... */ }
    public function consultarBoleto(string $identificador): BoletoResponse { /* ... */ }
    public function consultarBoletos(array $filtros = []): array { /* ... */ }
    public function alterarBoleto(string $identificador, InstrucaoBoleto $instrucao): BoletoResponse { /* ... */ }
    public function cancelarBoleto(string $identificador, ?InstrucaoBoleto $instrucao = null): bool { /* ... */ }
    public function gerarPdf(string $identificador, string $payerDocumentNumber = ''): string { /* ... */ }
    public function downloadPdf(string $identificador, string $payerDocumentNumber = ''): string { /* ... */ }
}

// Registrar no manager
$manager->registrarBanco('meubanco', MeuBancoGateway::class);

// Consultar schema antes de usar
$schema = $manager->configSchema('meubanco');

// Usar
$gateway = $manager->banco('meubanco', $config);
```

## Estrutura do Projeto

```
src/
├── Config/                       # Validação de configuração
│   └── ConfigSchema.php
├── Contracts/                    # Interfaces
│   ├── BoletoGatewayInterface.php
│   ├── ConfigurableGatewayInterface.php
│   ├── BankSetupInterface.php
│   ├── AuthenticatorInterface.php
│   ├── HttpClientInterface.php
│   └── TokenStorageInterface.php
├── DTO/                          # Data Transfer Objects
│   ├── Boleto.php
│   ├── BoletoResponse.php
│   ├── InstrucaoBoleto.php
│   ├── Pagador.php
│   ├── Beneficiario.php
│   ├── Desconto.php
│   ├── Multa.php
│   └── Juros.php
├── Http/                         # Cliente HTTP e utilitários
│   ├── CurlHttpClient.php
│   └── CertificateHelper.php
├── Storage/                      # Armazenamento de tokens
│   └── FileTokenStorage.php
├── Exceptions/                   # Exceções
│   ├── BoletoException.php
│   ├── AuthenticationException.php
│   └── ApiException.php
├── Banks/                        # Implementações por banco
│   └── Santander/
│       ├── SantanderGateway.php
│       ├── SantanderAuthenticator.php
│       └── SantanderMapper.php
└── BoletoManager.php             # Factory principal
```

## Exemplos

Veja a pasta `examples/` para exemplos completos:

| Arquivo                     | Descrição                          |
|-----------------------------|------------------------------------|
| `santander_sample.php`      | Criação de boleto                  |
| `santander_alterar.php`     | Alteração (instruções)             |
| `santander_baixar.php`      | Cancelamento (baixa)               |
| `santander_pdf.php`         | Geração e download de PDF          |
| `santander_config.php`      | Configuração compartilhada         |
| `itau_sample.php`           | Criação de boleto Itau             |
| `itau_bolecode_pix.php`     | Criação de Bolecode Pix Itau       |
| `itau_francesas.php`        | Consulta Francesa/movimentacoes v3 |
| `itau_boleto_v1_official_pdf.php` | Teste opcional de `base64` oficial, somente se a API v1 estiver liberada |
| `itau_config.php`           | Configuração compartilhada Itau    |

## Licença

MIT
