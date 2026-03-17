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

## Licença

MIT
