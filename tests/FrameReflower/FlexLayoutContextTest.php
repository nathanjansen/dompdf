<?php
namespace Dompdf\Tests\FrameReflower;

use DOMElement;
use Dompdf\Dompdf;
use Dompdf\FrameDecorator\AbstractFrameDecorator;
use Dompdf\FrameReflower\FlexLayoutContext;
use Dompdf\Tests\TestCase;
use Symfony\Component\Process\Process;

class FlexLayoutContextTest extends TestCase
{
    public function testAssignedMeasurementCarriesNumericLayoutToPrivateCopy(): void
    {
        $dompdf = $this->document('<div style="display:flex;width:300pt"><section id="item" style="width:200pt;flex:0 0 100pt">AAAA AAAA AAAA AAAA</section></div>');
        $this->inspectEntry($dompdf, function ($item) {
            $before = $this->snapshot($item->get_root());
            $context = new FlexLayoutContext($item, true, null);
            foreach ([1, 2] as $repeat) {
                $result = $context->layout();
                $fragment = $result["fragment"];
                $this->assertEquals(100, $fragment->get_content_box()["w"]);
                $this->assertEqualsWithDelta(39.6, $result["consumed_block_size"], 0.01);
                $this->assertEquals(300, $fragment->get_containing_block("w"));
                $this->assertSame("200pt", $fragment->get_style()->get_specified("width"));
                $this->assertSame($before, $this->snapshot($item->get_root()));
            }
        });
    }

    public function testNestedFlexMeasurementDoesNotFragmentOrCommit(): void
    {
        $children = str_repeat('<div style="height:20pt;page-break-inside:avoid">row</div>', 8);
        $dompdf = $this->document('<section id="item"><div style="display:flex;width:100pt"><div style="width:100pt">' . $children . '</div></div></section>');
        $this->inspectEntry($dompdf, function ($item) {
            $before = $this->snapshot($item->get_root());
            $context = new FlexLayoutContext($item, true, null);
            $result = $context->layout();
            $this->assertNull($result["continuation"]);
            $this->assertEquals(160, $result["consumed_block_size"]);
            $this->assertCount(8, $this->content($result["fragment"])[0]);
            $this->assertSame($before, $this->snapshot($item->get_root()));
        });
    }

    public function testRepeatedFlexedMeasurementPreservesLiveStateAndCallbacks(): void
    {
        $dompdf = $this->document('<div style="display:flex;width:150pt"><section id="item" style="width:200pt;flex:1 1 100pt;min-width:0">'
            . 'AAAA AAAA AAAA AAAA</section><div style="flex:1 1 100pt;min-width:0">B</div></div>',
            'body {counter-reset:step 10} #item {counter-increment:step 2} #item:before {content:"N" counter(step)}');
        $callbacks = 0;
        $this->inspectEntry($dompdf, function ($item) use (&$callbacks) {
            $before = $this->snapshot($item->get_root());
            $count = $callbacks;
            $context = new FlexLayoutContext($item, true, null);
            $first = null;
            foreach ([1, 2] as $repeat) {
                $result = $context->layout();
                $fragment = $result["fragment"];
                $this->assertEquals(75, $fragment->get_content_box()["w"]);
                $this->assertEquals(150, $fragment->get_containing_block("w"));
                $this->assertSame("200pt", $fragment->get_style()->get_specified("width"));
                $this->assertGreaterThan(20, $result["consumed_block_size"]);
                $text = implode("", $this->content($fragment)[0]);
                $this->assertSame("N12AAAAAAAAAAAAAAAA", preg_replace('/\s+/', '', $text));
                $geometry = [$fragment->get_content_box(), $result["consumed_block_size"]];
                if ($first !== null) {
                    $this->assertSame($first, $geometry);
                }
                $first = $geometry;
                $this->assertSame($before, $this->snapshot($item->get_root()));
                $this->assertSame($count, $callbacks);
            }
        }, function () use (&$callbacks) {
            $callbacks++;
        });
        $this->assertGreaterThan(0, $callbacks);
    }

    private function document(string $content, string $css = "", array $callbacks = []): Dompdf
    {
        $dompdf = new Dompdf();
        $dompdf->setCallbacks($callbacks);
        $dompdf->loadHtml('<!doctype html><html><head><style>@page {size:200pt 100pt;margin:0} body {margin:0;font:10pt/20pt Times-Roman} '
            . $css . '</style></head><body>' . $content . '</body></html>');
        return $dompdf;
    }

