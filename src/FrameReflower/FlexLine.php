<?php
/**
 * @package dompdf
 * @link    https://github.com/dompdf/dompdf
 * @license http://www.gnu.org/copyleft/lesser.html GNU Lesser General Public License
 */
namespace Dompdf\FrameReflower;

/**
 * Pure numeric flexible-length resolution for one flex line.
 *
 * @internal
 * @spec CSS Flexible Box Layout §9.7
 */
class FlexLine
{
    /**
     * Resolve the flexible content-box sizes of one flex line.
     *
     * @param array<int,array<string,float>> $items
     * @return array<int,float>
     */
    public static function resolve(array $items, float $innerMainSize, float $gap): array
    {
        $count = count($items);
        if ($count === 0) {
            return [];
        }

        $hypotheticalOuter = $gap * ($count - 1);
        if (!is_finite($hypotheticalOuter)) {
            throw new \OverflowException("Flex line geometry exceeds finite floating-point range");
        }
        foreach ($items as $item) {
            $hypotheticalOuter += $item["hypothetical"] + $item["outer"];
            if (!is_finite($hypotheticalOuter)) {
                throw new \OverflowException("Flex line geometry exceeds finite floating-point range");
            }
        }
        $useGrow = $hypotheticalOuter < $innerMainSize;
        $factorKey = $useGrow ? "grow" : "shrink";

        $targets = [];
        $frozen = [];
        foreach ($items as $index => $item) {
            $targets[$index] = $item["base"];
            $frozen[$index] = $item[$factorKey] == 0.0
                || ($useGrow && $item["base"] > $item["hypothetical"])
                || (!$useGrow && $item["base"] < $item["hypothetical"]);
            if ($frozen[$index]) {
                $targets[$index] = $item["hypothetical"];
            }
        }

        $initialFreeSpace = self::freeSpace($items, $targets, $frozen, $innerMainSize, $gap);

        while (true) {
            $unfrozen = 0;
            foreach ($frozen as $isFrozen) {
                if (!$isFrozen) {
                    $unfrozen++;
                }
            }
            if ($unfrozen === 0) {
                break;
            }

            // Each pass freezes at least one item; this is the §9.7 termination invariant.
            $remainingFreeSpace = self::freeSpace($items, $targets, $frozen, $innerMainSize, $gap);
            $weights = self::weights($items, $frozen, $useGrow);
            $factorSum = $weights["factorSum"];
            if ($factorSum < 1.0) {
                $capped = $initialFreeSpace * $factorSum;
                if (abs($capped) < abs($remainingFreeSpace)) {
                    $remainingFreeSpace = $capped;
                }
            }

            $totalViolation = 0.0;
            $minViolations = [];
            $maxViolations = [];
            foreach ($items as $index => $item) {
                if ($frozen[$index]) {
                    continue;
                }

                $target = $item["base"];
                if ($remainingFreeSpace != 0.0 && $weights["sum"] != 0.0) {
                    if ($useGrow) {
                        $target += $remainingFreeSpace * $weights["values"][$index] / $weights["sum"];
                    } else {
                        $target -= abs($remainingFreeSpace) * $weights["values"][$index] / $weights["sum"];
                    }
                }
                if (!is_finite($target)) {
                    throw new \OverflowException("Flex line geometry exceeds finite floating-point range");
                }

                $unclamped = $target;
                if ($target < $item["min"]) {
                    $target = $item["min"];
                    $minViolations[$index] = true;
                } elseif ($target > $item["max"]) {
                    $target = $item["max"];
                    $maxViolations[$index] = true;
                }
                $targets[$index] = $target;
                $totalViolation += $target - $unclamped;
                if (!is_finite($totalViolation)) {
                    throw new \OverflowException("Flex line geometry exceeds finite floating-point range");
                }
            }

            if ($totalViolation == 0.0) {
                foreach ($frozen as $index => $isFrozen) {
                    if (!$isFrozen) {
                        $frozen[$index] = true;
                    }
                }
            } else {
                $toFreeze = $totalViolation > 0.0 ? $minViolations : $maxViolations;
                foreach ($toFreeze as $index => $unused) {
                    $frozen[$index] = true;
                }
            }
        }

        return array_values($targets);
    }

    /**
     * @param array<int,array<string,float>> $items
     * @param array<int,float> $targets
     * @param array<int,bool> $frozen
     */
    private static function freeSpace(array $items, array $targets, array $frozen, float $innerMainSize, float $gap): float
    {
        $used = $gap * (count($items) - 1);
        if (!is_finite($used)) {
            throw new \OverflowException("Flex line geometry exceeds finite floating-point range");
        }
        foreach ($items as $index => $item) {
            $used += ($frozen[$index] ? $targets[$index] : $item["base"]) + $item["outer"];
            if (!is_finite($used)) {
                throw new \OverflowException("Flex line geometry exceeds finite floating-point range");
            }
        }
        $freeSpace = $innerMainSize - $used;
        if (!is_finite($freeSpace)) {
            throw new \OverflowException("Flex line geometry exceeds finite floating-point range");
        }
        return $freeSpace;
    }

    /**
     * Normalize factor weights before summing so large accepted factors cannot
     * overflow while preserving each item's ratio.
     *
     * @param array<int,array<string,float>> $items
     * @param array<int,bool> $frozen
     * @return array{values:array<int,float>,sum:float,factorSum:float}
     */
    private static function weights(array $items, array $frozen, bool $useGrow): array
    {
        $maxFactor = 0.0;
        $maxBase = 0.0;
        foreach ($items as $index => $item) {
            if ($frozen[$index]) {
                continue;
            }
            $maxFactor = max($maxFactor, $item[$useGrow ? "grow" : "shrink"]);
            if (!$useGrow) {
                $maxBase = max($maxBase, abs($item["base"]));
            }
        }

        $values = [];
        $sum = 0.0;
        $normalizedFactorSum = 0.0;
        foreach ($items as $index => $item) {
            if ($frozen[$index]) {
                $values[$index] = 0.0;
                continue;
            }
            $factor = $item[$useGrow ? "grow" : "shrink"];
            $normalizedFactor = $maxFactor == 0.0 ? 0.0 : $factor / $maxFactor;
            $normalizedFactorSum += $normalizedFactor;
            $value = $normalizedFactor;
            if (!$useGrow) {
                $value *= $maxBase == 0.0 ? 0.0 : $item["base"] / $maxBase;
            }
            $values[$index] = $value;
            $sum += $value;
        }

        // The subunit cap uses unscaled flex factors; shrink distribution uses
        // the separate scaled values above.
        $factorSum = $maxFactor * $normalizedFactorSum;
        if ($maxFactor == 0.0 || $factorSum >= 1.0) {
            $factorSum = $maxFactor == 0.0 ? 0.0 : 1.0;
        }
        return ["values" => $values, "sum" => $sum, "factorSum" => $factorSum];
    }
}
