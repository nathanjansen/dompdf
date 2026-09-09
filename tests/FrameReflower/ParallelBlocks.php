<?php
namespace Dompdf\Tests\FrameReflower;

use Dompdf\FrameDecorator\Block as BlockFrame;
use Dompdf\FrameReflower\Block;
use Dompdf\FrameReflower\FlexLayoutContext;

/** Fixed two-column proof coordinator; the production Page still paints/advances. */
class ParallelBlocks extends Block
{
    public function reflow(?BlockFrame $block = null)
    {
        $body = $this->_frame;
        $cb = $body->get_containing_block();
        $body->set_position($cb["x"], $cb["y"]);
        $body->get_style()->set_used("width", $cb["w"]);
        $body->get_style()->set_used("height", $cb["h"]);
        $this->_set_content();
        $items = iterator_to_array($body->get_children());
        $continuations = [];
        foreach ($items as $item) {
            $x = $item->get_node()->getAttribute("data-item") === "A" ? 0.0 : 100.0;
            $item->set_containing_block($x, 0, 100, 100);
            $result = (new FlexLayoutContext($item, false, 100.0))->layout();
            if ($result["continuation"]) {
                $continuations[] = $result["continuation"];
            }
        }
        if ($continuations) {
            $next = $body->copy($body->get_node()->cloneNode());
            $next->set_reflower(new self($next));
            $body->get_parent()->insert_child_after($next, $body);
            foreach ($continuations as $item) {
                $next->append_child($item);
            }
        }
    }
}