    private function snapshot(AbstractFrameDecorator $frame): array
    {
        $result = [];
        foreach ($frame->get_subtree() as $child) {
            $style = (array) $child->get_style();
            // The stylesheet is a shared resource; snapshot mutable Style inputs.
            unset($style["\0*\0_stylesheet"]);
            $result[] = [
                $child, $child->get_node(), $child->get_node()->nodeValue,
                $child->get_parent(), $child->get_prev_sibling(), $child->get_next_sibling(),
                $child->get_root(), $style, $child->_counters, $child->content_set,
                [$child->get_containing_block("x"), $child->get_containing_block("y"), $child->get_containing_block("w"), $child->get_containing_block("h")],
                [$child->get_position("x"), $child->get_position("y")]
            ];
        }
        return $result;
    }

    private function inspectEntry(Dompdf $dompdf, callable $inspect, ?callable $renderCallback = null): void
    {
        $callbacks = [[
            "event" => "begin_page_reflow",
            "f" => function ($body) use ($inspect) {
                foreach ($body->get_subtree() as $frame) {
                    $node = $frame->get_node();
                    if ($node instanceof DOMElement && $node->getAttribute("id") === "item") {
                        $frame->set_reflower(new EntryBlock($frame, $inspect));
                    }
                }
            }
        ]];
        if ($renderCallback) {
            $callbacks[] = ["event" => "begin_frame", "f" => $renderCallback];
            $callbacks[] = ["event" => "begin_page_render", "f" => $renderCallback];
        }
        $dompdf->setCallbacks($callbacks);
        $dompdf->render();
    }

    private function measured(AbstractFrameDecorator $item): array
    {
        return (new FlexLayoutContext($item, true, null))->layout();
    }

    private function content(AbstractFrameDecorator $item): array
    {
        $text = [];
        $bullets = [];
        foreach ($item->get_subtree() as $frame) {
            $node = $frame->get_node();
            if ($frame->is_text_node() && trim($node->nodeValue) !== "") {
                $text[] = $node->nodeValue;
            } elseif ($node->nodeName === "bullet") {
                $bullets[] = $node->getAttribute("dompdf-counter");
            }
        }
        return [$text, $bullets];
    }

    private function pageState($page): array
    {
        $state = (array) $page;
        return [$state["\0*\0_page_full"], $state["\0*\0bottom_page_edge"],
            $state["\0*\0_floating_frames"], $state["\0*\0_in_table"], $page->get_flex_context()];
    }

    public function testMeasurementDoesNotIncrementCounters(): void
    {
        $html = '<div style="counter-increment:step 3">prior</div><section id="item"><p>one<br>two</p><ol><li>three</li><li>four</li></ol></section><div>after</div>';
        $css = '@page {size:200pt 400pt} body {counter-reset:step 10;--size:10pt} '
            . '#item {width:100pt;counter-increment:step 2;font-size:var(--size)} '
            . '#item:before {content:"N" counter(step)} li {counter-increment:step} li:before {content:"L" counter(step)}';
        $baseline = null;
        $reference = $this->document($html, $css, [["event" => "begin_frame", "f" => function ($frame) use (&$baseline) {
            $node = $frame->get_node();
            if ($node instanceof DOMElement && $node->getAttribute("id") === "item") {
                $baseline = $this->content($frame);
            }
        }]]);
        $reference->render();
        $this->assertSame([["N15", "one", "two", "L16", "three", "L17", "four"], ["1", "2"]], $baseline);
        $dompdf = $this->document($html, $css);
        $this->inspectEntry($dompdf, function ($item) use ($baseline) {
            $before = $this->snapshot($item->get_root());
            $context = new FlexLayoutContext($item, true, null);
            foreach ([1, 2] as $probe) {
                $result = $context->layout();
                $this->assertSame($baseline, $this->content($result["fragment"]));
                $this->assertSame($before, $this->snapshot($item->get_root()));
            }
            // A snapshot remains usable after the live item generates content.
            $item->reflow();
            $this->assertSame($baseline, $this->content($item));
            $this->assertSame($baseline, $this->content($context->layout()["fragment"]));
            // Also preserve content_set when capturing already generated content.
            $this->assertSame($baseline, $this->content($this->measured($item)["fragment"]));
            $item->reset();
        });
    }

