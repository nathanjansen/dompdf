<?php
/**
 * @package dompdf
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace Dompdf\FrameReflower;

use Dompdf\Frame;
use Dompdf\FrameDecorator\AbstractFrameDecorator;
use Dompdf\FrameDecorator\Page;

/**
 * One independent item flow. Page rendering remains the parent's responsibility.
 *
 * Capture a measurement context at source-order entry, before intrinsic sizing
 * or reflow changes the item. Each layout uses a fresh copy of that snapshot.
 * Existing generated content is copied with its content_set flag; decoration
 * is not repeated, so list markers are copied exactly once.
 * The item must already be a block formatting root with a containing block.
 * Measurement contexts are reusable; live fragmentation contexts are one-shot.
 *
 * @internal
 */
class FlexLayoutContext
{
    private $item;
    private $measuring;
    private $availableBlockSize;
    private $continuation;
    private $deferred = false;
    private $forced = false;

    public function __construct(AbstractFrameDecorator $item, bool $measuring, ?float $availableBlockSize)
    {
        $this->measuring = $measuring;
        $this->availableBlockSize = $availableBlockSize;
        $this->item = $measuring ? $this->copy_measurement_tree($item) : $item;
    }

    public function get_item(): AbstractFrameDecorator
    {
        return $this->item;
    }

    public function is_measuring(): bool
    {
        return $this->measuring;
    }

    public function get_available_block_size(): ?float
    {
        return $this->availableBlockSize;
    }

    public function contains(Frame $frame): bool
    {
        do {
            if ($frame === $this->item) {
                return true;
            }
        } while ($frame = $frame->get_parent());
        return false;
    }

    /** Called only for page fragmentation, after native descendant splitting. */
    public function capture_continuation(AbstractFrameDecorator $continuation, bool $forced): void
    {
        $continuation->get_parent()->remove_child($continuation);
        $this->continuation = $continuation;
        $this->forced = $forced;
    }

    public function defer(): void
    {
        $this->item->reset();
        $this->item->_already_pushed = true;
        $this->continuation = $this->item;
        $this->deferred = true;
    }

    /**
     * Reflow content without rendering, callbacks, or advancing a physical page.
     * consumed_block_size is physical margin-box consumption, not flex main size.
     * An empty completed item or consumed forced break is progress at zero height.
     */
    public function layout(): array
    {
        if ($this->measuring) {
            $context = new self($this->copy_measurement_tree($this->item), false, null);
            $context->measuring = true;
            return $context->reflow();
        }
        return $this->reflow();
    }

    private function reflow(): array
    {
        $page = $this->item->get_root();
        $page->push_flex_context($this);
        try {
            $this->item->reflow();
            $fragment = $this->deferred ? null : $this->item;
            return [
                "fragment" => $fragment,
                "continuation" => $this->continuation,
                "consumed_block_size" => $fragment ? $fragment->get_margin_height() : 0.0,
                "made_progress" => !$this->deferred,
                "break_reason" => $this->deferred ? "deferred" : ($this->continuation ? ($this->forced ? "forced" : "overflow") : null)
            ];
        } finally {
            $page->pop_flex_context($this);
        }
    }

    private function copy_measurement_tree(AbstractFrameDecorator $item): AbstractFrameDecorator
    {
        $ancestors = [];
        for ($parent = $item->get_parent(); $parent; $parent = $parent->get_parent()) {
            $ancestors[] = $parent;
        }
        $copyParent = null;
        $root = null;
        foreach (array_reverse($ancestors) as $ancestor) {
            $copyParent = $this->copy_frame($ancestor, $copyParent, $root, false);
            if ($copyParent instanceof Page) {
                $root = $copyParent;
            }
        }
        return $this->copy_frame($item, $copyParent, $root, true);
    }

    private function copy_frame(
        AbstractFrameDecorator $source,
        ?AbstractFrameDecorator $parent,
        ?Page $root,
        bool $children
    ): AbstractFrameDecorator {
        $frame = new Frame($source->get_node()->cloneNode());
        $style = clone $source->get_style();
        $style->inherit($parent ? $parent->get_style() : null);
        $frame->set_style($style);
        $class = get_class($source);
        $copy = new $class($frame, $source->get_dompdf());
        $copy->set_root($root ?: $copy);
        $copy->_counters = $source->_counters;
        $copy->content_set = $source->content_set;
        $copy->is_split = $source->is_split;
        $copy->is_split_off = $source->is_split_off;
        $cb = $source->get_containing_block();
        $copy->set_containing_block($cb["x"], $cb["y"], $cb["w"], $cb["h"]);
        $position = $source->get_position();
        $copy->set_position($position["x"], $position["y"]);
        if ($parent) {
            $parent->append_child($copy);
        }
        if ($source->get_positioner()) {
            $copy->set_positioner($source->get_positioner());
        }
        $reflower = get_class($source->get_reflower());
        $copy->set_reflower(new $reflower($copy, $source->get_dompdf()->getFontMetrics()));
        if ($children) {
            foreach ($source->get_children() as $child) {
                $this->copy_frame($child, $copy, $root, true);
            }
        }
        return $copy;
    }
}
