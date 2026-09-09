<?php
/**
 * @package dompdf
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace Dompdf\Positioner;

use Dompdf\FrameDecorator\AbstractFrameDecorator;

/** Positions a direct flex item at its assigned margin-box origin. */
class Flex extends AbstractPositioner
{
    public function position(AbstractFrameDecorator $frame): void
    {
        $layout = $frame->get_flex_layout();
        $frame->set_position($layout["x"], $layout["y"]);
    }
}
