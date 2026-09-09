<?php
/**
 * @package dompdf
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace Dompdf\FrameReflower;

use Dompdf\FrameDecorator\Block as BlockFrameDecorator;
use Dompdf\FrameDecorator\Image;
use Dompdf\Positioner\Flex as FlexPositioner;

/** Fixed row integration; native item reflowers own their content layout. */
class Flex extends Block
{
    private $content_height = 0.0;

    protected function _calculate_content_height(): float
    {
        return $this->content_height;
    }

    public function reflow(?BlockFrameDecorator $block = null)
    {
        $frame = $this->_frame;
        $page = $frame->get_root();
        $page->check_forced_page_break($frame);
        if ($page->is_full()) {
            return;
        }
        $this->determine_absolute_containing_block();
        $this->_set_content();
        $style = $frame->get_style();
        $cb = $frame->get_containing_block();
        [$width, $leftMargin, $rightMargin, $left, $right] = $this->_calculate_restricted_width();
        $style->set_used("width", $width);
        $style->set_used("margin_left", $leftMargin);
        $style->set_used("margin_right", $rightMargin);
        $style->set_used("left", $left);
        $style->set_used("right", $right);
        $frame->position();
        $height = $style->length_in_pt($style->height, $cb["h"]);
        $this->content_height = 0.0;
        [$minimumHeight] = $this->_calculate_restricted_height();
        $style->set_used("height", $minimumHeight);
        if ($page->check_flex_container_break($frame)) {
            return;
        }
        $style->set_used("height", $height);
        $box = $frame->get_content_box();
        $available = max(0.0, $page->get_bottom_page_edge() - $box["y"]);
        $available = max(0.0, $available - (float) $style->computed_bottom_spacing($cb["w"]));
        $childHeight = $height === "auto" ? $available : $height;
        $this->content_height = 0.0;
        $offset = 0.0;
        $continuations = [];

        foreach ($frame->get_flex_items() as $item) {
            $item->set_containing_block($box["x"], $box["y"], $width, $childHeight);
            $slot = $frame->get_continuation_slot($item);
            if ($slot === null) {
                $itemStyle = $item->get_style();
                $basis = $itemStyle->flex_basis;
                $basis = $basis === "auto" ? $itemStyle->width : $basis;
                if (is_numeric($basis) || \Dompdf\Helpers::is_percent($basis)) {
                    $itemWidth = (float) $itemStyle->length_in_pt($basis, $width);
                } elseif ($item instanceof Image) {
                    [$imageWidth] = $item->get_intrinsic_dimensions();
                    $itemWidth = $item->resample($imageWidth);
                } else {
                    // Intrinsic calls mutate text/counters: use the private entry snapshot.
                    $probe = new FlexLayoutContext($item, true, null);
                    $probe->get_item()->get_reflower()->_set_content();
                    [, $itemWidth] = $probe->get_item()->get_min_max_width();
                    $itemWidth -= (float) $itemStyle->length_in_pt([
                        $itemStyle->margin_left, $itemStyle->margin_right, $itemStyle->padding_left,
                        $itemStyle->padding_right, $itemStyle->border_left_width, $itemStyle->border_right_width
                    ], $width);
                }
                $itemHeight = $itemStyle->length_in_pt($itemStyle->height, $childHeight);
                $slot = ["offset" => $offset, "width" => max(0.0, $itemWidth), "height" => $itemHeight === "auto" ? null : $itemHeight];
            }
            $frame->set_item_layout($item, ["x" => $box["x"] + $slot["offset"], "y" => $box["y"],
                "width" => $slot["width"], "height" => $slot["height"], "definite_width" => true,
                "definite_height" => $slot["height"] !== null]);
            $itemStyle = $item->get_style();
            $offset = $slot["offset"] + $slot["width"] + (float) $itemStyle->length_in_pt([
                $itemStyle->margin_left, $itemStyle->margin_right, $itemStyle->padding_left,
                $itemStyle->padding_right, $itemStyle->border_left_width, $itemStyle->border_right_width
            ], $width);
            $item->set_positioner(new FlexPositioner());
            $result = (new FlexLayoutContext($item, false, $available))->layout();
            if ($result["fragment"]) {
                $this->content_height = max($this->content_height, $result["consumed_block_size"]);
            }
            if ($result["continuation"]) {
                $continuations[] = [$result["continuation"], $slot];
            }
        }

        [$usedHeight, $topMargin, $bottomMargin, $top, $bottom] = $this->_calculate_restricted_height();
        $style->set_used("height", $usedHeight);
        $style->set_used("margin_top", $topMargin);
        $style->set_used("margin_bottom", $bottomMargin);
        $style->set_used("top", $top);
        $style->set_used("bottom", $bottom);

        foreach ($frame->get_children() as $child) {
            if ($child->is_absolute()) {
                $child->set_containing_block($box["x"], $box["y"], $width, $childHeight);
                $child->reflow();
            }
        }
        if (!$continuations && $page->check_flex_container_break($frame)) {
            return;
        }
        if ($block && $frame->is_in_flow()) {
            $block->add_frame_to_line($frame);
            if ($frame->is_block_level()) {
                $block->add_line();
            }
        }

        if ($continuations) {
            $next = $frame->copy($frame->get_node()->cloneNode());
            $nextStyle = $next->get_style();
            $nextStyle->counter_increment = "none";
            $nextStyle->counter_reset = "none";
            foreach (["margin", "padding", "border"] as $edge) {
                $bottomProp = $edge === "border" ? "border_bottom_width" : $edge . "_bottom";
                $topProp = $edge === "border" ? "border_top_width" : $edge . "_top";
                $style->$bottomProp = 0.0;
                $nextStyle->$topProp = 0.0;
            }
            $nextStyle->page_break_before = "auto";
            foreach ($continuations as [$item, $slot]) {
                $next->append_child($item);
                $next->set_continuation_slot($item, $slot);
            }
            $page->commit_flex_fragment($frame, $next);
            $next->_counters = $frame->_counters;
        }
    }
}
