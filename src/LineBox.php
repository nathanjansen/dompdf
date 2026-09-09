<?php
/**
 * @package dompdf
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace Dompdf;

use Dompdf\FrameDecorator\AbstractFrameDecorator;
use Dompdf\FrameDecorator\Block;
use Dompdf\FrameDecorator\ListBullet;
use Dompdf\FrameDecorator\Page;
use Dompdf\FrameReflower\Text as TextFrameReflower;
use Dompdf\Positioner\Inline as InlinePositioner;
use Iterator;

/**
 * The line box class
 *
 * This class represents a line box
 * http://www.w3.org/TR/CSS2/visuren.html#line-box
 *
 * @package dompdf
 */
class LineBox
{
    /**
     * @var Block
     */
    protected $_block_frame;

    /**
     * @var AbstractFrameDecorator[]
     */
    protected $_frames = [];

    /**
     * @var ListBullet[]
     */
    protected $list_markers = [];

    /**
     * @var int
     */
    public $wc = 0;

    /**
     * @var float
     */
    public $y = 0.0;

    /**
     * @var float
     */
    public $w = 0.0;

    /**
     * @var float
     */
    public $h = 0.0;

    /** Local alignment baseline, only for native lines containing inline-flex. */
    public $baseline = null;

    /**
     * @var float
     */
    public $left = 0.0;

    /**
     * @var float
     */
    public $right = 0.0;

    /**
     * @var AbstractFrameDecorator
     */
    public $tallest_frame = null;

    /**
     * @var bool[]
     */
    public $floating_blocks = [];

    /**
     * @var bool
     */
    public $br = false;

    /**
     * Whether the line box contains any inline-positioned frames.
     *
     * @var bool
     */
    public $inline = false;

    /**
     * @var int
     */
    public static $max_float_reflows = 10000;

    /**
     * FIXME smelly hack, used by the get_float_offsets method.
     *
     * @var int
     */
    private static $float_offset_anti_infinite_loop = 10000;

    /**
     * @param Block $frame the Block containing this line
     * @param float $y
     */
    public function __construct(Block $frame, float $y = 0.0)
    {
        $this->_block_frame = $frame;
        $this->_frames = [];
        $this->y = $y;

        $this->get_float_offsets();
    }

    /**
     * Returns the floating elements inside the first floating parent
     *
     * @param Page $root
     *
     * @return Frame[]
     */
    public function get_floats_inside(Page $root): array
    {
        $floating_frames = $root->get_floating_frames();

        if (count($floating_frames) == 0) {
            return $floating_frames;
        }

        // Find nearest floating element
        $p = $this->_block_frame;
        while ($p->get_style()->float === "none") {
            $parent = $p->get_parent();

            if (!$parent) {
                break;
            }

            $p = $parent;
        }

        if ($p == $root) {
            return $floating_frames;
        }

        $parent = $p;

        $childs = [];

        foreach ($floating_frames as $_floating) {
            $p = $_floating->get_parent();

            while (($p = $p->get_parent()) && $p !== $parent);

            if ($p) {
                $childs[] = $p;
            }
        }

        return $childs;
    }

    /**
     * Resets the anti-infinite-loop counter for the get_float_offsets method.
     */
    public static function reset_float_reflow_limit(): void
    {
        self::$float_offset_anti_infinite_loop = self::$max_float_reflows;
    }

