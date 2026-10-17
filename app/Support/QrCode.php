<?php

namespace App\Support;

/**
 * رمز QR يُرسم SVG على خادمنا (E27): رابط otpauth فيه سر TOTP، فلا يمر بأي خدمة QR خارجية ولا بحزمة.
 * ترميز بايت بمستوى تصحيح M، والإصدار الأصغر الذي يتسع (1–40)، وأفضل قناع بحسب نقاط ISO/IEC 18004.
 * الخوارزمية على نهج مرجع Project Nayuki (MIT)، والمصفوفة تُختبر مقابل رموز معروفة في QrCodeTest.
 */
final class QrCode
{
    /** كلمات التصحيح لكل كتلة وعدد الكتل لمستوى M، بالإصدار (الفهرس 0 غير مستعمل) */
    private const ECC_PER_BLOCK = [-1, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28];

    private const NUM_BLOCKS = [-1, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49];

    /** بتّا مستوى التصحيح في معلومات الصيغة: L=1 M=0 Q=3 H=2 */
    private const ECL_FORMAT_BITS = 0;

    private int $version;

    private int $size;

    /** @var list<list<bool>> [y][x] */
    private array $modules = [];

    /** @var list<list<bool>> */
    private array $isFunction = [];

    private function __construct(string $data, ?int $forcedMask)
    {
        $this->version = self::versionFor(strlen($data));
        $this->size = $this->version * 4 + 17;
        $row = array_fill(0, $this->size, false);
        $this->modules = array_fill(0, $this->size, $row);
        $this->isFunction = array_fill(0, $this->size, $row);

        $this->drawFunctionPatterns();
        $this->drawCodewords($this->addEccAndInterleave($this->dataCodewords($data)));

        $mask = $forcedMask;
        if ($mask === null) {
            $best = PHP_INT_MAX;
            for ($i = 0; $i < 8; $i++) {
                $this->applyMask($i);
                $this->drawFormatBits($i);
                $penalty = $this->penaltyScore();
                if ($penalty < $best) {
                    [$mask, $best] = [$i, $penalty];
                }
                $this->applyMask($i);   // XOR يعيدها كما كانت
            }
        }
        $this->applyMask($mask);
        $this->drawFormatBits($mask);
    }

    /** المصفوفة (true = داكن)، والقناع null = الأفضل. للاختبارات والرسم @return list<list<bool>> */
    public static function matrix(string $data, ?int $mask = null): array
    {
        return (new self($data, $mask))->modules;
    }

    /** SVG بخلفية بيضاء وهامش هادئ 4 وحدات: الماسح يحتاج داكناً على فاتح حتى في الوضع الليلي */
    public static function svg(string $data, int $border = 4): string
    {
        $modules = self::matrix($data);
        $size = count($modules);
        $dim = $size + $border * 2;
        $path = '';
        foreach ($modules as $y => $row) {
            $x = 0;
            while ($x < $size) {
                if (!$row[$x]) {
                    $x++;

                    continue;
                }
                $start = $x;
                while ($x < $size && $row[$x]) {
                    $x++;
                }
                $len = $x - $start;
                $path .= 'M'.($start + $border).' '.($y + $border).'h'.$len.'v1h-'.$len.'z';
            }
        }

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.$dim.' '.$dim.'" width="'.($dim * 6).'" height="'.($dim * 6).'" shape-rendering="crispEdges">'
            .'<rect width="100%" height="100%" fill="#ffffff"/><path fill="#000000" d="'.$path.'"/></svg>';
    }

    // ==================== الترميز ====================

    private static function versionFor(int $bytes): int
    {
        for ($v = 1; $v <= 40; $v++) {
            $needed = 4 + ($v <= 9 ? 8 : 16) + $bytes * 8;
            if ($needed <= self::numDataCodewords($v) * 8) {
                return $v;
            }
        }

        throw new \InvalidArgumentException('النص أطول من سعة QR');
    }

    private static function numRawDataModules(int $ver): int
    {
        $result = (16 * $ver + 128) * $ver + 64;
        if ($ver >= 2) {
            $numAlign = intdiv($ver, 7) + 2;
            $result -= (25 * $numAlign - 10) * $numAlign - 55;
            if ($ver >= 7) {
                $result -= 36;
            }
        }

        return $result;
    }

    private static function numDataCodewords(int $ver): int
    {
        return intdiv(self::numRawDataModules($ver), 8) - self::ECC_PER_BLOCK[$ver] * self::NUM_BLOCKS[$ver];
    }

    /** @return list<int> */
    private function dataCodewords(string $data): array
    {
        $bits = [];
        $append = function (int $value, int $length) use (&$bits): void {
            for ($i = $length - 1; $i >= 0; $i--) {
                $bits[] = ($value >> $i) & 1;
            }
        };
        $append(0b0100, 4);   // وضع البايت
        $append(strlen($data), $this->version <= 9 ? 8 : 16);
        foreach (str_split($data) as $char) {
            $append(ord($char), 8);
        }

        $capacity = self::numDataCodewords($this->version) * 8;
        $append(0, min(4, $capacity - count($bits)));
        $append(0, (8 - count($bits) % 8) % 8);
        for ($pad = 0xEC; count($bits) < $capacity; $pad ^= 0xEC ^ 0x11) {
            $append($pad, 8);
        }

        $out = [];
        foreach (array_chunk($bits, 8) as $byte) {
            $out[] = (int) bindec(implode('', $byte));
        }

        return $out;
    }

