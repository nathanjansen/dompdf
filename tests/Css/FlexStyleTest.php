<?php
namespace Dompdf\Tests\Css;

use Dompdf\Css\Style;
use Dompdf\Css\Stylesheet;
use Dompdf\Dompdf;
use Dompdf\Tests\TestCase;

class FlexStyleTest extends TestCase
{
    private function style(): Style
    {
        return new Style(new Stylesheet(new Dompdf()));
    }

    public function testDefaultsAndSingleFlexFactor(): void
    {
        $style = $this->style();

        $this->assertSame("row", $style->flex_direction);
        $this->assertSame("nowrap", $style->flex_wrap);
        $this->assertSame(0, $style->order);
        $this->assertSame(0.0, $style->flex_grow);
        $this->assertSame(1.0, $style->flex_shrink);
        $this->assertSame("auto", $style->flex_basis);
        $this->assertSame("flex-start", $style->justify_content);
        $this->assertSame("stretch", $style->align_items);
        $this->assertSame("auto", $style->align_self);
        $this->assertSame("stretch", $style->align_content);
        $this->assertSame("normal", $style->row_gap);
        $this->assertSame("normal", $style->column_gap);

        $style->set_prop("flex", "2");
        $this->assertSame(2.0, $style->flex_grow);
        $this->assertSame(1.0, $style->flex_shrink);
        $this->assertSame(0.0, $style->flex_basis);
    }

    public function testLonghandValuesAndInvalidDeclarations(): void
    {
        $style = $this->style();

        foreach ([
            "flex_direction" => [["ROW", "row"], ["row-reverse", "row-reverse"], ["column", "column"], ["column-reverse", "column-reverse"]],
            "flex_wrap" => [["nowrap", "nowrap"], ["wrap", "wrap"], ["wrap-reverse", "wrap-reverse"]],
            "order" => [["-2", -2], ["0", 0], ["2", 2]],
            "flex_grow" => [["0", 0.0], [".25", 0.25], ["2", 2.0]],
            "flex_shrink" => [["0", 0.0], [".25", 0.25], ["2", 2.0]],
            "justify_content" => [["FLEX-START", "flex-start"], ["flex-end", "flex-end"], ["center", "center"], ["normal", "normal"], ["stretch", "stretch"], ["space-between", "space-between"], ["space-around", "space-around"], ["space-evenly", "space-evenly"], ["safe start", "safe start"], ["unsafe flex-end", "unsafe flex-end"], ["left", "left"], ["right", "right"]],
            "align_items" => [["flex-start", "flex-start"], ["flex-end", "flex-end"], ["center", "center"], ["normal", "normal"], ["baseline", "baseline"], ["first baseline", "first baseline"], ["last baseline", "last baseline"], ["stretch", "stretch"], ["safe self-start", "safe self-start"], ["unsafe self-end", "unsafe self-end"]],
            "align_self" => [["auto", "auto"], ["flex-start", "flex-start"], ["flex-end", "flex-end"], ["center", "center"], ["normal", "normal"], ["baseline", "baseline"], ["first baseline", "first baseline"], ["last baseline", "last baseline"], ["stretch", "stretch"], ["safe self-start", "safe self-start"], ["unsafe self-end", "unsafe self-end"]],
            "align_content" => [["flex-start", "flex-start"], ["flex-end", "flex-end"], ["center", "center"], ["normal", "normal"], ["baseline", "baseline"], ["first baseline", "first baseline"], ["last baseline", "last baseline"], ["space-between", "space-between"], ["space-around", "space-around"], ["space-evenly", "space-evenly"], ["stretch", "stretch"], ["safe start", "safe start"], ["unsafe flex-end", "unsafe flex-end"]],
        ] as $prop => $values) {
            foreach ($values as $value) {
                $style->set_prop($prop, $value[0]);
                $this->assertSame($value[1], $style->$prop, $prop . ": " . $value[0]);
            }
        }

        foreach ([
            ["auto", "auto"], ["content", "content"], ["min-content", "min-content"],
            ["max-content", "max-content"], ["fit-content", "fit-content"], ["fit-content(40pt)", "fit-content(40pt)"],
            ["fit-content(25%)", "fit-content(25%)"], ["fit-content(calc(50% - 10pt))", "fit-content(calc(50% - 10pt))"], ["0", 0.0],
            ["0%", "0%"], ["40pt", 40.0], ["25%", "25%"],
            ["calc(50% - 10pt)", "calc(50% - 10pt)"]
        ] as $value) {
            $style->set_prop("flex_basis", $value[0]);
            $this->assertSame($value[1], $style->flex_basis, "flex_basis: " . $value[0]);
        }

        foreach ([
            ["row_gap", "normal", "normal"], ["row_gap", "0", 0.0], ["row_gap", "10pt", 10.0],
            ["column_gap", "10%", "10%"], ["column_gap", "calc(20pt - 5pt)", 15.0]
        ] as $value) {
            $style->set_prop($value[0], $value[1]);
            $this->assertSame($value[2], $style->{$value[0]}, $value[0] . ": " . $value[1]);
        }

        foreach ([
            ["order", "2", "1.5", 2], ["flex_grow", "2", "-1", 2.0], ["flex_shrink", "2", "-1", 2.0],
            ["flex_grow", "2", "1e309", 2.0], ["flex_basis", "40pt", "-1pt", 40.0], ["flex_basis", "40pt", "1e309%", 40.0],
            ["flex_basis", "40pt", "fit-content(-1pt)", 40.0], ["flex_basis", "40pt", "fit-content(1e309%)", 40.0], ["flex_basis", "40pt", "fit-content(1pt 2pt)", 40.0],
            ["row_gap", "10pt", "-1pt", 10.0], ["row_gap", "10pt", "1e309%", 10.0],
            ["align_items", "stretch", "safe stretch", "stretch"], ["align_items", "stretch", "space-evenly", "stretch"],
            ["align_content", "stretch", "safe self-start", "stretch"], ["align_content", "stretch", "left", "stretch"],
            ["align_content", "stretch", "safe right", "stretch"], ["justify_content", "center", "first baseline", "center"]
        ] as $value) {
            $style->set_prop($value[0], $value[1]);
            $style->set_prop($value[0], $value[2]);
            $this->assertSame($value[3], $style->{$value[0]}, $value[0] . ": " . $value[2]);
        }
    }