    public function get_float_offsets(): void
    {
        $reflower = $this->_block_frame->get_reflower();

        if (!$reflower) {
            return;
        }

        $cb_w = null;

        $block = $this->_block_frame;
        $root = $block->get_root();

        if (!$root) {
            return;
        }

        $style = $this->_block_frame->get_style();
        $floating_frames = $this->get_floats_inside($root);
        $inside_left_floating_width = 0;
        $inside_right_floating_width = 0;
        $outside_left_floating_width = 0;
        $outside_right_floating_width = 0;

        foreach ($floating_frames as $child_key => $floating_frame) {
            $floating_frame_parent = $floating_frame->get_parent();
            $id = $floating_frame->get_id();

            if (isset($this->floating_blocks[$id])) {
                continue;
            }

            $float = $floating_frame->get_style()->float;
            $floating_width = $floating_frame->get_margin_width();

            if (!$cb_w) {
                $cb_w = $floating_frame->get_containing_block("w");
            }

            $line_w = $this->get_width();

            if (!$floating_frame->_float_next_line && ($cb_w <= $line_w + $floating_width) && ($cb_w > $line_w)) {
                $floating_frame->_float_next_line = true;
                continue;
            }

            // If the child is still shifted by the floating element
            if (self::$float_offset_anti_infinite_loop-- > 0 &&
                $floating_frame->get_position("y") + $floating_frame->get_margin_height() >= $this->y &&
                $block->get_position("x") + $block->get_margin_width() >= $floating_frame->get_position("x")
            ) {
                if ($float === "left") {
                    if ($floating_frame_parent === $this->_block_frame) {
                        $inside_left_floating_width += $floating_width;
                    } else {
                        $outside_left_floating_width += $floating_width;
                    }
                } elseif ($float === "right") {
                    if ($floating_frame_parent === $this->_block_frame) {
                        $inside_right_floating_width += $floating_width;
                    } else {
                        $outside_right_floating_width += $floating_width;
                    }
                }

                $this->floating_blocks[$id] = true;
            } // else, the floating element won't shift anymore
            else {
                $root->remove_floating_frame($child_key);
            }
        }

        $this->left += $inside_left_floating_width;
        if ($outside_left_floating_width > 0 && $outside_left_floating_width > ((float)$style->length_in_pt($style->margin_left) + (float)$style->length_in_pt($style->padding_left))) {
            $this->left += $outside_left_floating_width - (float)$style->length_in_pt($style->margin_left) - (float)$style->length_in_pt($style->padding_left);
        }
        $this->right += $inside_right_floating_width;
        if ($outside_right_floating_width > 0 && $outside_right_floating_width > ((float)$style->length_in_pt($style->margin_left) + (float)$style->length_in_pt($style->padding_right))) {
            $this->right += $outside_right_floating_width - (float)$style->length_in_pt($style->margin_right) - (float)$style->length_in_pt($style->padding_right);
        }
    }

    /**
     * @return float
     */
    public function get_width(): float
    {
        return $this->left + $this->w + $this->right;
    }

    /**
     * @return Block
     */
    public function get_block_frame(): Block
    {
        return $this->_block_frame;
    }

    /**
     * @return AbstractFrameDecorator[]
     */
    public function &get_frames(): array
    {
        return $this->_frames;
    }

    /**
     * @return bool
     */
    public function is_empty(): bool
    {
        return $this->_frames === [];
    }

    /**
     * @param AbstractFrameDecorator $frame
     */
    public function add_frame(Frame $frame): void
    {
        $this->_frames[] = $frame;

        if ($frame->get_positioner() instanceof InlinePositioner) {
            $this->inline = true;
        }
    }

    /**
     * Remove the frame at the given index and all following frames from the
     * line.
     *
     * @param int $index
     */
    public function remove_frames(int $index): void
    {
        $lastIndex = count($this->_frames) - 1;

        if ($index < 0 || $index > $lastIndex) {
            return;
        }

        for ($i = $lastIndex; $i >= $index; $i--) {
            $f = $this->_frames[$i];
            unset($this->_frames[$i]);
            $this->w -= $f->get_margin_width();
        }

        // Reset array indices
        $this->_frames = array_values($this->_frames);

        // Recalculate the height of the line
        $h = 0.0;
        $this->inline = false;

        foreach ($this->_frames as $f) {
            $h = max($h, $f->get_margin_height());

            if ($f->get_positioner() instanceof InlinePositioner) {
                $this->inline = true;
            }
        }

        $this->h = $h;
        $this->recalculate_flex_metrics();
    }

