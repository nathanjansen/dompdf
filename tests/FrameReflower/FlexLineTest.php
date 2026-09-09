<?php
namespace Dompdf\Tests\FrameReflower;

use Dompdf\FrameReflower\FlexLine;
use Dompdf\Tests\TestCase;

class FlexLineTest extends TestCase
{
    public function testRejectsUnrepresentableFreeSpace(): void
    {
        $this->expectException(\OverflowException::class);
        FlexLine::resolve([
            ["base" => 0.0, "hypothetical" => 0.0, "min" => 0.0, "max" => INF, "outer" => -1.0e308, "grow" => 1.0, "shrink" => 1.0],
            ["base" => 0.0, "hypothetical" => 0.0, "min" => 0.0, "max" => INF, "outer" => -1.0e308, "grow" => 1.0, "shrink" => 1.0],
        ], 1.0e308, 0.0);
    }

    /**
     * @dataProvider resolveCases
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('resolveCases')]
    public function testResolveFlexibleLengths(string $name, array $items, float $inner, float $gap, array $expected): void
    {
        $sizes = FlexLine::resolve($items, $inner, $gap);

        $this->assertCount(count($expected), $sizes, $name);
        foreach ($expected as $index => $value) {
            $this->assertTrue(is_finite($sizes[$index]), $name . " item " . $index);
            $tolerance = $name === "tiny grow factors" ? 1.0e-12 : 0.01;
            $this->assertTrue(abs($sizes[$index] - $value) <= $tolerance, $name . " item " . $index);
        }
        if ($name === "tiny grow factors") {
            $this->assertGreaterThan(0.0, $sizes[0]);
            $this->assertGreaterThan(0.0, $sizes[1]);
        }
    }

    public static function resolveCases(): array
    {
        $infinity = INF;
        return [
            ["N01", [["base" => 100.0, "hypothetical" => 100.0, "min" => 0.0, "max" => $infinity, "outer" => 0.0, "grow" => 1.0, "shrink" => 1.0], ["base" => 100.0, "hypothetical" => 100.0, "min" => 0.0, "max" => $infinity, "outer" => 0.0, "grow" => 1.0, "shrink" => 1.0]], 300.0, 0.0, [150.0, 150.0]],
            ["N02", [["base" => 200.0, "hypothetical" => 200.0, "min" => 0.0, "max" => $infinity, "outer" => 0.0, "grow" => 1.0, "shrink" => 1.0], ["base" => 100.0, "hypothetical" => 100.0, "min" => 0.0, "max" => $infinity, "outer" => 0.0, "grow" => 1.0, "shrink" => 1.0]], 200.0, 0.0, [133.333333, 66.666667]],
            ["N03", [["base" => 100.0, "hypothetical" => 100.0, "min" => 0.0, "max" => 120.0, "outer" => 0.0, "grow" => 1.0, "shrink" => 1.0], ["base" => 100.0, "hypothetical" => 100.0, "min" => 0.0, "max" => $infinity, "outer" => 0.0, "grow" => 1.0, "shrink" => 1.0]], 300.0, 0.0, [120.0, 180.0]],
            ["N04", [["base" => 100.0, "hypothetical" => 100.0, "min" => 90.0, "max" => $infinity, "outer" => 0.0, "grow" => 1.0, "shrink" => 1.0], ["base" => 100.0, "hypothetical" => 100.0, "min" => 0.0, "max" => $infinity, "outer" => 0.0, "grow" => 1.0, "shrink" => 1.0]], 150.0, 0.0, [90.0, 60.0]],
            ["N05", [["base" => 0.0, "hypothetical" => 0.0, "min" => 0.0, "max" => $infinity, "outer" => 0.0, "grow" => 0.25, "shrink" => 1.0], ["base" => 0.0, "hypothetical" => 0.0, "min" => 0.0, "max" => $infinity, "outer" => 0.0, "grow" => 0.25, "shrink" => 1.0]], 200.0, 0.0, [50.0, 50.0]],
            ["N06", [["base" => 0.0, "hypothetical" => 0.0, "min" => 0.0, "max" => $infinity, "outer" => 0.0, "grow" => 0.0, "shrink" => 1.0], ["base" => 0.0, "hypothetical" => 0.0, "min" => 0.0, "max" => $infinity, "outer" => 0.0, "grow" => 0.0, "shrink" => 1.0]], 20.0, 0.0, [0.0, 0.0]],
            ["N07", [["base" => 100.0, "hypothetical" => 100.0, "min" => 0.0, "max" => $infinity, "outer" => 0.0, "grow" => 1.0, "shrink" => 1.0], ["base" => 100.0, "hypothetical" => 100.0, "min" => 0.0, "max" => $infinity, "outer" => 0.0, "grow" => 1.0, "shrink" => 1.0]], 220.0, 20.0, [100.0, 100.0]],
            ["N08", [["base" => 100.0, "hypothetical" => 100.0, "min" => 0.0, "max" => $infinity, "outer" => 20.0, "grow" => 1.0, "shrink" => 1.0], ["base" => 100.0, "hypothetical" => 100.0, "min" => 0.0, "max" => $infinity, "outer" => 20.0, "grow" => 1.0, "shrink" => 1.0]], 240.0, 0.0, [100.0, 100.0]],
            ["N09", [["base" => 80.0, "hypothetical" => 80.0, "min" => 0.0, "max" => $infinity, "outer" => 0.0, "grow" => 0.0, "shrink" => 0.0], ["base" => 80.0, "hypothetical" => 80.0, "min" => 0.0, "max" => $infinity, "outer" => 0.0, "grow" => 0.0, "shrink" => 0.0]], 100.0, 0.0, [80.0, 80.0]],
            ["N10", [["base" => 20.0, "hypothetical" => 20.0, "min" => 0.0, "max" => $infinity, "outer" => 0.0, "grow" => 1.0, "shrink" => 1.0], ["base" => 20.0, "hypothetical" => 20.0, "min" => 0.0, "max" => $infinity, "outer" => 0.0, "grow" => 1.0, "shrink" => 1.0]], 0.0, 0.0, [0.0, 0.0]],
            ["E01 subunit shrink", [["base" => 100.0, "hypothetical" => 100.0, "min" => 0.0, "max" => $infinity, "outer" => 0.0, "grow" => 0.0, "shrink" => 0.25], ["base" => 100.0, "hypothetical" => 100.0, "min" => 0.0, "max" => $infinity, "outer" => 0.0, "grow" => 0.0, "shrink" => 0.25]], 100.0, 0.0, [75.0, 75.0]],
            ["E02 positive mixed violation", [["base" => 100.0, "hypothetical" => 140.0, "min" => 140.0, "max" => $infinity, "outer" => 0.0, "grow" => 1.0, "shrink" => 1.0], ["base" => 100.0, "hypothetical" => 100.0, "min" => 0.0, "max" => 110.0, "outer" => 0.0, "grow" => 1.0, "shrink" => 1.0], ["base" => 100.0, "hypothetical" => 100.0, "min" => 0.0, "max" => $infinity, "outer" => 0.0, "grow" => 1.0, "shrink" => 1.0]], 360.0, 0.0, [140.0, 110.0, 110.0]],
            ["E03 negative mixed violation", [["base" => 100.0, "hypothetical" => 125.0, "min" => 125.0, "max" => $infinity, "outer" => 0.0, "grow" => 1.0, "shrink" => 1.0], ["base" => 100.0, "hypothetical" => 100.0, "min" => 0.0, "max" => 110.0, "outer" => 0.0, "grow" => 1.0, "shrink" => 1.0], ["base" => 100.0, "hypothetical" => 100.0, "min" => 0.0, "max" => $infinity, "outer" => 0.0, "grow" => 1.0, "shrink" => 1.0]], 360.0, 0.0, [125.0, 110.0, 125.0]],
            ["E04 zero violation", [["base" => 100.0, "hypothetical" => 140.0, "min" => 140.0, "max" => $infinity, "outer" => 0.0, "grow" => 1.0, "shrink" => 1.0], ["base" => 100.0, "hypothetical" => 100.0, "min" => 0.0, "max" => 100.0, "outer" => 0.0, "grow" => 1.0, "shrink" => 1.0], ["base" => 100.0, "hypothetical" => 100.0, "min" => 0.0, "max" => $infinity, "outer" => 0.0, "grow" => 1.0, "shrink" => 1.0]], 360.0, 0.0, [140.0, 100.0, 120.0]],
            ["E05 negative outer", [["base" => 100.0, "hypothetical" => 100.0, "min" => 0.0, "max" => $infinity, "outer" => -20.0, "grow" => 1.0, "shrink" => 1.0], ["base" => 100.0, "hypothetical" => 100.0, "min" => 0.0, "max" => $infinity, "outer" => -20.0, "grow" => 1.0, "shrink" => 1.0]], 200.0, 0.0, [120.0, 120.0]],
            ["E06 negative base", [["base" => -20.0, "hypothetical" => 0.0, "min" => 0.0, "max" => $infinity, "outer" => 0.0, "grow" => 1.0, "shrink" => 1.0], ["base" => 100.0, "hypothetical" => 100.0, "min" => 0.0, "max" => $infinity, "outer" => 0.0, "grow" => 1.0, "shrink" => 1.0]], 200.0, 0.0, [40.0, 160.0]],
            ["E07 zero scaled-shrink sum", [["base" => 0.0, "hypothetical" => 0.0, "min" => 0.0, "max" => $infinity, "outer" => 10.0, "grow" => 0.0, "shrink" => 1.0], ["base" => 0.0, "hypothetical" => 0.0, "min" => 0.0, "max" => $infinity, "outer" => 10.0, "grow" => 0.0, "shrink" => 1.0]], 10.0, 0.0, [0.0, 0.0]],
            ["E08 empty line", [], 100.0, 0.0, []],
            ["subunit shrink uses unscaled factors", [["base" => 200.0, "hypothetical" => 200.0, "min" => 0.0, "max" => $infinity, "outer" => 0.0, "grow" => 0.0, "shrink" => 0.25], ["base" => 100.0, "hypothetical" => 100.0, "min" => 0.0, "max" => $infinity, "outer" => 0.0, "grow" => 0.0, "shrink" => 0.25]], 200.0, 0.0, [166.666667, 83.333333]],
            ["initial grow inflexible freeze", [["base" => 120.0, "hypothetical" => 100.0, "min" => 0.0, "max" => 100.0, "outer" => 0.0, "grow" => 1.0, "shrink" => 1.0], ["base" => 100.0, "hypothetical" => 100.0, "min" => 0.0, "max" => $infinity, "outer" => 0.0, "grow" => 1.0, "shrink" => 1.0]], 300.0, 0.0, [100.0, 200.0]],
            ["initial shrink inflexible freeze", [["base" => 50.0, "hypothetical" => 100.0, "min" => 100.0, "max" => $infinity, "outer" => 0.0, "grow" => 1.0, "shrink" => 1.0], ["base" => 100.0, "hypothetical" => 100.0, "min" => 0.0, "max" => $infinity, "outer" => 0.0, "grow" => 1.0, "shrink" => 1.0]], 180.0, 0.0, [100.0, 80.0]],
            ["initial free space is retained", [["base" => 0.0, "hypothetical" => 0.0, "min" => 0.0, "max" => 20.0, "outer" => 0.0, "grow" => 0.25, "shrink" => 1.0], ["base" => 0.0, "hypothetical" => 0.0, "min" => 0.0, "max" => $infinity, "outer" => 0.0, "grow" => 0.25, "shrink" => 1.0]], 200.0, 0.0, [20.0, 50.0]],
            ["large factors remain finite", [["base" => 100.0, "hypothetical" => 100.0, "min" => 0.0, "max" => $infinity, "outer" => 0.0, "grow" => 1.0e308, "shrink" => 1.0], ["base" => 100.0, "hypothetical" => 100.0, "min" => 0.0, "max" => $infinity, "outer" => 0.0, "grow" => 1.0e308, "shrink" => 1.0]], 300.0, 0.0, [150.0, 150.0]],
            ["large scaled shrink factors remain finite", [["base" => 1.0e200, "hypothetical" => 1.0e200, "min" => 0.0, "max" => $infinity, "outer" => 0.0, "grow" => 1.0, "shrink" => 1.0e200], ["base" => 1.0e200, "hypothetical" => 1.0e200, "min" => 0.0, "max" => $infinity, "outer" => 0.0, "grow" => 1.0, "shrink" => 1.0e200]], 0.0, 0.0, [0.0, 0.0]],
            ["tiny grow factors", [["base" => 0.0, "hypothetical" => 0.0, "min" => 0.0, "max" => $infinity, "outer" => 0.0, "grow" => 1.0e-6, "shrink" => 1.0], ["base" => 0.0, "hypothetical" => 0.0, "min" => 0.0, "max" => $infinity, "outer" => 0.0, "grow" => 1.0e-6, "shrink" => 1.0]], 200.0, 0.0, [0.0002, 0.0002]],
        ];
    }
}
