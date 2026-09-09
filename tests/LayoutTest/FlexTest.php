<?php
namespace Dompdf\Tests\LayoutTest;

use DOMElement;
use Dompdf\Dompdf;
use Dompdf\Tests\TestCase;
use Symfony\Component\Process\Process;

class FlexTest extends TestCase
{
    private function layout(string $body, string $css = "", ?int $maxPages = null): array
    {
        $boxes = [];
        $text = [];
        $bullets = [];
        $dompdf = new Dompdf();
        $dompdf->setCallbacks([["event" => "begin_frame", "f" => function ($frame, $canvas) use (&$boxes, &$text, &$bullets, $maxPages) {
            $node = $frame->get_node();
            $page = $canvas->get_page_number();
            if ($maxPages !== null && $page > $maxPages) {
                $this->fail("Layout exceeded the expected $maxPages page(s)");
            }
            if ($node instanceof DOMElement && $node->hasAttribute("data-test")) {
                $box = $frame->get_border_box();
                $content = $frame->get_content_box();
                $boxes[$node->getAttribute("data-test")][] = [
                    "page" => $page, "box" => [$box["x"], $box["y"], $box["w"], $box["h"]],
                    "content" => [$content["x"], $content["y"], $content["w"], $content["h"]],
                    "class" => get_class($frame), "block" => $frame->is_block(),
                    "cb_width" => $frame->get_containing_block("w"),
                    "specified_width" => $frame->get_style()->get_specified("width")
                ];
                if ($frame instanceof \Dompdf\FrameDecorator\Flex) {
                    $source = [];
                    foreach ($frame->get_children() as $child) {
                        $source[] = $child->get_node()->getAttribute("data-test");
                    }
                    $boxes[$node->getAttribute("data-test")][count($boxes[$node->getAttribute("data-test")]) - 1]["source"] = $source;
                }
            }
            if ($frame->is_text_node() && trim($node->nodeValue) !== "") {
                $text[$page][] = [trim($node->nodeValue), $frame->get_position("x"), $frame->get_position("y")];
            }
            if ($node->nodeName === "bullet") {
                $bullets[] = $node->getAttribute("dompdf-counter");
            }
        }]]);
        $dompdf->loadHtml('<!doctype html><html><head><style>@page {size:400pt 400pt;margin:0} '
            . 'html,body {margin:0;padding:0} body {font:10pt/20pt Times-Roman} * {box-sizing:content-box}'
            . $css . '</style></head><body>' . $body . '</body></html>');
        $dompdf->render();
        return ["boxes" => $boxes, "text" => $text, "bullets" => $bullets, "pages" => $dompdf->getCanvas()->get_page_count(), "pdf" => $dompdf->output()];
    }

    private function assertBox(array $result, string $id, array $expected, int $fragment = 0): void
    {
        $this->assertArrayHasKey($id, $result["boxes"]);
        foreach ($expected as $axis => $value) {
            $this->assertEqualsWithDelta($value, $result["boxes"][$id][$fragment]["box"][$axis], 0.01, "$id axis $axis");
        }
    }

    public function testFixedRow(): void
    {
        $result = $this->layout('<div data-test="row" style="display:flex;width:300pt;align-items:flex-start">'
            . '<div data-test="a" style="flex:0 0 100pt;height:20pt">A</div>'
            . '<div data-test="b" style="flex:0 0 100pt;height:20pt">B</div></div><div data-test="after" style="height:10pt">after</div>');
        $this->assertCount(1, $result["boxes"]["a"]);
        $this->assertCount(1, $result["boxes"]["b"]);
        $this->assertBox($result, "a", [0, 0, 100, 20]);
        $this->assertBox($result, "b", [100, 0, 100, 20]);
        $this->assertBox($result, "row", [0, 0, 300, 20]);
        $this->assertBox($result, "after", [0, 20, 400, 10]);
        $this->assertFalse($result["boxes"]["row"][0]["block"]);
        $this->assertSame(["A", "B", "after"], array_column($result["text"][1], 0));
        $this->assertEquals(100, $result["text"][1][1][1]);
    }

    public static function flexibleSizeProvider(): array
    {
        return [
            "N01 grow" => [300, "flex:1 1 100pt", "flex:1 1 100pt", 150, 150],
            "N02 scaled shrink" => [200, "flex:0 1 200pt", "flex:0 1 100pt", 400 / 3, 200 / 3],
            "N03 max freeze" => [300, "flex:1 1 100pt;max-width:120pt", "flex:1 1 100pt", 120, 180],
            "N04 min freeze" => [150, "flex:0 1 100pt;min-width:90pt", "flex:0 1 100pt", 90, 60],
            "N05 subunit" => [200, "flex:.25 1 0pt", "flex:.25 1 0pt", 50, 50],
            "min wins max" => [200, "flex:1 1 100pt;min-width:140pt;max-width:120pt", "flex:1 1 100pt", 140, 60]
        ];
    }

