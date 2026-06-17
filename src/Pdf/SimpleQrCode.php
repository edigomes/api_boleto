<?php

namespace ApiBoleto\Pdf;

class SimpleQrCode
{
    private const ECL_LOW = 1;

    /**
     * Versoes 1 a 10 com correcao L. Cobre payloads PIX comuns sem adicionar dependencia externa.
     *
     * @var array<int, array{data:int,ec:int,blocks:int[],align:int[]}>
     */
    private const VERSION_INFO = [
        1 => ['data' => 19, 'ec' => 7,  'blocks' => [19],             'align' => []],
        2 => ['data' => 34, 'ec' => 10, 'blocks' => [34],             'align' => [6, 18]],
        3 => ['data' => 55, 'ec' => 15, 'blocks' => [55],             'align' => [6, 22]],
        4 => ['data' => 80, 'ec' => 20, 'blocks' => [80],             'align' => [6, 26]],
        5 => ['data' => 108, 'ec' => 26, 'blocks' => [108],           'align' => [6, 30]],
        6 => ['data' => 136, 'ec' => 18, 'blocks' => [68, 68],        'align' => [6, 34]],
        7 => ['data' => 156, 'ec' => 20, 'blocks' => [78, 78],        'align' => [6, 22, 38]],
        8 => ['data' => 194, 'ec' => 24, 'blocks' => [97, 97],        'align' => [6, 24, 42]],
        9 => ['data' => 232, 'ec' => 30, 'blocks' => [116, 116],      'align' => [6, 26, 46]],
        10 => ['data' => 274, 'ec' => 18, 'blocks' => [68, 68, 69, 69], 'align' => [6, 28, 50]],
    ];

    /** @var int[][] */
    private array $matrix = [];

    /** @var bool[][] */
    private array $reserved = [];

    private int $size = 0;

    /** @var int[] */
    private static array $expTable = [];

    /** @var int[] */
    private static array $logTable = [];

    public function renderPng(string $payload, int $scale = 4, int $quietZone = 4): string
    {
        if ($payload === ''
            || !function_exists('imagecreatetruecolor')
            || !function_exists('imagepng')
            || !function_exists('imagefill')
            || !function_exists('imagefilledrectangle')) {
            return '';
        }

        $matrix = $this->encode($payload);
        if ($matrix === []) {
            return '';
        }

        $moduleCount = count($matrix);
        $imageSize = ($moduleCount + ($quietZone * 2)) * $scale;
        $image = imagecreatetruecolor($imageSize, $imageSize);
        if ($image === false) {
            return '';
        }

        $white = imagecolorallocate($image, 255, 255, 255);
        $black = imagecolorallocate($image, 0, 0, 0);
        imagefill($image, 0, 0, $white);

        for ($y = 0; $y < $moduleCount; $y++) {
            for ($x = 0; $x < $moduleCount; $x++) {
                if ($matrix[$y][$x] !== 1) {
                    continue;
                }

                $left = ($x + $quietZone) * $scale;
                $top = ($y + $quietZone) * $scale;
                imagefilledrectangle($image, $left, $top, $left + $scale - 1, $top + $scale - 1, $black);
            }
        }

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return $png;
    }

    /**
     * @return int[][]
     */
    private function encode(string $payload): array
    {
        $version = $this->chooseVersion(strlen($payload));
        if ($version === 0) {
            return [];
        }

        $info = self::VERSION_INFO[$version];
        $this->initializeMatrix($version);
        $this->drawFunctionPatterns($version, $info['align']);

        $dataCodewords = $this->buildDataCodewords($payload, $version, $info['data']);
        $codewords = $this->addErrorCorrection($dataCodewords, $info['blocks'], $info['ec']);

        $this->drawCodewords($codewords);

        $mask = 0;
        $this->applyMask($mask);
        $this->drawFormatBits($mask);

        return $this->matrix;
    }

