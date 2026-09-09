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

    /** Remaining physical column extent, not its CSS percentage reference. */
    private $continuation_extent;

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
