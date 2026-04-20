<?php

declare(strict_types=1);

namespace TrilbyMedia\Cap\Tests;

use PHPUnit\Framework\TestCase;
use TrilbyMedia\Cap\Prng;

final class PrngTest extends TestCase
{
    /**
     * @dataProvider vectorsProvider
     */
    public function testMatchesUpstreamVector(string $seed, int $length, string $expected): void
    {
        $this->assertSame($expected, Prng::generate($seed, $length));
    }

    public function testEmptyOutput(): void
    {
        $this->assertSame('', Prng::generate('seed', 0));
    }

    public function testOutputLength(): void
    {
        $this->assertSame(32, strlen(Prng::generate('seed', 32)));
        $this->assertSame(129, strlen(Prng::generate('seed', 129)));
    }

    public static function vectorsProvider(): iterable
    {
        $path = __DIR__ . '/fixtures/prng-vectors.json';
        $data = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        foreach ($data as $vec) {
            yield sprintf('seed=%s len=%d', var_export($vec['seed'], true), $vec['length']) =>
                [$vec['seed'], $vec['length'], $vec['output']];
        }
    }
}
