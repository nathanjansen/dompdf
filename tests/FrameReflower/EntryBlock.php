<?php
namespace Dompdf\Tests\FrameReflower;

use Dompdf\FrameDecorator\Block as BlockFrame;
use Dompdf\FrameReflower\Block;

/** Runs an observation at the real source-order entry to a block. */
class EntryBlock extends Block
{
    private $inspect;

    public function __construct(BlockFrame $frame, callable $inspect)
    {
        parent::__construct($frame);
        $this->inspect = $inspect;
    }

    public function reflow(?BlockFrame $block = null)
    {
        $this->_frame->set_reflower(new Block($this->_frame));
        $cb = [$this->_frame->get_containing_block("x"), $this->_frame->get_containing_block("y"), $this->_frame->get_containing_block("w"), $this->_frame->get_containing_block("h")];
        ($this->inspect)($this->_frame);
        $this->_frame->set_containing_block(...$cb);
        parent::reflow($block);
    }
}
