<?php

namespace Tests\Unit;

use App\Support\UrlPath;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UrlPathTest extends TestCase
{
    public function test_plain_values_pass_and_reserved_characters_are_encoded(): void
    {
        $this->assertSame('106540352242922', UrlPath::segment('106540352242922'));
        $this->assertSame('gemini-2.5-flash', UrlPath::segment('gemini-2.5-flash'));
        $this->assertSame('a%3Fkey%3Dx%23y%20z', UrlPath::segment('a?key=x#y z'));
        $this->assertSame('%40evil.example', UrlPath::segment('@evil.example'));
    }

    public static function unsafe(): array
    {
        return [
            'empty' => [''], 'dot' => ['.'], 'dotdot' => ['..'], 'slash' => ['a/b'], 'backslash' => ['a\\b'],
            'newline' => ["a\nb"], 'nul' => ["a\0b"], 'del' => ["a\x7Fb"], 'too long' => [str_repeat('9', 129)],
        ];
    }

    #[DataProvider('unsafe')]
    public function test_unsafe_segments_are_refused(string $value): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('UNSAFE_PATH_SEGMENT');

        UrlPath::segment($value);
    }
}
