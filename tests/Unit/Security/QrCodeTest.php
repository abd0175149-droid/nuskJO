<?php

namespace Tests\Unit\Security;

use App\Support\QrCode;
use PHPUnit\Framework\TestCase;

/**
 * QR على خادمنا (E27): المصفوفة مطابقة وحدة بوحدة لرمز مرجعي (ترميز بايت، مستوى M، الإصدار 3، القناع 5)،
 * والإصدار يكبر مع النص حتى 40، والـ SVG بلا أي مرجع خارجي.
 */
class QrCodeTest extends TestCase
{
    private const REFERENCE_DATA = 'otpauth://totp/A:b?secret=JBSWY3DPEHPK3PXP';

    private const REFERENCE = [
        '#######....#.#.#.#....#######',
        '#.....#.###......##.#.#.....#',
        '#.###.#.#####..#..##..#.###.#',
        '#.###.#.#..##..###..#.#.###.#',
        '#.###.#..##.##.##.###.#.###.#',
        '#.....#..##.##..#...#.#.....#',
        '#######.#.#.#.#.#.#.#.#######',
        '........##..#.#.#...#........',
        '#.....#.##..##.#.#...##..###.',
        '..##.#..##.........#.#.##.#..',
        '###.#.####.#.#.#....#..#.#...',
        '#...##.#...#..#...##...#.#...',
        '.###.###..##.#.#..##..##...##',
        '###..#.##.#...#.##.#######.##',
        '.#...##..#.##..#....##..##...',
        '#.#...........#....#..#####.#',
        '##..#.#...###.##...###.#.##..',
        '#####...#..####.####..####.##',
        '#######.#..#.###..###....##.#',
        '#.#.##...#.#...#..##.###.#.##',
        '#...###..#....###...#####.#.#',
        '........##.....##.#.#...#....',
        '#######..#..#..######.#.#....',
        '#.....#...#.#.....###...#...#',
        '#.###.#....#.##..#.######..#.',
        '#.###.#..#.###..#...##....#.#',
        '#.###.#.....#######.##..#.##.',
        '#.....#..#..##.#...#..##..#.#',
        '#######.#.#..####......#..#..',
    ];

    private static function rows(array $matrix): array
    {
        return array_map(fn (array $row) => implode('', array_map(fn (bool $dark) => $dark ? '#' : '.', $row)), $matrix);
    }

    public function test_matrix_matches_a_reference_symbol_module_for_module(): void
    {
        $this->assertSame(self::REFERENCE, self::rows(QrCode::matrix(self::REFERENCE_DATA, 5)));
    }

    public function test_version_grows_with_the_payload_and_keeps_the_finder_patterns(): void
    {
        $sizes = [];
        foreach ([14 => 21, 15 => 25, 213 => 57, 214 => 61, 2331 => 177] as $bytes => $size) {
            $matrix = QrCode::matrix(str_repeat('a', $bytes));
            $sizes[$bytes] = count($matrix);
            foreach ([[0, 0], [count($matrix) - 7, 0], [0, count($matrix) - 7]] as [$x, $y]) {
                $this->assertSame('#######', implode('', array_map(fn ($d) => $d ? '#' : '.', array_slice($matrix[$y], $x, 7))));
            }
        }
        $this->assertSame([14 => 21, 15 => 25, 213 => 57, 214 => 61, 2331 => 177], $sizes);

        $this->expectException(\InvalidArgumentException::class);
        QrCode::matrix(str_repeat('a', 2332));
    }

    public function test_svg_is_self_contained_dark_on_white(): void
    {
        $svg = QrCode::svg(self::REFERENCE_DATA);
        $xml = simplexml_load_string($svg);

        $this->assertNotFalse($xml);
        $this->assertSame('0 0 37 37', (string) $xml['viewBox']);   // 29 + هامش 4 من كل جهة
        $this->assertStringContainsString('fill="#ffffff"', $svg);
        $this->assertDoesNotMatchRegularExpression('/href|<script|<image|url\(|https?:\/\/(?!www\.w3\.org)/i', $svg);
    }
}
