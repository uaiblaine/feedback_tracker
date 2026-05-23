<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Interval math helpers for the academic-time engine.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\calendar;

/**
 * Pure functions over half-open numeric intervals [start, end).
 *
 * Inputs and outputs are arrays of [start, end] tuples (int or float, but
 * mixing within one call is undefined). A "canonical" interval list is
 * sorted ascending by start and contains no overlapping or empty entries.
 *
 * All public methods accept arbitrary inputs and produce canonical outputs.
 */
class interval_math {
    /**
     * Canonicalise an interval list: sort by start, merge overlapping or
     * adjacent intervals, drop empty ones.
     *
     * @param array $intervals List of [start, end] pairs.
     * @return array Canonical interval list.
     */
    public static function union(array $intervals): array {
        $clean = [];
        foreach ($intervals as $iv) {
            if (!isset($iv[0], $iv[1])) {
                continue;
            }
            if ($iv[1] <= $iv[0]) {
                continue;
            }
            $clean[] = [$iv[0], $iv[1]];
        }
        if (empty($clean)) {
            return [];
        }
        usort($clean, static fn($a, $b) => $a[0] <=> $b[0]);
        $result = [array_shift($clean)];
        foreach ($clean as $iv) {
            $lastidx = count($result) - 1;
            if ($iv[0] <= $result[$lastidx][1]) {
                if ($iv[1] > $result[$lastidx][1]) {
                    $result[$lastidx][1] = $iv[1];
                }
            } else {
                $result[] = $iv;
            }
        }
        return $result;
    }

    /**
     * Intersection of two interval lists.
     *
     * @param array $a Interval list (not required to be canonical).
     * @param array $b Interval list (not required to be canonical).
     * @return array Canonical intersection.
     */
    public static function intersect(array $a, array $b): array {
        $a = self::union($a);
        $b = self::union($b);
        $result = [];
        $i = 0;
        $j = 0;
        $counta = count($a);
        $countb = count($b);
        while ($i < $counta && $j < $countb) {
            $lo = max($a[$i][0], $b[$j][0]);
            $hi = min($a[$i][1], $b[$j][1]);
            if ($lo < $hi) {
                $result[] = [$lo, $hi];
            }
            if ($a[$i][1] < $b[$j][1]) {
                $i++;
            } else {
                $j++;
            }
        }
        return $result;
    }

    /**
     * Set subtraction: $a minus $b.
     *
     * @param array $a Interval list (not required to be canonical).
     * @param array $b Interval list (not required to be canonical).
     * @return array Canonical result of $a minus $b.
     */
    public static function subtract(array $a, array $b): array {
        $a = self::union($a);
        $b = self::union($b);
        if (empty($a)) {
            return [];
        }
        if (empty($b)) {
            return $a;
        }
        $result = [];
        foreach ($a as $ival) {
            $cur = $ival[0];
            $end = $ival[1];
            foreach ($b as $bival) {
                if ($bival[1] <= $cur) {
                    continue;
                }
                if ($bival[0] >= $end) {
                    break;
                }
                if ($bival[0] > $cur) {
                    $result[] = [$cur, $bival[0]];
                }
                if ($bival[1] > $cur) {
                    $cur = $bival[1];
                }
                if ($cur >= $end) {
                    break;
                }
            }
            if ($cur < $end) {
                $result[] = [$cur, $end];
            }
        }
        return $result;
    }

    /**
     * Total length of an interval list.
     *
     * @param array $intervals Interval list (not required to be canonical).
     * @return int|float Sum of lengths.
     */
    public static function total(array $intervals) {
        $sum = 0;
        foreach (self::union($intervals) as $iv) {
            $sum += ($iv[1] - $iv[0]);
        }
        return $sum;
    }
}