    /** @param list<int> $data @return list<int> */
    private function addEccAndInterleave(array $data): array
    {
        $numBlocks = self::NUM_BLOCKS[$this->version];
        $eccLen = self::ECC_PER_BLOCK[$this->version];
        $raw = intdiv(self::numRawDataModules($this->version), 8);
        $numShort = $numBlocks - $raw % $numBlocks;
        $shortLen = intdiv($raw, $numBlocks);

        $divisor = self::rsDivisor($eccLen);
        $blocks = [];
        for ($i = 0, $k = 0; $i < $numBlocks; $i++) {
            $len = $shortLen - $eccLen + ($i < $numShort ? 0 : 1);
            $dat = array_slice($data, $k, $len);
            $k += $len;
            $ecc = self::rsRemainder($dat, $divisor);
            if ($i < $numShort) {
                $dat[] = 0;   // موضع وهمي يساوي الأطوال، ويُتخطى في التشبيك
            }
            $blocks[] = array_merge($dat, $ecc);
        }

        $result = [];
        $blockLen = count($blocks[0]);
        for ($i = 0; $i < $blockLen; $i++) {
            foreach ($blocks as $j => $block) {
                if ($i !== $shortLen - $eccLen || $j >= $numShort) {
                    $result[] = $block[$i];
                }
            }
        }

        return $result;
    }

    /** @return list<int> */
    private static function rsDivisor(int $degree): array
    {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;
        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = self::rsMultiply($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = self::rsMultiply($root, 0x02);
        }

        return $result;
    }

    /** @param list<int> $data @param list<int> $divisor @return list<int> */
    private static function rsRemainder(array $data, array $divisor): array
    {
        $result = array_fill(0, count($divisor), 0);
        foreach ($data as $b) {
            $factor = $b ^ array_shift($result);
            $result[] = 0;
            foreach ($divisor as $i => $coef) {
                $result[$i] ^= self::rsMultiply($coef, $factor);
            }
        }

        return $result;
    }

    /** ضرب في GF(2^8) بكثير الحدود 0x11D */
    private static function rsMultiply(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = ($z << 1) ^ (($z >> 7) * 0x11D);
            $z ^= (($y >> $i) & 1) * $x;
        }