    public function testContextRestoredAfterException(): void
    {
        $dompdf = $this->document('<section id="item"><div>nested</div></section>');
        $this->inspectEntry($dompdf, function ($item) {
            $page = $item->get_root();
            $page->add_floating_frame($item);
            $page->table_reflow_start();
            $page->table_reflow_start();
            $full = new \ReflectionProperty($page, "_page_full");
            $full->setAccessible(true);
            $full->setValue($page, true);
            $before = $this->pageState($page);
            $child = $item->get_first_child();
            $child->set_reflower(new EntryBlock($child, function ($nested) use ($page) {
                $page->add_floating_frame($nested);
                $page->table_reflow_start();
                throw new \RuntimeException("nested reflow failed");
            }));
            try {
                (new FlexLayoutContext($item, false, 60.0))->layout();
                $this->fail("The nested item must execute and throw");
            } catch (\RuntimeException $exception) {
                $this->assertSame("nested reflow failed", $exception->getMessage());
            }
            $this->assertSame($before, $this->pageState($page));
            // The next ordinary reflow uses the original page, without stale state.
            $full->setValue($page, false);
            $page->table_reflow_end();
            $page->table_reflow_end();
            $page->remove_floating_frame(0);
            $item->reset();
        });
    }

    public function testFragmentationIncrementsItemCounterExactlyOnce(): void
    {
        foreach (["div", "span", "lines"] as $variant) {
            $wrapper = $variant === "div" ? "div" : "span";
            $children = $variant === "lines" ? 'A1<br>A2<br>A3' : '<p>A1</p><p>A2</p><p>A3</p>';
            $dompdf = $this->document('<section id="item"><' . $wrapper . ' style="counter-increment:step 3">' . $children . '</' . $wrapper . '></section><section id="sibling"></section>',
                'body {counter-reset:step 10} #item {counter-increment:step 2;orphans:1} #item:before {content:"N" counter(step);display:block;height:20pt} '
            . '#sibling:before {content:"B" counter(step)} p {height:20pt;margin:0;page-break-inside:avoid}');
            $this->inspectEntry($dompdf, function ($item) {
                $parent = $item->get_parent();
                $sibling = $item->get_next_sibling();
                $item->set_containing_block(0, 0, 100, 40);
                $result = (new FlexLayoutContext($item, false, 40.0))->layout();
                $scope = $item->lookup_counter_frame("step");
                $this->assertSame(15, $scope->_counters["step"], "Retained item and nested wrapper must retain their increments");
                $this->assertSame([["N12", "A1"], []], $this->content($result["fragment"]));
                $sibling->set_containing_block(100, 0, 100, 40);
                $siblingResult = (new FlexLayoutContext($sibling, false, 40.0))->layout();
                $this->assertSame([["B15"], []], $this->content($siblingResult["fragment"]));
                $next = $result["continuation"];
                $parent->append_child($next);
                $next->set_containing_block(0, 0, 100, 100);
                $retry = (new FlexLayoutContext($next, false, 100.0))->layout();
                $this->assertNull($retry["continuation"]);
                $this->assertSame([["A2", "A3"], []], $this->content($retry["fragment"]));
                $this->assertSame(15, $scope->_counters["step"], "A continuation must not increment the item or wrapper again");
                $parent->remove_child($next);
                $sibling->reset();
                $item->reset();
            });
        }
    }

    public function testNestedContextIsIsolated(): void
    {
        $dompdf = $this->document('<section id="item"><div><p>first</p><p style="page-break-before:always">second</p></div><div>neighbor</div></section>', 'p {margin:0;height:20pt}');
        $this->inspectEntry($dompdf, function ($item) {
            $page = $item->get_root();
            $outer = new FlexLayoutContext($item, false, 80.0);
            $page->push_flex_context($outer);
            try {
                $page->add_floating_frame($item);
                $before = $this->pageState($page);
                $nested = $item->get_first_child();
                $neighbor = $nested->get_next_sibling();
                $nested->set_containing_block(0, 0, 100, 80);
                $inner = new FlexLayoutContext($nested, false, 40.0);
                $page->push_flex_context($inner);
                try {
                    $this->assertSame([], $page->get_floating_frames());
                    try {
                        $page->pop_flex_context($outer);
                        $this->fail("An out-of-order pop must be rejected");
                    } catch (\LogicException $exception) {
                        $this->assertSame($inner, $page->get_flex_context());
                    }
                    $this->assertFalse($page->check_forced_page_break($neighbor));
                } finally {
                    $page->pop_flex_context($inner);
                }
                $result = $inner->layout();
                $this->assertSame("forced", $result["break_reason"]);
                $this->assertSame([["first"], []], $this->content($result["fragment"]));
                $this->assertSame([["second"], []], $this->content($result["continuation"]));
                $this->assertSame($before, $this->pageState($page));
                $this->assertSame($item, $neighbor->get_parent());
                $this->assertSame($neighbor, $nested->get_next_sibling());
                $nested->get_parent()->insert_child_after($result["continuation"], $nested);
            } finally {
                $page->pop_flex_context($outer);
            }
            $this->assertNull($page->get_flex_context());
            $item->reset();
        });
    }