    /** @dataProvider flexibleSizeProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('flexibleSizeProvider')]
    public function testFlexibleSizesReachRealBoxes(float $width, string $a, string $b, float $aWidth, float $bWidth): void
    {
        $result = $this->layout('<div data-test="row" style="display:flex;width:' . $width . 'pt">'
            . '<div data-test="a" style="min-width:0;height:20pt;' . $a . '">A</div>'
            . '<div data-test="b" style="min-width:0;height:20pt;' . $b . '">B</div></div><div data-test="after">AFTER</div>');
        $this->assertBox($result, "a", [0, 0, $aWidth, 20]);
        $this->assertBox($result, "b", [$aWidth, 0, $bWidth, 20]);
        $this->assertBox($result, "after", [0, 20, 400, 19.8]);
        $this->assertSame(["A", "B", "AFTER"], array_column($result["text"][1], 0));
        $this->assertEqualsWithDelta($aWidth, $result["text"][1][1][1], 0.01);
    }

    public function testFlexedWidthControlsTextWrappingAndPercentageDescendant(): void
    {
        $control = $this->layout('<div data-test="text" style="width:25pt">AAAA AAAA AAAA AAAA</div>', 'body {font:10pt/10pt Courier}');
        $result = $this->layout('<div style="display:flex;width:100pt"><div data-test="a" style="width:200pt;flex:1 1 100pt;min-width:0">'
            . '<div data-test="child" style="width:50%">AAAA AAAA AAAA AAAA</div></div>'
            . '<div data-test="b" style="flex:1 1 100pt;min-width:0">B</div></div><div data-test="after">AFTER</div>',
            'body {font:10pt/10pt Courier}');
        $this->assertEqualsWithDelta(50, $result["boxes"]["a"][0]["content"][2], 0.01);
        $this->assertEqualsWithDelta(25, $result["boxes"]["child"][0]["content"][2], 0.01);
        $this->assertSame("200pt", $result["boxes"]["a"][0]["specified_width"]);
        $this->assertSame(["AAAA", "AAAA", "AAAA", "AAAA", "B", "AFTER"], array_column($result["text"][1], 0));
        $this->assertEqualsWithDelta($control["text"][1][3][2], $result["text"][1][3][2], 0.01);
        $this->assertEqualsWithDelta($control["boxes"]["text"][0]["box"][3], $result["boxes"]["after"][0]["box"][1], 0.01);
    }

    public static function automaticMinimumProvider(): array
    {
        return [
            "auto" => ["", 180, 0],
            "explicit zero" => ["min-width:0", 0, 100],
            "preferred width cap" => ["width:80pt", 80, 20],
            "maximum cap" => ["max-width:70pt", 70, 30],
            "hidden is scrollable" => ["overflow:hidden", 0, 100],
            "auto is scrollable" => ["overflow:auto", 0, 100],
            "scroll is scrollable" => ["overflow:scroll", 0, 100],
            "clip is non-scrollable" => ["overflow:clip", 180, 0]
        ];
    }

    /** @dataProvider automaticMinimumProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('automaticMinimumProvider')]
    public function testAutomaticMinimumUsesContentNotDefiniteBasis(string $constraint, float $aWidth, float $bWidth): void
    {
        // Courier has a literal 6pt advance at 10pt: 30 unbreakable glyphs = 180pt.
        $result = $this->layout('<div style="display:flex;width:100pt"><div data-test="a" style="flex:1 1 0pt;' . $constraint . '">'
            . str_repeat('M', 30) . '</div><div data-test="b" style="flex:1 1 100pt;min-width:0">B</div></div>', 'body {font:10pt Courier}');
        $this->assertEqualsWithDelta($aWidth, $result["boxes"]["a"][0]["content"][2], 0.01);
        $this->assertEqualsWithDelta($bWidth, $result["boxes"]["b"][0]["content"][2], 0.01);
        $this->assertEqualsWithDelta($aWidth, $result["boxes"]["b"][0]["box"][0], 0.01);
    }

    public static function sizingEdgesProvider(): array
    {
        return [
            "negative border-box base" => ["box-sizing:border-box;flex:1 1 0pt;padding:0 10pt;border:2pt solid", 76, 100, 200],
            "zero content-box base" => ["box-sizing:content-box;flex:1 1 0pt;padding:0 10pt;border:2pt solid", 88, 112, 188],
            "percentage border-box basis and edges" => ["box-sizing:border-box;flex:1 1 25%;padding:0 10%;border:2pt solid;margin:0 10pt", 63.5, 147.5, 152.5],
            "percentage content-box basis and edges" => ["box-sizing:content-box;flex:1 1 25%;padding:0 10%;border:2pt solid;margin:0 10pt", 95.5, 179.5, 120.5]
        ];
    }

    /** @dataProvider sizingEdgesProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('sizingEdgesProvider')]
    public function testBoxSizingConvertsBaseWithoutDoubleCountingEdges(string $a, float $aWidth, float $bX, float $bWidth): void
    {
        $result = $this->layout('<div style="display:flex;width:300pt"><div data-test="a" style="min-width:0;' . $a
            . '">A</div><div data-test="b" style="min-width:0;flex:1 1 100pt">B</div></div>');
        $this->assertEqualsWithDelta($aWidth, $result["boxes"]["a"][0]["content"][2], 0.01);
        $this->assertEqualsWithDelta($bX, $result["boxes"]["b"][0]["box"][0], 0.01);
        $this->assertEqualsWithDelta($bWidth, $result["boxes"]["b"][0]["content"][2], 0.01);
        $this->assertEquals(300, $result["boxes"]["a"][0]["cb_width"]);
    }

    public static function intrinsicBasisProvider(): array
    {
        return ["min" => ["min-content", 24], "max" => ["max-content", 114],
            "content" => ["content", 114], "fit percent" => ["fit-content(25%)", 50], "fit" => ["fit-content", 114]];
    }

    /** @dataProvider intrinsicBasisProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('intrinsicBasisProvider')]
    public function testIntrinsicBasisMeasuresContentWithoutOwnWidthOrPercentageEdges(string $basis, float $expected): void
    {
        $result = $this->layout('<div style="display:flex;width:200pt"><div data-test="a" style="width:160pt;min-width:0;flex:0 0 ' . $basis
            . ';padding:0 10%">AAAA AAAA AAAA AAAA</div><div data-test="b" style="flex:0 0 10pt">B</div></div>', 'body {font:10pt Courier}');
        $this->assertEqualsWithDelta($expected, $result["boxes"]["a"][0]["content"][2], 0.01);
        $this->assertEqualsWithDelta($expected + 40, $result["boxes"]["b"][0]["box"][0], 0.01);
        $this->assertSame("160pt", $result["boxes"]["a"][0]["specified_width"]);
    }

    public function testInlineFlexIsOneAtomicOuterInline(): void
    {
        $result = $this->layout('<span data-test="before" style="display:inline-block;width:20pt;height:20pt">P</span>'
            . '<span data-test="row" style="display:inline-flex;width:100pt;vertical-align:top">'
            . '<span data-test="a" style="width:40pt;height:20pt">A</span><span data-test="b" style="width:60pt;height:20pt">B</span></span>'
            . '<span data-test="after" style="display:inline-block;width:20pt;height:20pt;vertical-align:top">Q</span>');
        $this->assertBox($result, "row", [20, 0, 100, 20]);
        $this->assertBox($result, "a", [20, 0, 40, 20]);
        $this->assertBox($result, "b", [60, 0, 60, 20]);
        $this->assertBox($result, "after", [120, 0, 20, 20]);
        $this->assertSame(["P", "A", "B", "Q"], array_column($result["text"][1], 0));
    }

    public function testAnonymousTextAndNonItemsRetainRealContent(): void
    {
        $result = $this->layout('<div data-test="row" style="display:flex;width:200pt;position:relative"> '
            . '<span data-test="a" style="width:40pt;height:20pt;float:left">A</span> '
            . '<span style="display:none">HIDDEN</span><span data-test="b" style="width:50pt;height:20pt">B</span>'
            . '<div style="position:absolute;left:150pt;top:40pt">ABS</div>'
            . '<div style="position:fixed;left:150pt;top:60pt">FIX</div>text</div>');
        $this->assertBox($result, "a", [0, 0, 40, 20]);
        $this->assertBox($result, "b", [40, 0, 50, 20]);
        $tokens = array_column($result["text"][1], 0);
        sort($tokens);
        $this->assertSame(["A", "ABS", "B", "FIX", "text"], $tokens);
        foreach ($result["text"][1] as [$token, $x]) {
            if ($token === "text") {
                $this->assertEquals(90, $x);
            }
        }
    }

    public function testAssignedWidthKeepsEdgesAndParentPercentageReference(): void
    {
        $result = $this->layout('<div style="display:flex;width:300pt">'
            . '<div data-test="a" style="width:200pt;flex:0 0 100pt;margin-left:10pt;margin-right:5pt;padding:0 10pt;border:1pt solid">A</div>'
            . '<div data-test="b" style="width:50pt;padding-left:10%;height:20pt">B</div></div>');
        $this->assertBox($result, "a", [10, 0, 122, 21.8]);
        $this->assertEquals(100, $result["boxes"]["a"][0]["content"][2]);
        $this->assertEquals(300, $result["boxes"]["a"][0]["cb_width"]);
        $this->assertSame("200pt", $result["boxes"]["a"][0]["specified_width"]);
        $this->assertBox($result, "b", [137, 0, 80, 20]);
        $this->assertEquals(167, $result["text"][1][1][1]);
    }

    public function testPublicRowContinuationsKeepSlotsAndFollowingContent(): void
    {
        foreach ([[8, 6, false], [8, 3, false], [3, 8, false], [3, 3, true]] as [$aCount, $bCount, $forced]) {
            $html = '<div style="display:flex;width:200pt;align-items:flex-start">';
            foreach (["A" => $aCount, "B" => $bCount] as $prefix => $count) {
                $html .= '<div data-test="' . $prefix . '" style="flex:0 0 100pt;min-width:0">';
                for ($i = 1; $i <= $count; $i++) {
                    $break = $forced && $prefix === "A" && $i === 2 ? 'page-break-before:always;' : '';
                    $html .= '<div style="height:20pt;page-break-inside:avoid;' . $break . '">' . $prefix . $i . '</div>';
                }
                $html .= '</div>';
            }
            $result = $this->layout($html . '</div><div>AFTER</div>', '@page {size:200pt 100pt}');
            $this->assertSame(2, $result["pages"]);
            $expected = $forced ? [1 => ["A1", "B1", "B2", "B3"], 2 => ["A2", "A3", "AFTER"]] : [
                1 => array_merge(array_map(function ($i) { return "A" . $i;
                }, range(1, min(5, $aCount))), array_map(function ($i) { return "B" . $i; }, range(1, min(5, $bCount)))),
                2 => array_merge($aCount > 5 ? array_map(function ($i) { return "A" . $i;
                }, range(6, $aCount)) : [], $bCount > 5 ? array_map(function ($i) { return "B" . $i; }, range(6, $bCount)) : [], ["AFTER"])
            ];
            foreach ($expected as $page => $tokens) {
                $this->assertSame($tokens, array_column($result["text"][$page], 0));
                foreach ($result["text"][$page] as [$token, $x, $y]) {
                    $this->assertEquals($token[0] === "B" ? 100 : 0, $x);
                    $this->assertLessThan(100, $y);
                }
                $process = new Process(["gs", "-q", "-dBATCH", "-dNOPAUSE", "-sDEVICE=txtwrite", "-dFirstPage=$page", "-dLastPage=$page", "-sOutputFile=-", "-"]);
                $process->setInput($result["pdf"]);
                $process->mustRun();
                preg_match_all('/\b(?:[AB][1-8]|AFTER)\b/', $process->getOutput(), $matches);
                sort($tokens);
                sort($matches[0]);
                $this->assertSame($tokens, $matches[0]);
            }
            foreach ($result["boxes"] as $fragments) {
                foreach ($fragments as $fragment) {
                    $this->assertEquals(100, $fragment["content"][2]);
                }
            }
        }
    }

    private function image(): string
    {
        return 'data:image/svg+xml;base64,' . base64_encode('<svg xmlns="http://www.w3.org/2000/svg" width="200" height="100"><rect width="200" height="100" fill="red"/></svg>');
    }

    public function testDirectImageKeepsAssignedWidthAndIntrinsicRatio(): void
    {
        $result = $this->layout('<div style="display:flex;width:200pt"><img data-test="image" src="' . $this->image()
            . '" style="width:200pt;flex:0 0 80pt;min-width:0"><div data-test="b" style="width:50pt;height:20pt">B</div></div>');
        $this->assertBox($result, "image", [0, 0, 80, 40]);
        $this->assertBox($result, "b", [80, 0, 50, 20]);
        $this->assertSame(\Dompdf\FrameDecorator\Image::class, $result["boxes"]["image"][0]["class"]);
        $this->assertSame("200pt", $result["boxes"]["image"][0]["specified_width"]);
    }

    public static function replacedSizingProvider(): array
    {
        return [
            "default minimum not basis cap" => [200, "width:200pt;flex:0 0 80pt", "flex:0 0 50pt", 150, 75, 50],
            "transferred auto basis" => [150, "height:40pt;flex:1 1 auto", "flex:1 1 100pt", 80, 40, 70],
            "preferred cap on transfer" => [150, "width:60pt;height:40pt;flex:0 1 auto", "flex:1 1 100pt", 60, 40, 90],
            "cross maximum clamps auto height" => [150, "flex:0 0 80pt;min-width:0;max-height:20pt", "flex:1 1 100pt", 80, 20, 70],
            "cross minimum clamps auto height" => [150, "flex:0 0 80pt;min-width:0;min-height:60pt", "flex:1 1 100pt", 80, 60, 70],
            "main maximum caps auto minimum" => [150, "flex:1 1 auto;max-width:60pt", "flex:1 1 100pt", 60, 30, 90],
            "auto basis cross maximum" => [300, "flex:none;max-height:20pt", "flex:1 1 100pt", 40, 20, 260],
            "auto basis cross minimum" => [300, "flex:none;min-height:100pt", "flex:1 1 100pt", 200, 100, 100]
        ];
    }

    /** @dataProvider replacedSizingProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('replacedSizingProvider')]
    public function testReplacedSizingUsesNaturalRatioAndSuggestions(float $rowWidth, string $imageStyle, string $peerStyle, float $w, float $h, float $peerWidth): void
    {
        $result = $this->layout('<div style="display:flex;width:' . $rowWidth . 'pt"><img data-test="image" src="' . $this->image()
            . '" style="align-self:flex-start;' . $imageStyle . '"><div data-test="b" style="min-width:0;' . $peerStyle . '">B</div></div>');
        $this->assertBox($result, "image", [0, 0, $w, $h]);
        $this->assertEqualsWithDelta($w, $result["boxes"]["b"][0]["box"][0], 0.01);
        $this->assertEqualsWithDelta($peerWidth, $result["boxes"]["b"][0]["content"][2], 0.01);
    }

    public static function percentageHeightProvider(): array
    {
        return [
            "auto item auto container" => ["", "", 10, 12],
            "auto item definite container" => ["height:100pt", "", 10, 12],
            "definite item" => ["", "height:80pt", 40, 40],
            "max clamped item" => ["", "height:80pt;max-height:40pt", 20, 20],
            "min wins height max" => ["", "height:20pt;min-height:60pt;max-height:40pt", 30, 30],
            "percentage item definite container" => ["height:120pt", "height:50%", 30, 30],
            "percentage item auto container" => ["", "height:50%", 10, 12],
            "percentage container auto parent" => ["height:50%", "height:50%", 10, 12],
            "border-box assigned cross size" => ["", "height:80pt;box-sizing:border-box;padding:10pt;border:2pt solid", 28, 28]
        ];
    }

    /** @dataProvider percentageHeightProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('percentageHeightProvider')]
    public function testPercentageHeightUsesDefiniteContentReference(string $container, string $item, float $imageHeight, float $blockHeight): void
    {
        $result = $this->layout('<div style="display:flex;width:200pt;' . $container . '"><div data-test="item" style="flex:none;width:150pt;align-self:flex-start;' . $item . '">'
            . '<span style="height:999pt"><span><img data-test="image" src="' . $this->image() . '" style="width:20pt;height:50%;vertical-align:top"></span></span>'
            . '<div data-test="percent" style="height:50%"><div style="height:12pt"></div></div></div></div>', '@page {size:400pt 500pt}');
        $this->assertEqualsWithDelta(20, $result["boxes"]["image"][0]["content"][2], 0.01);
        $this->assertEqualsWithDelta($imageHeight, $result["boxes"]["image"][0]["content"][3], 0.01);
        $this->assertEqualsWithDelta($blockHeight, $result["boxes"]["percent"][0]["content"][3], 0.01);
    }

    public function testPercentageFlexContainerUsesDefiniteAncestorHeight(): void
    {
        $result = $this->layout('<section style="height:100pt"><div data-test="row" style="display:flex;width:200pt;height:50%">'
            . '<div data-test="item" style="flex:none;width:100pt;height:50%;align-self:flex-start"><span><img data-test="image" src="' . $this->image()
            . '" style="width:20pt;height:50%"></span></div></div></section>');
        $this->assertEqualsWithDelta(50, $result["boxes"]["row"][0]["content"][3], 0.01);
        $this->assertEqualsWithDelta(25, $result["boxes"]["item"][0]["content"][3], 0.01);
        $this->assertEqualsWithDelta(12.5, $result["boxes"]["image"][0]["content"][3], 0.01);
    }

    public function testPercentageHeightChainDoesNotTurnPageSpaceIntoDefiniteSize(): void
    {
        foreach (["auto" => 10, "80pt" => 5] as $ancestorHeight => $imageHeight) {
            $result = $this->layout('<section style="height:' . $ancestorHeight . '"><div style="height:50%">'
                . '<div style="display:flex;width:200pt;height:50%"><div style="width:100pt;flex:none;align-self:flex-start;height:50%">'
                . '<span><img data-test="image" src="' . $this->image() . '" style="width:20pt;height:50%"></span></div></div></div></section>');
            $this->assertEqualsWithDelta($imageHeight, $result["boxes"]["image"][0]["content"][3], 0.01);
        }
    }

    public static function positionedHeightReferenceProvider(): array
    {
        return [
            "absolute two insets" => ["absolute", "top:0;bottom:0", 37.5],
            "fixed two insets" => ["fixed", "top:0;bottom:0", 37.5],
            "percentage insets and width-relative edges" => ["absolute", "top:10%;bottom:20%;margin:2%;padding:3%;border:2pt solid", 19.5],
            "fixed percentage insets and edges" => ["fixed", "top:10%;bottom:20%;margin:2%;padding:3%;border:2pt solid", 19.5],
            "auto margins" => ["absolute", "top:20pt;bottom:40pt;margin:auto", 30],
            "absolute missing bottom" => ["absolute", "top:0", 10],
            "fixed missing top" => ["fixed", "bottom:0", 10],
            "absolute content height" => ["absolute", "", 10]
        ];
    }

    /** @dataProvider positionedHeightReferenceProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('positionedHeightReferenceProvider')]
    public function testPercentageHeightRecognizesOnlyContentIndependentPositionedHeight(string $position, string $constraints, float $imageHeight): void
    {
        $result = $this->layout('<article style="position:' . $position . ';width:200pt;' . $constraints . '">'
            . '<div data-test="row" style="display:flex;width:100pt;height:50%"><div data-test="item" style="flex:none;width:100pt;height:50%;align-self:flex-start">'
            . '<span style="height:999pt"><img data-test="image" src="' . $this->image() . '" style="width:20pt;height:50%"></span>'
            . '</div></div></article><div>FLOW</div>', '@page {size:500pt 300pt}', 1);
        $this->assertSame(1, $result["pages"]);
        $this->assertEqualsWithDelta(20, $result["boxes"]["image"][0]["content"][2], 0.01);
        $this->assertEqualsWithDelta($imageHeight, $result["boxes"]["image"][0]["content"][3], 0.01);
        if ($imageHeight !== 10.0) {
            $this->assertEqualsWithDelta($imageHeight * 4, $result["boxes"]["row"][0]["content"][3], 0.01);
            $this->assertEqualsWithDelta($imageHeight * 2, $result["boxes"]["item"][0]["content"][3], 0.01);
        }
        $this->assertContains("FLOW", array_column($result["text"][1], 0));
    }

    /** @dataProvider positionedHeightReferenceProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('positionedHeightReferenceProvider')]
    public function testPositionedFlexContainerPassesInsetHeightToItems(string $position, string $constraints, float $ancestorImageHeight): void
    {
        $imageHeight = $ancestorImageHeight === 10.0 ? 10.0 : $ancestorImageHeight * 2;
        $result = $this->layout('<div data-test="row" style="position:' . $position . ';display:flex;width:200pt;' . $constraints . '">'
            . '<div data-test="item" style="flex:none;width:100pt;height:50%;align-self:flex-start"><span><img data-test="image" src="'
            . $this->image() . '" style="width:20pt;height:50%"></span></div></div><div>FLOW</div>', '@page {size:500pt 300pt}', 1);
        $this->assertSame(1, $result["pages"]);
        $this->assertEqualsWithDelta($imageHeight, $result["boxes"]["image"][0]["content"][3], 0.01);
        if ($imageHeight !== 10.0) {
            $this->assertEqualsWithDelta($imageHeight * 4, $result["boxes"]["row"][0]["content"][3], 0.01);
            $this->assertEqualsWithDelta($imageHeight * 2, $result["boxes"]["item"][0]["content"][3], 0.01);
        }
        $this->assertContains("FLOW", array_column($result["text"][1], 0));
    }

    public function testAutoCrossMinimumUsesContentBoxWithoutMakingHeightDefinite(): void
    {
        $result = $this->layout('<div style="display:flex;width:200pt"><div data-test="item" style="flex:none;width:100pt;align-self:flex-start;'
            . 'box-sizing:border-box;padding:10pt;border:2pt solid;min-height:80pt"><div data-test="child" style="height:50%">'
            . '<div style="height:12pt"></div></div></div></div>');
        $this->assertEqualsWithDelta(56, $result["boxes"]["item"][0]["content"][3], 0.01);
        $this->assertEqualsWithDelta(80, $result["boxes"]["item"][0]["box"][3], 0.01);
        $this->assertEqualsWithDelta(12, $result["boxes"]["child"][0]["content"][3], 0.01);
    }

    public function testFlexedContinuationKeepsResolvedOriginalSlot(): void
    {
        $html = '<article><div style="display:flex;width:200pt">';
        foreach (["A" => 3, "B" => 8] as $prefix => $count) {
            $html .= '<div data-test="' . $prefix . '" style="flex:1 1 50pt;min-width:0">';
            for ($i = 1; $i <= $count; $i++) {
                $html .= '<div style="height:20pt;page-break-inside:avoid">' . $prefix . $i . '</div>';
            }
            $html .= '</div>';
        }
        $result = $this->layout($html . '</div></article><div>AFTER</div>', '@page {size:200pt 100pt}');
        $this->assertSame(2, $result["pages"]);
        $this->assertSame(["A1", "A2", "A3", "B1", "B2", "B3", "B4", "B5"], array_column($result["text"][1], 0));
        $this->assertSame(["B6", "B7", "B8", "AFTER"], array_column($result["text"][2], 0));
        $this->assertBox($result, "A", [0, 0, 100, 60]);
        $this->assertBox($result, "B", [100, 0, 100, 60], 1);
    }

    public function testUnrepresentableLineGeometryFailsWithoutPaginationLoop(): void
    {
        $this->expectException(\OverflowException::class);
        $this->layout('<div style="display:flex;width:100pt"><div style="flex:0 0 1e308pt;min-width:0"></div>'
            . '<div style="flex:0 0 1e308pt;min-width:0"></div></div>', '', 1);
    }

    public function testWholeDeferredItemsKeepOriginalSlots(): void
    {
        foreach ([false, true] as $image) {
            $first = $image ? '<img data-test="a" src="' . $this->image() . '" style="flex:0 0 100pt;min-width:0">'
                : '<div data-test="a" style="width:100pt;height:40pt"></div>';
            $result = $this->layout('<div style="height:80pt">BEFORE</div><div style="display:flex;width:200pt">' . $first
                . '<div data-test="b" style="width:100pt;height:20pt">B</div></div><div data-test="after">AFTER</div>', '@page {size:200pt 100pt}');
            $this->assertSame(2, $result["pages"]);
            $this->assertCount(1, $result["boxes"]["a"]);
            $this->assertSame(2, $result["boxes"]["a"][0]["page"]);
            $this->assertBox($result, "a", [0, 0, 100, $image ? 50 : 40]);
            $this->assertBox($result, "b", [100, 80, 100, 20]);
            $this->assertSame(1, $result["boxes"]["b"][0]["page"]);
            $this->assertBox($result, "after", [0, $image ? 50 : 40, 200, 19.8]);
            $this->assertSame(["BEFORE", "B"], array_column($result["text"][1], 0));
            $this->assertSame(["AFTER"], array_column($result["text"][2], 0));
        }
    }

    public function testContainerOwnBoxDefersAndOversizedPageTopProgresses(): void
    {
        foreach (["height:80pt", "padding-bottom:80pt"] as $ownBox) {
            $result = $this->layout('<div style="height:40pt">BEFORE</div><div data-test="row" style="display:flex;width:200pt;' . $ownBox
                . '"><div style="width:50pt;height:0"></div></div><div>AFTER</div>', '@page {size:200pt 100pt}');
            $this->assertSame(2, $result["pages"]);
            $this->assertCount(1, $result["boxes"]["row"]);
            $this->assertSame(2, $result["boxes"]["row"][0]["page"]);
            $this->assertBox($result, "row", [0, 0, 200, 80]);
        }
        foreach (['<div data-test="large" style="height:150pt;width:100pt"></div>', '<img data-test="large" src="' . $this->image() . '" style="width:100pt;height:150pt">'] as $large) {
            $result = $this->layout('<div style="display:flex;width:200pt">' . $large . '</div><div>AFTER</div>', '@page {size:200pt 100pt}');
            $this->assertSame(2, $result["pages"]);
            $this->assertCount(1, $result["boxes"]["large"]);
            $this->assertBox($result, "large", [0, 0, 100, 150]);
            $this->assertSame(1, $result["boxes"]["large"][0]["page"]);
            $this->assertSame(["AFTER"], array_column($result["text"][2], 0));
        }
    }

    public function testNestedFlexContinuationStaysInItsOuterItem(): void
    {
        $html = '<article><div style="display:flex;width:200pt"><div data-test="nested" style="display:flex;flex:0 0 100pt"><div style="width:100pt">';
        for ($i = 1; $i <= 6; $i++) {
            $html .= '<div style="height:20pt;page-break-inside:avoid">A' . $i . '</div>';
        }
        $result = $this->layout($html . '</div></div><div data-test="b" style="width:100pt;height:20pt">B</div></div></article><div>AFTER</div>', '@page {size:200pt 100pt}');
        $this->assertSame(2, $result["pages"]);
        $this->assertSame(["A1", "A2", "A3", "A4", "A5", "B"], array_column($result["text"][1], 0));
        $this->assertSame(["A6", "AFTER"], array_column($result["text"][2], 0));
        $this->assertBox($result, "b", [100, 0, 100, 20]);
        $this->assertBox($result, "nested", [0, 0, 100, 20], 1);
    }

    public static function positionedOverflowProvider(): array
    {
        $cases = [];
        foreach (["absolute", "fixed"] as $position) {
            foreach ([false, true] as $nested) {
                foreach ([false, true] as $tallItem) {
                    $cases["$position nested=$nested tall=$tallItem"] = [$position, $nested, $tallItem];
                }
            }
        }
        return $cases;
    }

    /** @dataProvider positionedOverflowProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('positionedOverflowProvider')]
    public function testPositionedOverflowDoesNotPaginateNormalFlow(string $position, bool $nested, bool $tallItem): void
    {
        foreach (["block", "flex"] as $display) {
            $positioned = "position:$position;top:80pt;left:0;width:100pt";
            $row = "display:$display;width:100pt;" . ($tallItem ? "" : "height:40pt;");
            $content = $tallItem
                ? '<div style="height:20pt">A</div><div style="height:20pt">B</div><div style="height:20pt">C</div>'
                : 'A';
            $html = ($nested ? '<section style="' . $positioned . '">' : '')
                . '<div style="' . $row . ($nested ? '' : $positioned) . '"><div style="width:100pt">' . $content . '</div></div>'
                . ($nested ? '</section>' : '') . '<div data-test="flow">FLOW</div>';
            $result = $this->layout($html, '@page {size:200pt 100pt}', 1);
            $this->assertSame(1, $result["pages"]);
            $this->assertSame(1, $result["boxes"]["flow"][0]["page"]);
            $this->assertBox($result, "flow", [0, 0, 200, 19.8]);
            $tokens = array_column($result["text"][1], 0);
            sort($tokens);
            $this->assertSame($tallItem ? ["A", "B", "C", "FLOW"] : ["A", "FLOW"], $tokens);
        }
    }

    public function testFixedAncestorSuppressesDescendantForcedBreaks(): void
    {
        foreach ([false, true] as $nested) {
            $positioned = 'position:fixed;top:0;left:0;width:100pt;page-break-before:always';
            $html = ($nested ? '<section style="' . $positioned . '">' : '')
                . '<div style="display:flex;width:100pt;' . ($nested ? '' : $positioned) . '"><div style="width:100pt">'
                . '<div>A</div><div style="page-break-before:always">B</div></div></div>'
                . ($nested ? '</section>' : '') . '<div data-test="flow">FLOW</div>';
            $result = $this->layout($html, '@page {size:200pt 100pt}', 1);
            $this->assertSame(1, $result["pages"]);
            $this->assertBox($result, "flow", [0, 0, 200, 19.8]);
            $tokens = array_column($result["text"][1], 0);
            sort($tokens);
            $this->assertSame(["A", "B", "FLOW"], $tokens);
        }
    }

    public function testStableItemOrderDoesNotReorderSourceChildren(): void
    {
        $result = $this->layout('<div data-test="row" style="display:flex;width:100pt">'
            . '<span data-test="a" style="width:20pt;height:20pt;order:2">A</span>'
            . '<span data-test="b" style="width:20pt;height:20pt;order:1">B</span>'
            . '<span data-test="c" style="width:20pt;height:20pt;order:1">C</span></div>');
        $this->assertBox($result, "a", [40, 0, 20, 20]);
        $this->assertBox($result, "b", [0, 0, 20, 20]);
        $this->assertBox($result, "c", [20, 0, 20, 20]);
        $this->assertSame(["a", "b", "c"], $result["boxes"]["row"][0]["source"]);
    }

    public function testNaturalItemProbeUsesItsOwnCounterEntry(): void
    {
        $result = $this->layout('<div style="display:flex;width:100pt"><span id="counter" data-test="a"></span><div data-test="b" style="width:20pt">B</div></div>',
            'body {counter-reset:step 0} #counter {counter-increment:step 50} #counter:before {content:counter(step)}');
        $this->assertEquals(10, $result["boxes"]["a"][0]["content"][2]);
        $this->assertEquals(10, $result["boxes"]["b"][0]["box"][0]);
        $this->assertSame(["50", "B"], array_column($result["text"][1], 0));
    }

    public function testNormalizationPreservesNestedLayoutAndSingleListMarkers(): void
    {
        $result = $this->layout('<div style="display:flex;width:200pt">'
            . '<div data-test="nested" style="display:inline-flex;width:80pt"><span data-test="a" style="width:40pt;height:20pt">A</span><span data-test="b" style="width:40pt;height:20pt">B</span></div>'
            . '<div data-test="table" style="display:inline-table;width:40pt"><div style="display:table-row"><div style="display:table-cell">T</div></div></div>'
            . '</div><ol style="display:flex;width:200pt;margin:0;padding:0;list-style-position:inside"><li style="width:100pt">L1</li><li style="width:100pt">L2</li></ol>');
        $this->assertBox($result, "nested", [0, 0, 80, 20]);
        $this->assertBox($result, "b", [40, 0, 40, 20]);
        $this->assertEquals(80, $result["boxes"]["table"][0]["box"][0]);
        $this->assertSame(\Dompdf\FrameDecorator\Table::class, $result["boxes"]["table"][0]["class"]);
        $this->assertSame(["1", "2"], $result["bullets"]);
        $this->assertSame(["A", "B", "T", "L1", "L2"], array_column($result["text"][1], 0));
    }
}
