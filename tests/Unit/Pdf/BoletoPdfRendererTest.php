<?php

namespace ApiBoleto\Tests\Unit\Pdf;

use ApiBoleto\DTO\BoletoResponse;
use ApiBoleto\Exceptions\BoletoException;
use ApiBoleto\Pdf\BoletoPdfRenderer;
use PHPUnit\Framework\TestCase;

class BoletoPdfRendererTest extends TestCase
{
    public function testRenderGeraPdfComLinhaDigitavelECodigoBarras(): void
    {
        $boleto = BoletoResponse::fromArray([
            'nossoNumero' => '43977574',
            'codigoBarras' => '34199148200000010001094397757400364104497000',
            'linhaDigitavel' => '34191094389775740036741044970006914820000001000',
            'valor' => '00000000000001000',
            'vencimento' => '2026-06-19',
            'dadosOriginais' => [
                'data' => [
                    'beneficiario' => [
                        'id_beneficiario' => '123400123451',
                        'nome_cobranca' => 'CHLOROPHYLLA FHYTOCOSMETICA',
                    ],
                    'dado_boleto' => [
                        'codigo_carteira' => '109',
                        'pagador' => [
                            'pessoa' => [
                                'nome_pessoa' => 'Cliente Teste API',
                                'tipo_pessoa' => [
                                    'codigo_tipo_pessoa' => 'F',
                                    'numero_cadastro_pessoa_fisica' => '12345678909',
                                ],
                            ],
                        ],
                        'dados_individuais_boleto' => [[
                            'texto_seu_numero' => 'TESTEAPI02',
                        ]],
                    ],
                ],
            ],
        ]);

        $pdf = (new BoletoPdfRenderer())->render($boleto, [
            'bankName' => 'Banco Itau S.A.',
            'bankCode' => '341',
        ]);

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('34191.09438 97757.400367 41044.970006 9 14820000001000', $pdf);
        $this->assertSame(2, substr_count($pdf, '34191.09438 97757.400367 41044.970006 9 14820000001000'));
        $this->assertStringContainsString('Ficha de Compensacao', $pdf);
        $this->assertStringContainsString('/Encoding /WinAnsiEncoding', $pdf);
        $this->assertStringContainsString('/ToUnicode 6 0 R', $pdf);
        $this->assertTrue(
            strpos($pdf, 'Banco Itau S.A.') !== false || strpos($pdf, '/XObject') !== false,
            'O PDF deve renderizar o nome do banco em texto ou usar o logo como imagem.'
        );
        $this->assertStringContainsString('%%EOF', $pdf);
    }

    public function testRenderDerivaCodigoBarrasDaLinhaDigitavel(): void
    {
        $boleto = BoletoResponse::fromArray([
            'nossoNumero' => '000027',
            'linhaDigitavel' => '34191090080000270036741044970006714530000001200',
            'valor' => '12.00',
            'vencimento' => '2026-05-21',
            'dadosOriginais' => [
                'data' => [
                    'beneficiario' => [
                        'id_beneficiario' => '123400123451',
                        'nome_cobranca' => 'CHLOROPHYLLA FHYTOCOSMETICA',
                    ],
                    'dado_boleto' => [
                        'codigo_carteira' => '109',
                        'pagador' => [
                            'pessoa' => [
                                'nome_pessoa' => 'Edimario Gomes',
                            ],
                        ],
                        'dados_individuais_boleto' => [[
                            'texto_seu_numero' => '106',
                        ]],
                    ],
                ],
            ],
        ]);

        $pdf = (new BoletoPdfRenderer())->render($boleto, [
            'bankName' => 'Banco Itau S.A.',
            'bankCode' => '341',
        ]);

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('34191.09008 00002.700367 41044.970006 7 14530000001200', $pdf);
        $this->assertStringContainsString(' re f', $pdf);
    }

    public function testRenderIncluiImagemQrQuandoApiRetornaBase64(): void
    {
        if (!function_exists('imagecreatefromstring') || !function_exists('gzcompress')) {
            $this->markTestSkipped('GD/zlib indisponivel para validar imagem QR no PDF.');
        }

        $boleto = BoletoResponse::fromArray([
            'nossoNumero' => '43977574',
            'codigoBarras' => '34199148200000010001094397757400364104497000',
            'linhaDigitavel' => '34191094389775740036741044970006914820000001000',
            'valor' => '00000000000001000',
            'vencimento' => '2026-06-19',
            'qrCodePix' => '000201BRGOVBCBPIX',
            'dadosOriginais' => [
                'data' => [
                    'dados_qrcode' => [
                        'base64' => 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
                    ],
                    'beneficiario' => [
                        'id_beneficiario' => '123400123451',
                        'nome_cobranca' => 'CHLOROPHYLLA FHYTOCOSMETICA',
                    ],
                    'dado_boleto' => [
                        'codigo_carteira' => '109',
                        'pagador' => [
                            'pessoa' => [
                                'nome_pessoa' => 'Cliente Teste API',
                            ],
                        ],
                        'dados_individuais_boleto' => [[
                            'texto_seu_numero' => 'TESTEAPI02',
                        ]],
                    ],
                ],
            ],
        ]);

        $pdf = (new BoletoPdfRenderer())->render($boleto, [
            'bankName' => 'Banco Itau S.A.',
            'bankCode' => '341',
        ]);

        $this->assertStringContainsString('/XObject', $pdf);
        $this->assertStringContainsString('/Im1', $pdf);
        $this->assertStringContainsString('/Im2', $pdf);
    }

