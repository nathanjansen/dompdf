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
            . '" style="width:200pt;flex:0 0 80pt"><div data-test="b" style="width:50pt;height:20pt">B</div></div>');
        $this->assertBox($result, "image", [0, 0, 80, 40]);
        $this->assertBox($result, "b", [80, 0, 50, 20]);
        $this->assertSame(\Dompdf\FrameDecorator\Image::class, $result["boxes"]["image"][0]["class"]);
        $this->assertSame("200pt", $result["boxes"]["image"][0]["specified_width"]);
    }

    public function testWholeDeferredItemsKeepOriginalSlots(): void
    {
        foreach ([false, true] as $image) {
            $first = $image ? '<img data-test="a" src="' . $this->image() . '" style="flex:0 0 100pt">'
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