    public function testWholeItemDeferralAndZeroHeightProgress(): void
    {
        $dompdf = $this->document('<section id="item"><div style="height:40pt;page-break-inside:avoid">oversize</div></section>');
        $this->inspectEntry($dompdf, function ($item) {
            $item->set_containing_block(0, 80, 100, 20);
            $result = (new FlexLayoutContext($item, false, 20.0))->layout();
            $this->assertNull($result["fragment"]);
            $this->assertSame($item, $result["continuation"]);
            $this->assertFalse($result["made_progress"]);
            $this->assertSame("deferred", $result["break_reason"]);
            $item->set_containing_block(0, 0, 100, 20);
            $result = (new FlexLayoutContext($item, false, 20.0))->layout();
            $this->assertTrue($result["made_progress"]);
            $this->assertNull($result["continuation"]);
            $this->assertSame([["oversize"], []], $this->content($result["fragment"]));
            $item->reset();
        });
        foreach (['', '<div style="page-break-before:always;height:0"></div>'] as $content) {
            $dompdf = $this->document('<section id="item">' . $content . '</section>');
            $this->inspectEntry($dompdf, function ($item) use ($content) {
                $result = (new FlexLayoutContext($item, false, 100.0))->layout();
                $this->assertTrue($result["made_progress"]);
                $this->assertEquals(0, $result["consumed_block_size"]);
                if ($content !== '') {
                    $this->assertSame("forced", $result["break_reason"]);
                    $next = $result["continuation"];
                    $item->get_parent()->append_child($next);
                    $next->set_containing_block(0, 0, 100, 100);
                    $retry = (new FlexLayoutContext($next, false, 100.0))->layout();
                    $this->assertTrue($retry["made_progress"]);
                    $this->assertNull($retry["continuation"]);
                }
            });
        }
    }

    public function testMeasurementDoesNotChangeLiveTree(): void
    {
        $dompdf = $this->document('<section id="item"><p>one<br>two</p><ol><li>three</li><li>four</li></ol></section><div style="margin-top:11pt">after</div>',
            '#item {width:100pt;margin:3pt 0 7pt} #item:before {content:"intro";display:block;height:20pt} '
            . 'p {height:40pt;margin:0} ol {margin:0;padding:0 0 0 15pt} li {height:20pt}');
        $callbacks = 0;
        $this->inspectEntry($dompdf, function ($item) use ($dompdf, &$callbacks) {
            $root = $item->get_root();
            $before = $this->snapshot($root);
            $page = $dompdf->getCanvas()->get_page_number();
            $callbackCount = $callbacks;
            $pageState = $this->pageState($root);
            $context = new FlexLayoutContext($item, true, null);
            $result = $context->layout();
            $this->assertSame($before, $this->snapshot($root), "A measurement must not mutate the live tree or styles");
            $again = $context->layout();
            $this->assertSame($before, $this->snapshot($root));
            $this->assertEquals(110.0, $result["consumed_block_size"]);
            $this->assertSame($result["consumed_block_size"], $again["consumed_block_size"]);
            $this->assertSame($page, $dompdf->getCanvas()->get_page_number());
            $this->assertSame($callbackCount, $callbacks);
            $this->assertSame($pageState, $this->pageState($root));
            foreach ($result["fragment"]->get_subtree() as $frame) {
                $this->assertNotSame($root, $frame->get_root());
                $this->assertSame($result["fragment"]->get_root(), $frame->get_root());
            }
        }, function () use (&$callbacks) {
            $callbacks++;
        });
        $this->assertGreaterThan(0, $callbacks);
    }