    /** Native inline metrics; ordinary lines deliberately keep their legacy path. */
    public function recalculate_flex_metrics(): void
    {
        $this->baseline = null;
        foreach ($this->_frames as $frame) {
            if ($frame instanceof \Dompdf\FrameDecorator\Flex && $frame->get_positioner() instanceof InlinePositioner) {
                $this->baseline = 0.0;
                break;
            }
        }
        if ($this->baseline === null) {
            return;
        }
        $style = $this->_block_frame->get_style();
        $metrics = $this->_block_frame->get_dompdf()->getFontMetrics();
        $fontHeight = $metrics->getFontHeight($style->font_family, $style->font_size);
        $strut = $fontHeight * $style->line_height / ($style->font_size > 0 ? $style->font_size : 1);
        $ascent = $metrics->getFontBaseline($style->font_family, $style->font_size) + ($strut - $fontHeight) / 2;
        $descent = $strut - $ascent;
        foreach ($this->frames_to_align() as $frame) {
            [$height, $baseline, $leading, $align, $shift] = $this->flex_frame_metrics($frame);
            if (in_array($align, ["top", "bottom", "middle", "sub", "super", "text-top", "text-bottom"], true)) {
                continue;
            }
            $ascent = max($ascent, $baseline + $leading + $shift);
            $descent = max($descent, $height - $baseline - $leading - $shift);
        }
        $this->baseline = $ascent;
        $this->h = max($this->h, $ascent + $descent);
    }

    /** Target frame y relative to the line, not a movement delta. */
    public function get_vertical_offset(AbstractFrameDecorator $frame): ?float
    {
        if ($this->baseline === null) {
            return null;
        }
        [$height, $baseline, $leading, $align, $shift] = $this->flex_frame_metrics($frame);
        if ($align === "top") {
            return $leading;
        }
        if ($align === "bottom") {
            return $this->h - $height + $leading;
        }
        if (in_array($align, ["middle", "sub", "super", "text-top", "text-bottom"], true)) {
            // Preserve the native special-alignment limitations; no new x-height approximation.
            return $this->get_legacy_vertical_offset($frame);
        }
        return $this->baseline - $baseline - $shift;
    }

    /** Native solitary-atomic exception, shared with local baseline recovery. */
    public function is_legacy_alignment_skipped(AbstractFrameDecorator $frame): bool
    {
        $display = $frame->get_style()->display;
        if ($display === "inline" || $display === "-dompdf-list-bullet") {
            return false;
        }
        foreach ($this->get_frames() as $other) {
            if ($other !== $frame && !($other->is_text_node() && $other->get_node()->nodeValue === "")) {
                return false;
            }
        }
        return true;
    }

    /** Existing native movement delta; distinct from the flex-line local target. */
    public function get_legacy_vertical_offset(AbstractFrameDecorator $frame): float
    {
        $fontMetrics = $frame->get_dompdf()->getFontMetrics();
        $height = $this->h;
        $style = $frame->get_style();
        $isInlineBlock = $style->display !== "inline"
            && $style->display !== "-dompdf-list-bullet";

        $baseline = $fontMetrics->getFontBaseline($style->font_family, $style->font_size);
        $y_offset = 0;

        //FIXME: The 0.8 ratio applied to the height is arbitrary (used to accommodate descenders?)
        if ($isInlineBlock) {
            // Workaround: Skip vertical alignment if the frame is the
            // only one one the line, excluding empty text frames, which
            // may be the result of trailing white space
            // FIXME: This special case should be removed once vertical
            // alignment is properly fixed
            if ($this->is_legacy_alignment_skipped($frame)) {
                return 0.0;
            }

            $marginHeight = $frame->get_margin_height();
            $imageHeightDiff = $height * 0.8 - $marginHeight;

            $align = $frame->get_style()->vertical_align;
            if (in_array($align, \Dompdf\Css\Style::VERTICAL_ALIGN_KEYWORDS, true)) {
                switch ($align) {
                    case "middle":
                        $y_offset = $imageHeightDiff / 2;
                        break;

                    case "sub":
                        $y_offset = 0.3 * $height + $imageHeightDiff;
                        break;

                    case "super":
                        $y_offset = -0.2 * $height + $imageHeightDiff;
                        break;

                    case "text-top": // FIXME: this should be the height of the frame minus the height of the text
                        $y_offset = $height - $style->line_height;
                        break;

                    case "top":
                        break;

                    case "text-bottom": // FIXME: align bottom of image with the descender?
                    case "bottom":
                        $y_offset = 0.3 * $height + $imageHeightDiff;
                        break;

                    case "baseline":
                    default:
                        $y_offset = $imageHeightDiff;
                        break;
                }
            } else {
                $y_offset = $baseline - (float)$style->length_in_pt($align, $style->font_size) - $marginHeight;
            }
        } else {
            $parent = $frame->get_parent();
            if ($parent instanceof \Dompdf\FrameDecorator\TableCell) {
                $align = "baseline";
            } else {
                $align = $parent->get_style()->vertical_align;
            }
            if (in_array($align, \Dompdf\Css\Style::VERTICAL_ALIGN_KEYWORDS, true)) {
                switch ($align) {
                    case "middle":
                        $y_offset = ($height * 0.8 - $baseline) / 2;
                        break;

                    case "sub":
                        $y_offset = $height * 0.8 - $baseline * 0.5;
                        break;

                    case "super":
                        $y_offset = $height * 0.8 - $baseline * 1.4;
                        break;

                    case "text-top":
                    case "top": // Not strictly accurate, but good enough for now
                        break;

                    case "text-bottom":
                    case "bottom":
                        $y_offset = $height * 0.8 - $baseline;
                        break;

                    case "baseline":
                    default:
                        $y_offset = $height * 0.8 - $baseline;
                        break;
                }
            } else {
                $y_offset = $height * 0.8 - $baseline - (float)$style->length_in_pt($align, $style->font_size);
            }
        }

        return (float) $y_offset;
    }

