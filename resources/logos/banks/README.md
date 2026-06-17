# Logos dos bancos

Esta pasta guarda os logos usados pelo renderer interno de PDF.

Convenção:

- `itau.png`: logo retangular usado no cabeçalho do boleto Itaú.
- `santander.png`: logo retangular usado no cabeçalho do boleto Santander.
- `*-symbol.png`: fonte auxiliar usada para compor um logo local.

O renderer procura automaticamente por `resources/logos/banks/{banco}.png` a partir do codigo do banco. Tambem e possivel sobrescrever no momento da renderizacao com a opcao `logoPath`.

Fonte do logo Itaú:

- Pagina consultada: https://commons.wikimedia.org/wiki/File:Banco_Ita%C3%BA_logo.png
- Download auxiliar usado: https://logospng.org/logo-banco-itau/

Observacao: logos de bancos podem ser marca registrada. Use apenas para identificacao visual do banco emissor no boleto.

Fonte do logo Santander:

- Asset local simplificado, gerado para identificacao visual no cabeçalho do boleto.