        return $z;
    }

    // ==================== الرسم ====================

    private function setFunction(int $x, int $y, bool $dark): void
    {
        $this->modules[$y][$x] = $dark;
        $this->isFunction[$y][$x] = true;
    }

    private function drawFunctionPatterns(): void
    {
        for ($i = 0; $i < $this->size; $i++) {
            $this->setFunction(6, $i, $i % 2 === 0);
            $this->setFunction($i, 6, $i % 2 === 0);
        }

        $this->drawFinder(3, 3);
        $this->drawFinder($this->size - 4, 3);
        $this->drawFinder(3, $this->size - 4);

        $positions = $this->alignmentPositions();
        $n = count($positions);
        foreach ($positions as $i => $px) {
            foreach ($positions as $j => $py) {
                if (!(($i === 0 && $j === 0) || ($i === 0 && $j === $n - 1) || ($i === $n - 1 && $j === 0))) {
                    $this->drawAlignment($px, $py);
                }
            }
        }

        $this->drawFormatBits(0);   // حجز المواضع، وتُكتب القيمة بعد اختيار القناع
        $this->drawVersion();
    }

    private function drawFinder(int $x, int $y): void
    {
        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $xx = $x + $dx;
                $yy = $y + $dy;
                if ($xx >= 0 && $xx < $this->size && $yy >= 0 && $yy < $this->size) {
                    $dist = max(abs($dx), abs($dy));
                    $this->setFunction($xx, $yy, $dist !== 2 && $dist !== 4);
                }
            }
        }
    }

    private function drawAlignment(int $x, int $y): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $this->setFunction($x + $dx, $y + $dy, max(abs($dx), abs($dy)) !== 1);
            }
        }
    }

    /** @return list<int> */
    private function alignmentPositions(): array
    {
        if ($this->version === 1) {
            return [];
        }
        $numAlign = intdiv($this->version, 7) + 2;
        $step = intdiv($this->version * 8 + $numAlign * 3 + 5, $numAlign * 4 - 4) * 2;
        $result = [];
        for ($i = 0; $i < $numAlign - 1; $i++) {
            $result[] = $this->size - 7 - $i * $step;
        }
        $result[] = 6;

        return array_reverse($result);
    }

    private function drawFormatBits(int $mask): void
    {
        $data = self::ECL_FORMAT_BITS << 3 | $mask;
        $rem = $data;
        for ($i = 0; $i < 10; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 9) * 0x537);
        }
        $bits = ($data << 10 | $rem) ^ 0x5412;
        $bit = fn (int $i) => (($bits >> $i) & 1) !== 0;

        for ($i = 0; $i <= 5; $i++) {
            $this->setFunction(8, $i, $bit($i));
        }
        $this->setFunction(8, 7, $bit(6));
        $this->setFunction(8, 8, $bit(7));
        $this->setFunction(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->setFunction(14 - $i, 8, $bit($i));
        }

        for ($i = 0; $i < 8; $i++) {
            $this->setFunction($this->size - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->setFunction(8, $this->size - 15 + $i, $bit($i));
        }
        $this->setFunction(8, $this->size - 8, true);   // الوحدة الداكنة الثابتة
    }

    private function drawVersion(): void
    {
        if ($this->version < 7) {
            return;
        }
        $rem = $this->version;
        for ($i = 0; $i < 12; $i++) {
            $rem = ($rem << 1) ^ (($rem >> 11) * 0x1F25);
        }
        $bits = $this->version << 12 | $rem;
        for ($i = 0; $i < 18; $i++) {
            $dark = (($bits >> $i) & 1) !== 0;
            $a = $this->size - 11 + $i % 3;
            $b = intdiv($i, 3);
            $this->setFunction($a, $b, $dark);
            $this->setFunction($b, $a, $dark);
        }
    }

    /** @param list<int> $data */
    private function drawCodewords(array $data): void
    {
        $total = count($data) * 8;
        $i = 0;
        for ($right = $this->size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5;
            }
            for ($vert = 0; $vert < $this->size; $vert++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    $upward = (($right + 1) & 2) === 0;
                    $y = $upward ? $this->size - 1 - $vert : $vert;
                    if (!$this->isFunction[$y][$x] && $i < $total) {
                        $this->modules[$y][$x] = (($data[$i >> 3] >> (7 - ($i & 7))) & 1) !== 0;
                        $i++;
                    }
                }
            }
        }
    }

    private function applyMask(int $mask): void
    {
        for ($y = 0; $y < $this->size; $y++) {
            for ($x = 0; $x < $this->size; $x++) {
                $invert = match ($mask) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };
                if ($invert && !$this->isFunction[$y][$x]) {
                    $this->modules[$y][$x] = !$this->modules[$y][$x];
                }
            }
        }
    }

    // ==================== نقاط القناع ====================

    private function penaltyScore(): int
    {
        $result = 0;
        $size = $this->size;
        $m = $this->modules;

        foreach ([false, true] as $columns) {
            for ($a = 0; $a < $size; $a++) {
                $runColor = false;
                $runLen = 0;
                $history = array_fill(0, 7, 0);
                for ($b = 0; $b < $size; $b++) {
                    $cell = $columns ? $m[$b][$a] : $m[$a][$b];
                    if ($cell === $runColor) {
                        $runLen++;
                        if ($runLen === 5) {
                            $result += 3;
                        } elseif ($runLen > 5) {
                            $result++;
                        }
                    } else {
                        $this->finderAddHistory($runLen, $history);
                        if (!$runColor) {
                            $result += $this->finderCount($history) * 40;
                        }
                        $runColor = $cell;
                        $runLen = 1;
                    }
                }
                $result += $this->finderTerminate($runColor, $runLen, $history) * 40;
            }
        }

        for ($y = 0; $y < $size - 1; $y++) {
            for ($x = 0; $x < $size - 1; $x++) {
                $c = $m[$y][$x];
                if ($c === $m[$y][$x + 1] && $c === $m[$y + 1][$x] && $c === $m[$y + 1][$x + 1]) {
                    $result += 3;
                }
            }
        }

        $dark = 0;
        foreach ($m as $row) {
            $dark += count(array_filter($row));
        }
        $total = $size * $size;
        $k = intdiv(abs($dark * 20 - $total * 10) + $total - 1, $total) - 1;

        return $result + $k * 10;
    }

    /** @param list<int> $history */
    private function finderCount(array $history): int
    {
        $n = $history[1];
        $core = $n > 0 && $history[2] === $n && $history[3] === $n * 3 && $history[4] === $n && $history[5] === $n;

        return ($core && $history[0] >= $n * 4 && $history[6] >= $n ? 1 : 0)
            + ($core && $history[6] >= $n * 4 && $history[0] >= $n ? 1 : 0);
    }

    /** @param list<int> $history */
    private function finderTerminate(bool $runColor, int $runLen, array &$history): int
    {
        if ($runColor) {
            $this->finderAddHistory($runLen, $history);
            $runLen = 0;
        }
        $runLen += $this->size;
        $this->finderAddHistory($runLen, $history);

        return $this->finderCount($history);
    }

    /** @param list<int> $history */
    private function finderAddHistory(int $runLen, array &$history): void
    {
        if ($history[0] === 0) {
            $runLen += $this->size;
        }
        array_pop($history);
        array_unshift($history, $runLen);
    }
}
