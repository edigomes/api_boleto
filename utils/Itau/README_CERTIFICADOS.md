# Certificados Itau

Esta pasta recebeu um arquivo `.pfx` do Itau. Para usar na lib, o PFX precisa ser separado em:

- `certFile`: certificado publico/client certificate em `.crt`
- `certKeyFile`: chave privada em `.key`
- `certKeyPassword`: senha da chave privada

Os arquivos gerados localmente foram:

```text
utils/Itau/certs/itau_cert.crt
utils/Itau/certs/itau_private.key
```

Esses arquivos, o `.pfx`, a senha e planilhas/tokens estao protegidos no `.gitignore`.

## Como foi gerado

Use OpenSSL. No Windows deste ambiente foi usado:

```powershell
$openssl = "C:\Program Files\Git\mingw64\bin\openssl.exe"
$pfx = "utils\Itau\13624227000150.pfx"
$cert = "utils\Itau\certs\itau_cert.crt"
$key = "utils\Itau\certs\itau_private.key"
$env:PFX_PASS = (Get-Content -Raw "utils\Itau\cert_pass.txt").Trim()

& $openssl pkcs12 -legacy -in $pfx -clcerts -nokeys -out $cert -passin env:PFX_PASS
& $openssl pkcs12 -legacy -in $pfx -nocerts -out $key -passin env:PFX_PASS -passout env:PFX_PASS

Remove-Item Env:\PFX_PASS -ErrorAction SilentlyContinue
```

O `-legacy` e necessario quando o PFX usa cifra antiga, como `RC2-40-CBC`.

## Como usar nos exemplos

Configure as variaveis de ambiente:

```powershell
$env:ITAU_CERT_FILE="C:\Users\edigo\OneDrive\Documentos\Projetos\api_boleto\utils\Itau\certs\itau_cert.crt"
$env:ITAU_CERT_KEY_FILE="C:\Users\edigo\OneDrive\Documentos\Projetos\api_boleto\utils\Itau\certs\itau_private.key"
$env:ITAU_CERT_KEY_PASSWORD=(Get-Content -Raw "C:\Users\edigo\OneDrive\Documentos\Projetos\api_boleto\utils\Itau\cert_pass.txt").Trim()
```

Depois rode:

```powershell
C:\xampp74\php\php.exe examples\itau_sample.php
```

## Validacao do par certificado/chave

Para confirmar que o certificado e a chave pertencem ao mesmo par:

```powershell
$openssl = "C:\Program Files\Git\mingw64\bin\openssl.exe"
$env:PFX_PASS = (Get-Content -Raw "utils\Itau\cert_pass.txt").Trim()

$certHash = (& $openssl x509 -in "utils\Itau\certs\itau_cert.crt" -pubkey -noout |
  & $openssl pkey -pubin -outform DER |
  & $openssl dgst -sha256).Trim()

$keyHash = (& $openssl pkey -in "utils\Itau\certs\itau_private.key" -passin env:PFX_PASS -pubout -outform DER |
  & $openssl dgst -sha256).Trim()

Remove-Item Env:\PFX_PASS -ErrorAction SilentlyContinue

$certHash -eq $keyHash
```

O retorno esperado e `True`.
