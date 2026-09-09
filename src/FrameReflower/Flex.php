<?php
/**
 * @package dompdf
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace Dompdf\FrameReflower;

use Dompdf\FrameDecorator\Block as BlockFrameDecorator;
use Dompdf\FrameDecorator\Image;
use Dompdf\FrameDecorator\AbstractFrameDecorator;
use Dompdf\Helpers;
use Dompdf\Positioner\Flex as FlexPositioner;

/** Fixed row integration; native item reflowers own their content layout. */
class Flex extends Block
{
    private $content_height = 0.0;

    protected function _calculate_content_height(): float
    {
        return $this->content_height;
    }

    private function item_sizes(AbstractFrameDecorator $item, float $width, ?float $height): array
    {
        $style = $item->get_style();
        $edges = (float) $style->length_in_pt([$style->padding_left, $style->padding_right,
            $style->border_left_width, $style->border_right_width], $width);
        $outer = $edges + (float) $style->length_in_pt([$style->margin_left, $style->margin_right], $width);
        $borderBox = $style->box_sizing === "border-box";
        $crossEdges = $borderBox ? (float) $style->length_in_pt([$style->padding_top, $style->padding_bottom,
            $style->border_top_width, $style->border_bottom_width], $width) : 0.0;
        $minHeight = $style->min_height === "auto" || ($height === null && Helpers::is_percent($style->min_height))
            ? 0.0 : max(0.0, (float) $style->length_in_pt($style->min_height, $height ?? 0.0) - $crossEdges);
        $maxHeight = $style->max_height === "none" || ($height === null && Helpers::is_percent($style->max_height))
            ? INF : max(0.0, (float) $style->length_in_pt($style->max_height, $height ?? 0.0) - $crossEdges);
        $itemHeight = $style->height === "auto" || ($height === null && Helpers::is_percent($style->height))
            ? null : max($minHeight, min((float) $style->length_in_pt($style->height, $height ?? 0.0) - $crossEdges, $maxHeight));
        $basis = $style->flex_basis;
        $preferred = $style->width;
        $basis = $basis === "auto" ? ($preferred === "auto" ? "content" : $preferred) : $basis;
        $intrinsic = in_array($basis, ["content", "min-content", "max-content", "fit-content"], true)
            || (is_string($basis) && strpos($basis, "fit-content(") === 0);
        $autoMin = $style->min_width === "auto" && in_array($style->overflow, ["visible", "clip"], true);
        $minContent = $maxContent = 0.0;
        if ($item instanceof Image) {
            [$imageWidth, $imageHeight] = $item->get_intrinsic_dimensions();
            $ratio = $imageWidth / $imageHeight;
            $minContent = $maxContent = max($minHeight * $ratio, min($item->resample($imageWidth), $maxHeight * $ratio));
        } elseif ($intrinsic || $autoMin) {
            $probe = (new FlexLayoutContext($item, true, null))->get_item();
            // Ignore only this root's preferred/min/max width; retain child constraints.
            $probe->get_style()->set_used("width", "auto");
            $probe->get_style()->set_used("min_width", "auto");
            $probe->get_style()->set_used("max_width", "none");
            $probe->get_reflower()->_set_content();
            if ($probe instanceof \Dompdf\FrameDecorator\Table) {
                // Table's cell-map metric includes full-reference edges, unlike Block.
                [$minContent, $maxContent] = $probe->get_min_max_width();
                $minContent -= $outer;
                $maxContent -= $outer;
            } else {
                [$minContent, $maxContent] = $probe->get_reflower()->get_min_max_content_width();
            }
        }
        if ($intrinsic) {
            if ($basis === "min-content") {
                $base = $minContent;
            } elseif ($basis === "fit-content" || strpos($basis, "fit-content(") === 0) {
                $limit = $basis === "fit-content" ? $width - $outer
                    : (float) $style->length_in_pt(substr($basis, 12, -1), $width);
                $base = max($minContent, min($limit, $maxContent));
            } else {
                $base = $maxContent;
            }
        } else {
            // §9.2 explicitly permits a negative inner border-box flex base.
            $base = (float) $style->length_in_pt($basis, $width) - ($borderBox ? $edges : 0.0);
        }
        if ($item instanceof Image) {
            if ($itemHeight !== null) {
                $minContent = min($minContent, $itemHeight * $ratio);
                if ($basis === "content") {
                    $base = $itemHeight * $ratio;
                }
            }
        }
        $max = $style->max_width === "none" ? INF
            : max(0.0, (float) $style->length_in_pt($style->max_width, $width) - ($borderBox ? $edges : 0.0));
        if ($autoMin) {
            $min = $minContent;
            if ($preferred !== "auto") {
                $min = min($min, max(0.0, (float) $style->length_in_pt($preferred, $width) - ($borderBox ? $edges : 0.0)));
            }
            $min = min($min, $max);
        } else {
            $min = $style->min_width === "auto" ? 0.0
                : max(0.0, (float) $style->length_in_pt($style->min_width, $width) - ($borderBox ? $edges : 0.0));
        }
        $max = max($min, $max);
        return ["base" => $base, "hypothetical" => max($min, min($base, $max)),
            "min" => $min, "max" => $max, "outer" => $outer,
            "grow" => $style->flex_grow, "shrink" => $style->flex_shrink, "height" => $itemHeight];
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
        $layout = $frame->get_flex_layout();
        $heightReference = $this->get_percentage_height_reference();
        $height = $layout ? ($layout["height"] ?? "auto")
            : ($heightReference === null && Helpers::is_percent($style->height)
                ? "auto" : $style->length_in_pt($style->height, $heightReference ?? 0));
        $this->content_height = 0.0;
        [$minimumHeight] = $this->_calculate_restricted_height();
        if ($height !== "auto" || ($frame->is_absolute()
            && $style->get_computed("top") !== "auto" && $style->get_computed("bottom") !== "auto")) {
            $height = $minimumHeight;
        }
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
        $items = $frame->get_flex_items();
        $slots = [];
        $sizing = [];

        // Resolve the complete line before any live item can split or generate content.
        foreach ($items as $index => $item) {
            $item->set_containing_block($box["x"], $box["y"], $width, $childHeight);
            $slot = $frame->get_continuation_slot($item);
            if ($slot === null) {
                $sizes = $this->item_sizes($item, $width, $height === "auto" ? null : $height);
                $sizing[] = $sizes;
                $slot = ["width" => $sizes["base"], "height" => $sizes["height"]];
            }
            $slots[$index] = $slot;
        }
        if ($sizing) {
            $sizes = FlexLine::resolve($sizing, $width, 0.0);
            foreach ($slots as $index => &$slot) {
                $slot["offset"] = $offset;
                $slot["width"] = $sizes[$index];
                $offset += $slot["width"] + $sizing[$index]["outer"];
            }
            unset($slot);
        }

        foreach ($items as $index => $item) {
            $slot = $slots[$index];
            $frame->set_item_layout($item, ["x" => $box["x"] + $slot["offset"], "y" => $box["y"],
                "width" => $slot["width"], "height" => $slot["height"], "definite_width" => true,
                "definite_height" => $slot["height"] !== null]);
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
