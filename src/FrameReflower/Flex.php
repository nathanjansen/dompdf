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

/** Horizontal-tb flex integration; native item reflowers own content layout. */
class Flex extends Block
{
    private $content_height = 0.0;

    protected function _calculate_content_height(): float
    {
        $layout = $this->_frame->get_flex_layout();
        return $this->content_height + array_sum($layout["content_spacing"] ?? []);
    }

    /** Leading and additional between-box space, excluding the authored gap. */
    private function alignment_spacing(string $alignment, float $free, int $count, bool $reverse = false, bool $horizontal = true, ?string $selfDirection = null): array
    {
        if (in_array($alignment, ["baseline", "first baseline", "last baseline"], true)) {
            $alignment = "safe " . ($selfDirection !== null ? "self-" : "")
                . ($alignment === "last baseline" ? "end" : "start");
        }
        $safe = strpos($alignment, "safe ") === 0;
        $alignment = preg_replace('/^(?:safe|unsafe) /', '', $alignment);
        if ($safe && $free < 0) {
            $alignment = "flex-start";
        }
        if (($alignment === "space-around" || $alignment === "space-evenly") && $free < 0) {
            $alignment = "flex-start";
        }
        if (in_array($alignment, ["start", "end", "self-start", "self-end", "left", "right"], true)) {
            $direction = strpos($alignment, "self-") === 0 ? $selfDirection : $this->_frame->get_style()->direction;
            $startAtEnd = $horizontal && $direction === "rtl";
            $end = in_array($alignment, ["end", "self-end"], true);
            if ($horizontal && ($alignment === "left" || $alignment === "right")) {
                $startAtEnd = false;
                $end = $alignment === "right";
            }
            $alignment = ($reverse !== ($startAtEnd !== $end)) ? "flex-end" : "flex-start";
        }
        if ($alignment === "flex-end") {
            return [$free, 0.0];
        }
        if ($alignment === "center") {
            return [$free / 2, 0.0];
        }
        if ($alignment === "space-between" && $count > 1 && $free > 0) {
            return [0.0, $free / ($count - 1)];
        }
        if ($alignment === "space-around" && $count > 0 && $free > 0) {
            return [$free / (2 * $count), $free / $count];
        }
        if ($alignment === "space-evenly" && $count > 0 && $free > 0) {
            return [$free / ($count + 1), $free / ($count + 1)];
        }
        return [0.0, 0.0];
    }