    public function testFlexAndFlexFlowShorthandsAreCompleteAndAtomic(): void
    {
        $style = $this->style();

        foreach ([
            ["none", 0.0, 0.0, "auto"], ["auto", 1.0, 1.0, "auto"],
            ["2 3 40pt", 2.0, 3.0, 40.0], ["40pt 2 3", 2.0, 3.0, 40.0],
            ["40pt", 1.0, 1.0, 40.0], ["2 3", 2.0, 3.0, 0.0], ["1+2", 1.0, 2.0, 0.0],
            ["1e2", 100.0, 1.0, 0.0], ["1e-2", 0.01, 1.0, 0.0], ["1e+2", 100.0, 1.0, 0.0],
            ["1e2 1 40pt", 100.0, 1.0, 40.0], ["1e2pt", 1.0, 1.0, 100.0],
            ["0", 0.0, 1.0, 0.0], ["0%", 1.0, 1.0, "0%"], ["2 3 calc(50% - 10pt)", 2.0, 3.0, "calc(50% - 10pt)"],
            ["0 0 0", 0.0, 0.0, 0.0], ["0 0%", 0.0, 1.0, "0%"]
        ] as $value) {
            $style->set_prop("flex", $value[0]);
            $this->assertSame($value[1], $style->flex_grow, "flex grow: " . $value[0]);
            $this->assertSame($value[2], $style->flex_shrink, "flex shrink: " . $value[0]);
            $this->assertSame($value[3], $style->flex_basis, "flex basis: " . $value[0]);
        }

        $style->set_prop("flex", "2 3 40pt");
        foreach (["-1 1 20pt", "1 -1 20pt", "1 1 -20pt", "1 1 20pt extra", "1 ! 1 20pt", "2 40pt 3", "0 0% 0"] as $value) {
            $style->set_prop("flex", $value);
            $this->assertSame(2.0, $style->flex_grow, "invalid flex grow: " . $value);
            $this->assertSame(3.0, $style->flex_shrink, "invalid flex shrink: " . $value);
            $this->assertSame(40.0, $style->flex_basis, "invalid flex basis: " . $value);
        }

        $style->set_prop("flex", "initial");
        $this->assertSame(0.0, $style->flex_grow);
        $this->assertSame(1.0, $style->flex_shrink);
        $this->assertSame("auto", $style->flex_basis);

        foreach ([
            ["row-reverse wrap", "row-reverse", "wrap"], ["wrap-reverse column", "column", "wrap-reverse"],
            ["column", "column", "nowrap"], ["wrap", "row", "wrap"]
        ] as $value) {
            $style->set_prop("flex_flow", $value[0]);
            $this->assertSame($value[1], $style->flex_direction, "flex-flow direction: " . $value[0]);
            $this->assertSame($value[2], $style->flex_wrap, "flex-flow wrap: " . $value[0]);
        }

        $style->set_prop("flex_flow", "row wrap");
        foreach (["row column", "wrap nowrap", "row wrap extra", "row ! wrap"] as $value) {
            $style->set_prop("flex_flow", $value);
            $this->assertSame("row", $style->flex_direction, "invalid flex-flow direction: " . $value);
            $this->assertSame("wrap", $style->flex_wrap, "invalid flex-flow wrap: " . $value);
        }
    }

