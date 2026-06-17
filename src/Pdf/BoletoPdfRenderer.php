<?php

namespace ApiBoleto\Pdf;

use ApiBoleto\DTO\BoletoResponse;
use ApiBoleto\Exceptions\BoletoException;

class BoletoPdfRenderer
{
    private const PAGE_WIDTH = 595.28;
    private const PAGE_HEIGHT = 841.89;

    /** @var string[] */
    private array $commands = [];

    /** @var array<int, array{name:string,width:int,height:int,data:string}> */
    private array $images = [];

    public function render(BoletoResponse $boleto, array $options = []): string
    {
        $codigoBarras = $this->resolveCodigoBarras($boleto);

        if ($codigoBarras === '' && $boleto->linhaDigitavel === '') {
            throw new BoletoException('Nao ha codigo de barras ou linha digitavel para gerar o PDF do boleto.');
        }

        $this->commands = [];
        $this->images = [];

        $bankName = (string) ($options['bankName'] ?? 'Banco');
        $bankCode = $this->formatBankCode((string) ($options['bankCode'] ?? ''));
        $data = $this->extractData($boleto, $bankCode);
        $bankLogo = $this->addBankLogoImage($options, $bankCode);
        $qrImage = $this->addQrImage($data['qr_base64'], $data['qr_payload'], $data['qr_url']);

        $this->setLineWidth(0.5);
        $this->receiptSection($boleto, $data, $bankName, $bankCode, $bankLogo, $qrImage);
        $this->dashedLine(28, 406, 567, 406);
        $this->text(43, 413, 'Corte na linha pontilhada', 6);
        $this->compensationSection($boleto, $data, $bankName, $bankCode, $bankLogo, $qrImage, $codigoBarras);

        return $this->buildPdf(implode("\n", $this->commands) . "\n");
    }

    private function receiptSection(
        BoletoResponse $boleto,
        array $data,
        string $bankName,
        string $bankCode,
        string $bankLogo,
        string $qrImage
    ): void {
        $x = 28;
        $w = 539;

        $this->bankHeader($x, 795, $w, $bankName, $bankCode, $boleto->linhaDigitavel, 'Recibo do Pagador', $bankLogo);

        $this->multiField('Beneficiario', $data['beneficiario_nome'], $x, 768, 240, 23, 7.2, 1, true);
        $this->field('Agencia/Codigo do beneficiario', $data['id_beneficiario'], 268, 768, 115, 23, true, 7.2);
        $this->field('Especie', 'R$', 383, 768, 34, 23, false, 7.2, true);
        $this->field('Quantidade', '', 417, 768, 49, 23, false, 7.2, true);
        $this->field('Nosso Numero', $data['nosso_numero_formatado'], 466, 768, 101, 23, true, 7.2);

        $this->field('Numero do Documento', $data['seu_numero'], $x, 745, 135, 23, false, 7.2, true);
        $this->field('CPF/CNPJ', $this->formatDocument($data['beneficiario_documento']), 163, 745, 130, 23, false, 7.2, true);
        $this->field('Vencimento', $this->formatDate($boleto->vencimento), 293, 745, 95, 23, false, 7.2, true);
        $this->field('Valor do Documento', $this->formatCurrency($boleto->valor), 388, 745, 179, 23, true, 7.2);

        $this->field('(-) Descontos/Abatimentos', $data['desconto_abatimento'], $x, 721, 97, 24);
        $this->field('(-) Outras Deducoes', '', 125, 721, 97, 24);
        $this->field('(+) Mora Multa', $data['juros_multa'], 222, 721, 97, 24);
        $this->field('(+) Acrescimos', '', 319, 721, 97, 24);
        $this->field('(=) Valor Cobrado', '', 416, 721, 151, 24);

        $payerReceipt = trim($data['pagador_nome'] . ' - ' . $data['pagador_endereco'], ' -');
        $this->multiField('Pagador', $payerReceipt, $x, 693, 370, 28, 7.2, 1, true);
        $this->field('CPF/CNPJ', $this->formatDocument($data['pagador_documento']), 398, 693, 169, 28, false, 7.2, true);
        $this->field('Beneficiario Final', $data['beneficiario_final'], $x, 665, $w, 28, false, 7.2, true);
        $this->text($x + 3, 656, 'Demonstrativo', 6);
        $this->textRight('Autenticacao mecanica', $x + $w - 3, 656, 6);

        foreach ($this->demonstrativoLines($data['instrucoes']) as $index => $line) {
            $this->text($x + 3, 638 - ($index * 12), $this->fit($line, 500, 8), 8, true);
        }

        if ($qrImage !== '') {
            $this->rect(379, 519, 68, 68);
            $this->drawImage($qrImage, 383, 523, 60, 60);
        }

        $this->text(437, 500, 'Autenticacao Mecanica', 7);
        $this->line(28, 492, 567, 492);
    }

