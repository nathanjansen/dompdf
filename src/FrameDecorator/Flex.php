<?php
/**
 * @package dompdf
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace Dompdf\FrameDecorator;

use Dompdf\Frame;

/** Flex outer box; inherited Block helpers do not make it a block container. */
class Flex extends Block
{
    private $item_layouts = [];

    /** Resolved physical slots, retained through ancestor continuation reset. */
    private $continuation_slots = [];

    /** Remaining physical container height, not its CSS percentage reference. */
    private $continuation_extent;

    /** Remaining line geometry; item membership lives in the durable slots. */
    private $continuation_lines = [];

    public function get_baseline(bool $last = false): ?float
    {
        $items = $this->get_flex_items();
        if (!$items) {
            return null;
        }
        $style = $this->get_style();
        $column = in_array($style->flex_direction, ["column", "column-reverse"], true);
        $rtl = $style->direction === "rtl";
        $reverseLines = $style->flex_wrap === "wrap-reverse";
        $ordinals = [];
        foreach ($items as $index => $item) {
            $ordinals[$item->get_id()] = $index;
        }
        usort($items, function ($a, $b) use ($column, $rtl, $reverseLines, $ordinals) {
            $secondary = $column ? "y" : "x";
            $first = ($this->get_item_layout($a)["line"] ?? 0) <=> ($this->get_item_layout($b)["line"] ?? 0);
            $second = $a->get_position($secondary) <=> $b->get_position($secondary);
            return (($reverseLines ? -$first : $first) ?: (!$column && $rtl ? -$second : $second))
                ?: ($ordinals[$a->get_id()] <=> $ordinals[$b->get_id()]);
        });
        if ($last) {
            $items = array_reverse($items);
        }
        $donor = $items[0];
        if (!$column) {
            $line = ($this->get_item_layout($donor) ?? [])["line"] ?? null;
            foreach ($items as $item) {
                $layout = $this->get_item_layout($item) ?? [];
                if (($layout["line"] ?? null) === $line && ($layout["baseline_group"] ?? null) === ($last ? "last" : "first")) {
                    $donor = $item;
                    break;
                }
            }
        }
        $baseline = $donor->get_baseline($last);
        if ($baseline === null) {
            $border = $donor->get_border_box();
            $baseline = $border["y"] + $border["h"] - $donor->get_position("y");
        }
        return $donor->get_position("y") - $this->get_position("y") + $baseline;
    }

    public function get_continuation_lines(): array
    {
        return $this->continuation_lines;
    }

    public function set_continuation_lines(array $lines): void
    {
        $this->continuation_lines = $lines;
    }

    public function get_continuation_extent(): ?float
    {
        return $this->continuation_extent;
    }

    public function set_continuation_extent(float $extent): void
    {
        $this->continuation_extent = $extent;
    }

    public function get_flex_items(): array
    {
        $items = [];
        foreach ($this->get_children() as $index => $child) {
            if (!$child->is_absolute() && $child->get_style()->display !== "none") {
                $items[] = [$child, $index];
            }
        }
        usort($items, function ($a, $b) {
            return ($a[0]->get_style()->order <=> $b[0]->get_style()->order) ?: ($a[1] <=> $b[1]);
        });
        return array_column($items, 0);
    }

    public function set_item_layout(Frame $item, array $layout): void
    {
        $this->item_layouts[$item->get_id()] = $layout;
    }

    public function get_item_layout(Frame $item): ?array
    {
        return $this->item_layouts[$item->get_id()] ?? null;
    }

    public function set_continuation_slot(Frame $item, array $slot): void
    {
        $this->continuation_slots[$item->get_id()] = $slot;
    }

    public function get_continuation_slot(Frame $item): ?array
    {
        return $this->continuation_slots[$item->get_id()] ?? null;
    }

    public function reset()
    {
        parent::reset();
        $this->item_layouts = [];
    }
}