    private function chooseVersion(int $payloadLength): int
    {
        foreach (self::VERSION_INFO as $version => $info) {
            $countBits = $version <= 9 ? 8 : 16;
            $requiredBits = 4 + $countBits + ($payloadLength * 8);
            if ($requiredBits <= $info['data'] * 8) {
                return $version;
            }
        }

        return 0;
    }

    private function initializeMatrix(int $version): void
    {
        $this->size = 17 + ($version * 4);
        $this->matrix = [];
        $this->reserved = [];

        for ($y = 0; $y < $this->size; $y++) {
            $this->matrix[$y] = array_fill(0, $this->size, -1);
            $this->reserved[$y] = array_fill(0, $this->size, false);
        }
    }

    /**
     * @param int[] $alignment
     */
    private function drawFunctionPatterns(int $version, array $alignment): void
    {
        $this->drawFinderPattern(0, 0);
        $this->drawFinderPattern($this->size - 7, 0);
        $this->drawFinderPattern(0, $this->size - 7);

        for ($i = 8; $i < $this->size - 8; $i++) {
            $dark = $i % 2 === 0;
            $this->setFunctionModule(6, $i, $dark);
            $this->setFunctionModule($i, 6, $dark);
        }

        foreach ($alignment as $centerY) {
            foreach ($alignment as $centerX) {
                $nearTopLeft = $centerX === 6 && $centerY === 6;
                $nearTopRight = $centerX === $this->size - 7 && $centerY === 6;
                $nearBottomLeft = $centerX === 6 && $centerY === $this->size - 7;
                if ($nearTopLeft || $nearTopRight || $nearBottomLeft) {
                    continue;
                }
                $this->drawAlignmentPattern($centerX, $centerY);
            }
        }

        $this->setFunctionModule(8, $this->size - 8, true);
        $this->drawFormatBits(0);

        if ($version >= 7) {
            $this->drawVersionBits($version);
        }
    }

    private function drawFinderPattern(int $left, int $top): void
    {
        for ($dy = -1; $dy <= 7; $dy++) {
            for ($dx = -1; $dx <= 7; $dx++) {
                $x = $left + $dx;
                $y = $top + $dy;
                if ($x < 0 || $x >= $this->size || $y < 0 || $y >= $this->size) {
                    continue;
                }

                $dark = $dx >= 0 && $dx <= 6 && $dy >= 0 && $dy <= 6
                    && ($dx === 0 || $dx === 6 || $dy === 0 || $dy === 6
                        || ($dx >= 2 && $dx <= 4 && $dy >= 2 && $dy <= 4));
                $this->setFunctionModule($x, $y, $dark);
            }
        }
    }