    private function compensationSection(
        BoletoResponse $boleto,
        array $data,
        string $bankName,
        string $bankCode,
        string $bankLogo,
        string $qrImage,
        string $codigoBarras
    ): void {
        $x = 28;
        $w = 539;

        $this->bankHeader($x, 355, $w, $bankName, $bankCode, $boleto->linhaDigitavel, '', $bankLogo);

        $this->multiField('Local de Pagamento', $data['local_pagamento'], $x, 319, 419, 28, 5.5, 2);
        $this->field('Vencimento', $this->formatDate($boleto->vencimento), 447, 319, 120, 28, true);

        $this->multiField('Beneficiario', $data['beneficiario_detalhe'], $x, 291, 419, 28, 7, 2);
        $this->field('Agencia/Codigo Beneficiario', $data['id_beneficiario'], 447, 291, 120, 28);

        $this->field('Data do Documento', $this->formatDate($data['data_documento']), $x, 263, 90, 28);
        $this->field('Numero do Documento', $data['seu_numero'], 118, 263, 114, 28);
        $this->field('Especie Doc.', $data['codigo_especie'], 232, 263, 68, 28);
        $this->field('Aceite', $data['aceite'], 300, 263, 47, 28);
        $this->field('Data Processamento', $this->formatDate($data['data_processamento']), 347, 263, 100, 28);
        $this->field('Nosso Numero', $data['nosso_numero_formatado'], 447, 263, 120, 28);

        $this->field('Uso do Banco', '', $x, 235, 90, 28);
        $this->field('Carteira', $data['codigo_carteira'], 118, 235, 70, 28);
        $this->field('Especie', 'R$', 188, 235, 56, 28);
        $this->field('Quantidade', '', 244, 235, 103, 28);
        $this->field('Valor', '', 347, 235, 100, 28);
        $this->field('(=) Valor do Documento', $this->formatCurrency($boleto->valor), 447, 235, 120, 28, true);

        $this->multiField('Instrucoes de responsabilidade do BENEFICIARIO', $data['instrucoes'], $x, 151, 419, 84, 6.8, 9);
        $this->field('(-) Desconto / Abatimento', $data['desconto_abatimento'], 447, 207, 120, 28);
        $this->field('(-) Outras Deducoes', '', 447, 179, 120, 28);
        $this->field('(+) Mora / Multa', $data['juros_multa'], 447, 151, 120, 28);
        $this->field('(+) Outros Acrescimos', '', 447, 123, 120, 28);
        $this->field('(=) Valor Cobrado', '', 447, 95, 120, 28);

        $payerValue = $data['pagador_nome_documento'] . ' - ' . $data['pagador_endereco'];
        $this->multiField('Pagador', $payerValue, $x, 107, 419, 44, 6.8, 3);
        $this->field('Sacador/Avalista', $data['sacador_avalista'], $x, 79, 419, 28);

        if ($qrImage !== '') {
            $this->rect(379, 79, 68, 68);
            $this->drawImage($qrImage, 383, 83, 60, 60);
        }

        if ($codigoBarras !== '') {
            $this->drawInterleaved2of5($codigoBarras, 40, 35, 42);
        }

        $this->text(405, 58, 'Autenticacao Mecanica - Ficha de Compensacao', 6.5);
    }

    private function bankHeader(
        float $x,
        float $y,
        float $w,
        string $bankName,
        string $bankCode,
        string $linhaDigitavel,
        string $rightTitle,
        string $bankLogo
    ): void {
        $firstFieldEnd = $bankLogo !== '' ? $x + 92 : $x + 126;
        $codeFieldEnd = $firstFieldEnd + 50;

        $this->line($x, $y - 4, $x + $w, $y - 4);

        if ($bankLogo !== '') {
            $this->drawImage($bankLogo, $x, $y - 1, 90, 25.5);
        } else {
            $this->text($x + 4, $y + 9, $bankName, 10.5, true);
        }

        $this->line($firstFieldEnd, $y - 4, $firstFieldEnd, $y + 28);
        $this->textCentered($bankCode, $firstFieldEnd, $codeFieldEnd, $y + 8, 13, true);
        $this->line($codeFieldEnd, $y - 4, $codeFieldEnd, $y + 28);

        if ($rightTitle !== '') {
            $this->textRight($rightTitle, $x + $w - 2, $y + 20, 7.5, true);
        }

        if ($linhaDigitavel !== '') {
            $size = $rightTitle !== '' ? 9.2 : 10.5;
            $this->textRight($this->formatLinhaDigitavel($linhaDigitavel), $x + $w - 2, $y + 6, $size, true);
        }
    }