    public function testSplitCannotMoveOtherItemsOrEnclosingSiblings(): void
    {
        $dompdf = $this->document('<aside>before</aside><article><section id="item"><p>A1</p><p style="page-break-before:always">A2</p></section><section>B1</section><section>C1</section></article><aside>after</aside>',
            'aside {height:0;line-height:0;font-size:0} p {height:20pt;margin:0}');
        $this->inspectEntry($dompdf, function ($item) {
            $parent = $item->get_parent();
            $outer = $parent->get_parent();
            $items = iterator_to_array($parent->get_children());
            $siblings = iterator_to_array($outer->get_children());
            $result = (new FlexLayoutContext($item, false, 100.0))->layout();
            $this->assertSame("forced", $result["break_reason"]);
            $this->assertSame($items, iterator_to_array($parent->get_children()));
            $this->assertSame($siblings, iterator_to_array($outer->get_children()));
            $this->assertSame($parent, $items[1]->get_parent());
            $this->assertSame($parent, $items[2]->get_parent());
            $this->assertSame([["A1"], []], $this->content($result["fragment"]));
            $this->assertSame([["A2"], []], $this->content($result["continuation"]));
            $this->assertNull($result["continuation"]->get_parent());
            $parent->insert_child_after($result["continuation"], $item);
            $item->reset();
        });
    }

    public function testSiblingItemsProduceIndependentContinuations(): void
    {
        foreach ([[8, 6, false], [8, 3, false], [3, 3, true]] as [$aCount, $bCount, $forced]) {
            $html = '';
            foreach (["A" => $aCount, "B" => $bCount] as $name => $count) {
                $html .= '<section data-item="' . $name . '">';
                for ($i = 1; $i <= $count; $i++) {
                    $break = $forced && $name === "A" && $i === 2 ? ' style="page-break-before:always"' : '';
                    $html .= '<div data-token="' . $name . $i . '"' . $break . '>' . $name . $i . '</div>';
                }
                $html .= '</section>';
            }
            $tokens = [];
            $widths = [];
            $dompdf = $this->document($html,
                'section {width:100pt} [data-token] {height:20pt;page-break-inside:avoid}', [
                ["event" => "begin_page_reflow", "f" => function ($body) {
                    $body->set_reflower(new ParallelBlocks($body));
                }],
                ["event" => "begin_frame", "f" => function ($frame, $canvas) use (&$tokens, &$widths) {
                    $node = $frame->get_node();
                    $page = $canvas->get_page_number();
                    if ($node instanceof DOMElement && $node->hasAttribute("data-item")) {
                        $widths[$page][$node->getAttribute("data-item")][] = $frame->get_content_box()["w"];
                    }
                    if ($frame->is_text_node() && preg_match('/^[AB][1-8]$/', $node->nodeValue)) {
                        $tokens[$page][] = $node->nodeValue;
                        $x = $node->nodeValue[0] === "A" ? 0.0 : 100.0;
                        $this->assertEquals($x, $frame->get_position("x"));
                        $this->assertGreaterThanOrEqual(0, $frame->get_position("y"));
                        $this->assertLessThan(100, $frame->get_position("y"));
                    }
                }]
            ]);
            $dompdf->render();
            $expected = $forced ? [1 => ["A1", "B1", "B2", "B3"], 2 => ["A2", "A3"]] : [
                1 => array_merge(["A1", "A2", "A3", "A4", "A5", "B1", "B2", "B3"], $bCount === 6 ? ["B4", "B5"] : []),
                2 => $bCount === 6 ? ["A6", "A7", "A8", "B6"] : ["A6", "A7", "A8"]
            ];
            $this->assertSame($expected, $tokens);
            $this->assertSame(2, $dompdf->getCanvas()->get_page_count());
            $this->assertEquals([100.0], $widths[1]["A"]);
            $this->assertEquals([100.0], $widths[1]["B"]);
            $this->assertEquals([100.0], $widths[2]["A"]);
            if ($bCount === 6) {
                $this->assertEquals([100.0], $widths[2]["B"]);
            }
            // Read actual painted PDF text on each page, independently of callbacks.
            foreach ($expected as $page => $pageTokens) {
                $process = new Process(["gs", "-q", "-dBATCH", "-dNOPAUSE", "-sDEVICE=txtwrite",
                    "-dFirstPage=" . $page, "-dLastPage=" . $page, "-sOutputFile=-", "-"]);
                $process->setInput($dompdf->output());
                $process->mustRun();
                preg_match_all('/\b[AB][1-8]\b/', $process->getOutput(), $matches);
                sort($pageTokens);
                sort($matches[0]);
                $this->assertSame($pageTokens, $matches[0]);
            }
        }
    }
}
