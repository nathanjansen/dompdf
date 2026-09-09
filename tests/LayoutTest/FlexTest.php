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
                    "margins" => [$frame->get_style()->margin_top, $frame->get_style()->margin_right,
                        $frame->get_style()->margin_bottom, $frame->get_style()->margin_left],
                    "specified_width" => $frame->get_style()->get_specified("width")
                ];
                if ($frame instanceof \Dompdf\FrameDecorator\Block) {
                    $boxes[$node->getAttribute("data-test")][count($boxes[$node->getAttribute("data-test")]) - 1]["lines"] = array_map(function ($line) {
                        return [$line->y, $line->h];
                    }, $frame->get_line_boxes());
                }
                if ($frame instanceof \Dompdf\FrameDecorator\Flex) {
                    $source = [];
                    foreach ($frame->get_children() as $child) {
                        $source[] = $child->get_node()->getAttribute("data-test");
                    }
                    $boxes[$node->getAttribute("data-test")][count($boxes[$node->getAttribute("data-test")]) - 1]["source"] = $source;
                }
            }
            if ($frame->is_text_node() && trim($node->nodeValue) !== "") {
                $style = $frame->get_style();
                $baseline = $frame->get_position("y") + $frame->get_dompdf()->getFontMetrics()->getFontBaseline($style->font_family, $style->font_size);
                $text[$page][] = [trim($node->nodeValue), $frame->get_position("x"), $frame->get_position("y"), $baseline];
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

    public static function alignmentGeometryProvider(): array
    {
        return [
            "G01 center" => ["width:300pt;height:20pt;justify-content:center", ["", ""], [[50, 0], [150, 0]]],
            "G02 between" => ["width:300pt;height:20pt;justify-content:space-between", ["", ""], [[0, 0], [200, 0]]],
            "G03 around" => ["width:300pt;height:20pt;justify-content:space-around", ["", ""], [[25, 0], [175, 0]]],
            "singleton between" => ["width:300pt;height:20pt;justify-content:space-between", [""], [[0, 0]]],
            "negative between" => ["width:150pt;height:20pt;justify-content:space-between", ["", ""], [[0, 0], [100, 0]]],
            "negative around safe fallback" => ["width:150pt;height:20pt;justify-content:space-around", ["", ""], [[0, 0], [100, 0]]],
            "G04 main auto edge" => ["width:300pt;height:20pt;justify-content:space-between", ["", "margin-left:auto"], [[0, 0], [200, 0]]],
            "multiple auto edges" => ["width:500pt;height:20pt", ["margin-left:auto", "margin-left:auto", "margin-right:auto"], [[66.666667, 0], [233.333333, 0], [333.333333, 0]]],
            "cross auto overrides self" => ["width:200pt;height:80pt;align-items:center", ["margin-top:auto;margin-bottom:auto;align-self:flex-end"], [[0, 30]]],
            "G09 center and self end" => ["width:200pt;height:80pt;align-items:center", ["", "align-self:flex-end"], [[0, 30], [100, 60]]],
            "line packing between" => ["width:100pt;height:100pt;flex-wrap:wrap;align-content:space-between", ["", ""], [[0, 0], [0, 80]]],
            "line packing center" => ["width:100pt;height:100pt;flex-wrap:wrap;align-content:center", ["", ""], [[0, 30], [0, 50]]],
            "one actual wrapped line" => ["width:300pt;height:100pt;flex-wrap:wrap;align-content:center", ["", ""], [[0, 40], [100, 40]]],
            "nowrap ignores packing" => ["width:300pt;height:100pt;align-content:center", ["", ""], [[0, 0], [100, 0]]]
            , "even distribution" => ["width:300pt;height:20pt;justify-content:space-evenly", ["", ""], [[33.333333, 0], [166.666667, 0]]]
            , "reverse logical start" => ["width:300pt;height:20pt;flex-direction:row-reverse;justify-content:start", ["", ""], [[100, 0], [0, 0]]]
            , "reverse logical end" => ["width:300pt;height:20pt;flex-direction:row-reverse;justify-content:end", ["", ""], [[200, 0], [100, 0]]]
            , "RTL physical left" => ["width:300pt;height:20pt;direction:rtl;justify-content:left", ["", ""], [[100, 0], [0, 0]]]
            , "LTR physical right" => ["width:300pt;height:20pt;justify-content:right", ["", ""], [[100, 0], [200, 0]]]
            , "safe overflow center" => ["width:150pt;height:20pt;justify-content:safe center", ["", ""], [[0, 0], [100, 0]]]
            , "unsafe overflow center" => ["width:150pt;height:20pt;justify-content:unsafe center", ["", ""], [[-25, 0], [75, 0]]]
            , "wrapped logical start" => ["width:100pt;height:100pt;flex-wrap:wrap-reverse;align-content:start", ["", ""], [[0, 20], [0, 0]]]
            , "self direction start" => ["width:200pt;height:40pt;flex-direction:column;align-items:start", ["direction:rtl;align-self:self-start", "align-self:self-end"], [[100, 0], [100, 20]]]
            , "column first baseline self fallback" => ["width:200pt;height:40pt;direction:rtl;flex-direction:column;align-items:first baseline", ["direction:ltr"], [[0, 0]]]
            , "column last baseline safe fallback" => ["width:50pt;height:40pt;flex-direction:column;align-items:last baseline", ["direction:ltr"], [[0, 0]]]
            , "reverse safe overflow" => ["width:150pt;height:20pt;flex-direction:row-reverse;justify-content:safe center", ["", ""], [[50, 0], [-50, 0]]]
            , "reverse negative between" => ["width:150pt;height:20pt;flex-direction:row-reverse;justify-content:space-between", ["", ""], [[50, 0], [-50, 0]]]
            , "reverse negative around" => ["width:150pt;height:20pt;flex-direction:row-reverse;justify-content:space-around", ["", ""], [[50, 0], [-50, 0]]]
            , "reverse negative evenly" => ["width:150pt;height:20pt;flex-direction:row-reverse;justify-content:space-evenly", ["", ""], [[50, 0], [-50, 0]]]
            , "reverse cross safe overflow" => ["width:100pt;height:30pt;flex-wrap:wrap-reverse;align-content:safe center", ["", ""], [[0, 10], [0, -10]]]
        ];
    }

    /** @dataProvider alignmentGeometryProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('alignmentGeometryProvider')]
    public function testPublicAlignmentGeometry(string $container, array $styles, array $expected): void
    {
        $html = '<div style="display:flex;align-items:flex-start;' . $container . '">';
        foreach ($styles as $index => $style) {
            $html .= '<div data-test="item' . $index . '" style="flex:none;width:100pt;height:20pt;' . $style . '"></div>';
        }
        $result = $this->layout($html . '</div>', '@page {size:600pt 400pt}', 1);
        foreach ($expected as $index => $position) {
            $this->assertBox($result, "item" . $index, array_merge($position, [100, 20]));
        }
        $this->assertSame(1, $result["pages"]);
    }

    public function testColumnAutoCrossSizeUsesNativeFitContentBeforeStretch(): void
    {
        $result = $this->layout('<div style="display:flex;flex-direction:column;width:200pt;align-items:flex-start;font:10pt/20pt Courier">'
            . '<div data-test="fit" style="flex:none">M</div>'
            . '<div data-test="stretch" style="flex:none;align-self:stretch">M</div></div>', '', 1);
        $this->assertBox($result, "fit", [0, 0, 6]);
        $this->assertEqualsWithDelta(200, $result["boxes"]["stretch"][0]["content"][2], 0.01);
    }

    public function testFirstContentBaselineSharesNativeGroupAndGrowsAutoBox(): void
    {
        foreach (["block", "flex"] as $display) {
            $result = $this->layout('<div data-test="row" style="display:flex;width:200pt;align-items:flex-start">'
                . '<div data-test="A" style="display:' . $display . ';width:100pt;align-content:first baseline">'
                . '<div data-test="a-line" style="height:10pt;line-height:10pt">A</div></div>'
                . '<div data-test="B" style="width:100pt;padding-top:10pt;align-content:first baseline">'
                . '<div data-test="b-line" style="height:10pt;line-height:10pt">B</div></div></div>', '', 1);
            $this->assertBox($result, "A", [0, 0, 100, 20]);
            $this->assertBox($result, "B", [100, 0, 100, 20]);
            $this->assertEqualsWithDelta(10, $result["boxes"]["a-line"][0]["box"][1], 0.01);
            $this->assertEqualsWithDelta(10, $result["boxes"]["b-line"][0]["box"][1], 0.01);
            $this->assertEqualsWithDelta($result["text"][1][0][3], $result["text"][1][1][3], 0.01);
        }
    }

    public function testLastContentBaselineResolvesLeadingSpaceBeforeNativeLayout(): void
    {
        $result = $this->layout('<div style="display:flex;width:200pt;align-items:flex-end">'
            . '<div data-test="A" style="width:100pt;height:40pt;box-sizing:border-box;align-content:last baseline">'
            . '<div data-test="a-line" style="height:10pt;line-height:10pt">A</div></div>'
            . '<div data-test="B" style="width:100pt;height:40pt;box-sizing:border-box;padding-bottom:10pt;align-content:last baseline">'
            . '<div data-test="b-line" style="height:10pt;line-height:10pt">B</div></div></div>', '', 1);
        $this->assertBox($result, "A", [0, 0, 100, 40]);
        $this->assertBox($result, "B", [100, 0, 100, 40]);
        $this->assertBox($result, "a-line", [0, 20, 100, 10]);
        $this->assertBox($result, "b-line", [100, 20, 100, 10]);
        $this->assertEqualsWithDelta($result["text"][1][0][3], $result["text"][1][1][3], 0.01);
    }

    public function testContentBaselineSharesSelfGroupButNotCenteredItem(): void
    {
        $result = $this->layout('<div style="display:flex;width:300pt;height:40pt;align-items:flex-start">'
            . '<div data-test="A" style="width:100pt;align-content:first baseline"><div data-test="a-line" style="height:10pt;line-height:10pt">A</div></div>'
            . '<div data-test="B" style="width:100pt;padding-top:10pt;align-self:first baseline"><div style="height:10pt;line-height:10pt">B</div></div>'
            . '<div data-test="C" style="width:100pt;align-self:center;align-content:first baseline"><div data-test="c-line" style="height:10pt;line-height:10pt">C</div></div></div>', '', 1);
        $this->assertBox($result, "A", [0, 0, 100, 20]);
        $this->assertBox($result, "a-line", [0, 10, 100, 10]);
        $this->assertBox($result, "C", [200, 15, 100, 10]);
        $this->assertBox($result, "c-line", [200, 15, 100, 10]);
        $this->assertEqualsWithDelta($result["text"][1][0][3], $result["text"][1][1][3], 0.01);
        $this->assertEqualsWithDelta(5, $result["text"][1][2][3] - $result["text"][1][0][3], 0.01);
    }

    public function testContentBaselineUsesFinalStretchPercentageContent(): void
    {
        $result = $this->layout('<div style="display:flex;width:200pt;height:80pt">'
            . '<div data-test="A" style="width:100pt;align-content:first baseline"><div data-test="percent" style="height:50%"></div>'
            . '<div data-test="a-line" style="height:10pt;line-height:10pt">A</div></div>'
            . '<div data-test="B" style="width:100pt;padding-top:10pt;align-content:first baseline">'
            . '<div data-test="b-line" style="height:10pt;line-height:10pt">B</div></div></div>', '', 1);
        $this->assertBox($result, "A", [0, 0, 100, 80]);
        $this->assertBox($result, "B", [100, 0, 100, 80]);
        $this->assertBox($result, "percent", [0, 0, 100, 40]);
        $this->assertBox($result, "a-line", [0, 40, 100, 10]);
        $this->assertBox($result, "b-line", [100, 40, 100, 10]);
        $this->assertEqualsWithDelta($result["text"][1][0][3], $result["text"][1][1][3], 0.01);
    }

    public function testFinalContentBaselineGrowthPrecedesPeerCrossPlacementAndAutoMargins(): void
    {
        foreach (["align-self:flex-end", "align-self:center;margin-top:auto"] as $alignment) {
            $result = $this->layout('<div style="display:flex;width:200pt;height:80pt">'
                . '<div data-test="A" style="width:100pt;align-content:last baseline"><div data-test="a-line" style="height:10pt;line-height:10pt">A</div>'
                . '<div data-test="percent" style="height:50%"></div></div>'
                . '<div data-test="B" style="width:100pt;padding-bottom:20pt;align-content:last baseline;' . $alignment . '">'
                . '<div data-test="b-line" style="height:10pt;line-height:10pt">B</div></div></div>'
                . '<div data-test="after" style="height:20pt">AFTER</div>', '', 1);
            $this->assertBox($result, "A", [0, 0, 100, 80]);
            $this->assertBox($result, "a-line", [0, 30, 100, 10]);
            $this->assertBox($result, "percent", [0, 40, 100, 40]);
            $this->assertBox($result, "B", [100, 30, 100, 50]);
            $this->assertBox($result, "b-line", [100, 30, 100, 10]);
            $this->assertBox($result, "after", [0, 80, 400, 20]);
            $this->assertEqualsWithDelta(37.92, $result["text"][1][0][3], 0.01);
            $this->assertEqualsWithDelta(37.92, $result["text"][1][1][3], 0.01);
            $this->assertEquals(strpos($alignment, "margin-top") !== false ? 30 : 0, $result["boxes"]["B"][0]["margins"][0]);
        }
    }

    public function testContentBaselineCoordinationUsesActualSafeAndStretchEdges(): void
    {
        foreach (["safe self-end", "stretch"] as $alignment) {
            $result = $this->layout('<div style="display:flex;width:200pt;height:60pt;align-items:' . $alignment . '">'
                . '<div data-test="A" style="width:100pt;height:40pt;box-sizing:border-box;align-content:last baseline"><div data-test="a-line" style="height:10pt;line-height:10pt">A</div></div>'
                . '<div data-test="B" style="width:100pt;height:40pt;box-sizing:border-box;padding-bottom:10pt;align-content:last baseline"><div data-test="b-line" style="height:10pt;line-height:10pt">B</div></div></div>', '', 1);
            $this->assertBox($result, "A", [0, 20, 100, 40]);
            $this->assertBox($result, "B", [100, 20, 100, 40]);
            $this->assertBox($result, "a-line", [0, 40, 100, 10]);
            $this->assertBox($result, "b-line", [100, 40, 100, 10]);
        }
        $result = $this->layout('<div style="display:flex;width:200pt;height:60pt;flex-wrap:wrap-reverse">'
            . '<div data-test="A" style="width:100pt;height:40pt;align-content:first baseline"><div data-test="a-line" style="height:10pt;line-height:10pt">A</div></div>'
            . '<div data-test="B" style="width:100pt;height:40pt;box-sizing:border-box;padding-top:10pt;align-content:first baseline"><div data-test="b-line" style="height:10pt;line-height:10pt">B</div></div></div>', '', 1);
        $this->assertBox($result, "A", [0, 0, 100, 40]);
        $this->assertBox($result, "B", [100, 0, 100, 40]);
        $this->assertBox($result, "a-line", [0, 10, 100, 10]);
        $this->assertBox($result, "b-line", [100, 10, 100, 10]);
    }

    public function testContentBaselineLeadingSpaceIsConsumedOnceAcrossPages(): void
    {
        $children = '';
        for ($i = 1; $i <= 8; $i++) {
            $children .= '<div data-test="line' . $i . '" style="height:20pt">A' . $i . '</div>';
        }
        $result = $this->layout('<div style="display:flex;width:200pt;align-items:flex-start">'
            . '<div data-test="A" style="width:100pt;align-content:first baseline">' . $children . '</div>'
            . '<div style="width:100pt;padding-top:20pt;align-content:first baseline"><div style="height:20pt">B</div></div></div>'
            . '<div data-test="after" style="height:20pt">AFTER</div>', '@page {size:200pt 100pt}', 2);
        $this->assertSame(['A1', 'A2', 'A3', 'A4', 'B'], array_column($result["text"][1], 0));
        $this->assertSame(['A5', 'A6', 'A7', 'A8', 'AFTER'], array_column($result["text"][2], 0));
        $this->assertBox($result, "line1", [0, 20, 100, 20]);
        $this->assertBox($result, "line5", [0, 0, 100, 20]);
        $this->assertBox($result, "A", [0, 0, 100, 100]);
        $this->assertBox($result, "A", [0, 0, 100, 80], 1);
        $this->assertBox($result, "after", [0, 80, 200, 20]);
    }

    public function testLastContentBaselineTailBelongsOnlyToFinalFragment(): void
    {
        $result = $this->layout('<div style="display:flex;width:200pt;align-items:flex-end">'
            . '<div data-test="A" style="width:100pt;align-content:last baseline"><div style="height:20pt">A1</div>'
            . '<div style="height:20pt;page-break-before:always">A2</div></div>'
            . '<div style="width:100pt;padding-bottom:10pt;align-content:last baseline"><div style="height:20pt">B</div></div></div>'
            . '<div data-test="after" style="height:20pt">AFTER</div>', '@page {size:200pt 100pt}', 2);
        $this->assertSame(['A1', 'B'], array_column($result["text"][1], 0));
        $this->assertSame(['A2', 'AFTER'], array_column($result["text"][2], 0));
        $this->assertBox($result, "A", [0, 0, 100, 20]);
        $this->assertBox($result, "A", [0, 0, 100, 30], 1);
        $this->assertBox($result, "after", [0, 30, 200, 20]);
    }

    public function testStretchedImageRetainsAssignedMainSizeAndPercentageChildReference(): void
    {
        $result = $this->layout('<div style="display:flex;width:200pt;height:80pt">'
            . '<img data-test="image" style="flex:0 0 80pt;min-width:0;width:200pt" src="' . $this->image() . '">'
            . '<div data-test="item" style="flex:0 0 100pt;box-sizing:border-box;padding:5pt;border:5pt solid">'
            . '<img data-test="child" style="width:20pt;height:50%" src="' . $this->image() . '"></div></div>', '', 1);
        $this->assertBox($result, "image", [0, 0, 80, 80]);
        $this->assertSame("200pt", $result["boxes"]["image"][0]["specified_width"]);
        $this->assertBox($result, "item", [80, 0, 100, 80]);
        $this->assertBox($result, "child", [90, 10, 20, 30]);
    }

    public function testNativeInlineBlockDonatesLastBaselineBesideInlineFlex(): void
    {
        $result = $this->layout('<div data-test="line">BEFORE<span style="display:inline-block;width:60pt">IBFIRST<br>IBLAST</span>'
            . '<span style="display:inline-flex;width:40pt"><span>A</span></span>AFTER<br><span data-test="next">NEXT</span></div>', '', 1);
        $ink = array_column($result["text"][1], null, 0);
        foreach (["BEFORE", "A", "AFTER"] as $token) {
            $this->assertEqualsWithDelta($ink["IBLAST"][3], $ink[$token][3], 0.01, $token);
        }
        $this->assertEqualsWithDelta(19.8, $ink["IBLAST"][3] - $ink["IBFIRST"][3], 0.01);
        $this->assertGreaterThanOrEqual(39.6, $result["boxes"]["line"][0]["lines"][0][1]);
        $this->assertGreaterThanOrEqual($result["boxes"]["line"][0]["lines"][0][1], $ink["NEXT"][2]);
    }

    public function testInlineBlockNonvisibleOverflowUsesMarginBottomBesideInlineFlex(): void
    {
        foreach (["hidden", "scroll", "auto", "visible"] as $overflow) {
            $result = $this->layout('<div data-test="line">BEFORE<span data-test="ib" style="display:inline-block;width:60pt;height:40pt;margin-bottom:5pt;overflow:' . $overflow . '">IB</span>'
                . '<span style="display:inline-flex;width:60pt;height:20pt"><span>A</span></span>AFTER<br>NEXT</div>', '', 1);
            $ink = array_column($result["text"][1], null, 0);
            $box = $result["boxes"]["ib"][0]["box"];
            $expected = $overflow === "visible" ? $ink["IB"][3] : $box[1] + $box[3] + 5;
            foreach (["BEFORE", "A", "AFTER"] as $token) {
                $this->assertEqualsWithDelta($expected, $ink[$token][3], 0.01, $overflow . " " . $token);
            }
            $this->assertGreaterThanOrEqual($box[1] + $box[3] + 5, $ink["NEXT"][2]);
        }
    }

    public function testBlockLineDonationUsesHiddenAtomicBottomWithoutChangingBlockItemBaseline(): void
    {
        foreach (["hidden", "visible"] as $overflow) {
            $result = $this->layout('<div style="display:flex;width:200pt;align-items:baseline"><div style="width:100pt">'
                . '<span data-test="ib" style="display:inline-block;width:60pt;height:40pt;overflow:' . $overflow . '">IB</span>'
                . '</div><div style="width:100pt">PEER</div></div>', '', 1);
            $ink = array_column($result["text"][1], null, 0);
            $box = $result["boxes"]["ib"][0]["box"];
            $expected = $overflow === "hidden" ? $box[1] + $box[3] : $ink["IB"][3];
            $this->assertEqualsWithDelta($expected, $ink["PEER"][3], 0.01);
        }
    }

    public function testInlineFlexExportsCoordinatedContentGroupInsteadOfStartmostNonparticipant(): void
    {
        $result = $this->layout('<div>BEFORE<span style="display:inline-flex;width:180pt;height:60pt;align-items:flex-start">'
            . '<span style="width:60pt;height:10pt;align-self:center;line-height:10pt">C</span>'
            . '<span style="width:60pt;align-content:first baseline"><span style="display:block;height:10pt;line-height:10pt">A</span></span>'
            . '<span style="width:60pt;padding-top:10pt;align-content:first baseline"><span style="display:block;height:10pt;line-height:10pt">B</span></span>'
            . '</span>AFTER</div>', '', 1);
        $ink = array_column($result["text"][1], null, 0);
        foreach (["BEFORE", "B", "AFTER"] as $token) {
            $this->assertEqualsWithDelta($ink["A"][3], $ink[$token][3], 0.01, $token);
        }
        $this->assertEqualsWithDelta(15, $ink["C"][3] - $ink["A"][3], 0.01);
    }

    public function testInlineFlexBaselineDonorFollowsPhysicalStartAfterOrderAndDirection(): void
    {
        foreach ([["row", "order:1", "B"], ["row-reverse", "", "B"], ["column-reverse", "", "B"],
            ["row;direction:rtl", "", "A"]] as [$direction, $order, $donor]) {
            $result = $this->layout('<div>BEFORE<span style="display:inline-flex;width:100pt;height:60pt;flex-direction:' . $direction . ';align-items:flex-start">'
                . '<span style="flex:none;width:40pt;height:20pt;align-self:flex-end;' . $order . '">A</span>'
                . '<span style="flex:none;width:40pt;height:20pt">B</span></span>AFTER</div>', '', 1);
            $ink = array_column($result["text"][1], null, 0);
            $this->assertEqualsWithDelta($ink[$donor][3], $ink["BEFORE"][3], 0.01, $direction);
            $this->assertEqualsWithDelta($ink[$donor][3], $ink["AFTER"][3], 0.01, $direction);
        }
        $result = $this->layout('<div>BEFORE<span data-test="empty" style="display:inline-flex;width:10pt;height:30pt"></span>AFTER</div>', '', 1);
        $ink = array_column($result["text"][1], null, 0);
        $box = $result["boxes"]["empty"][0]["box"];
        $this->assertEqualsWithDelta($box[1] + $box[3], $ink["BEFORE"][3], 0.01);
        $this->assertEqualsWithDelta($ink["BEFORE"][3], $ink["AFTER"][3], 0.01);
    }

    public function testCoincidentZeroWidthDonorsRetainOrderModifiedOrdinal(): void
    {
        $items = '<span style="flex:none;width:0;min-width:0;order:1;font:20pt/40pt Times-Roman;white-space:nowrap">A</span>'
            . '<span style="flex:none;width:0;min-width:0;order:0;font:10pt/20pt Times-Roman;white-space:nowrap">B</span>';
        $result = $this->layout('<div>BEFORE<span style="display:inline-flex;width:0;height:60pt;align-items:flex-start">' . $items . '</span>AFTER</div>', '', 1);
        $ink = array_column($result["text"][1], null, 0);
        $this->assertEqualsWithDelta($ink["A"][1], $ink["B"][1], 0.01);
        $this->assertEqualsWithDelta($ink["B"][3], $ink["BEFORE"][3], 0.01);
        $this->assertEqualsWithDelta($ink["B"][3], $ink["AFTER"][3], 0.01);
        $this->assertEqualsWithDelta(15.84, $ink["A"][3] - $ink["B"][3], 0.01);
        $result = $this->layout('<div style="display:flex;width:200pt;align-items:last baseline">'
            . '<div style="display:flex;flex:none;width:0;height:60pt;align-items:flex-start">' . $items . '</div>'
            . '<div style="width:100pt">PEER</div></div>', '', 1);
        $ink = array_column($result["text"][1], null, 0);
        $this->assertEqualsWithDelta($ink["A"][3], $ink["PEER"][3], 0.01);
    }

    public function testBlockExportsLineBaselineDespiteShiftedAtomicFirstParticipant(): void
    {
        foreach ([false, true] as $withFlex) {
            $result = $this->layout('<div style="display:flex;width:200pt;align-items:baseline">'
                . '<div style="width:100pt"><span style="display:inline-block;width:20pt;height:20pt;vertical-align:5pt">I</span>'
                . ($withFlex ? '<span style="display:inline-flex;width:20pt;height:10pt"><span>F</span></span>' : '')
                . 'X</div><div style="width:100pt">PEER</div></div>', '', 1);
            $ink = array_column($result["text"][1], null, 0);
            $this->assertEqualsWithDelta($ink["X"][3], $ink["PEER"][3], 0.01);
        }
    }

    public function testStretchInstallsDefiniteContentHeightAndClampsCrossSize(): void
    {
        $result = $this->layout('<div style="display:flex;width:300pt;height:80pt">'
            . '<div data-test="A" style="flex:none;width:100pt"><div data-test="child" style="height:50%"></div></div>'
            . '<div data-test="B" style="flex:none;width:100pt;max-height:50pt"></div>'
            . '<div data-test="C" style="flex:none;width:100pt;min-height:90pt"></div></div>', '', 1);
        $this->assertBox($result, "A", [0, 0, 100, 80]);
        $this->assertBox($result, "child", [0, 0, 100, 40]);
        $this->assertBox($result, "B", [100, 0, 100, 50]);
        $this->assertBox($result, "C", [200, 0, 100, 90]);
    }

    public function testFlexBaselineUsesActualFirstTextAndCombinedAscentDescent(): void
    {
        $result = $this->layout('<div data-test="flex" style="display:flex;width:100pt;align-items:baseline">'
            . '<div data-test="A" style="flex:none;width:40pt;font:10pt/20pt Times-Roman;padding-top:20pt">A</div>'
            . '<div data-test="B" style="flex:none;width:40pt;font:20pt/40pt Times-Roman;padding-bottom:20pt">B</div></div>'
            . '<div data-test="next">NEXT</div>', '', 1);
        $this->assertSame(["A", "B", "NEXT"], array_column($result["text"][1], 0));
        $this->assertEqualsWithDelta(35.84, $result["text"][1][0][3], 0.01);
        $this->assertEqualsWithDelta(35.84, $result["text"][1][1][3], 0.01);
        $this->assertBox($result, "flex", [0, 0, 100, 63.76]);
        $this->assertBox($result, "next", [0, 63.76]);
    }

    public function testInlineFlexParticipatesInNativeBaselineAndNextLineExtent(): void
    {
        $result = $this->layout('<div data-test="parent"><span>BEFORE</span>'
            . '<span data-test="flex" style="display:inline-flex;width:100pt;align-items:baseline">'
            . '<span data-test="A" style="flex:none;width:40pt;font:10pt/20pt Times-Roman;padding-top:20pt">A</span>'
            . '<span data-test="B" style="flex:none;width:40pt;font:20pt/40pt Times-Roman;padding-bottom:20pt">B</span></span>'
            . '<span>AFTER</span><br><span>NEXT</span></div>', '', 1);
        $this->assertSame(["BEFORE", "A", "B", "AFTER", "NEXT"], array_column($result["text"][1], 0));
        foreach (array_slice($result["text"][1], 0, 4) as $text) {
            $this->assertEqualsWithDelta(35.84, $text[3], 0.01, $text[0]);
        }
        $this->assertEqualsWithDelta(63.76, $result["boxes"]["parent"][0]["lines"][0][1], 0.01);
        $this->assertEqualsWithDelta(63.76, $result["boxes"]["parent"][0]["lines"][1][0], 0.01);
    }

    public function testInlineFlexBaselineDonorDoesNotChangeWithItemCrossAlignment(): void
    {
        $result = $this->layout('<div><span>BEFORE</span><span style="display:inline-flex;width:100pt;height:100pt;align-items:flex-start">'
            . '<span style="flex:none;width:40pt;height:20pt;align-self:flex-end">A</span>'
            . '<span style="flex:none;width:40pt;height:20pt">B</span></span><span>AFTER</span><br>NEXT</div>', '', 1);
        $text = array_column($result["text"][1], 3, 0);
        $this->assertEqualsWithDelta(80, $text["A"] - $text["B"], 0.01);
        $this->assertEqualsWithDelta($text["A"], $text["BEFORE"], 0.01);
        $this->assertEqualsWithDelta($text["A"], $text["AFTER"], 0.01);
    }

    public function testFirstNativeLineDonatesItsBaselineNotRaisedTextBaseline(): void
    {
        $result = $this->layout('<div style="display:flex;width:200pt;align-items:baseline">'
            . '<div style="flex:none;width:100pt"><span style="vertical-align:super">SUP</span>BASE</div>'
            . '<div style="flex:none;width:100pt">PEER</div></div>', '', 1);
        $text = array_column($result["text"][1], 3, 0);
        $this->assertEqualsWithDelta($text["BASE"], $text["PEER"], 0.01);
        $this->assertLessThan($text["BASE"], $text["SUP"]);
    }

    public function testOnlyRaisedNativeTextStillExportsTheUnraisedLineBaseline(): void
    {
        $result = $this->layout('<div style="display:flex;width:200pt;align-items:baseline">'
            . '<div style="flex:none;width:100pt"><span style="vertical-align:super">SUP</span></div>'
            . '<div style="flex:none;width:100pt">PEER</div></div>', '', 1);
        $text = array_column($result["text"][1], 3, 0);
        $this->assertEqualsWithDelta(15.84, $text["PEER"], 0.01);
        $this->assertEqualsWithDelta(12.24, $text["SUP"], 0.01);
    }

    public function testLastBaselineAlignsLastNativeLinesAtEndOfCrossAllocation(): void
    {
        $result = $this->layout('<div style="display:flex;width:200pt;height:100pt;align-items:last baseline">'
            . '<div data-test="A" style="flex:none;width:100pt">FIRST<br>LAST</div>'
            . '<div data-test="B" style="flex:none;width:100pt">PEER</div></div>', '', 1);
        $text = array_column($result["text"][1], 3, 0);
        $this->assertEqualsWithDelta(96.04, $text["LAST"], 0.01);
        $this->assertEqualsWithDelta($text["LAST"], $text["PEER"], 0.01);
        $this->assertBox($result, "A", [0, 60.4, 100, 39.6]);
        $this->assertBox($result, "B", [100, 80.2, 100, 19.8]);
    }

    public function testLastBaselineOverflowFallbackMovesWholeGroupToSafeStart(): void
    {
        $result = $this->layout('<div style="display:flex;width:200pt;height:20pt;align-items:last baseline">'
            . '<div data-test="A" style="flex:none;width:100pt">FIRST<br>LAST</div>'
            . '<div data-test="B" style="flex:none;width:100pt">PEER</div></div>', '', 1);
        $text = array_column($result["text"][1], 3, 0);
        $this->assertEqualsWithDelta(35.64, $text["LAST"], 0.01);
        $this->assertEqualsWithDelta($text["LAST"], $text["PEER"], 0.01);
        $this->assertBox($result, "A", [0, 0, 100, 39.6]);
        $this->assertBox($result, "B", [100, 19.8, 100, 19.8]);
    }

    public function testCrossAutoMarginsPreservePhysicalOverflowStartAndUsedEdges(): void
    {
        foreach (["margin-top:auto;margin-bottom:auto" => [0, -20, 0], "margin-top:auto;margin-bottom:5pt" => [0, -20, 0],
            "margin-top:5pt;margin-bottom:auto" => [5, -25, 5]] as $margins => $expected) {
            $result = $this->layout('<div style="display:flex;width:100pt;height:20pt;align-items:flex-end">'
                . '<div data-test="item" style="flex:none;width:100pt;height:40pt;' . $margins . '">A</div></div>', '', 1);
            $this->assertBox($result, "item", [0, $expected[2], 100, 40]);
            $this->assertEqualsWithDelta($expected[0], $result["boxes"]["item"][0]["margins"][0], 0.01);
            $this->assertEqualsWithDelta($expected[1], $result["boxes"]["item"][0]["margins"][2], 0.01);
        }
    }

    public function testResolvedAutoMarginsArePerEdgeAndNotCountedTwice(): void
    {
        $result = $this->layout('<div style="display:flex;width:500pt;height:80pt;justify-content:center">'
            . '<div data-test="A" style="flex:none;width:100pt;height:20pt;margin-left:auto;margin-right:auto;margin-top:auto;margin-bottom:auto">A</div>'
            . '<div data-test="B" style="flex:none;width:100pt;height:20pt;margin-left:auto">B</div></div>', '@page {size:600pt 400pt}', 1);
        $this->assertBox($result, "A", [100, 30, 100, 20]);
        $this->assertBox($result, "B", [400, 0, 100, 20]);
        $this->assertEquals([30, 100, 30, 100], $result["boxes"]["A"][0]["margins"]);
        $this->assertEquals([0, 0, 0, 100], $result["boxes"]["B"][0]["margins"]);
        $this->assertEqualsWithDelta(100, $result["text"][1][0][1], 0.01);
    }

    public function testAlignedWrappedRowsAndColumnsKeepOriginalSlotsOnContinuation(): void
    {
        foreach (["row" => ["width:250pt;justify-content:center;flex-wrap:wrap", 100, 40,
                [[25, 0], [125, 0], [25, 40], [125, 40], [25, 0], [125, 0]], ["ABCD", "EFAFTER"]],
            "column" => ["width:200pt;height:160pt;flex-direction:column;justify-content:center;align-items:center", 100, 20,
                [[50, 20], [50, 40], [50, 60], [50, 80], [50, 0], [50, 20]], ["ABCD", "EFAFTER"]]] as $axis => $case) {
            [$style, $width, $height, $positions, $tokens] = $case;
            $html = '<div style="display:flex;align-content:flex-start;align-items:flex-start;' . $style . '">';
            foreach (str_split("ABCDEF") as $letter) {
                $html .= '<div data-test="' . $letter . '" style="flex:none;width:' . $width . 'pt;height:' . $height . 'pt">' . $letter . '</div>';
            }
            $result = $this->layout($html . '</div><div data-test="after" style="height:20pt">AFTER</div>', '@page {size:300pt 100pt}', 2);
            foreach (str_split("ABCDEF") as $index => $letter) {
                $this->assertBox($result, $letter, $positions[$index]);
            }
            $this->assertSame($tokens[0], implode("", array_column($result["text"][1], 0)), $axis);
            $this->assertSame($tokens[1], implode("", array_column($result["text"][2], 0)), $axis);
        }
    }

    public function testPartialAutoMarginSuffixDoesNotRepeatConsumedTopAllocation(): void
    {
        $html = '<div style="display:flex;width:100pt;height:200pt;align-items:flex-start">'
            . '<div data-test="item" style="flex:none;width:100pt;margin-top:auto;margin-bottom:0">';
        for ($i = 1; $i <= 8; $i++) {
            $html .= '<div style="height:20pt;page-break-inside:avoid">A' . $i . '</div>';
        }
        $result = $this->layout($html . '</div></div><div style="height:20pt">AFTER</div>', '@page {size:200pt 100pt}', 3);
        $this->assertSame("A1A2A3", implode("", array_column($result["text"][1], 0)));
        $this->assertSame("A4A5A6A7A8", implode("", array_column($result["text"][2], 0)));
        $this->assertEqualsWithDelta(40, $result["boxes"]["item"][0]["margins"][0], 0.01);
        $this->assertEqualsWithDelta(0, $result["boxes"]["item"][1]["margins"][0], 0.01);
        $this->assertSame("AFTER", implode("", array_column($result["text"][3], 0)));
    }

    public function testWholeDeferredAlignedItemKeepsUnconsumedAutoMarginAndSlot(): void
    {
        $result = $this->layout('<div style="height:40pt">LEAD</div><div style="display:flex;width:200pt">'
            . '<div data-test="A" style="flex:none;width:100pt;height:20pt;margin-top:auto;page-break-inside:avoid">A</div>'
            . '<div data-test="B" style="flex:none;width:100pt;height:80pt;page-break-inside:avoid">B</div></div>'
            . '<div data-test="after" style="height:20pt">AFTER</div>', '@page {size:200pt 100pt}', 2);
        $this->assertSame(['LEAD'], array_column($result["text"][1], 0));
        $this->assertSame(['A', 'B', 'AFTER'], array_column($result["text"][2], 0));
        $this->assertSame(2, $result["boxes"]["A"][0]["page"]);
        $this->assertBox($result, "A", [0, 60, 100, 20]);
        $this->assertEquals(60, $result["boxes"]["A"][0]["margins"][0]);
        $this->assertBox($result, "B", [100, 0, 100, 80]);
        $this->assertBox($result, "after", [0, 80, 200, 20]);
    }

    public function testOrdinaryInlineBaselineAndLineHeightRemainUnchanged(): void
    {
        $result = $this->layout('<div data-test="parent"><span>BEFORE</span><span>MIDDLE</span><span>AFTER</span><br><span>NEXT</span></div>', '', 1);
        foreach (array_slice($result["text"][1], 0, 3) as $text) {
            $this->assertEqualsWithDelta(15.84, $text[3], 0.01);
        }
        $this->assertEqualsWithDelta(19.8, $result["boxes"]["parent"][0]["lines"][0][1], 0.01);
        $this->assertEqualsWithDelta(19.8, $result["boxes"]["parent"][0]["lines"][1][0], 0.01);
    }

    public function testInlineFlexNativeAdmissionHandlesSolitaryWrappedAndCellLines(): void
    {
        $flex = '<span data-test="flex" style="display:inline-flex;width:60pt;height:40pt;align-items:flex-start">'
            . '<span style="flex:none;width:60pt;height:20pt">FLEX</span></span>';
        foreach (["solitary" => '<div data-test="parent">' . $flex . '<br>NEXT</div>',
            "wrapped" => '<div data-test="parent" style="width:80pt"><span style="display:inline-block;width:60pt;height:20pt">BEFORE</span>' . $flex . '<br>NEXT</div>',
            "cell" => '<table style="border-spacing:0"><tr><td data-test="parent" style="padding:0">BEFORE' . $flex . 'AFTER<br>NEXT</td></tr></table>'] as $case => $html) {
            $result = $this->layout($html, '', 1);
            $parent = $result["boxes"]["parent"][0];
            $box = $result["boxes"]["flex"][0]["box"];
            $lines = $parent["lines"];
            $last = $lines[count($lines) - 1];
            $this->assertGreaterThanOrEqual($box[1] + $box[3] - 0.01, $last[0], $case);
            $text = array_column($result["text"][1], 3, 0);
            if ($case === "cell") {
                $this->assertEqualsWithDelta($text["FLEX"], $text["BEFORE"], 0.01);
                $this->assertEqualsWithDelta($text["FLEX"], $text["AFTER"], 0.01);
            }
            $this->assertCount(1, $result["boxes"]["flex"]);
        }
    }

    public function testInlineFlexNonbaselineTopAndNativeMiddleKeepLineBounds(): void
    {
        foreach (["top", "middle"] as $align) {
            $result = $this->layout('<div data-test="parent"><span>BEFORE</span>'
                . '<span data-test="flex" style="display:inline-flex;width:60pt;height:20pt;vertical-align:' . $align . '">'
                . '<span style="flex:none;width:60pt;height:20pt">FLEX</span></span><span>AFTER</span><br>NEXT</div>', '', 1);
            $box = $result["boxes"]["flex"][0]["box"];
            $line = $result["boxes"]["parent"][0]["lines"][0];
            $this->assertEqualsWithDelta($align === "top" ? 0 : -2, $box[1], 0.01);
            $this->assertGreaterThanOrEqual($box[1] + $box[3] - 0.01, $line[0] + $line[1]);
            $this->assertEqualsWithDelta($line[0] + $line[1], $result["boxes"]["parent"][0]["lines"][1][0], 0.01);
        }
    }

    public function testFirstTextLineAndImageOnlyLineDonateDifferentBaselines(): void
    {
        $result = $this->layout('<div style="display:flex;width:300pt;align-items:baseline">'
            . '<div data-test="A" style="flex:none;width:100pt;padding-top:3pt;border-top:2pt solid;margin-top:5pt">FIRST<br>LAST</div>'
            . '<div style="flex:none;width:100pt">PEER</div>'
            . '<div data-test="image-line" style="flex:none;width:100pt"><img data-test="image" style="width:20pt;height:30pt" src="' . $this->image() . '"></div></div>', '', 1);
        $text = array_column($result["text"][1], 3, 0);
        $this->assertEqualsWithDelta($text["FIRST"], $text["PEER"], 0.01);
        $this->assertEqualsWithDelta(19.8, $text["LAST"] - $text["FIRST"], 0.01);
        $image = $result["boxes"]["image"][0]["box"];
        $this->assertEqualsWithDelta($image[1] + $image[3], $text["PEER"], 0.01);
    }

    public static function lineGapGeometryProvider(): array
    {
        return [
            "LG01 row internal gaps" => ["width:220pt;gap:20pt", ["100pt", "100pt", "100pt"], 20,
                [[0, 0, 100, 20], [120, 0, 100, 20], [0, 40, 100, 20]], [220, 60]],
            "LG02 exact without trailing gap" => ["width:220pt;gap:20pt", ["100pt", "100pt"], 20,
                [[0, 0, 100, 20], [120, 0, 100, 20]], [220, 20]],
            "LG03 oversized first" => ["width:150pt;gap:10pt", ["180pt", "40pt"], 20,
                [[0, 0, 180, 20], [0, 30, 40, 20]], [150, 50]],
            "LG04 zero after exact gapless line" => ["width:200pt", ["100pt", "100pt", "0pt"], 20,
                [[0, 0, 100, 20], [100, 0, 100, 20], [200, 0, 0, 20]], [200, 20]],
            "negative outer size stays signed" => ["width:100pt", ["10pt;margin-right:-30pt", "120pt"], 20,
                [[0, 0, 10, 20], [-20, 0, 120, 20]], [100, 20]],
            "zero item still pays nonzero gap" => ["width:220pt;gap:20pt", ["100pt", "100pt", "0pt"], 20,
                [[0, 0, 100, 20], [120, 0, 100, 20], [0, 40, 0, 20]], [220, 60]],
            "LG06 unequal row gaps" => ["width:200pt;row-gap:30pt;column-gap:20pt", ["80pt", "80pt", "80pt"], 10,
                [[0, 0, 80, 10], [100, 0, 80, 10], [0, 40, 80, 10]], [200, 50]],
            "LG08 definite percentage" => ["width:200pt;column-gap:10%", ["60pt", "60pt"], 20,
                [[0, 0, 60, 20], [80, 0, 60, 20]], [200, 20]],
            "LG10 reversed lines" => ["width:220pt;flex-wrap:wrap-reverse;gap:10pt 20pt", ["100pt", "100pt", "100pt"], 20,
                [[0, 30, 100, 20], [120, 30, 100, 20], [0, 0, 100, 20]], [220, 50]],
            "RTL row lines" => ["width:220pt;direction:rtl;gap:20pt", ["100pt", "100pt", "100pt"], 20,
                [[120, 0, 100, 20], [0, 0, 100, 20], [120, 40, 100, 20]], [220, 60]],
            "RTL row reverse and wrap reverse" => ["width:220pt;direction:rtl;flex-direction:row-reverse;flex-wrap:wrap-reverse;gap:10pt 20pt", ["100pt", "100pt", "100pt"], 20,
                [[0, 30, 100, 20], [120, 30, 100, 20], [0, 0, 100, 20]], [220, 50]],
            "gap percentage uses content box" => ["width:200pt;padding:10pt;border:2pt solid;column-gap:10%", ["60pt", "60pt"], 20,
                [[12, 12, 60, 20], [92, 12, 60, 20]], [224, 44]],
            "auto cross percentage stays cyclic" => ["width:100pt;row-gap:10%", ["100pt", "100pt"], 20,
                [[0, 0, 100, 20], [0, 20, 100, 20]], [100, 40]],
            "auto cross calc retains length" => ["width:100pt;row-gap:calc(5pt + 10%)", ["100pt", "100pt"], 20,
                [[0, 0, 100, 20], [0, 25, 100, 20]], [100, 45]]
        ];
    }

    /** @dataProvider lineGapGeometryProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('lineGapGeometryProvider')]
    public function testLineMembershipGapsAndPhysicalCrossExtent(string $css, array $widths, float $height, array $expected, array $container): void
    {
        $html = '<div data-test="container" style="display:flex;flex-wrap:wrap;align-items:flex-start;align-content:flex-start;' . $css . '">';
        foreach ($widths as $index => $width) {
            $html .= '<div data-test="item' . $index . '" style="flex:none;min-width:0;width:' . $width . ';height:' . $height . 'pt"></div>';
        }
        $result = $this->layout($html . '</div>', '', 1);
        $this->assertSame(1, $result["pages"]);
        foreach ($expected as $index => $box) {
            $this->assertBox($result, "item" . $index, $box);
        }
        $this->assertBox($result, "container", [0, 0, $container[0], $container[1]]);
    }

    public static function columnGapGeometryProvider(): array
    {
        return [
            "LG07 column unequal gaps" => ["height:100pt;gap:10pt 30pt", 30, 3, [[0, 0], [0, 40], [70, 0]], 100],
            "LG11 auto column" => ["flex-wrap:nowrap;gap:10pt 30pt", 30, 3, [[0, 0], [0, 40], [0, 80]], 110],
            "LG12 auto column cannot wrap to page height" => ["gap:10pt 30pt", 30, 3, [[0, 0], [0, 40], [0, 80]], 110],
            "definite column percentage" => ["height:100pt;row-gap:10%", 30, 3, [[0, 0], [0, 40], [40, 0]], 100],
            "cyclic column percentage" => ["row-gap:10%", 20, 2, [[0, 0], [0, 20]], 40],
            "cyclic column calc" => ["row-gap:calc(5pt + 10%)", 20, 2, [[0, 0], [0, 25]], 45],
            "definite column calc" => ["height:100pt;row-gap:calc(5pt + 10%)", 20, 2, [[0, 0], [0, 35]], 100],
            "column reversed cross lines" => ["height:100pt;flex-wrap:wrap-reverse;gap:10pt 30pt", 30, 3, [[100, 0], [100, 40], [30, 0]], 100],
            "column RTL reversed both axes" => ["height:100pt;direction:rtl;flex-direction:column-reverse;flex-wrap:wrap-reverse;gap:10pt 30pt", 30, 3,
                [[0, 70], [0, 30], [70, 70]], 100]
        ];
    }

    /** @dataProvider columnGapGeometryProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('columnGapGeometryProvider')]
    public function testColumnLineMembershipAndPhysicalGapMapping(string $css, float $height, int $count, array $positions, float $containerHeight): void
    {
        $html = '<div data-test="container" style="display:flex;flex-flow:column wrap;width:140pt;align-items:flex-start;align-content:flex-start;' . $css . '">';
        for ($index = 0; $index < $count; $index++) {
            $html .= '<div data-test="item' . $index . '" style="flex:none;width:40pt;height:' . $height . 'pt"></div>';
        }
        $result = $this->layout($html . '</div>', '', 1);
        foreach ($positions as $index => $position) {
            $this->assertBox($result, "item" . $index, [$position[0], $position[1], 40, $height]);
        }
        $this->assertBox($result, "container", [0, 0, 140, $containerHeight]);
    }

    public function testWrappingUsesOuterHypotheticalSizesThenFlexesEachLine(): void
    {
        $grow = $this->layout('<div style="display:flex;flex-wrap:wrap;width:220pt;gap:20pt;align-items:flex-start;align-content:flex-start">'
            . '<div data-test="A" class="item"></div><div data-test="B" class="item"></div><div data-test="C" class="item"></div></div>',
            '.item {flex:1 1 100pt;min-width:0;height:20pt}');
        $this->assertBox($grow, "A", [0, 0, 100, 20]);
        $this->assertBox($grow, "B", [120, 0, 100, 20]);
        $this->assertBox($grow, "C", [0, 40, 220, 20]);
        $shrink = $this->layout('<div style="display:flex;flex-wrap:wrap;width:100pt;gap:10pt;align-items:flex-start;align-content:flex-start">'
            . '<div data-test="A" style="flex:0 1 120pt;min-width:0;height:20pt"></div>'
            . '<div data-test="B" style="flex:0 1 80pt;min-width:0;height:20pt"></div></div>');
        $this->assertBox($shrink, "A", [0, 0, 100, 20]);
        $this->assertBox($shrink, "B", [0, 30, 80, 20]);
        $negative = $this->layout('<div data-test="container" style="display:flex;flex-wrap:wrap;width:100pt;align-items:flex-start;align-content:flex-start">'
            . '<div data-test="A" style="flex:none;width:60pt;height:20pt;margin-right:-20pt"></div>'
            . '<div data-test="B" style="flex:none;width:60pt;height:20pt"></div></div>');
        $this->assertBox($negative, "A", [0, 0, 60, 20]);
        $this->assertBox($negative, "B", [40, 0, 60, 20]);
        $this->assertBox($negative, "container", [0, 0, 100, 20]);
    }

    public function testWrappedRowsPreserveAllPhysicalLinesAndSourceOrderAcrossPages(): void
    {
        foreach (["wrap" => ["ABCD", "EF"], "wrap-reverse" => ["EFCD", "AB"]] as $wrap => $pages) {
            $html = '<div data-test="container" style="display:flex;flex-wrap:' . $wrap . ';width:200pt;align-items:flex-start;align-content:flex-start">';
            foreach (str_split("ABCDEF") as $letter) {
                $html .= '<div data-test="' . $letter . '" style="flex:none;width:100pt;height:40pt">' . $letter . '</div>';
            }
            $result = $this->layout($html . '</div><div data-test="after">AFTER</div>', '@page {size:200pt 100pt}', 2);
            $this->assertSame(2, $result["pages"]);
            foreach ($pages as $pageIndex => $letters) {
                foreach (str_split($letters) as $index => $letter) {
                    $this->assertCount(1, $result["boxes"][$letter]);
                    $this->assertSame($pageIndex + 1, $result["boxes"][$letter][0]["page"]);
                    $this->assertBox($result, $letter, [($index % 2) * 100, intdiv($index, 2) * 40, 100, 40]);
                }
            }
            $remaining = str_split($pages[1]);
            sort($remaining);
            $this->assertSame($remaining, $result["boxes"]["container"][1]["source"]);
            $this->assertBox($result, "container", [0, 0, 200, 80]);
            $this->assertBox($result, "container", [0, 0, 200, 40], 1);
            $this->assertBox($result, "after", [0, 40]);
            $this->assertSame(2, $result["boxes"]["after"][0]["page"]);
        }
    }

    public function testWrappedRowContinuesLongPeerBeforeUntouchedLaterLine(): void
    {
        $html = '<div data-test="container" style="display:flex;flex-wrap:wrap;width:200pt;row-gap:10pt;align-items:flex-start;align-content:flex-start">'
            . '<div data-test="A" style="flex:none;width:100pt">';
        for ($i = 1; $i <= 8; $i++) {
            $html .= '<div style="height:20pt;page-break-inside:avoid">A' . $i . '</div>';
        }
        $result = $this->layout($html . '</div><div data-test="B" style="flex:none;width:100pt;height:20pt">B</div>'
            . '<div data-test="C" style="flex:none;width:100pt;height:20pt">C</div></div><div data-test="after" style="height:10pt;line-height:10pt">AFTER</div>',
            '@page {size:200pt 100pt}', 2);
        $this->assertSame(2, $result["pages"]);
        $this->assertSame("A1A2A3A4A5B", implode("", array_column($result["text"][1], 0)));
        $this->assertSame("A6A7A8CAFTER", implode("", array_column($result["text"][2], 0)));
        $this->assertBox($result, "B", [100, 0, 100, 20]);
        $this->assertCount(1, $result["boxes"]["B"]);
        $this->assertBox($result, "C", [0, 70, 100, 20]);
        $this->assertSame(2, $result["boxes"]["C"][0]["page"]);
        $this->assertBox($result, "after", [0, 90]);
    }

    public function testFragmentBoundaryDiscardsOnlyTheSeparatingGap(): void
    {
        foreach (["row", "column"] as $direction) {
            $result = $this->layout('<div data-test="container" style="display:flex;flex-direction:' . $direction . ';flex-wrap:wrap;width:100pt;row-gap:20pt;align-items:flex-start;align-content:flex-start">'
                . '<div data-test="A" style="flex:none;width:100pt;height:60pt">A</div>'
                . '<div data-test="B" style="flex:none;width:100pt;height:60pt">B</div></div><div data-test="after">AFTER</div>',
                '@page {size:200pt 100pt}', 2);
            $this->assertSame(2, $result["pages"]);
            $this->assertSame("A", implode("", array_column($result["text"][1], 0)));
            $this->assertSame("BAFTER", implode("", array_column($result["text"][2], 0)));
            $this->assertBox($result, "B", [0, 0, 100, 60]);
            $this->assertBox($result, "container", [0, 0, 100, 60]);
            $this->assertBox($result, "container", [0, 0, 100, 60], 1);
            $this->assertBox($result, "after", [0, 60]);
        }
    }

    public function testWrappedColumnsKeepIndependentForcedRemaindersAndTrailingExtent(): void
    {
        $result = $this->layout('<div data-test="container" style="display:flex;flex-flow:column wrap;width:200pt;height:80pt;align-items:flex-start;align-content:flex-start">'
            . '<div data-test="A" style="flex:none;width:100pt;height:60pt"><div style="height:20pt">A1</div>'
            . '<div style="height:20pt;page-break-before:always">A2</div></div>'
            . '<div data-test="B" style="flex:none;width:100pt;height:60pt">B</div></div><div data-test="after">AFTER</div>',
            '@page {size:200pt 100pt}', 2);
        $this->assertSame(2, $result["pages"]);
        $this->assertSame("A1B", implode("", array_column($result["text"][1], 0)));
        $this->assertSame("A2AFTER", implode("", array_column($result["text"][2], 0)));
        $this->assertBox($result, "B", [100, 0, 100, 60]);
        $this->assertCount(1, $result["boxes"]["B"]);
        $this->assertBox($result, "A", [0, 0, 100, 40], 1);
        $this->assertBox($result, "container", [0, 0, 200, 60], 1);
        $this->assertBox($result, "after", [0, 60]);
    }

    public function testNestedWrappedActualItemKeepsItsOriginalSlotAndAllLaterLines(): void
    {
        $html = '<div style="display:flex;width:300pt"><div data-test="S" style="flex:none;width:100pt;height:20pt">S</div>'
            . '<div data-test="inner" style="display:flex;flex:none;width:200pt;flex-wrap:wrap;align-items:flex-start;align-content:flex-start">';
        foreach (str_split("ABCDEF") as $letter) {
            $html .= '<div data-test="' . $letter . '" style="flex:none;width:100pt;height:40pt">' . $letter . '</div>';
        }
        $result = $this->layout($html . '</div></div><div data-test="after">AFTER</div>', '@page {size:300pt 100pt}', 2);
        $this->assertSame(2, $result["pages"]);
        $this->assertSame("SABCD", implode("", array_column($result["text"][1], 0)));
        $this->assertSame("EFAFTER", implode("", array_column($result["text"][2], 0)));
        $this->assertBox($result, "S", [0, 0, 100, 20]);
        $this->assertCount(1, $result["boxes"]["S"]);
        $this->assertBox($result, "inner", [100, 0, 200, 40], 1);
        $this->assertBox($result, "E", [100, 0, 100, 40]);
        $this->assertBox($result, "F", [200, 0, 100, 40]);
        $this->assertBox($result, "after", [0, 40]);
    }

    public function testWrappedRowContinuationKeepsOriginalPercentageHeightReference(): void
    {
        foreach (["160pt", "100%"] as $height) {
            $html = '<div style="display:flex;flex-wrap:wrap;width:200pt;height:160pt;align-items:flex-start;align-content:flex-start">'
                . '<div data-test="A" style="flex:none;width:100pt;height:' . $height . '">';
            for ($i = 1; $i <= 5; $i++) {
                $html .= '<div style="height:20pt;page-break-inside:avoid">A' . $i . '</div>';
            }
            $html .= '<div data-test="percent" style="height:25%;page-break-inside:avoid">P</div></div>'
                . '<div style="flex:none;width:100pt;height:20pt">B</div></div><div data-test="after">AFTER</div>';
            $result = $this->layout($html, '@page {size:200pt 100pt}', 2);
            $this->assertSame(2, $result["pages"]);
            $this->assertBox($result, "percent", [0, 0, 100, 40]);
            $this->assertSame(2, $result["boxes"]["percent"][0]["page"]);
            $this->assertBox($result, "A", [0, 0, 100, 60], 1);
        }
    }

    public function testNativeTextAtFlexedWidthDeterminesLaterLineCrossPosition(): void
    {
        $result = $this->layout('<div data-test="container" style="display:flex;flex-wrap:wrap;width:200pt;gap:10pt 20pt;align-items:flex-start;align-content:flex-start">'
            . '<div data-test="A" class="item">MMMM MMMM MMMM</div>'
            . '<div data-test="B" class="item" style="height:20pt"></div><div data-test="C" class="item" style="height:20pt"></div></div>',
            '.item {flex:1 1 60pt;min-width:0}', 1);
        // Times-Roman's native 20pt line-height occupies 19.8pt per line.
        $this->assertBox($result, "A", [0, 0, 90, 39.6]);
        $this->assertBox($result, "B", [110, 0, 90, 20]);
        $this->assertBox($result, "C", [0, 49.6, 200, 20]);
        $this->assertBox($result, "container", [0, 0, 200, 69.6]);
        $this->assertSame(["MMMM MMMM", "MMMM"], array_column($result["text"][1], 0));
    }

    public function testLineCrossExtentIncludesAsymmetricOuterEdges(): void
    {
        $result = $this->layout('<div data-test="container" style="display:flex;flex-wrap:wrap;width:220pt;gap:7pt 20pt;align-items:flex-start;align-content:flex-start">'
            . '<div data-test="A" style="flex:none;width:60pt;height:20pt;padding:4pt 10pt 6pt;border:1pt solid;margin:3pt 5pt 5pt"></div>'
            . '<div data-test="B" style="flex:none;width:100pt;height:20pt"></div><div data-test="C" style="flex:none;width:100pt;height:20pt"></div></div>');
        $this->assertBox($result, "A", [5, 3, 82, 32]);
        $this->assertBox($result, "B", [112, 0, 100, 20]);
        $this->assertBox($result, "C", [0, 47, 100, 20]);
        $this->assertBox($result, "container", [0, 0, 220, 67]);
    }

    public function testWrappedNativeImageCrossSizeUsesItsFinalAssignedWidth(): void
    {
        $result = $this->layout('<div data-test="container" style="display:flex;flex-wrap:wrap;width:150pt;gap:10pt;align-items:flex-start;align-content:flex-start">'
            . '<img data-test="A" class="item" src="' . $this->image() . '"><img data-test="B" class="item" src="' . $this->image() . '"></div>',
            '.item {flex:none;width:100pt;min-width:0}', 1);
        $this->assertBox($result, "A", [0, 0, 100, 50]);
        $this->assertBox($result, "B", [0, 60, 100, 50]);
        $this->assertBox($result, "container", [0, 0, 150, 110]);
    }

    public function testWrapReverseMapsFlexStartWithinUnequalCrossSizeLine(): void
    {
        $result = $this->layout('<div data-test="container" style="display:flex;flex-wrap:wrap-reverse;width:220pt;gap:10pt 20pt;align-items:flex-start;align-content:flex-start">'
            . '<div data-test="A" style="flex:none;width:100pt;height:10pt"></div>'
            . '<div data-test="B" style="flex:none;width:100pt;height:20pt"></div>'
            . '<div data-test="C" style="flex:none;width:100pt;height:30pt"></div></div>');
        $this->assertBox($result, "A", [0, 50, 100, 10]);
        $this->assertBox($result, "B", [120, 40, 100, 20]);
        $this->assertBox($result, "C", [0, 0, 100, 30]);
        $this->assertBox($result, "container", [0, 0, 220, 60]);
    }

    public function testContinuationRetainsOriginalPercentageGapAndItemHeightReference(): void
    {
        $html = '<div style="display:flex;flex-flow:column wrap;width:200pt;height:200pt;row-gap:10%;align-items:flex-start;align-content:flex-start">'
            . '<div data-test="A" style="flex:none;width:100pt;height:160pt">';
        for ($i = 1; $i <= 5; $i++) {
            $html .= '<div style="height:20pt;page-break-inside:avoid">A' . $i . '</div>';
        }
        $result = $this->layout($html . '<div data-test="P" style="height:25%;page-break-inside:avoid">P</div></div>'
            . '<div data-test="B" style="flex:none;width:100pt;height:20pt">B</div></div><div data-test="after">AFTER</div>',
            '@page {size:200pt 100pt}', 3);
        $this->assertSame(3, $result["pages"]);
        $this->assertBox($result, "P", [0, 0, 100, 40]);
        $this->assertBox($result, "A", [0, 0, 100, 60], 1);
        $this->assertBox($result, "B", [0, 80, 100, 20]);
        $this->assertSame(2, $result["boxes"]["B"][0]["page"]);
        $this->assertSame("PB", implode("", array_column($result["text"][2], 0)));
        $this->assertSame("AFTER", implode("", array_column($result["text"][3], 0)));
    }

    public function testAutoColumnMaxHeightLimitsLinesWithoutResolvingCyclicPercentages(): void
    {
        $result = $this->layout('<div data-test="container" style="display:flex;flex-flow:column wrap;width:200pt;max-height:100pt;row-gap:10%;align-items:flex-start;align-content:flex-start">'
            . '<div data-test="A" style="flex:none;width:100pt;height:40pt"></div>'
            . '<div data-test="B" style="flex:none;width:100pt;height:40pt"></div>'
            . '<div data-test="C" style="flex:none;width:100pt;height:40pt"><div data-test="P" style="height:50%"></div></div></div>');
        $this->assertBox($result, "A", [0, 0, 100, 40]);
        $this->assertBox($result, "B", [0, 40, 100, 40]);
        $this->assertBox($result, "C", [100, 0, 100, 40]);
        $this->assertBox($result, "P", [100, 0, 100, 20]);
        $this->assertBox($result, "container", [0, 0, 200, 100]);
    }

    public function testWholeDeferredPeerKeepsItsFullHeightBeforeLaterWrappedLine(): void
    {
        $result = $this->layout('<div style="height:40pt">LEAD</div><div data-test="container" style="display:flex;flex-wrap:wrap;width:200pt;row-gap:10pt;align-items:flex-start;align-content:flex-start">'
            . '<div data-test="A" style="flex:none;width:100pt;height:20pt;page-break-inside:avoid">A</div>'
            . '<div data-test="B" style="flex:none;width:100pt;height:80pt;page-break-inside:avoid">B</div>'
            . '<div data-test="C" style="flex:none;width:100pt;height:20pt;page-break-inside:avoid">C</div></div><div data-test="after">AFTER</div>',
            '@page {size:200pt 100pt}', 3);
        $this->assertSame(3, $result["pages"]);
        $this->assertSame("LEADA", implode("", array_column($result["text"][1], 0)));
        $this->assertSame("B", implode("", array_column($result["text"][2], 0)));
        $this->assertSame("CAFTER", implode("", array_column($result["text"][3], 0)));
        $this->assertBox($result, "B", [100, 0, 100, 80]);
        $this->assertBox($result, "C", [0, 0, 100, 20]);
        $this->assertSame(3, $result["boxes"]["C"][0]["page"]);
        $this->assertBox($result, "after", [0, 20]);
    }

    public function testDifferentPeerPrefixesLeaveTheLongestActualRemainderBeforeLaterLine(): void
    {
        $html = '<div style="display:flex;flex-wrap:wrap;width:200pt;row-gap:10pt;align-items:flex-start;align-content:flex-start">';
        foreach (["A", "B"] as $letter) {
            $html .= '<div style="flex:none;width:100pt">';
            for ($i = 1; $i <= 8; $i++) {
                $html .= '<div style="height:20pt;page-break-inside:avoid;' . ($letter === "B" && $i === 2 ? 'page-break-before:always' : '') . '">' . $letter . $i . '</div>';
            }
            $html .= '</div>';
        }
        $result = $this->layout($html . '<div data-test="C" style="flex:none;width:100pt;height:20pt">C</div></div><div data-test="after">AFTER</div>',
            '@page {size:200pt 100pt}', 3);
        $this->assertSame(3, $result["pages"]);
        $this->assertSame("A1A2A3A4A5B1", implode("", array_column($result["text"][1], 0)));
        $this->assertSame("A6A7A8B2B3B4B5B6", implode("", array_column($result["text"][2], 0)));
        $this->assertSame("B7B8CAFTER", implode("", array_column($result["text"][3], 0)));
        $this->assertBox($result, "C", [0, 50, 100, 20]);
        $this->assertSame(3, $result["boxes"]["C"][0]["page"]);
        $this->assertBox($result, "after", [0, 70]);
    }

    public function testReversedWrappedLinesKeepPreparedGeneratedSourceCounters(): void
    {
        $html = '<div data-test="container" style="display:flex;flex-wrap:wrap-reverse;width:200pt;align-items:flex-start;align-content:flex-start">';
        foreach (str_split("ABCDEFGH") as $letter) {
            $html .= '<div data-test="' . $letter . '" class="counter" style="flex:none;width:100pt;height:40pt">' . $letter . '</div>';
        }
        $result = $this->layout($html . '</div><div data-test="after" class="after">AFTER</div>',
            '@page {size:200pt 100pt}body {counter-reset:n}.counter {counter-increment:n}.counter:before,.after:before {content:counter(n)}', 2);
        $this->assertSame(2, $result["pages"]);
        $this->assertSame("5E6F7G8H", implode("", array_column($result["text"][1], 0)));
        $this->assertSame("1A2B3C4D8AFTER", implode("", array_column($result["text"][2], 0)));
        $this->assertSame(["A", "B", "C", "D"], $result["boxes"]["container"][1]["source"]);
        $this->assertBox($result, "A", [0, 40, 100, 40]);
        $this->assertBox($result, "C", [0, 0, 100, 40]);
        $this->assertBox($result, "after", [0, 80]);
    }

    public static function nestedGapReferenceProvider(): array
    {
        return [
            "numeric indefinite" => ["auto", "10%", 40, 20],
            "numeric indefinite calc" => ["auto", "calc(5pt + 10%)", 45, 25],
            "definite basis control" => ["40pt", "10%", 40, 24]
        ];
    }

    /** @dataProvider nestedGapReferenceProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('nestedGapReferenceProvider')]
    public function testNestedAssignedHeightDoesNotInventGapDefiniteness(string $basis, string $gap, float $height, float $secondY): void
    {
        $result = $this->layout('<div data-test="outer" style="display:flex;flex-direction:column;width:100pt;align-items:flex-start">'
            . '<div data-test="inner" style="display:flex;flex:0 0 ' . $basis . ';flex-wrap:wrap;width:100pt;row-gap:' . $gap . ';align-items:flex-start;align-content:flex-start">'
            . '<div data-test="A" style="flex:none;width:100pt;height:20pt">A</div>'
            . '<div data-test="B" style="flex:none;width:100pt;height:20pt">B</div></div></div><div data-test="after">AFTER</div>', '', 1);
        $this->assertBox($result, "outer", [0, 0, 100, $height]);
        $this->assertBox($result, "inner", [0, 0, 100, $height]);
        $this->assertBox($result, "B", [0, $secondY, 100, 20]);
        $this->assertBox($result, "after", [0, $height]);
        $this->assertSame(["A", "B", "AFTER"], array_column($result["text"][1], 0));
    }

    public function testOuterLineUsesPreparedNestedRemainderIncludingRemainingEdges(): void
    {
        foreach (["" => [40, 40, 50, 70], "margin-bottom:3pt;padding-bottom:4pt;border-bottom:2pt solid" => [40, 46, 59, 79]] as $edges => $expected) {
            $html = '<div style="display:flex;flex-wrap:wrap;width:300pt;row-gap:10pt;align-items:flex-start;align-content:flex-start">'
                . '<div style="flex:none;width:100pt;height:20pt">S</div>'
                . '<div data-test="inner" style="display:flex;flex:none;flex-wrap:wrap;width:200pt;row-gap:10pt;align-items:flex-start;align-content:flex-start;' . $edges . '">';
            foreach (str_split("ABCDEF") as $letter) {
                $html .= '<div style="flex:none;width:100pt;height:40pt">' . $letter . '</div>';
            }
            $result = $this->layout($html . '</div><div data-test="T" style="flex:none;width:100pt;height:20pt">T</div></div>'
                . '<div data-test="after" style="height:10pt;line-height:10pt">AFTER</div>', '@page {size:300pt 100pt}', 2);
            $this->assertSame(2, $result["pages"]);
            $this->assertSame("SABCD", implode("", array_column($result["text"][1], 0)));
            $this->assertSame("EFTAFTER", implode("", array_column($result["text"][2], 0)));
            $this->assertEqualsWithDelta($expected[0], $result["boxes"]["inner"][1]["content"][3], 0.01);
            $this->assertBox($result, "inner", [100, 0, 200, $expected[1]], 1);
            $this->assertBox($result, "T", [0, $expected[2], 100, 20]);
            $this->assertBox($result, "after", [0, $expected[3]]);
        }
    }

    public function testRemainingLineCrossSizeFloorsSignedNestedOuterSizeAtZero(): void
    {
        $html = '<div style="display:flex;flex-wrap:wrap;width:300pt;row-gap:10pt;align-items:flex-start;align-content:flex-start">'
            . '<div style="flex:none;width:100pt;height:20pt">S</div>'
            . '<div data-test="inner" style="display:flex;flex:none;flex-wrap:wrap;width:200pt;row-gap:10pt;margin-bottom:-100pt;align-items:flex-start;align-content:flex-start">';
        foreach (str_split("ABCDEF") as $letter) {
            $content = $letter === "C" ? '<div style="height:1pt"></div><div style="height:39pt;page-break-before:always">C</div>' : $letter;
            $html .= '<div style="flex:none;width:100pt;height:40pt">' . $content . '</div>';
        }
        $result = $this->layout($html . '</div><div data-test="T" style="flex:none;width:100pt;height:20pt">T</div></div>'
            . '<div data-test="after" style="height:10pt;line-height:10pt">AFTER</div>', '@page {size:300pt 100pt}', 2);
        $this->assertSame(2, $result["pages"]);
        $this->assertSame("SABD", implode("", array_column($result["text"][1], 0)));
        $this->assertSame("CEFTAFTER", implode("", array_column($result["text"][2], 0)));
        $this->assertEqualsWithDelta(89, $result["boxes"]["inner"][1]["content"][3], 0.01);
        $this->assertBox($result, "inner", [100, 0, 200, 89], 1);
        $this->assertSame(2, $result["boxes"]["T"][0]["page"]);
        $this->assertBox($result, "T", [0, 10, 100, 20]);
        $this->assertSame(2, $result["boxes"]["after"][0]["page"]);
        $this->assertBox($result, "after", [0, 30]);
    }

    public function testNestedNowrapRemainderDoesNotClaimUnknownExtentIsZero(): void
    {
        $html = '<div style="display:flex;flex-wrap:wrap;width:300pt;row-gap:10pt;align-items:flex-start;align-content:flex-start">'
            . '<div style="flex:none;width:100pt;height:20pt">S</div><div style="display:flex;flex:none;width:200pt"><div style="flex:none;width:200pt">';
        for ($i = 1; $i <= 8; $i++) {
            $html .= '<div style="height:20pt;page-break-inside:avoid">A' . $i . '</div>';
        }
        $result = $this->layout($html . '</div></div><div data-test="T" style="flex:none;width:100pt;height:20pt">T</div></div>'
            . '<div data-test="after" style="height:10pt;line-height:10pt">AFTER</div>', '@page {size:300pt 100pt}', 2);
        $this->assertSame("SA1A2A3A4A5", implode("", array_column($result["text"][1], 0)));
        $this->assertSame("A6A7A8TAFTER", implode("", array_column($result["text"][2], 0)));
        $this->assertBox($result, "T", [0, 70, 100, 20]);
        $this->assertBox($result, "after", [0, 90]);
        $this->assertSame(2, $result["pages"]);
    }

    public function testPreparedCountersSurviveWholeItemDeferralWithoutReplay(): void
    {
        $result = $this->layout('<div style="height:60pt">LEAD</div><div style="display:flex;width:200pt">'
            . '<div data-test="A" class="counter" style="flex:none;width:100pt;height:60pt">A</div>'
            . '<div data-test="B" class="counter" style="flex:none;width:100pt;height:20pt">B</div></div><div class="after">AFTER</div>',
            '@page {size:200pt 100pt}body {counter-reset:n}.counter {counter-increment:n}.counter:before,.after:before {content:counter(n)}', 2);
        $this->assertSame(2, $result["pages"]);
        $this->assertSame("LEAD2B", implode("", array_column($result["text"][1], 0)));
        $this->assertSame("1A2AFTER", implode("", array_column($result["text"][2], 0)));
        $this->assertSame(2, $result["boxes"]["A"][0]["page"]);
        $this->assertSame(1, $result["boxes"]["B"][0]["page"]);
    }

    public static function directionGeometryProvider(): array
    {
        return [
            "row reverse LTR" => ["row-reverse", "ltr", "flex:none;width:100pt;height:20pt", [200, 0, 100, 20], [100, 0, 100, 20]],
            "row RTL" => ["row", "rtl", "flex:none;width:100pt;height:20pt", [200, 0, 100, 20], [100, 0, 100, 20]],
            "row reverse RTL" => ["row-reverse", "rtl", "flex:none;width:100pt;height:20pt", [0, 0, 100, 20], [100, 0, 100, 20]],
            "column grows" => ["column", "ltr", "flex:1 1 100pt;min-height:0;width:100pt", [0, 0, 100, 150], [0, 150, 100, 150]],
            "column reverse" => ["column-reverse", "ltr", "flex:none;width:100pt;height:100pt", [0, 200, 100, 100], [0, 100, 100, 100]],
            "column RTL cross start" => ["column", "rtl", "flex:1 1 100pt;min-height:0;width:100pt", [200, 0, 100, 150], [200, 150, 100, 150]]
        ];
    }

    public function testAutoColumnPreservesEveryItemAcrossPhysicalPagesInEitherDirection(): void
    {
        foreach (["column" => ["ABCDE", "FAFTER"], "column-reverse" => ["FEDCB", "AAFTER"]] as $direction => $expected) {
            $html = '<article><div style="display:flex;flex-direction:' . $direction . ';width:200pt">';
            foreach (str_split("ABCDEF") as $letter) {
                $html .= '<div data-test="' . $letter . '" style="flex:none;width:100pt;height:20pt">' . $letter . '</div>';
            }
            $result = $this->layout($html . '</div></article><div data-test="after">AFTER</div>', '@page {size:200pt 100pt}', 2);
            $this->assertSame(2, $result["pages"]);
            foreach ([1, 2] as $page) {
                $tokens = $result["text"][$page];
                usort($tokens, function ($a, $b) {
                    return $a[2] <=> $b[2];
                });
                $this->assertSame($expected[$page - 1], implode("", array_column($tokens, 0)));
            }
            foreach (str_split($expected[0]) as $index => $letter) {
                $this->assertBox($result, $letter, [0, $index * 20, 100, 20]);
            }
            $this->assertBox($result, $expected[1][0], [0, 0, 100, 20]);
            $this->assertBox($result, "after", [0, 20]);
        }
    }

    public function testColumnReplacedMinimumUsesSmallerNaturalAndTransferredSuggestion(): void
    {
        $result = $this->layout('<div style="display:flex;flex-direction:column;width:200pt;height:80pt">'
            . '<img data-test="image" src="' . $this->image() . '" style="width:200pt;flex:0 1 auto;align-self:flex-start"></div>');
        $this->assertBox($result, "image", [0, 0, 200, 80]);
    }

    public function testAutoColumnOuterMinMaxConstrainExtentWithoutCreatingDefiniteness(): void
    {
        $minimum = $this->layout('<div data-test="row" style="display:flex;flex-direction:column;width:100pt;min-height:100pt">'
            . '<div data-test="item" style="flex:none;height:50%"><div style="height:20pt"></div><div data-test="percent" style="height:50%"></div></div>'
            . '</div><div data-test="after">AFTER</div>');
        $this->assertBox($minimum, "row", [0, 0, 100, 100]);
        $this->assertBox($minimum, "item", [0, 0, 100, 20]);
        $this->assertEqualsWithDelta(0, $minimum["boxes"]["percent"][0]["content"][3], 0.01);
        $this->assertBox($minimum, "after", [0, 100]);
        $maximum = $this->layout('<div data-test="row" style="display:flex;flex-direction:column;width:100pt;max-height:100pt">'
            . '<div data-test="A" style="flex:0 1 auto;min-height:0;height:80pt">A</div>'
            . '<div data-test="B" style="flex:0 1 auto;min-height:0;height:80pt">B</div></div>');
        $this->assertBox($maximum, "row", [0, 0, 100, 100]);
        $this->assertBox($maximum, "A", [0, 0, 100, 50]);
        $this->assertBox($maximum, "B", [0, 50, 100, 50]);
    }

    public static function emptyColumnExtentProvider(): array
    {
        return [
            "column minimum" => ["column", "min-height:100pt", 100],
            "reverse minimum" => ["column-reverse", "min-height:100pt", 100],
            "column auto control" => ["column", "", 0],
            "reverse auto control" => ["column-reverse", "", 0]
        ];
    }

    /** @dataProvider emptyColumnExtentProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('emptyColumnExtentProvider')]
    public function testEmptyAutoColumnsHonorOuterMinimumAndFollowingFlow(string $direction, string $constraint, float $height): void
    {
        $result = $this->layout('<div data-test="column" style="display:flex;flex-direction:' . $direction . ';width:100pt;' . $constraint . '"></div>'
            . '<div data-test="after">AFTER</div>');
        $this->assertBox($result, "column", [0, 0, 100, $height]);
        $this->assertBox($result, "after", [0, $height]);
        $this->assertSame("AFTER", implode("", array_column($result["text"][1], 0)));
        $this->assertSame(1, $result["pages"]);
    }

    public function testReverseColumnContinuationKeepsSourceChildrenAndCounters(): void
    {
        $html = '<div data-test="row" style="display:flex;flex-direction:column-reverse;width:200pt">';
        foreach (str_split("ABCDEFGH") as $letter) {
            $html .= '<div data-test="' . $letter . '" class="item" style="flex:none;width:100pt;height:20pt">' . $letter . '</div>';
        }
        $result = $this->layout($html . '</div><div class="after">AFTER</div>', '@page {size:200pt 100pt}body {counter-reset:n}'
            . '.item {counter-increment:n}.item:before,.after:before {content:"N" counter(n)}', 2);
        $this->assertSame(["A", "B", "C"], $result["boxes"]["row"][1]["source"]);
        $this->assertSame("N1AN2BN3CN8AFTER", implode("", array_column($result["text"][2], 0)));
        $this->assertSame("N4DN5EN6FN7GN8H", implode("", array_column($result["text"][1], 0)));
        $this->assertBox($result, "C", [0, 0, 100, 20]);
        $this->assertBox($result, "B", [0, 20, 100, 20]);
        $this->assertBox($result, "A", [0, 40, 100, 20]);
    }

    public function testAutoColumnDistinguishesZeroLengthAndUnresolvedPercentageBasis(): void
    {
        foreach (["0 0 0pt" => 0, "0 0 0%" => 100, "0 0" => 0] as $flex => $height) {
            $result = $this->layout('<div data-test="row" style="display:flex;flex-direction:column;width:100pt;min-height:0">'
                . '<div data-test="item" style="flex:' . $flex . ';min-height:0"><div data-test="green" style="height:100pt"></div>'
                . '<div data-test="red" style="height:100%"></div></div></div>');
            $this->assertEqualsWithDelta($height, $result["boxes"]["row"][0]["content"][3], 0.01);
            $this->assertEqualsWithDelta($height, $result["boxes"]["item"][0]["content"][3], 0.01);
            $this->assertEqualsWithDelta(100, $result["boxes"]["green"][0]["content"][3], 0.01);
            $this->assertEqualsWithDelta(0, $result["boxes"]["red"][0]["content"][3], 0.01);
        }
    }

    public function testColumnPartialItemRetainsLaterSiblingAndLogicalPercentageReference(): void
    {
        foreach (["40pt", "25%"] as $tailHeight) {
            $html = '<article><div style="display:flex;flex-direction:column;width:200pt"><div data-test="A" style="flex:none;width:100pt;height:160pt">';
            for ($i = 1; $i <= 6; $i++) {
                $html .= '<div style="height:20pt;page-break-inside:avoid">A' . $i . '</div>';
            }
            $result = $this->layout($html . '<div data-test="tail" style="height:' . $tailHeight . ';page-break-inside:avoid">TAIL</div></div>'
                . '<div data-test="B" style="flex:none;width:100pt;height:20pt">B</div></div></article><div data-test="after">AFTER</div>', '@page {size:200pt 100pt}', 2);
            $this->assertSame(2, $result["pages"]);
            $this->assertSame("A1A2A3A4A5", implode("", array_column($result["text"][1], 0)));
            $this->assertSame("A6TAILBAFTER", implode("", array_column($result["text"][2], 0)));
            $this->assertBox($result, "tail", [0, 20, 100, 40]);
            $this->assertBox($result, "B", [0, 60, 100, 20]);
            $this->assertBox($result, "after", [0, 80]);
        }
    }

    public function testColumnWholeDeferralKeepsEmptyBlockOrImageAndUntouchedSibling(): void
    {
        foreach ([false, true] as $image) {
            $item = $image ? '<img data-test="A" src="' . $this->image() . '" style="flex:none;width:60pt;height:30pt">'
                : '<div data-test="A" style="flex:none;width:60pt;height:30pt"></div>';
            $result = $this->layout('<div style="height:80pt">LEAD</div><article><div style="display:flex;flex-direction:column;width:200pt">'
                . $item . '<div data-test="B" style="flex:none;width:100pt;height:20pt">B</div></div></article><div data-test="after">AFTER</div>',
                '@page {size:200pt 100pt}', 2);
            $this->assertSame(2, $result["pages"]);
            $this->assertSame("LEAD", implode("", array_column($result["text"][1], 0)));
            $this->assertSame("BAFTER", implode("", array_column($result["text"][2], 0)));
            $this->assertCount(1, $result["boxes"]["A"]);
            $this->assertSame(2, $result["boxes"]["A"][0]["page"]);
            $this->assertBox($result, "A", [0, 0, 60, 30]);
            $this->assertBox($result, "B", [0, 30, 100, 20]);
            $this->assertBox($result, "after", [0, 50]);
        }
    }

    public function testColumnOversizedAndZeroHeightItemsMakeProgress(): void
    {
        foreach ([150, 0] as $height) {
            $result = $this->layout('<div style="display:flex;flex-direction:column;width:200pt"><div data-test="A" style="flex:none;width:100pt;height:' . $height . 'pt"></div>'
                . '<div data-test="B" style="flex:none;width:100pt;height:20pt">B</div></div><div data-test="after">AFTER</div>', '@page {size:200pt 100pt}', 2);
            $this->assertSame($height ? 2 : 1, $result["pages"]);
            $this->assertCount(1, $result["boxes"]["A"]);
            $this->assertBox($result, "A", [0, 0, 100, $height]);
            $this->assertBox($result, "B", [0, 0, 100, 20]);
            $this->assertBox($result, "after", [0, 20]);
        }
    }

    public function testColumnBoundaryForcedBreakKeepsUntouchedItems(): void
    {
        $html = '<div style="display:flex;flex-direction:column;width:200pt">';
        foreach (["A", "B", "C"] as $letter) {
            $html .= '<div data-test="' . $letter . '" style="flex:none;width:100pt;height:20pt;' . ($letter === "B" ? 'page-break-before:always' : '') . '">' . $letter . '</div>';
        }
        $result = $this->layout($html . '</div><div data-test="after">AFTER</div>', '@page {size:200pt 100pt}', 2);
        $this->assertSame("A", implode("", array_column($result["text"][1], 0)));
        $this->assertSame("BCAFTER", implode("", array_column($result["text"][2], 0)));
        $this->assertBox($result, "B", [0, 0, 100, 20]);
        $this->assertBox($result, "C", [0, 20, 100, 20]);
        $this->assertBox($result, "after", [0, 40]);
    }

    public function testAutoColumnLongItemKeepsUntouchedSiblingFromFullOrPartialPage(): void
    {
        foreach ([0, 20] as $lead) {
            $html = $lead ? '<div style="height:20pt">LEAD</div>' : '';
            $html .= '<div style="display:flex;flex-direction:column;width:200pt"><div style="flex:none;width:100pt">';
            for ($i = 1; $i <= 8; $i++) {
                $html .= '<div style="height:20pt;page-break-inside:avoid">A' . $i . '</div>';
            }
            $result = $this->layout($html . '</div><div data-test="B" style="flex:none;width:100pt;height:20pt">B</div></div>'
                . '<div data-test="after" style="height:20pt">AFTER</div>', '@page {size:200pt 100pt}', 3);
            $this->assertSame($lead ? 3 : 2, $result["pages"]);
            $this->assertSame($lead ? "LEADA1A2A3A4" : "A1A2A3A4A5", implode("", array_column($result["text"][1], 0)));
            $this->assertSame($lead ? "A5A6A7A8B" : "A6A7A8BAFTER", implode("", array_column($result["text"][2], 0)));
            if ($lead) {
                $this->assertSame("AFTER", implode("", array_column($result["text"][3], 0)));
            }
            $this->assertBox($result, "B", [0, $lead ? 80 : 60, 100, 20]);
            $this->assertBox($result, "after", [0, $lead ? 0 : 80]);
        }
    }

    public function testNestedColumnContinuationKeepsOuterNeighborAndOriginalReference(): void
    {
        $html = '<article><div style="display:flex;width:200pt"><div data-test="column" style="display:flex;flex-direction:column;flex:none;width:100pt;height:180pt">'
            . '<div style="flex:none;width:100pt;height:calc(100% - 20pt)">';
        for ($i = 1; $i <= 6; $i++) {
            $html .= '<div style="height:20pt;page-break-inside:avoid">A' . $i . '</div>';
        }
        $result = $this->layout($html . '<div data-test="tail" style="height:25%;page-break-inside:avoid">TAIL</div></div>'
            . '<div style="flex:none;width:100pt;height:20pt">B</div></div><div data-test="neighbor" style="flex:none;width:100pt;height:20pt">NEIGHBOR</div>'
            . '</div></article><div data-test="after">AFTER</div>', '@page {size:200pt 100pt}', 2);
        $this->assertSame(2, $result["pages"]);
        $this->assertSame("A1A2A3A4A5NEIGHBOR", implode("", array_column($result["text"][1], 0)));
        $this->assertSame("A6TAILBAFTER", implode("", array_column($result["text"][2], 0)));
        $this->assertCount(1, $result["boxes"]["neighbor"]);
        $this->assertBox($result, "neighbor", [100, 0, 100, 20]);
        $this->assertBox($result, "tail", [0, 20, 100, 40]);
        $this->assertBox($result, "column", [0, 0, 100, 80], 1);
        $this->assertBox($result, "after", [0, 80]);
    }

    public function testAxisPlacementCountsAsymmetricEdgesOnceAndKeepsOrderTies(): void
    {
        $row = $this->layout('<div style="display:flex;flex-direction:row-reverse;width:300pt">'
            . '<div data-test="A" style="flex:none;width:50pt;height:20pt;padding:0 10pt 0 5pt;border-left:1pt solid;border-right:1pt solid;margin:0 3pt 0 7pt">A</div>'
            . '<div data-test="B" style="flex:none;width:40pt;height:20pt;padding:0 4pt 0 2pt;border-left:3pt solid;border-right:1pt solid;margin:0 9pt 0 5pt">B</div></div>');
        $this->assertBox($row, "A", [230, 0, 67, 20]);
        $this->assertBox($row, "B", [164, 0, 50, 20]);
        $column = $this->layout('<div style="display:flex;flex-direction:column;width:100pt">'
            . '<div data-test="A" style="flex:none;width:100pt;height:50pt;padding:5pt 0 10pt;border-top:1pt solid;border-bottom:1pt solid;margin:7pt 0 3pt">A</div>'
            . '<div data-test="B" style="flex:none;width:100pt;height:40pt;padding:2pt 0 4pt;border-top:3pt solid;border-bottom:1pt solid;margin:5pt 0 9pt">B</div></div><div data-test="after">AFTER</div>');
        $this->assertBox($column, "A", [0, 7, 100, 67]);
        $this->assertBox($column, "B", [0, 82, 100, 50]);
        $this->assertBox($column, "after", [0, 141]);
        $ties = $this->layout('<div data-test="row" style="display:flex;width:200pt"><div data-test="A" style="flex:none;width:50pt">A</div>'
            . '<div data-test="B" style="flex:none;width:50pt;order:-1">B</div><div data-test="C" style="flex:none;width:50pt">C</div></div>');
        $this->assertBox($ties, "A", [50, 0, 50]);
        $this->assertBox($ties, "B", [0, 0, 50]);
        $this->assertBox($ties, "C", [100, 0, 50]);
        $this->assertSame(["A", "B", "C"], $ties["boxes"]["row"][0]["source"]);
    }

    /** @dataProvider directionGeometryProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('directionGeometryProvider')]
    public function testHorizontalWritingModeMapsAxesWithoutChangingSourceOrder(string $direction, string $textDirection, string $item, array $a, array $b): void
    {
        $result = $this->layout('<div data-test="row" style="display:flex;width:300pt;height:300pt;flex-direction:' . $direction . ';direction:' . $textDirection . '">'
            . '<div data-test="A" style="align-self:flex-start;' . $item . '">A</div><div data-test="B" style="align-self:flex-start;' . $item . '">B</div>'
            . '</div><div data-test="after">AFTER</div>', '@page {size:500pt 1000pt}', 1);
        $this->assertBox($result, "A", $a);
        $this->assertBox($result, "B", $b);
        $this->assertSame(["A", "B"], $result["boxes"]["row"][0]["source"]);
        $this->assertEqualsWithDelta(300, $result["boxes"]["after"][0]["box"][1], 0.01);
        $this->assertSame("ABAFTER", implode("", array_column($result["text"][1], 0)));
    }

    public function testSourceCounterContentIsPreparedBeforeAnySortedIntrinsicProbe(): void
    {
        foreach ([[false, "row", 0, 12], [true, "row", 18, 0], [false, "row-reverse", 88, 70], [true, "row-reverse", 70, 82]] as [$ordered, $direction, $ax, $bx]) {
            $result = $this->layout('<div data-test="row" style="display:flex;width:100pt;flex-direction:' . $direction . '"><div data-test="A" class="counter" style="order:' . ($ordered ? 2 : 0) . '">A</div>'
                . '<div data-test="B" class="counter" style="order:' . ($ordered ? 1 : 0) . '">B</div></div><div class="after">AFTER</div>',
                'body {font:10pt/20pt Courier;counter-reset:n 8}.counter {flex:none;counter-increment:n}.counter:before,.after:before {content:counter(n)}');
            $this->assertBox($result, "A", [$ax, 0, 12]);
            $this->assertBox($result, "B", [$bx, 0, 18]);
            $this->assertSame(["A", "B"], $result["boxes"]["row"][0]["source"]);
            $this->assertSame("9A10B10AFTER", implode("", array_column($result["text"][1], 0)));
        }
    }

    public function testPreparedNestedScopesIncludeBeforeAfterAndPositionedSourceChildren(): void
    {
        $result = $this->layout('<div data-test="row" style="display:flex;width:200pt">'
            . '<div class="item a" style="order:2">A<span style="counter-reset:m 4"><i style="counter-increment:m 2">X</i></span></div>'
            . '<div style="position:absolute;counter-increment:n 3">P</div><div class="item" style="order:1">B</div>'
            . '<div style="display:none;counter-increment:n 100">HIDDEN</div></div><div class="after">AFTER</div>',
            'body {counter-reset:n}.item {flex:none;width:80pt;counter-increment:n}.item:before,.a:after,.after:before {content:counter(n)}i:before {content:counter(m)}');
        $text = implode("", array_column($result["text"][1], 0));
        $this->assertSame(1, substr_count($text, "P"));
        $this->assertSame("1A6X15B5AFTER", str_replace("P", "", $text));
    }

    public function testPreparedGeneratedInlineSuffixSurvivesLineAndPageSplits(): void
    {
        $result = $this->layout('<div style="display:flex;width:200pt"><div class="a" style="flex:none;width:80pt;counter-increment:n">'
            . '<span style="counter-increment:n 3"><i></i><br>END</span></div><div class="b" style="flex:none;width:80pt;counter-increment:n">B</div>'
            . '</div><div class="after">AFTER</div>', '@page {size:200pt 100pt}body {counter-reset:n}.a:before,.b:before {content:"N" counter(n)}'
            . '.a:after {content:"E" counter(n)}.after:before {content:"Z" counter(n)}'
            . 'i:before {content:"T1\A T2\A T3\A T4\A T5\A T6\A T7\A T8";white-space:pre;font:10pt/30pt Times-Roman}', 4);
        $this->assertGreaterThan(1, $result["pages"]);
        $text = "";
        foreach ($result["text"] as $page) {
            $text .= implode("", array_column($page, 0));
        }
        foreach (["N1", "N5", "E4", "Z5", "END", "AFTER", "T1", "T2", "T3", "T4", "T5", "T6", "T7", "T8"] as $token) {
            $this->assertSame(1, substr_count($text, $token), $token);
        }
    }

    public function testPreparedListPageFragmentsDoNotRepeatMarkers(): void
    {
        $html = '<div style="display:flex;width:200pt"><section style="flex:none;width:100pt"><ol style="margin:0;padding-left:20pt"><li>';
        for ($i = 1; $i <= 8; $i++) {
            $html .= '<div style="height:20pt;page-break-inside:avoid">A' . $i . '</div>';
        }
        $result = $this->layout($html . '</li><li>B</li></ol></section></div><div>AFTER</div>', '@page {size:200pt 100pt}', 3);
        $this->assertSame(["1", "2"], $result["bullets"]);
        $text = "";
        foreach ($result["text"] as $page) {
            $text .= implode("", array_column($page, 0));
        }
        $this->assertSame("A1A2A3A4A5A6A7A8BAFTER", $text);
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
        $html = '<article><div style="display:flex;width:200pt;align-items:flex-start">';
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

    public function testDefaultStretchUsesTallestContentWithoutReplayingShortPeer(): void
    {
        $html = '<div style="display:flex;width:200pt">';
        foreach (["A" => 3, "B" => 8] as $prefix => $count) {
            $html .= '<div data-test="' . $prefix . '" style="flex:1 1 50pt;min-width:0">';
            for ($i = 1; $i <= $count; $i++) {
                $html .= '<div style="height:20pt">' . $prefix . $i . '</div>';
            }
            $html .= '</div>';
        }
        $result = $this->layout($html . '</div><div data-test="after">AFTER</div>', '', 1);
        $this->assertBox($result, "A", [0, 0, 100, 160]);
        $this->assertBox($result, "B", [100, 0, 100, 160]);
        $this->assertBox($result, "after", [0, 160]);
        $this->assertSame("A1A2A3B1B2B3B4B5B6B7B8AFTER", implode("", array_column($result["text"][1], 0)));
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