    private function extractData(BoletoResponse $boleto, string $bankCode): array
    {
        $api = $boleto->dadosOriginais;
        $data = $api['data'] ?? $api;
        $dado = $data['dado_boleto'] ?? $api['dado_boleto'] ?? [];
        $individual = $this->firstItem($dado['dados_individuais_boleto'] ?? []);
        $beneficiario = $data['beneficiario'] ?? $api['beneficiario'] ?? $data['beneficiary'] ?? $api['beneficiary'] ?? [];
        $pagador = $dado['pagador'] ?? $data['pagador'] ?? $api['pagador'] ?? $data['payer'] ?? $api['payer'] ?? [];

        $pagadorPessoa = $pagador['pessoa'] ?? $pagador;
        $pagadorTipoPessoa = $pagadorPessoa['tipo_pessoa'] ?? [];
        $pagadorEndereco = $pagador['endereco'] ?? $pagador;

        $beneficiarioDocumento = $this->extractDocument($beneficiario['tipo_pessoa'] ?? $beneficiario);
        $pagadorNome = (string) $this->firstNonEmpty([
            $pagadorPessoa['nome_pessoa'] ?? null,
            $pagadorPessoa['name'] ?? null,
            $pagador['name'] ?? null,
            $pagador['nomePagador'] ?? null,
        ]);
        $pagadorDocumento = (string) $this->firstNonEmpty([
            $this->extractDocument($pagadorTipoPessoa),
            $pagadorPessoa['documentNumber'] ?? null,
            $pagador['documentNumber'] ?? null,
            $pagador['numeroDocumento'] ?? null,
        ]);
        $beneficiarioNome = (string) $this->firstNonEmpty([
            $beneficiario['nome_cobranca'] ?? null,
            $beneficiario['nome'] ?? null,
            $beneficiario['name'] ?? null,
        ]);
        $beneficiarioEndereco = $this->formatAddress($beneficiario['endereco'] ?? []);
        $beneficiarioNomeDocumento = $this->nameAndDocument($beneficiarioNome, $beneficiarioDocumento);
        $idBeneficiario = (string) $this->firstNonEmpty([
            $beneficiario['id_beneficiario'] ?? null,
            $beneficiario['idBeneficiario'] ?? null,
            $data['beneficiaryCode'] ?? null,
            $api['beneficiaryCode'] ?? null,
            $data['covenantCode'] ?? null,
            $api['covenantCode'] ?? null,
        ]);
        $codigoCarteira = (string) $this->firstNonEmpty([
            $dado['codigo_carteira'] ?? null,
            $data['codigoCarteira'] ?? null,
            $data['walletCode'] ?? null,
            $api['walletCode'] ?? null,
        ]);
        $codigoEspecie = (string) $this->firstNonEmpty([
            $dado['codigo_especie'] ?? null,
            $data['especie']['codigoEspecie'] ?? null,
            $data['codigoEspecie'] ?? null,
            $data['documentKind'] ?? null,
            $api['documentKind'] ?? null,
        ]);
        $nossoNumero = (string) $this->firstNonEmpty([
            $boleto->nossoNumero,
            $individual['numero_nosso_numero'] ?? null,
            $data['nossoNumero'] ?? null,
            $data['bankNumber'] ?? null,
            $api['bankNumber'] ?? null,
            $data['participantCode'] ?? null,
            $api['participantCode'] ?? null,
        ]);
        $seuNumero = (string) $this->firstNonEmpty([
            $individual['texto_seu_numero'] ?? null,
            $data['seuNumero'] ?? null,
            $data['clientNumber'] ?? null,
            $api['clientNumber'] ?? null,
            $data['nsuCode'] ?? null,
            $api['nsuCode'] ?? null,
        ]);

        return [
            'beneficiario_nome_documento' => $beneficiarioNomeDocumento,
            'beneficiario_detalhe' => trim($beneficiarioNomeDocumento . ' - ' . $beneficiarioEndereco, ' -'),
            'beneficiario_endereco' => $beneficiarioEndereco,
            'beneficiario_nome' => $beneficiarioNome,
            'beneficiario_documento' => $beneficiarioDocumento,
            'id_beneficiario' => $idBeneficiario,
            'codigo_carteira' => $codigoCarteira,
            'codigo_especie' => $codigoEspecie,
            'pagador_nome_documento' => $this->nameAndDocument($pagadorNome, $pagadorDocumento),
            'pagador_nome' => $pagadorNome,
            'pagador_documento' => $pagadorDocumento,
            'pagador_endereco' => $this->formatAddress($pagadorEndereco),
            'beneficiario_final' => (string) $this->firstNonEmpty([
                $dado['beneficiario_final'] ?? null,
                $data['beneficiario_final'] ?? null,
                $api['beneficiario_final'] ?? null,
            ]),
            'seu_numero' => $seuNumero,
            'nosso_numero_formatado' => $codigoCarteira !== '' && $nossoNumero !== ''
                ? $codigoCarteira . '/' . $nossoNumero
                : $nossoNumero,
            'data_documento' => (string) $this->firstNonEmpty([
                $dado['data_emissao'] ?? null,
                $data['dataEmissao'] ?? null,
                $data['issueDate'] ?? null,
                $api['issueDate'] ?? null,
                $data['nsuDate'] ?? null,
                $api['nsuDate'] ?? null,
            ]),
            'data_processamento' => (string) $this->firstNonEmpty([
                $dado['data_emissao'] ?? null,
                $data['dataEntrada'] ?? null,
                $data['dataEmissao'] ?? null,
                $data['issueDate'] ?? null,
                $api['issueDate'] ?? null,
                $data['nsuDate'] ?? null,
                $api['nsuDate'] ?? null,
            ]),
            'aceite' => 'N',
            'local_pagamento' => $this->localPagamento($bankCode),
            'instrucoes' => $this->instrucoes($dado, $individual, $data),
            'desconto_abatimento' => $this->formatCurrency((string) $this->firstNonEmpty([
                $dado['valor_abatimento'] ?? null,
                $data['valorAbatimento'] ?? null,
                $data['deductionValue'] ?? null,
                $api['deductionValue'] ?? null,
                $data['discount']['discountOne']['value'] ?? null,
                $api['discount']['discountOne']['value'] ?? null,
            ])),
            'juros_multa' => $this->formatJurosMulta($dado, $data),
            'sacador_avalista' => $this->formatSacadorAvalista($dado, $data),
            'qr_base64' => $this->extractQrBase64($api),
            'qr_payload' => $this->extractQrPayload($boleto, $api),
            'qr_url' => $this->extractQrUrl($boleto, $api),
        ];
    }