    public function testRenderSantanderIncluiDadosDoBancoEImagemQrPorPayloadPix(): void
    {
        if (!function_exists('imagecreatefromstring')
            || !function_exists('imagecreatetruecolor')
            || !function_exists('imagepng')
            || !function_exists('gzcompress')) {
            $this->markTestSkipped('GD/zlib indisponivel para validar imagem QR no PDF.');
        }

        $boleto = BoletoResponse::fromArray([
            'nossoNumero' => '000000007841',
            'linhaDigitavel' => '03399021994950000000200784101016990180000000620',
            'valor' => '6.20',
            'vencimento' => '2022-06-16',
            'qrCodePix' => '00020101021226860014br.gov.bcb.pix2564pix.example.com/qr/v2/12352040000530398654046.205802BR5909VENDEDOR6009SAO PAULO62070503***6304ABCD',
            'dadosOriginais' => [
                'covenantCode' => '02199495',
                'bankNumber' => '000000007841',
                'clientNumber' => 'NF0001258993862',
                'issueDate' => '2022-06-09',
                'documentKind' => 'DUPLICATA_MERCANTIL',
                'beneficiary' => [
                    'name' => 'VENCU EQUIPAMENTOS LTDA',
                    'documentNumber' => '72927528000111',
                ],
                'payer' => [
                    'name' => 'CARLOS HK',
                    'documentNumber' => '00000125893862',
                    'address' => 'AV DAS MACOES',
                    'neighborhood' => 'CENTRO',
                    'city' => 'SAO PAULO',
                    'state' => 'SP',
                    'zipCode' => '04795-100',
                ],
                'messages' => ['Teste Santander com PIX.'],
            ],
        ]);

        $pdf = (new BoletoPdfRenderer())->render($boleto, [
            'bankName' => 'Banco Santander S.A.',
            'bankCode' => '033',
        ]);

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('033-7', $pdf);
        $this->assertStringContainsString('Pagavel preferencialmente no Banco Santander', $pdf);
        $this->assertStringContainsString('NF0001258993862', $pdf);
        $this->assertStringContainsString('VENCU EQUIPAMENTOS LTDA', $pdf);
        $this->assertStringContainsString('/XObject', $pdf);
        $this->assertStringContainsString('/Im1', $pdf);
        $this->assertStringContainsString('/Im2', $pdf);
        $this->assertStringContainsString('371.00 159.00 64.00 64.00 re S', $pdf);
        $this->assertStringNotContainsString('379.00 79.00 68.00 68.00 re S', $pdf);
    }

    public function testRenderSantanderFormataValorInteiroComoReais(): void
    {
        $boleto = BoletoResponse::fromArray([
            'nossoNumero' => '10417',
            'linhaDigitavel' => '03399079417600000000001041701010914920000307500',
            'valor' => '3075',
            'vencimento' => '2026-06-29',
            'dadosOriginais' => [
                'covenantCode' => '0794760',
                'bankNumber' => '10417',
                'clientNumber' => '615',
                'issueDate' => '2026-06-15',
                'documentKind' => 'DM',
                'beneficiary' => [
                    'name' => 'DISTRIBUIDORA BOI REAL LTDA',
                    'documentNumber' => '64726807000199',
                ],
                'payer' => [
                    'name' => 'SUPERMERCADO BEM ESTAR NAZARE LTDA',
                    'documentNumber' => '54098347000148',
                    'address' => 'RUA GENERAL OSORIO',
                ],
            ],
        ]);

        $pdf = (new BoletoPdfRenderer())->render($boleto, [
            'bankName' => 'Banco Santander S.A.',
            'bankCode' => '033',
        ]);

        $this->assertStringContainsString('R$ 3.075,00', $pdf);
        $this->assertStringNotContainsString('R$ 30,75', $pdf);
    }

    public function testRenderCodificaAcentosComoWinAnsiComMapaUnicode(): void
    {
        $nome = 'Edim' . "\xC3\xA1" . 'rio Gomes';
        $endereco = 'Rua Capit' . "\xC3\xA3" . 'o Rebelinho, 396';
        $boleto = BoletoResponse::fromArray([
            'nossoNumero' => '10731757',
            'codigoBarras' => '34196148300000010001091073175770364104497000',
            'linhaDigitavel' => '34191091077317577036841044970006614830000001000',
            'valor' => '00000000000001000',
            'vencimento' => '2026-06-20',
            'dadosOriginais' => [
                'data' => [
                    'beneficiario' => [
                        'id_beneficiario' => '123400123451',
                        'nome_cobranca' => 'CHLOROPHYLLA FHYTOCOSMETICA',
                    ],
                    'dado_boleto' => [
                        'codigo_carteira' => '109',
                        'pagador' => [
                            'pessoa' => [
                                'nome_pessoa' => $nome,
                                'tipo_pessoa' => [
                                    'codigo_tipo_pessoa' => 'F',
                                    'numero_cadastro_pessoa_fisica' => '12345678909',
                                ],
                            ],
                            'endereco' => [
                                'nome_logradouro' => $endereco,
                                'nome_bairro' => 'Pina',
                                'nome_cidade' => 'Recife',
                                'sigla_UF' => 'PE',
                                'numero_CEP' => '51011010',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $pdf = (new BoletoPdfRenderer())->render($boleto, [
            'bankName' => 'Banco Itau S.A.',
            'bankCode' => '341',
        ]);

        $this->assertStringContainsString('Edim' . "\xE1" . 'rio Gomes', $pdf);
        $this->assertStringContainsString('Rua Capit' . "\xE3" . 'o Rebelinho', $pdf);
        $this->assertStringNotContainsString($nome, $pdf);
        $this->assertStringContainsString('<E1> <00E1>', $pdf);
        $this->assertStringContainsString('<E3> <00E3>', $pdf);
    }

    public function testRenderSemDadosDePagamentoLancaExcecao(): void
    {
        $this->expectException(BoletoException::class);

        (new BoletoPdfRenderer())->render(new BoletoResponse());
    }
}