    public function testGapShorthandAliasesAndCascade(): void
    {
        $style = $this->style();
        $style->set_prop("gap", "10pt 20%");
        $this->assertSame(10.0, $style->row_gap);
        $this->assertSame("20%", $style->column_gap);
        $style->set_prop("gap", "5pt");
        $this->assertSame(5.0, $style->row_gap);
        $this->assertSame(5.0, $style->column_gap);
        foreach (["10pt20pt", "0pt0", "1e2px20pt"] as $value) {
            $style->set_prop("gap", $value);
            $this->assertSame(5.0, $style->row_gap, "invalid gap: " . $value);
            $this->assertSame(5.0, $style->column_gap, "invalid gap: " . $value);
        }
        $style->set_prop("gap", "10pt 20pt 30pt");
        $this->assertSame(5.0, $style->row_gap);
        $this->assertSame(5.0, $style->column_gap);
        $style->set_prop("gap", "10pt -1pt");
        $this->assertSame(5.0, $style->row_gap);
        $this->assertSame(5.0, $style->column_gap);
        $style->set_prop("gap", "initial");
        $this->assertSame("normal", $style->row_gap);
        $this->assertSame("normal", $style->column_gap);

        $style->set_prop("grid-gap", "3pt 4pt");
        $this->assertSame(3.0, $style->row_gap);
        $this->assertSame(4.0, $style->column_gap);
        $style->set_prop("grid-row-gap", "5pt");
        $style->set_prop("grid-column-gap", "6pt");
        $this->assertSame(5.0, $style->row_gap);
        $this->assertSame(6.0, $style->column_gap);
        $style->set_prop("grid-row-gap", "7pt", true);
        $style->set_prop("row-gap", "8pt");
        $this->assertSame(7.0, $style->row_gap);

        $style->set_prop("flex_grow", "2", true);
        $style->set_prop("flex_grow", "3");
        $this->assertSame(2.0, $style->flex_grow);
        $style->set_prop("flex_grow", "-1", true);
        $style->set_prop("flex_grow", "3");
        $this->assertSame(2.0, $style->flex_grow);

        $invalidImportant = $this->style();
        $invalidImportant->set_prop("flex_shrink", "-1", true);
        $invalidImportant->set_prop("flex_shrink", "3");
        $this->assertSame(3.0, $invalidImportant->flex_shrink);

        $unresolvedImportant = $this->style();
        $unresolvedImportant->set_prop("flex_grow", "var(--grow)", true);
        $unresolvedImportant->set_prop("flex_grow", "3");
        $this->assertSame(0.0, $unresolvedImportant->flex_grow);
        $unresolvedImportant->set_prop("--grow", "2");
        $this->assertSame(2.0, $unresolvedImportant->flex_grow);

        $style->set_prop("flex", "1 1 10pt");
        $style->set_prop("flex_basis", "20pt");
        $this->assertSame(20.0, $style->flex_basis);
        $style->set_prop("flex", "2 2 30pt");
        $this->assertSame(30.0, $style->flex_basis);

        $important = $this->style();
        $important->set_prop("flex_grow", "4", true);
        $normal = $this->style();
        $normal->set_prop("flex_grow", "5");
        $resolved = $this->style();
        $resolved->merge($important);
        $resolved->merge($normal);
        $this->assertSame(4.0, $resolved->flex_grow);
    }

    public function testCssWideVariablesInheritanceAndFontDependencies(): void
    {
        $style = $this->style();
        $style->set_prop("flex", "var(--flex, 2 3 40pt)");
        $this->assertSame(2.0, $style->flex_grow);
        $style->set_prop("--flex", "1 2 25%");
        $this->assertSame(1.0, $style->flex_grow);
        $this->assertSame(2.0, $style->flex_shrink);
        $this->assertSame("25%", $style->flex_basis);

        $parent = $this->style();
        $parent->set_prop("flex_grow", "3");
        $child = $this->style();
        $child->set_prop("flex_grow", "inherit");
        $child->inherit($parent);
        $this->assertSame(3.0, $child->flex_grow);
        $child->set_prop("flex_grow", "unset");
        $child->inherit($parent);
        $this->assertSame(0.0, $child->flex_grow);

        $source = $this->style();
        $source->set_prop("flex_basis", "2em");
        $source->set_prop("row_gap", "3em");
        $source->set_prop("column_gap", "4em");
        $this->assertSame(24.0, $source->flex_basis);
        $target = $this->style();
        $target->set_prop("font_size", "10pt");
        $target->merge($source);
        $this->assertSame(20.0, $target->flex_basis);
        $this->assertSame(30.0, $target->row_gap);
        $this->assertSame(40.0, $target->column_gap);

        $source->set_prop("flex_basis", "fit-content(2em)");
        $this->assertSame("fit-content(24pt)", $source->flex_basis);
        $target->merge($source);
        $this->assertSame("fit-content(20pt)", $target->flex_basis);
    }
}