    private function instrucoes(array $dado, array $individual, array $data): string
    {
        $mensagens = [
            'Instrucoes de responsabilidade do BENEFICIARIO. Qualquer duvida sobre este Boleto, contate o BENEFICIARIO.',
        ];

        foreach (($individual['lista_mensagens_cobranca'] ?? []) as $item) {
            if (isset($item['mensagem']) && (string) $item['mensagem'] !== '') {
                $mensagens[] = (string) $item['mensagem'];
            }
        }

        foreach (($data['mensagensBoleto'] ?? []) as $item) {
            if (isset($item['mensagem']) && (string) $item['mensagem'] !== '') {
                $mensagens[] = (string) $item['mensagem'];
            }
        }

        foreach (($data['messages'] ?? []) as $item) {
            if (is_string($item) && $item !== '') {
                $mensagens[] = $item;
            } elseif (is_array($item) && isset($item['mensagem']) && (string) $item['mensagem'] !== '') {
                $mensagens[] = (string) $item['mensagem'];
            }
        }

        if (isset($dado['data_limite_pagamento'])) {
            $mensagens[] = 'Banco autorizado a receber ate ' . $this->formatDate((string) $dado['data_limite_pagamento']) . '.';
        } elseif (isset($data['dataLimitePagamento'])) {
            $mensagens[] = 'Banco autorizado a receber ate ' . $this->formatDate((string) $data['dataLimitePagamento']) . '.';
        }

        return implode(' ', array_unique($mensagens));
    }

    private function localPagamento(string $bankCode): string
    {
        $digits = substr(preg_replace('/\D/', '', $bankCode) ?? '', 0, 3);
        if ($digits === '033') {
            return 'Pagavel preferencialmente no Banco Santander';
        }

        return 'Ate o vencimento, preferencialmente no Itau';
    }

    /**
     * @return string[]
     */
    private function demonstrativoLines(string $instrucoes): array
    {
        $default = 'Instrucoes de responsabilidade do BENEFICIARIO. Qualquer duvida sobre este Boleto, contate o BENEFICIARIO.';
        $text = trim(str_replace($default, '', $instrucoes));
        if ($text === '') {
            return [];
        }

        return array_slice($this->wrapText($text, 64), 0, 6);
    }

    private function formatJurosMulta(array $dado, array $data): string
    {
        $parts = [];

        foreach ([$dado['juros'] ?? null, $data['juros'] ?? null] as $juros) {
            if (!is_array($juros)) {
                continue;
            }
            if (!empty($juros['valor_juros']) || !empty($juros['valorJuros'])) {
                $parts[] = 'Juros ' . $this->formatCurrency((string) ($juros['valor_juros'] ?? $juros['valorJuros']));
            } elseif (!empty($juros['percentual_juros']) || !empty($juros['percentualJuros'])) {
                $parts[] = 'Juros ' . (string) ($juros['percentual_juros'] ?? $juros['percentualJuros']) . '%';
            }
        }

        foreach ([$dado['multa'] ?? null, $data['multa'] ?? null] as $multa) {
            if (!is_array($multa)) {
                continue;
            }
            if (!empty($multa['valor_multa']) || !empty($multa['valorMulta'])) {
                $parts[] = 'Multa ' . $this->formatCurrency((string) ($multa['valor_multa'] ?? $multa['valorMulta']));
            } elseif (!empty($multa['percentual_multa']) || !empty($multa['percentualMulta'])) {
                $parts[] = 'Multa ' . (string) ($multa['percentual_multa'] ?? $multa['percentualMulta']) . '%';
            }
        }

        if (!empty($data['interestPercentage'])) {
            $parts[] = 'Juros ' . (string) $data['interestPercentage'] . '%';
        } elseif (!empty($data['interestValue'])) {
            $parts[] = 'Juros ' . $this->formatCurrency((string) $data['interestValue']);
        }

        if (!empty($data['finePercentage'])) {
            $parts[] = 'Multa ' . (string) $data['finePercentage'] . '%';
        } elseif (!empty($data['fineValue'])) {
            $parts[] = 'Multa ' . $this->formatCurrency((string) $data['fineValue']);
        }

        return implode(' / ', array_unique($parts));
    }

    private function formatSacadorAvalista(array $dado, array $data): string
    {
        $sacador = $dado['sacador_avalista'] ?? $data['beneficiarioFinal'] ?? [];
        if (!is_array($sacador) || $sacador === []) {
            return '';
        }

        $pessoa = $sacador['pessoa'] ?? $sacador;
        $nome = (string) ($pessoa['nome_pessoa'] ?? $pessoa['nome'] ?? '');
        $documento = $this->extractDocument($pessoa['tipo_pessoa'] ?? $pessoa);

        return $this->nameAndDocument($nome, $documento);
    }

    private function extractDocument(array $tipoPessoa): string
    {
        return (string) $this->firstNonEmpty([
            $tipoPessoa['numero_cadastro_pessoa_fisica'] ?? null,
            $tipoPessoa['numero_cadastro_nacional_pessoa_juridica'] ?? null,
            $tipoPessoa['numeroDocumento'] ?? null,
            $tipoPessoa['numero_documento'] ?? null,
            $tipoPessoa['documentNumber'] ?? null,
            $tipoPessoa['document_number'] ?? null,
        ]);
    }

    private function nameAndDocument(string $name, string $document): string
    {
        $parts = array_filter([$name, $this->formatDocument($document)], static fn($value): bool => $value !== '');

        return implode(' - ', $parts);
    }