    /** Resolve shared content/self baselines from neutral, private native metrics. */
    private function content_baselines(array $members, array &$slots, array $sizing, array $items, float $lineSize, bool $crossReverse): array
    {
        $records = [];
        $first = $last = 0.0;
        foreach ($members as $index) {
            $slot = $slots[$index];
            $metric = $sizing[$index]["baseline_metrics"];
            $style = $items[$index]->get_style();
            $align = $style->align_self === "auto" ? $this->_frame->get_style()->align_items : $style->align_self;
            $content = $style->align_content;
            $selfFirst = in_array($align, ["baseline", "first baseline"], true);
            $selfLast = $align === "last baseline";
            $topAuto = isset($slot["margins"]["margin_top"]);
            $bottomAuto = isset($slot["margins"]["margin_bottom"]);
            $selfFirst = $selfFirst && !$topAuto && !$bottomAuto;
            $selfLast = $selfLast && !$topAuto && !$bottomAuto;
            $eligible = $items[$index] instanceof BlockFrameDecorator
                && !($items[$index] instanceof \Dompdf\FrameDecorator\Flex
                    && strpos($style->flex_direction, "column") === 0);
            $contentFirst = $eligible && in_array($content, ["baseline", "first baseline"], true);
            $contentLast = $eligible && $content === "last baseline";
            if ($eligible && ($selfFirst || $selfLast) && ($content === "normal" || $contentFirst || $contentLast)) {
                $contentFirst = $selfFirst;
                $contentLast = $selfLast;
            }
            $height = $slot["height"] ?? $metric["height"];
            $outer = $height + $metric["edges"];
            $before = $contentLast ? max(0.0, $height - $metric["content_height"]) : 0.0;
            $stretch = in_array($align, ["normal", "stretch"], true);
            $fallback = $stretch && ($contentFirst || $contentLast) ? ($contentLast ? "self-end" : "self-start") : $align;
            [$position] = $this->alignment_spacing($fallback, 1.0, 1, $crossReverse, false, $style->direction);
            $position = $crossReverse ? 1.0 - $position : $position;
            $fits = $outer <= $lineSize;
            $firstContent = $contentFirst && !$selfFirst && !$selfLast && (
                (!$topAuto && !$bottomAuto && $position === 0.0)
                || (!$topAuto && $bottomAuto && $fits));
            $lastContent = $contentLast && !$selfFirst && !$selfLast && (
                (!$topAuto && !$bottomAuto && $position === 1.0 && ($fits || strpos($align, "safe ") !== 0))
                || ($topAuto && !$bottomAuto && $fits));
            if ($selfFirst || $firstContent) {
                $first = max($first, $metric["baseline"] + $before);
            }
            if ($selfLast || $lastContent) {
                $last = max($last, $outer - $metric["last_baseline"] - $before);
            }
            $records[$index] = compact("height", "outer", "before", "selfFirst", "selfLast", "firstContent", "lastContent", "contentFirst", "contentLast");
        }
        $ascent = $descent = $lastAscent = $lastDescent = 0.0;
        foreach ($records as $index => $record) {
            $slot = &$slots[$index];
            $metric = $sizing[$index]["baseline_metrics"];
            $before = $record["before"];
            $after = 0.0;
            if ($record["firstContent"]) {
                $before += max(0.0, $first - $metric["baseline"] - $before);
            } elseif ($record["lastContent"]) {
                $after = max(0.0, $last - ($record["outer"] - $metric["last_baseline"] - $before));
                $before = max(0.0, $before - $after);
            }
            $height = $record["height"];
            if ($slot["height"] === null) {
                $height = max($height, min($metric["content_height"] + $before + $after, $sizing[$index]["cross_max"]));
            }
            if ($record["contentFirst"] || $record["contentLast"]) {
                $slot["content_spacing"] = [$before, $after];
            }
            $slot["cross_size"] = $height + $metric["edges"];
            $slot["baseline"] = $metric["baseline"] + $before;
            $slot["last_baseline"] = $metric["last_baseline"] + $before;
            unset($slot["baseline_group"]);
            if ($record["selfFirst"] || $record["firstContent"]) {
                $slot["baseline_group"] = "first";
                $ascent = max($ascent, $slot["baseline"]);
                $descent = max($descent, $slot["cross_size"] - $slot["baseline"]);
            }
            if ($record["selfLast"] || $record["lastContent"]) {
                $slot["baseline_group"] = "last";
                $lastAscent = max($lastAscent, $slot["last_baseline"]);
                $lastDescent = max($lastDescent, $slot["cross_size"] - $slot["last_baseline"]);
            }
            unset($slot);
        }
        return [$ascent, $descent, $lastAscent, $lastDescent];
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
            "grow" => $style->flex_grow, "shrink" => $style->flex_shrink, "height" => $itemHeight,
            "cross_min" => $minHeight, "cross_max" => max($minHeight, $maxHeight)];
    }

    private function column_item_sizes(AbstractFrameDecorator $item, float $width, ?float $height): array
    {
        $style = $item->get_style();
        $borderBox = $style->box_sizing === "border-box";
        $crossEdges = (float) $style->length_in_pt([$style->padding_left, $style->padding_right,
            $style->border_left_width, $style->border_right_width], $width);
        $crossOuter = $crossEdges + (float) $style->length_in_pt([$style->margin_left, $style->margin_right], $width);
        $cross = $style->width === "auto" ? $width - $crossOuter
            : (float) $style->length_in_pt($style->width, $width) - ($borderBox ? $crossEdges : 0.0);
        if ($style->width === "auto") {
            if ($item instanceof Image) {
                [$naturalWidth] = $item->get_intrinsic_dimensions();
                $cross = min($cross, $item->resample($naturalWidth));
            } else {
                $probe = (new FlexLayoutContext($item, true, null))->get_item();
                $probe->get_style()->set_used("width", "auto");
                $probe->get_style()->set_used("min_width", 0.0);
                $probe->get_style()->set_used("max_width", "none");
                [$minimum, $maximum] = $probe->get_reflower()->get_min_max_content_width();
                $cross = max($minimum, min($cross, $maximum));
            }
        }
        $crossMin = $style->min_width === "auto" ? 0.0
            : max(0.0, (float) $style->length_in_pt($style->min_width, $width) - ($borderBox ? $crossEdges : 0.0));
        $crossMax = $style->max_width === "none" ? INF
            : max(0.0, (float) $style->length_in_pt($style->max_width, $width) - ($borderBox ? $crossEdges : 0.0));
        $cross = max($crossMin, min($cross, $crossMax));
        $edges = (float) $style->length_in_pt([$style->padding_top, $style->padding_bottom,
            $style->border_top_width, $style->border_bottom_width], $width);
        $outer = $edges + (float) $style->length_in_pt([$style->margin_top, $style->margin_bottom], $width);
        $preferred = $style->height;
        $basis = $style->flex_basis === "auto" ? $preferred : $style->flex_basis;
        $intrinsic = $basis === "auto" || in_array($basis, ["content", "min-content", "max-content", "fit-content"], true)
            || (is_string($basis) && strpos($basis, "fit-content(") === 0)
            || ($height === null && Helpers::is_percent($basis));
        $autoMin = $style->min_height === "auto" && in_array($style->overflow, ["visible", "clip"], true);
        $content = 0.0;
        if ($intrinsic || $autoMin) {
            if ($item instanceof Image) {
                [$imageWidth, $imageHeight] = $item->get_intrinsic_dimensions();
                $content = $cross * $imageHeight / $imageWidth;
            } else {
                $context = new FlexLayoutContext($item, true, null);
                $probe = $context->get_item()->get_style();
                $probe->set_prop("height", "auto");
                $probe->set_used("width", $cross);
                $probe->set_used("min_width", 0.0);
                $probe->set_used("max_width", "none");
                $probe->set_used("min_height", 0.0);
                $probe->set_used("max_height", "none");
                $content = $context->layout()["fragment"]->get_content_box()["h"];
            }
        }
        $minimumContent = $content;
        if ($autoMin && $item instanceof Image) {
            [$imageWidth, $imageHeight] = $item->get_intrinsic_dimensions();
            $minimumContent = max($crossMin * $imageHeight / $imageWidth,
                min($item->resample($imageHeight), $crossMax * $imageHeight / $imageWidth));
            if ($style->width !== "auto") {
                $minimumContent = min($minimumContent, $content);
            }
        }
        $base = $intrinsic ? $content
            : (float) $style->length_in_pt($basis, $height ?? 0.0) - ($borderBox ? $edges : 0.0);
        $max = $style->max_height === "none" || ($height === null && Helpers::is_percent($style->max_height)) ? INF
            : max(0.0, (float) $style->length_in_pt($style->max_height, $height ?? 0.0) - ($borderBox ? $edges : 0.0));
        if ($autoMin) {
            $min = $minimumContent;
            if ($preferred !== "auto" && ($height !== null || !Helpers::is_percent($preferred))) {
                $min = min($min, max(0.0, (float) $style->length_in_pt($preferred, $height ?? 0.0) - ($borderBox ? $edges : 0.0)));
            }
            $min = min($min, $max);
        } else {
            $min = $style->min_height === "auto" || ($height === null && Helpers::is_percent($style->min_height)) ? 0.0
                : max(0.0, (float) $style->length_in_pt($style->min_height, $height ?? 0.0) - ($borderBox ? $edges : 0.0));
        }
        $max = max($min, $max);
        return ["base" => $base, "hypothetical" => max($min, min($base, $max)), "min" => $min, "max" => $max,
            "outer" => $outer, "grow" => $style->flex_grow, "shrink" => $style->flex_shrink,
            "width" => $cross, "cross_outer" => $crossOuter, "definite_height" => $height !== null || !$intrinsic,
            "cross_min" => $crossMin, "cross_max" => max($crossMin, $crossMax)];
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
        $this->prepare_content();
        $style = $frame->get_style();
        $column = in_array($style->flex_direction, ["column", "column-reverse"], true);
        $reverse = $column ? $style->flex_direction === "column-reverse"
            : (($style->direction === "rtl") !== ($style->flex_direction === "row-reverse"));
        $wrap = $style->flex_wrap !== "nowrap";
        $crossReverse = ($column && $style->direction === "rtl") !== ($style->flex_wrap === "wrap-reverse");
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
        $logicalHeight = $height === "auto" ? null : $height;
        if ($frame->get_continuation_extent() !== null) {
            $height = $minimumHeight = $frame->get_continuation_extent();
        }
        $style->set_used("height", $minimumHeight);
        if ($page->check_flex_container_break($frame)) {
            return;
        }
        $style->set_used("height", $height);
        $box = $frame->get_content_box();
        $box["y"] += $layout["content_spacing"][0] ?? 0.0;
        $available = max(0.0, $page->get_bottom_page_edge() - $box["y"]);
        $available = max(0.0, $available - (float) $style->computed_bottom_spacing($cb["w"]));
        $childHeight = $height === "auto" ? $available : $height;
        // Cyclic percentages resolve against zero, retaining calc() length terms.
        $widthReference = $style->display === "inline-flex" && $style->get_computed("width") === "auto" && !$layout ? 0.0 : $width;
        $gapHeight = $logicalHeight;
        if ($layout) {
            $gapHeight = $layout["definite_height"]
                ? (array_key_exists("reference_height", $layout) ? $layout["reference_height"] : $logicalHeight) : null;
        }
        $rowGap = $style->row_gap === "normal" ? 0.0 : (float) $style->length_in_pt($style->row_gap, $gapHeight ?? 0.0);
        $columnGap = $style->column_gap === "normal" ? 0.0 : (float) $style->length_in_pt($style->column_gap, $widthReference);
        $mainGap = $column ? $rowGap : $columnGap;
        $crossGap = $column ? $columnGap : $rowGap;
        $this->content_height = 0.0;
        $continuations = [];
        $items = $frame->get_flex_items();
        $sourceOrder = [];
        foreach ($frame->get_children() as $index => $child) {
            $sourceOrder[$child->get_id()] = $index;
        }
        $slots = [];
        $sizing = [];
        $lines = $frame->get_continuation_lines();
        $membership = [];
        $extent = $column ? ($height === "auto" ? 0.0 : $height) : $width;

        // Collect every sizing input before any live item can split prepared content.
        foreach ($items as $index => $item) {
            $item->set_containing_block($box["x"], $box["y"], $width, $childHeight);
            $slot = $frame->get_continuation_slot($item);
            if ($slot === null) {
                $sizes = $column ? $this->column_item_sizes($item, $width, $logicalHeight)
                    : $this->item_sizes($item, $width, $logicalHeight);
                $sizing[] = $sizes;
                $slot = $column ? ["width" => $sizes["width"], "height" => $sizes["base"],
                    "cross_outer" => $sizes["cross_outer"], "definite_height" => $sizes["definite_height"]]
                    : ["width" => $sizes["base"], "height" => $sizes["height"], "definite_height" => $sizes["height"] !== null];
                $slot["margins"] = [];
                foreach (["top", "right", "bottom", "left"] as $edge) {
                    if ($item->get_style()->get_computed("margin_" . $edge) === "auto") {
                        $slot["margins"]["margin_" . $edge] = 0.0;
                    }
                }
                if ($column && $height === "auto") {
                    // ponytail: inflexible/content-sized auto columns; flexible
                    // intrinsic main-size calculation remains the §9.9 goal10 seam.
                    $extent += $sizes["hypothetical"] + $sizes["outer"];
                }
            }
            $slots[$index] = $slot;
            if (isset($slot["line"])) {
                $membership[$slot["line"]][] = $index;
            }
        }
        if ($column && $height === "auto") {
            $extent += max(0, count($items) - 1) * $mainGap;
            $this->content_height = $extent;
            [$extent] = $this->_calculate_restricted_height();
            $this->content_height = 0.0;
        }
        if ($sizing) {
            $finiteMain = !$column || $logicalHeight !== null || ($style->max_height !== "none"
                && ($heightReference !== null || !Helpers::is_percent($style->max_height)));
            $lineId = 0;
            $length = 0.0;
            foreach ($sizing as $index => $sizes) {
                $outer = $sizes["hypothetical"] + $sizes["outer"];
                $gap = isset($membership[$lineId]) ? $mainGap : 0.0;
                if ($wrap && $finiteMain && isset($membership[$lineId]) && $length + $gap + $outer > $extent) {
                    $lineId++;
                    $length = $gap = 0.0;
                }
                $membership[$lineId][] = $index;
                $length += $gap + $outer;
            }
            $crossExtent = 0.0;
            foreach ($membership as $lineId => $members) {
                $lineSizing = [];
                foreach ($members as $index) {
                    $lineSizing[$index] = $sizing[$index];
                }
                $sizes = array_combine($members, FlexLine::resolve($lineSizing, $extent, $mainGap));
                $offset = 0.0;
                $crossSize = 0.0;
                $ascent = $descent = 0.0;
                $lastAscent = $lastDescent = 0.0;
                foreach ($members as $index) {
                    $slot = &$slots[$index];
                    $slot["line"] = $lineId;
                    $slot["offset"] = $reverse ? $extent - $offset - $sizes[$index] - $sizing[$index]["outer"] : $offset;
                    $slot[$column ? "height" : "width"] = $sizes[$index];
                    $slot["outer"] = $sizing[$index]["outer"];
                    if ($column) {
                        $slot["reference_height"] = $slot["definite_height"] ? $sizes[$index] : null;
                        $crossSize = max($crossSize, $slot["width"] + $slot["cross_outer"]);
                    } else {
                        // Native isolated layout at the final main size determines line height.
                        $frame->set_item_layout($items[$index], ["x" => $box["x"], "y" => $box["y"],
                            "width" => $slot["width"], "height" => $slot["height"], "definite_width" => true,
                            "definite_height" => $slot["height"] !== null, "content_spacing" => [0.0, 0.0]]);
                        $probe = (new FlexLayoutContext($items[$index], true, null))->layout();
                        $slot["cross_size"] = $probe["consumed_block_size"];
                        $crossSize = max($crossSize, $probe["consumed_block_size"]);
                        $fragment = $probe["fragment"];
                        $slot["baseline"] = $fragment->get_baseline();
                        $slot["last_baseline"] = $fragment->get_baseline(true);
                        if ($slot["baseline"] === null) {
                            $border = $fragment->get_border_box();
                            $slot["baseline"] = $border["y"] + $border["h"] - $fragment->get_position("y");
                        }
                        $slot["last_baseline"] = $slot["last_baseline"] ?? $slot["baseline"];
                        $nativeHeight = (float) $fragment->get_style()->height;
                        $sizing[$index]["baseline_metrics"] = ["baseline" => $slot["baseline"],
                            "last_baseline" => $slot["last_baseline"], "height" => $nativeHeight,
                            "edges" => $slot["cross_size"] - $nativeHeight,
                            "content_height" => $fragment instanceof BlockFrameDecorator
                                ? $fragment->get_reflower()->_calculate_content_height() : $nativeHeight];
                    }
                    $offset += $sizes[$index] + $slot["outer"] + $mainGap;
                    unset($slot);
                }
                if (!$column) {
                    [$ascent, $descent, $lastAscent, $lastDescent] = $this->content_baselines(
                        $members, $slots, $sizing, $items, $logicalHeight ?? $crossSize, $crossReverse);
                    $crossSize = max(0.0, max(array_column(array_intersect_key($slots, array_flip($members)), "cross_size")));
                }
                $crossSize = max($crossSize, $ascent + $descent, $lastAscent + $lastDescent);
                if (!$wrap) {
                    $crossSize = $column ? $width : ($logicalHeight ?? $crossSize);
                }
                $lines[$lineId] = ["offset" => $crossExtent, "cross_size" => $crossSize, "extent" => $extent,
                    "baseline" => $ascent, "last_ascent" => $lastAscent, "last_descent" => $lastDescent];
                $crossExtent += $crossSize + $crossGap;
            }
            $crossExtent = max(0.0, $crossExtent - $crossGap);
            $containerCross = $column ? $width : ($logicalHeight ?? $crossExtent);
            $freeCross = $containerCross - $crossExtent;
            $lineStretch = $wrap && in_array($style->align_content, ["normal", "stretch"], true) && $freeCross > 0 ? $freeCross / count($lines) : 0.0;
            $contentAlignment = isset($layout["content_spacing"]) && in_array($style->align_content, ["baseline", "first baseline", "last baseline"], true)
                ? "start" : $style->align_content;
            [$crossOffset, $lineSpace] = $wrap ? $this->alignment_spacing($contentAlignment, $freeCross, count($lines), $crossReverse, $column) : [0.0, 0.0];
            foreach ($membership as $lineId => $members) {
                $line = &$lines[$lineId];
                $line["cross_size"] += $lineStretch;
                $line["offset"] = $crossReverse ? $containerCross - $crossOffset - $line["cross_size"] : $crossOffset;
                $crossOffset += $line["cross_size"] + $crossGap + $lineSpace;
                $mainEdges = $column ? ["margin_top", "margin_bottom"] : ["margin_left", "margin_right"];
                $crossEdges = $column ? ["margin_left", "margin_right"] : ["margin_top", "margin_bottom"];
                $free = $extent - max(0, count($members) - 1) * $mainGap;
                $autoCount = 0;
                foreach ($members as $index) {
                    $free -= $slots[$index][$column ? "height" : "width"] + $slots[$index]["outer"];
                    foreach ($mainEdges as $edge) {
                        $autoCount += isset($slots[$index]["margins"][$edge]) ? 1 : 0;
                    }
                }
                $autoSpace = $autoCount > 0 ? max(0.0, $free) / $autoCount : 0.0;
                [$offset, $between] = $this->alignment_spacing($style->justify_content, $free - $autoSpace * $autoCount, count($members), $reverse, !$column);
                foreach ($members as $index) {
                    $slot = &$slots[$index];
                    $itemStyle = $items[$index]->get_style();
                    foreach ($mainEdges as $edge) {
                        if (isset($slot["margins"][$edge])) {
                            $slot["margins"][$edge] = $autoSpace;
                            $slot["outer"] += $autoSpace;
                        }
                    }
                    $main = $slot[$column ? "height" : "width"] + $slot["outer"];
                    $slot["offset"] = $reverse ? $extent - $offset - $main : $offset;
                    $offset += $main + $mainGap + $between;
                    $crossAuto = array_intersect($crossEdges, array_keys($slot["margins"]));
                    $align = $itemStyle->align_self === "auto" ? $style->align_items : $itemStyle->align_self;
                    $align = $align === "normal" ? "stretch" : $align;
                    $outer = $column ? $slot["width"] + $slot["cross_outer"] : $slot["cross_size"];
                    if ($align === "stretch" && $itemStyle->get_computed($column ? "width" : "height") === "auto" && !$crossAuto) {
                        $crossOuter = $column ? $slot["cross_outer"] : (float) $itemStyle->length_in_pt([
                            $itemStyle->margin_top, $itemStyle->margin_bottom, $itemStyle->padding_top, $itemStyle->padding_bottom,
                            $itemStyle->border_top_width, $itemStyle->border_bottom_width], $width);
                        $size = max($sizing[$index]["cross_min"], min($line["cross_size"] - $crossOuter, $sizing[$index]["cross_max"]));
                        $slot[$column ? "width" : "height"] = $size;
                        $outer = $size + $crossOuter;
                        if (!$column) {
                            $slot["definite_height"] = true;
                            $slot["reference_height"] = $size;
                            if (isset($slot["content_spacing"])) {
                                // Percentage descendants can change the donor after stretch.
                                $frame->set_item_layout($items[$index], ["x" => $box["x"], "y" => $box["y"],
                                    "width" => $slot["width"], "height" => $size, "definite_width" => true,
                                    "definite_height" => true, "reference_height" => $size, "content_spacing" => [0.0, 0.0]]);
                                $probe = (new FlexLayoutContext($items[$index], true, null))->layout();
                                $fragment = $probe["fragment"];
                                $metric = &$sizing[$index]["baseline_metrics"];
                                $metric["height"] = $size;
                                $metric["content_height"] = $fragment->get_reflower()->_calculate_content_height();
                                $metric["baseline"] = $fragment->get_baseline() ?? $metric["baseline"];
                                $metric["last_baseline"] = $fragment->get_baseline(true) ?? $metric["last_baseline"];
                                unset($metric);
                            }
                        }
                    }
                    if (!$column) {
                        $slot["cross_size"] = $outer;
                    }
                    unset($slot);
                }
                if (!$column && array_filter(array_intersect_key($slots, array_flip($members)), function ($slot) {
                    return isset($slot["content_spacing"]);
                })) {
                    [$line["baseline"], , $line["last_ascent"], $line["last_descent"]] = $this->content_baselines(
                        $members, $slots, $sizing, $items, $line["cross_size"], $crossReverse);
                }
                // Final content-group growth precedes every cross position and auto edge.
                foreach ($members as $index) {
                    $slot = &$slots[$index];
                    $itemStyle = $items[$index]->get_style();
                    $crossAuto = array_intersect($crossEdges, array_keys($slot["margins"]));
                    $align = $itemStyle->align_self === "auto" ? $style->align_items : $itemStyle->align_self;
                    $align = $align === "normal" ? "stretch" : $align;
                    $outer = $column ? $slot["width"] + $slot["cross_outer"] : $slot["cross_size"];
                    $crossFree = $line["cross_size"] - $outer;
                    if ($crossAuto) {
                        if ($crossFree > 0) {
                            foreach ($crossAuto as $edge) {
                                $slot["margins"][$edge] = $crossFree / count($crossAuto);
                            }
                        } else {
                            $end = $column && $style->direction === "rtl" ? $crossEdges[0] : $crossEdges[1];
                            $slot["margins"][$end] = (float) $itemStyle->length_in_pt($itemStyle->$end, $width) + $crossFree;
                        }
                        $outer = $line["cross_size"];
                        $crossFree = 0.0;
                    }
                    $fallback = $align === "stretch" && isset($slot["content_spacing"])
                        ? ($itemStyle->align_content === "last baseline" ? "self-end" : "self-start") : $align;
                    [$cross] = $this->alignment_spacing($fallback, $crossFree, 1, $crossReverse, $column, $itemStyle->direction);
                    $slot["cross_offset"] = $crossReverse ? $crossFree - $cross : $cross;
                    if (!$column && ($align === "baseline" || $align === "first baseline") && !$crossAuto) {
                        $slot["cross_offset"] = $line["baseline"] - $slot["baseline"];
                    } elseif (!$column && $align === "last baseline" && !$crossAuto) {
                        $slot["cross_offset"] = max($line["last_ascent"], $line["cross_size"] - $line["last_descent"]) - $slot["last_baseline"];
                    }
                    if (!$column) {
                        $slot["cross_size"] = $outer;
                    }
                    unset($slot);
                }
                unset($line);
            }
        }

        $canForce = !$page->get_flex_context() || !$page->get_flex_context()->is_measuring();
        for ($ancestor = $frame; $ancestor; $ancestor = $ancestor->get_parent()) {
            if ($ancestor->get_style()->position === "fixed") {
                $canForce = false;
            }
        }
        $lineVisits = array_keys($membership);
        usort($lineVisits, function ($a, $b) use ($lines) {
            return ($lines[$a]["offset"] <=> $lines[$b]["offset"]) ?: ($a <=> $b);
        });
        $nextLines = [];
        $nextExtent = 0.0;
        foreach ($lineVisits as $lineVisit => $lineId) {
            $line = $lines[$lineId];
            $visits = $membership[$lineId];
            if ($column) {
                usort($visits, function ($a, $b) use ($slots) {
                    return ($slots[$a]["offset"] <=> $slots[$b]["offset"]) ?: ($a <=> $b);
                });
            }
            $lineBottom = $column ? 0.0 : $line["offset"];
            $lineContinuations = [];
            $cut = 0.0;
            foreach ($visits as $visit => $index) {
                $item = $items[$index];
                $slot = $slots[$index];
                $x = $column ? $line["offset"] + $slot["cross_offset"] : $slot["offset"];
                $y = $column ? $slot["offset"] : $line["offset"] + $slot["cross_offset"];
                $assignment = ["x" => $box["x"] + $x, "y" => $box["y"] + $y,
                    "width" => $slot["width"], "height" => $slot["height"], "definite_width" => true,
                    "definite_height" => $slot["definite_height"], "line" => $lineId];
                $assignment["reference_height"] = $slot["reference_height"] ?? $slot["height"];
                if (isset($slot["content_spacing"])) {
                    $assignment["content_spacing"] = $slot["content_spacing"];
                }
                if (isset($slot["baseline_group"])) {
                    $assignment["baseline_group"] = $slot["baseline_group"];
                }
                foreach ($slot["margins"] as $edge => $value) {
                    $item->get_style()->set_used($edge, $value);
                }
                $frame->set_item_layout($item, $assignment);
                $item->set_positioner(new FlexPositioner());
                $forced = $column && $canForce && in_array($item->get_style()->page_break_before, ["always", "left", "right"], true);
                if ($forced) {
                    $item->get_style()->page_break_before = "auto";
                    $result = ["fragment" => null, "continuation" => $item, "consumed_block_size" => 0.0];
                } else {
                    $result = (new FlexLayoutContext($item, false, $available))->layout();
                }
                if ($result["fragment"]) {
                    if ($result["continuation"] && $item->get_reflower() instanceof Block) {
                        $prefix = max(0.0, $item->get_reflower()->_calculate_content_height() - ($slot["content_spacing"][1] ?? 0.0));
                        $item->get_style()->set_used("height", $prefix);
                        $result["consumed_block_size"] = $item->get_margin_height();
                        if (!$column) {
                            $slot["cross_size"] = max(0.0, $slot["cross_size"] - $result["consumed_block_size"]);
                            $remaining = $result["continuation"];
                            if ($remaining instanceof \Dompdf\FrameDecorator\Flex && $remaining->get_continuation_extent() !== null) {
                                // Nested owners can discard a boundary gap as well as a prefix.
                                $remainingStyle = $remaining->get_style();
                                $slot["cross_size"] = $remaining->get_continuation_extent()
                                    + (float) $remainingStyle->length_in_pt([$remainingStyle->margin_top, $remainingStyle->margin_bottom,
                                        $remainingStyle->padding_top, $remainingStyle->padding_bottom,
                                        $remainingStyle->border_top_width, $remainingStyle->border_bottom_width], $width);
                            }
                        }
                        if ($slot["height"] !== null) {
                            $slot["reference_height"] = $assignment["reference_height"];
                            $slot["height"] = max(0.0, $slot["height"] - $prefix);
                        }
                    }
                    $lineBottom = max($lineBottom, $y + $result["consumed_block_size"]);
                    $this->content_height = max($this->content_height, $y + $result["consumed_block_size"]);
                }
                if ($result["continuation"]) {
                    if ($result["fragment"]) {
                        if (isset($slot["content_spacing"])) {
                            $slot["content_spacing"][0] = max(0.0, $slot["content_spacing"][0] - $result["consumed_block_size"]);
                        }
                        if (isset($slot["margins"]["margin_top"])) {
                            $slot["margins"]["margin_top"] = 0.0;
                        }
                        if (!$column) {
                            $slot["cross_offset"] = 0.0;
                        }
                    }
                    if ($column) {
                        // At an item boundary discard its separating gap, not leading free space.
                        $cut = $result["fragment"] ? $y + $result["consumed_block_size"]
                            : ($visit > 0 ? $y : max($lineBottom, min($y, $available)));
                        $slot["offset"] = max(0.0, $y - $cut);
                    }
                    $lineContinuations[] = [$result["continuation"], $slot, $sourceOrder[$item->get_id()]];
                    if ($column) {
                        foreach (array_slice($visits, $visit + 1) as $later) {
                            $remaining = $slots[$later];
                            $remaining["offset"] = max(0.0, $remaining["offset"] - $cut);
                            $lineContinuations[] = [$items[$later], $remaining, $sourceOrder[$items[$later]->get_id()]];
                        }
                        if ($result["fragment"] || $visit === 0) {
                            $this->content_height = max($this->content_height, min($cut, $available));
                        }
                        break;
                    }
                }
            }
            if ($lineContinuations) {
                if ($column) {
                    $line["extent"] = max(0.0, $line["extent"] - $cut);
                    $nextExtent = max($nextExtent, $line["extent"]);
                } else {
                    $cut = $lineBottom;
                    $remainingCross = max(0.0, max(array_column(array_column($lineContinuations, 1), "cross_size")));
                    $cut = $line["offset"] + $line["cross_size"] - $remainingCross;
                    $line["cross_size"] = $remainingCross;
                    $line["offset"] = 0.0;
                    $nextExtent = $line["cross_size"];
                }
                $nextLines[$lineId] = $line;
                $continuations = array_merge($continuations, $lineContinuations);
                if (!$column) {
                    foreach (array_slice($lineVisits, $lineVisit + 1) as $laterLine) {
                        $remainingLine = $lines[$laterLine];
                        $remainingLine["offset"] = max(0.0, $remainingLine["offset"] - $cut);
                        $nextLines[$laterLine] = $remainingLine;
                        $nextExtent = max($nextExtent, $remainingLine["offset"] + $remainingLine["cross_size"]);
                        foreach ($membership[$laterLine] as $later) {
                            $continuations[] = [$items[$later], $slots[$later], $sourceOrder[$items[$later]->get_id()]];
                        }
                    }
                    break;
                }
            }
        }

        [$usedHeight, $topMargin, $bottomMargin, $top, $bottom] = $this->_calculate_restricted_height();
        if ($column) {
            $usedHeight = $continuations ? $this->content_height : $extent;
        } elseif ($continuations) {
            $usedHeight = $this->content_height + ($layout["content_spacing"][0] ?? 0.0);
        }
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
            usort($continuations, function ($a, $b) {
                return $a[2] <=> $b[2];
            });
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
            $next->set_continuation_extent($nextExtent);
            $next->set_continuation_lines($nextLines);
            foreach ($continuations as [$item, $slot]) {
                $next->append_child($item);
                $next->set_continuation_slot($item, $slot);
            }
            $page->commit_flex_fragment($frame, $next);
            $next->_counters = $frame->_counters;
        }
    }
}