    private function flex_frame_metrics(AbstractFrameDecorator $frame): array
    {
        $style = $frame->get_style();
        $height = $frame->get_margin_height();
        $baseline = $style->display === "inline-block" && $style->get_computed("overflow") !== "visible"
            ? $height : ($frame->get_baseline($style->display === "inline-block") ?? $height);
        $leading = 0.0;
        $align = $style->vertical_align;
        if ($frame->is_text_node()) {
            $fontHeight = $frame->get_dompdf()->getFontMetrics()->getFontHeight($style->font_family, $style->font_size);
            $leading = ($height - $fontHeight) / 2;
            $parent = $frame->get_parent();
            $align = $parent instanceof \Dompdf\FrameDecorator\TableCell ? "baseline" : $parent->get_style()->vertical_align;
        }
        $shift = 0.0;
        if (!in_array($align, \Dompdf\Css\Style::VERTICAL_ALIGN_KEYWORDS, true)) {
            $shift = (float) $style->length_in_pt($align, $style->line_height);
        }
        return [$height, $baseline, $leading, $align, $shift];
    }

    /**
     * Get the `outside` positioned list markers to be vertically aligned with
     * the line box.
     *
     * @return ListBullet[]
     */
    public function get_list_markers(): array
    {
        return $this->list_markers;
    }

    /**
     * Add a list marker to the line box.
     *
     * The list marker is only added for the purpose of vertical alignment, it
     * is not actually added to the list of frames of the line box.
     */
    public function add_list_marker(ListBullet $marker): void
    {
        $this->list_markers[] = $marker;
    }

    /**
     * An iterator of all list markers and inline positioned frames of the line
     * box.
     *
     * @return Iterator<AbstractFrameDecorator>
     */
    public function frames_to_align(): Iterator
    {
        yield from $this->list_markers;

        foreach ($this->_frames as $frame) {
            if ($frame->get_positioner() instanceof InlinePositioner) {
                yield $frame;
            }
        }
    }

    /**
     * Trim trailing whitespace from the line.
     */
    public function trim_trailing_ws(): void
    {
        $lastIndex = count($this->_frames) - 1;

        if ($lastIndex < 0) {
            return;
        }

        $lastFrame = $this->_frames[$lastIndex];
        $reflower = $lastFrame->get_reflower();

        if ($reflower instanceof TextFrameReflower && !$lastFrame->is_pre()) {
            $reflower->trim_trailing_ws();
            $this->recalculate_width();
        }
    }

    /**
     * Recalculate LineBox width based on the contained frames total width.
     *
     * @return float
     */
    public function recalculate_width(): float
    {
        $width = 0.0;

        foreach ($this->_frames as $frame) {
            $width += $frame->get_margin_width();
        }

        return $this->w = $width;
    }

    public function __toString(): string
    {
        $props = ["wc", "y", "w", "h", "left", "right", "br"];
        $s = "";
        foreach ($props as $prop) {
            $s .= "$prop: " . $this->$prop . "\n";
        }
        $s .= count($this->_frames) . " frames\n";

        return $s;
    }
}