    private function formatDocument(string $document): string
    {
        $digits = preg_replace('/\D/', '', $document) ?? '';
        if (strlen($digits) === 11) {
            return substr($digits, 0, 3) . '.' . substr($digits, 3, 3) . '.'
                . substr($digits, 6, 3) . '-' . substr($digits, 9, 2);
        }
        if (strlen($digits) === 14) {
            return substr($digits, 0, 2) . '.' . substr($digits, 2, 3) . '.'
                . substr($digits, 5, 3) . '/' . substr($digits, 8, 4) . '-' . substr($digits, 12, 2);
        }

        return $document;
    }

    private function formatAddress(array $endereco): string
    {
        $logradouro = (string) $this->firstNonEmpty([
            $endereco['nome_logradouro'] ?? null,
            $endereco['logradouro'] ?? null,
            $endereco['address'] ?? null,
        ]);
        $numero = (string) ($endereco['numero'] ?? '');
        if ($numero !== '' && strpos($logradouro, $numero) === false) {
            $logradouro = trim($logradouro . ', ' . $numero);
        }

        $parts = array_filter([
            $logradouro,
            $endereco['nome_bairro'] ?? $endereco['bairro'] ?? $endereco['neighborhood'] ?? '',
            $endereco['nome_cidade'] ?? $endereco['cidade'] ?? $endereco['city'] ?? '',
            $endereco['sigla_UF'] ?? $endereco['uf'] ?? $endereco['state'] ?? '',
            $this->formatCep((string) ($endereco['numero_CEP'] ?? $endereco['cep'] ?? $endereco['zipCode'] ?? '')),
        ], static fn($value): bool => (string) $value !== '');

        return implode(' - ', array_map('strval', $parts));
    }

    private function formatCep(string $cep): string
    {
        $digits = preg_replace('/\D/', '', $cep) ?? '';
        if (strlen($digits) === 8) {
            return substr($digits, 0, 5) . '-' . substr($digits, 5, 3);
        }

        return $cep;
    }

    private function firstItem(array $items): array
    {
        if ($items === []) {
            return [];
        }

        if (isset($items[0]) && is_array($items[0])) {
            return $items[0];
        }

        return $items;
    }