    private function drawAlignmentPattern(int $centerX, int $centerY): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $dark = max(abs($dx), abs($dy)) !== 1;
                $this->setFunctionModule($centerX + $dx, $centerY + $dy, $dark);
            }
        }
    }

    private function drawFormatBits(int $mask): void
    {
        $data = (self::ECL_LOW << 3) | $mask;
        $bits = (($data << 10) | $this->calculateBchCode($data, 0x537, 10)) ^ 0x5412;

        for ($i = 0; $i <= 5; $i++) {
            $this->setFunctionModule(8, $i, (($bits >> $i) & 1) !== 0);
        }
        $this->setFunctionModule(8, 7, (($bits >> 6) & 1) !== 0);
        $this->setFunctionModule(8, 8, (($bits >> 7) & 1) !== 0);
        $this->setFunctionModule(7, 8, (($bits >> 8) & 1) !== 0);

        for ($i = 9; $i < 15; $i++) {
            $this->setFunctionModule(14 - $i, 8, (($bits >> $i) & 1) !== 0);
        }

        for ($i = 0; $i < 8; $i++) {
            $this->setFunctionModule($this->size - 1 - $i, 8, (($bits >> $i) & 1) !== 0);
        }

        for ($i = 8; $i < 15; $i++) {
            $this->setFunctionModule(8, $this->size - 15 + $i, (($bits >> $i) & 1) !== 0);
        }

        $this->setFunctionModule(8, $this->size - 8, true);
    }

    private function drawVersionBits(int $version): void
    {
        $bits = ($version << 12) | $this->calculateBchCode($version, 0x1F25, 12);

        for ($i = 0; $i < 18; $i++) {
            $dark = (($bits >> $i) & 1) !== 0;
            $a = $this->size - 11 + ($i % 3);
            $b = intdiv($i, 3);
            $this->setFunctionModule($a, $b, $dark);
            $this->setFunctionModule($b, $a, $dark);
        }
    }

    private function setFunctionModule(int $x, int $y, bool $dark): void
    {
        if ($x < 0 || $x >= $this->size || $y < 0 || $y >= $this->size) {
            return;
        }

        $this->matrix[$y][$x] = $dark ? 1 : 0;
        $this->reserved[$y][$x] = true;
    }

    /**
     * @return int[]
     */
    private function buildDataCodewords(string $payload, int $version, int $dataCapacity): array
    {
        $bits = [];
        $this->appendBits($bits, 0x4, 4);
        $this->appendBits($bits, strlen($payload), $version <= 9 ? 8 : 16);

        for ($i = 0, $length = strlen($payload); $i < $length; $i++) {
            $this->appendBits($bits, ord($payload[$i]), 8);
        }

        $capacityBits = $dataCapacity * 8;
        $terminator = min(4, $capacityBits - count($bits));
        for ($i = 0; $i < $terminator; $i++) {
            $bits[] = 0;
        }

        while (count($bits) % 8 !== 0) {
            $bits[] = 0;
        }

        $codewords = [];
        for ($i = 0, $length = count($bits); $i < $length; $i += 8) {
            $byte = 0;
            for ($j = 0; $j < 8; $j++) {
                $byte = ($byte << 1) | $bits[$i + $j];
            }
            $codewords[] = $byte;
        }

        $pad = 0xEC;
        while (count($codewords) < $dataCapacity) {
            $codewords[] = $pad;
            $pad = $pad === 0xEC ? 0x11 : 0xEC;
        }

        return $codewords;
    }

    /**
     * @param int[] $bits
     */
    private function appendBits(array &$bits, int $value, int $length): void
    {
        for ($i = $length - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    }

    /**
     * @param int[] $dataCodewords
     * @param int[] $blockLengths
     * @return int[]
     */
    private function addErrorCorrection(array $dataCodewords, array $blockLengths, int $eccLength): array
    {
        $dataBlocks = [];
        $eccBlocks = [];
        $offset = 0;

        foreach ($blockLengths as $length) {
            $block = array_slice($dataCodewords, $offset, $length);
            $dataBlocks[] = $block;
            $eccBlocks[] = $this->reedSolomonRemainder($block, $eccLength);
            $offset += $length;
        }

        $result = [];
        $maxDataLength = max($blockLengths);
        for ($i = 0; $i < $maxDataLength; $i++) {
            foreach ($dataBlocks as $block) {
                if (isset($block[$i])) {
                    $result[] = $block[$i];
                }
            }
        }

        for ($i = 0; $i < $eccLength; $i++) {
            foreach ($eccBlocks as $block) {
                $result[] = $block[$i];
            }
        }

        return $result;
    }

    /**
     * @param int[] $codewords
     */
    private function drawCodewords(array $codewords): void
    {
        $bits = [];
        foreach ($codewords as $byte) {
            $this->appendBits($bits, $byte, 8);
        }

        $bitIndex = 0;
        $upward = true;

        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right--;
            }

            for ($vertical = 0; $vertical < $this->size; $vertical++) {
                $y = $upward ? $this->size - 1 - $vertical : $vertical;
                for ($column = 0; $column < 2; $column++) {
                    $x = $right - $column;
                    if ($this->reserved[$y][$x]) {
                        continue;
                    }

                    $this->matrix[$y][$x] = $bitIndex < count($bits) ? $bits[$bitIndex] : 0;
                    $bitIndex++;
                }
            }

            $upward = !$upward;
        }
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                if ($this->reserved[$y][$x] || !$this->maskApplies($mask, $x, $y)) {
                    continue;
                }

                $this->matrix[$y][$x] = ($this->matrix[$y][$x] === 1) ? 0 : 1;
            }
        }
    }

    private function maskApplies(int $mask, int $x, int $y): bool
    {
        switch ($mask) {
            case 0:
                return (($x + $y) % 2) === 0;
            case 1:
                return ($y % 2) === 0;
            case 2:
                return ($x % 3) === 0;
            case 3:
                return (($x + $y) % 3) === 0;
            case 4:
                return ((intdiv($y, 2) + intdiv($x, 3)) % 2) === 0;
            case 5:
                return ((($x * $y) % 2) + (($x * $y) % 3)) === 0;
            case 6:
                return (((($x * $y) % 2) + (($x * $y) % 3)) % 2) === 0;
            case 7:
                return (((($x + $y) % 2) + (($x * $y) % 3)) % 2) === 0;
        }

        return false;
    }

    /**
     * @param int[] $data
     * @return int[]
     */
    private function reedSolomonRemainder(array $data, int $degree): array
    {
        $generator = $this->reedSolomonGenerator($degree);
        $remainder = array_fill(0, $degree, 0);

        foreach ($data as $byte) {
            $factor = $byte ^ $remainder[0];
            array_shift($remainder);
            $remainder[] = 0;

            for ($i = 0; $i < $degree; $i++) {
                $remainder[$i] ^= $this->gfMultiply($generator[$i + 1], $factor);
            }
        }

        return $remainder;
    }

    /**
     * @return int[]
     */
    private function reedSolomonGenerator(int $degree): array
    {
        $result = [1];
        for ($i = 0; $i < $degree; $i++) {
            $result = $this->polyMultiply($result, [1, $this->gfPow($i)]);
        }

        return $result;
    }

    /**
     * @param int[] $left
     * @param int[] $right
     * @return int[]
     */
    private function polyMultiply(array $left, array $right): array
    {
        $result = array_fill(0, count($left) + count($right) - 1, 0);
        foreach ($left as $i => $leftValue) {
            foreach ($right as $j => $rightValue) {
                $result[$i + $j] ^= $this->gfMultiply($leftValue, $rightValue);
            }
        }

        return $result;
    }

    private function gfPow(int $power): int
    {
        $this->initializeGalois();

        return self::$expTable[$power % 255];
    }

    private function gfMultiply(int $left, int $right): int
    {
        if ($left === 0 || $right === 0) {
            return 0;
        }

        $this->initializeGalois();

        return self::$expTable[self::$logTable[$left] + self::$logTable[$right]];
    }

    private function initializeGalois(): void
    {
        if (self::$expTable !== []) {
            return;
        }

        $value = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$expTable[$i] = $value;
            self::$logTable[$value] = $i;
            $value <<= 1;
            if (($value & 0x100) !== 0) {
                $value ^= 0x11D;
            }
        }

        for ($i = 255; $i < 512; $i++) {
            self::$expTable[$i] = self::$expTable[$i - 255];
        }
    }

    private function calculateBchCode(int $value, int $polynomial, int $degree): int
    {
        $remainder = $value << $degree;
        $polyLength = $this->bitLength($polynomial);

        while ($this->bitLength($remainder) >= $polyLength) {
            $remainder ^= $polynomial << ($this->bitLength($remainder) - $polyLength);
        }

        return $remainder;
    }

    private function bitLength(int $value): int
    {
        $length = 0;
        while ($value > 0) {
            $length++;
            $value >>= 1;
        }

        return $length;
    }
}