    private function firstNonEmpty(array $values)
    {
        foreach ($values as $value) {
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function formatCurrency(string $value): string
    {
        $normalized = trim($value);
        if ($normalized === '') {
            return '';
        }

        if (ctype_digit($normalized) && strlen($normalized) > 2) {
            $amount = ((int) $normalized) / 100;
        } else {
            $amount = (float) str_replace(',', '.', $normalized);
        }

        if ($amount == 0.0 && (float) $normalized == 0.0) {
            return '';
        }

        return 'R$ ' . number_format($amount, 2, ',', '.');
    }

    private function formatDate(string $date): string
    {
        if ($date === '') {
            return '';
        }

        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return $date;
        }

        return date('d/m/Y', $timestamp);
    }

    private function formatBankCode(string $bankCode): string
    {
        $digits = preg_replace('/\D/', '', $bankCode) ?? '';
        $baseCode = substr($digits, 0, 3);
        $codes = [
            '033' => '033-7',
            '341' => '341-7',
        ];

        if ($bankCode === '') {
            return $codes['341'];
        }

        if (isset($codes[$baseCode])) {
            return $codes[$baseCode];
        }

        return $bankCode;
    }

    private function formatLinhaDigitavel(string $linhaDigitavel): string
    {
        $digits = preg_replace('/\D/', '', $linhaDigitavel) ?? '';
        if (strlen($digits) !== 47) {
            return $linhaDigitavel;
        }

        return substr($digits, 0, 5) . '.' . substr($digits, 5, 5)
            . ' ' . substr($digits, 10, 5) . '.' . substr($digits, 15, 6)
            . ' ' . substr($digits, 21, 5) . '.' . substr($digits, 26, 6)
            . ' ' . substr($digits, 32, 1)
            . ' ' . substr($digits, 33, 14);
    }

    private function resolveCodigoBarras(BoletoResponse $boleto): string
    {
        if ($boleto->codigoBarras !== '') {
            return preg_replace('/\D/', '', $boleto->codigoBarras) ?? '';
        }

        return $this->codigoBarrasFromLinhaDigitavel($boleto->linhaDigitavel);
    }

    private function codigoBarrasFromLinhaDigitavel(string $linhaDigitavel): string
    {
        $digits = preg_replace('/\D/', '', $linhaDigitavel) ?? '';
        if (strlen($digits) !== 47) {
            return '';
        }

        return substr($digits, 0, 4)
            . substr($digits, 32, 1)
            . substr($digits, 33, 14)
            . substr($digits, 4, 5)
            . substr($digits, 10, 10)
            . substr($digits, 21, 10);
    }

    private function field(
        string $label,
        string $value,
        float $x,
        float $y,
        float $w,
        float $h,
        bool $right = false,
        float $valueSize = 8,
        bool $valueBold = false
    ): void {
        $this->rect($x, $y, $w, $h);
        $this->text($x + 3, $y + $h - 8, $label, 5.8, true);
        $text = $this->fit($value, $w, $valueSize);
        if ($right) {
            $this->textRight($text, $x + $w - 3, $y + 6, $valueSize, true);
            return;
        }

        $this->text($x + 3, $y + 6, $text, $valueSize, $valueBold);
    }

    private function multiField(
        string $label,
        string $value,
        float $x,
        float $y,
        float $w,
        float $h,
        float $size = 7,
        int $maxLines = 5,
        bool $valueBold = false
    ): void {
        $this->rect($x, $y, $w, $h);
        $this->text($x + 3, $y + $h - 8, $label, 5.8, true);
        $lines = array_slice($this->wrapText($value, max(8, (int) floor($w / ($size * 0.72)))), 0, $maxLines);
        $this->textLines($lines, $x + 3, $y + $h - 18, $w - 6, $size, $size + 2, $valueBold);
    }

    private function wrapText(string $value, int $limit): array
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');
        if ($value === '') {
            return [''];
        }

        $lines = [];
        $line = '';
        foreach (explode(' ', $value) as $word) {
            if ($line === '') {
                $line = $word;
                continue;
            }
            if (strlen($line . ' ' . $word) <= $limit) {
                $line .= ' ' . $word;
                continue;
            }
            $lines[] = $line;
            $line = $word;
        }

        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @param string[] $lines
     */
    private function textLines(
        array $lines,
        float $x,
        float $y,
        float $w,
        float $size,
        float $leading,
        bool $bold = false
    ): void
    {
        foreach ($lines as $index => $line) {
            $this->text($x, $y - ($index * $leading), $this->fit((string) $line, $w, $size), $size, $bold);
        }
    }

    private function fit(string $value, float $width, float $size = 8): string
    {
        $limit = max(8, (int) floor($width / ($size * 0.48)));
        if (strlen($value) <= $limit) {
            return $value;
        }

        return substr($value, 0, max(0, $limit - 3)) . '...';
    }

    private function extractQrBase64(array $api): string
    {
        $data = $api['data'] ?? $api;
        $qrcode = $data['dados_qrcode']
            ?? $api['dados_qrcode']
            ?? $data['dadosQrcode']
            ?? $api['dadosQrcode']
            ?? $data['qrCode']
            ?? $api['qrCode']
            ?? [];

        if (is_array($qrcode)) {
            return (string) ($qrcode['base64']
                ?? $qrcode['imagemBase64']
                ?? $qrcode['imageBase64']
                ?? $qrcode['qrCodeBase64']
                ?? '');
        }

        return (string) $this->firstNonEmpty([
            $data['qrCodeBase64'] ?? null,
            $api['qrCodeBase64'] ?? null,
            $data['qrCodeImageBase64'] ?? null,
            $api['qrCodeImageBase64'] ?? null,
        ]);
    }

    private function extractQrPayload(BoletoResponse $boleto, array $api): string
    {
        $data = $api['data'] ?? $api;
        $qrcode = $data['dados_qrcode']
            ?? $api['dados_qrcode']
            ?? $data['dadosQrcode']
            ?? $api['dadosQrcode']
            ?? $data['qrCode']
            ?? $api['qrCode']
            ?? [];

        $values = [
            $boleto->qrCodePix,
            $data['qrCodePix'] ?? null,
            $api['qrCodePix'] ?? null,
            $data['emvqrcps'] ?? null,
            $api['emvqrcps'] ?? null,
            $data['pixQrCode'] ?? null,
            $api['pixQrCode'] ?? null,
            $data['pixCopiaECola'] ?? null,
            $api['pixCopiaECola'] ?? null,
        ];

        if (is_array($qrcode)) {
            $values[] = $qrcode['emv'] ?? null;
            $values[] = $qrcode['emvqrcps'] ?? null;
            $values[] = $qrcode['pixCopiaECola'] ?? null;
            $values[] = $qrcode['payload'] ?? null;
        }

        return (string) $this->firstNonEmpty($values);
    }

    private function extractQrUrl(BoletoResponse $boleto, array $api): string
    {
        $data = $api['data'] ?? $api;
        $qrcode = $data['dados_qrcode']
            ?? $api['dados_qrcode']
            ?? $data['dadosQrcode']
            ?? $api['dadosQrcode']
            ?? $data['qrCode']
            ?? $api['qrCode']
            ?? [];

        $values = [
            $boleto->qrCodeUrl,
            $data['qrCodeUrl'] ?? null,
            $api['qrCodeUrl'] ?? null,
            $data['qrCodeImageUrl'] ?? null,
            $api['qrCodeImageUrl'] ?? null,
            $data['pixQrCodeUrl'] ?? null,
            $api['pixQrCodeUrl'] ?? null,
        ];

        if (is_array($qrcode)) {
            $values[] = $qrcode['location'] ?? null;
            $values[] = $qrcode['url'] ?? null;
            $values[] = $qrcode['imageUrl'] ?? null;
        }

        return (string) $this->firstNonEmpty($values);
    }

    private function addQrImage(string $base64, string $payload = '', string $url = ''): string
    {
        $fromBase64 = $this->addQrImageFromBase64($base64);
        if ($fromBase64 !== '') {
            return $fromBase64;
        }

        if ($payload !== '') {
            $binary = (new SimpleQrCode())->renderPng($payload);
            if ($binary !== '') {
                return $this->addImageFromBinary($binary);
            }
        }

        return $this->addQrImageFromUrl($url);
    }

    private function addQrImageFromBase64(string $base64): string
    {
        if ($base64 === '') {
            return '';
        }

        $clean = trim($base64);
        if (preg_match('/^data:[^,]+,(.+)$/', $clean, $matches)) {
            $clean = $matches[1];
        }

        $binary = base64_decode($clean, true);
        if ($binary === false) {
            return '';
        }

        return $this->addImageFromBinary($binary);
    }

    private function addQrImageFromUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (preg_match('/^data:[^,]+,(.+)$/', $url, $matches)) {
            return $this->addQrImageFromBase64($matches[1]);
        }

        if (is_file($url)) {
            $binary = @file_get_contents($url);
            return is_string($binary) ? $this->addImageFromBinary($binary) : '';
        }

        if (!preg_match('/^https?:\/\//i', $url) || !ini_get('allow_url_fopen')) {
            return '';
        }

        $context = stream_context_create([
            'http' => ['timeout' => 5],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $binary = @file_get_contents($url, false, $context);

        return is_string($binary) ? $this->addImageFromBinary($binary) : '';
    }

    private function addBankLogoImage(array $options, string $bankCode): string
    {
        $path = $this->resolveBankLogoPath($options, $bankCode);
        if ($path === '' || !is_file($path)) {
            return '';
        }

        $binary = @file_get_contents($path);
        if ($binary === false) {
            return '';
        }

        return $this->addImageFromBinary($binary);
    }

    private function resolveBankLogoPath(array $options, string $bankCode): string
    {
        if (array_key_exists('logoPath', $options)) {
            return $options['logoPath'] === false ? '' : (string) $options['logoPath'];
        }

        $digits = preg_replace('/\D/', '', $bankCode) ?? '';
        $logos = [
            '341' => 'itau.png',
            '033' => 'santander.png',
            '001' => 'banco-do-brasil.png',
            '104' => 'caixa.png',
            '237' => 'bradesco.png',
        ];

        $filename = $logos[substr($digits, 0, 3)] ?? '';
        if ($filename === '') {
            return '';
        }

        return dirname(__DIR__, 2) . '/resources/logos/banks/' . $filename;
    }

    private function addImageFromBinary(string $binary): string
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('gzcompress')) {
            return '';
        }

        $gd = @imagecreatefromstring($binary);
        if ($gd === false) {
            return '';
        }

        $width = imagesx($gd);
        $height = imagesy($gd);
        $rgb = '';

        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                $color = imagecolorsforindex($gd, imagecolorat($gd, $x, $y));
                $alpha = isset($color['alpha']) ? (int) $color['alpha'] : 0;
                $ratio = $alpha / 127;
                $red = (int) round($color['red'] * (1 - $ratio) + 255 * $ratio);
                $green = (int) round($color['green'] * (1 - $ratio) + 255 * $ratio);
                $blue = (int) round($color['blue'] * (1 - $ratio) + 255 * $ratio);
                $rgb .= chr($red) . chr($green) . chr($blue);
            }
        }

        imagedestroy($gd);

        $name = 'Im' . (count($this->images) + 1);
        $this->images[] = [
            'name' => $name,
            'width' => $width,
            'height' => $height,
            'data' => gzcompress($rgb),
        ];

        return $name;
    }

    private function drawInterleaved2of5(string $digits, float $x, float $y, float $height): void
    {
        $digits = preg_replace('/\D/', '', $digits) ?? '';

        if ($digits === '') {
            return;
        }

        if (strlen($digits) % 2 !== 0) {
            $digits = '0' . $digits;
        }

        $patterns = [
            '0' => 'nnwwn',
            '1' => 'wnnnw',
            '2' => 'nwnnw',
            '3' => 'wwnnn',
            '4' => 'nnwnw',
            '5' => 'wnwnn',
            '6' => 'nwwnn',
            '7' => 'nnnww',
            '8' => 'wnnwn',
            '9' => 'nwnwn',
        ];

        $narrow = 0.72;
        $wide = $narrow * 3;
        $pos = $x;

        $this->bar($pos, $y, $narrow, $height);
        $pos += $narrow * 2;
        $this->bar($pos, $y, $narrow, $height);
        $pos += $narrow * 2;

        for ($i = 0, $length = strlen($digits); $i < $length; $i += 2) {
            $black = $patterns[$digits[$i]];
            $white = $patterns[$digits[$i + 1]];

            for ($j = 0; $j < 5; $j++) {
                $barWidth = $black[$j] === 'w' ? $wide : $narrow;
                $spaceWidth = $white[$j] === 'w' ? $wide : $narrow;
                $this->bar($pos, $y, $barWidth, $height);
                $pos += $barWidth + $spaceWidth;
            }
        }

        $this->bar($pos, $y, $wide, $height);
        $pos += $wide + $narrow;
        $this->bar($pos, $y, $narrow, $height);
    }

    private function text(float $x, float $y, string $text, float $size = 10, bool $bold = false): void
    {
        $font = $bold ? 'F2' : 'F1';
        $this->commands[] = sprintf(
            'BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET',
            $font,
            $size,
            $x,
            $y,
            $this->escapeText($text)
        );
    }

    private function textRight(string $text, float $rightX, float $y, float $size = 10, bool $bold = false): void
    {
        $x = $rightX - $this->estimateTextWidth($text, $size, $bold);
        $this->text($x, $y, $text, $size, $bold);
    }

    private function textCentered(
        string $text,
        float $leftX,
        float $rightX,
        float $y,
        float $size = 10,
        bool $bold = false
    ): void {
        $width = $this->estimateTextWidth($text, $size, $bold);
        $x = $leftX + (($rightX - $leftX - $width) / 2);
        $this->text($x, $y, $text, $size, $bold);
    }

    private function estimateTextWidth(string $text, float $size, bool $bold = false): float
    {
        $factor = $bold ? 0.55 : 0.50;

        return strlen($text) * $size * $factor;
    }

    private function line(float $x1, float $y1, float $x2, float $y2): void
    {
        $this->commands[] = sprintf('%.2F %.2F m %.2F %.2F l S', $x1, $y1, $x2, $y2);
    }

    private function dashedLine(float $x1, float $y1, float $x2, float $y2): void
    {
        $this->commands[] = '[3 3] 0 d';
        $this->line($x1, $y1, $x2, $y2);
        $this->commands[] = '[] 0 d';
    }

    private function rect(float $x, float $y, float $w, float $h): void
    {
        $this->commands[] = sprintf('%.2F %.2F %.2F %.2F re S', $x, $y, $w, $h);
    }

    private function bar(float $x, float $y, float $w, float $h): void
    {
        $this->commands[] = sprintf('%.2F %.2F %.2F %.2F re f', $x, $y, $w, $h);
    }

    private function drawImage(string $name, float $x, float $y, float $w, float $h): void
    {
        $this->commands[] = sprintf('q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q', $w, $h, $x, $y, $name);
    }

    private function setLineWidth(float $width): void
    {
        $this->commands[] = sprintf('%.2F w', $width);
    }

    private function fillGray(float $gray): void
    {
        $this->commands[] = sprintf('%.2F g', $gray);
    }

    private function escapeText(string $text): string
    {
        $encoded = $text;
        if (function_exists('iconv')) {
            $converted = iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
            if ($converted !== false) {
                $encoded = $converted;
            }
        }

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $encoded);
    }

    private function buildPdf(string $content): string
    {
        $imageObjectStart = 7;
        $contentObject = $imageObjectStart + count($this->images);
        $xobjects = '';
        $toUnicode = $this->buildWinAnsiToUnicodeCMap();

        foreach ($this->images as $index => $image) {
            $xobjects .= '/' . $image['name'] . ' ' . ($imageObjectStart + $index) . ' 0 R ';
        }

        $resourceXObject = $xobjects !== '' ? ' /XObject << ' . $xobjects . '>>' : '';

        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::PAGE_WIDTH . ' ' . self::PAGE_HEIGHT . '] /Resources << /Font << /F1 4 0 R /F2 5 0 R >>' . $resourceXObject . ' >> /Contents ' . $contentObject . ' 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding /ToUnicode 6 0 R >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding /ToUnicode 6 0 R >>',
            '<< /Length ' . strlen($toUnicode) . " >>\nstream\n" . $toUnicode . "endstream",
        ];

        foreach ($this->images as $image) {
            $objects[] = '<< /Type /XObject /Subtype /Image /Width ' . $image['width']
                . ' /Height ' . $image['height']
                . ' /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /Length '
                . strlen($image['data']) . " >>\nstream\n" . $image['data'] . "\nendstream";
        }

        $objects[] = '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "endstream";

        $pdf = "%PDF-1.4\n";
        $offsets = [0];

        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $number = $index + 1;
            $pdf .= "{$number} 0 obj\n{$object}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";

        for ($i = 1, $count = count($offsets); $i < $count; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xrefOffset}\n%%EOF\n";

        return $pdf;
    }

    private function buildWinAnsiToUnicodeCMap(): string
    {
        $special = [
            0x80 => 0x20AC,
            0x82 => 0x201A,
            0x83 => 0x0192,
            0x84 => 0x201E,
            0x85 => 0x2026,
            0x86 => 0x2020,
            0x87 => 0x2021,
            0x88 => 0x02C6,
            0x89 => 0x2030,
            0x8A => 0x0160,
            0x8B => 0x2039,
            0x8C => 0x0152,
            0x8E => 0x017D,
            0x91 => 0x2018,
            0x92 => 0x2019,
            0x93 => 0x201C,
            0x94 => 0x201D,
            0x95 => 0x2022,
            0x96 => 0x2013,
            0x97 => 0x2014,
            0x98 => 0x02DC,
            0x99 => 0x2122,
            0x9A => 0x0161,
            0x9B => 0x203A,
            0x9C => 0x0153,
            0x9E => 0x017E,
            0x9F => 0x0178,
        ];

        $entries = [];
        for ($code = 0x20; $code <= 0xFF; $code++) {
            if ($code >= 0x81 && $code <= 0x9D && !isset($special[$code])) {
                continue;
            }

            $unicode = $special[$code] ?? $code;
            $entries[] = sprintf('<%02X> <%04X>', $code, $unicode);
        }

        $cmap = "/CIDInit /ProcSet findresource begin\n"
            . "12 dict begin\n"
            . "begincmap\n"
            . "/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
            . "/CMapName /WinAnsiUnicode def\n"
            . "/CMapType 2 def\n"
            . "1 begincodespacerange\n"
            . "<00> <FF>\n"
            . "endcodespacerange\n";

        foreach (array_chunk($entries, 100) as $chunk) {
            $cmap .= count($chunk) . " beginbfchar\n"
                . implode("\n", $chunk) . "\n"
                . "endbfchar\n";
        }

        return $cmap
            . "endcmap\n"
            . "CMapName currentdict /CMap defineresource pop\n"
            . "end\n"
            . "end\n";
    }
}
