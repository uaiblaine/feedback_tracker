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
 * Effective open/close/cutoff resolver for an assign + user + group.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * Reads {assign} plus {assign_overrides} to compute the effective
 * allowsubmissionsfromdate / duedate / cutoffdate for one (assign, user,
 * group) tuple.
 *
 * Override priority: user override > group override > assign defaults.
 * Returns null fields where no value is set anywhere.
 */
class rule_resolver {
    /**
     * Resolve the effective rule for a (assign, user, group).
     *
     * @param \stdClass $assign An {assign} row (must include id,
     *                          allowsubmissionsfromdate, duedate, cutoffdate).
     * @param int $userid
     * @param int $groupid 0 if user has no group.
     * @return array{timeopens:?int, timecloses:?int, timecutoff:?int, hasrule:int}
     */
    public static function resolve_rule(\stdClass $assign, int $userid, int $groupid): array {
        global $DB;

        $timeopens = self::nonzero_or_null($assign->allowsubmissionsfromdate ?? null);
        $timecloses = self::nonzero_or_null($assign->duedate ?? null);
        $timecutoff = self::nonzero_or_null($assign->cutoffdate ?? null);

        $userovr = $DB->get_record('assign_overrides', [
            'assignid' => $assign->id,
            'userid' => $userid,
        ]);
        if ($userovr) {
            $timeopens = self::override_value($userovr->allowsubmissionsfromdate ?? null, $timeopens);
            $timecloses = self::override_value($userovr->duedate ?? null, $timecloses);
            $timecutoff = self::override_value($userovr->cutoffdate ?? null, $timecutoff);
        } else if ($groupid > 0) {
            $groupovr = $DB->get_record('assign_overrides', [
                'assignid' => $assign->id,
                'groupid' => $groupid,
            ]);
            if ($groupovr) {
                $timeopens = self::override_value($groupovr->allowsubmissionsfromdate ?? null, $timeopens);
                $timecloses = self::override_value($groupovr->duedate ?? null, $timecloses);
                $timecutoff = self::override_value($groupovr->cutoffdate ?? null, $timecutoff);
            }
        }

        return [
            'timeopens' => $timeopens,
            'timecloses' => $timecloses,
            'timecutoff' => $timecutoff,
            'hasrule' => ($timeopens !== null || $timecloses !== null || $timecutoff !== null) ? 1 : 0,
        ];
    }

    /**
     * Pure merge of one optional override row over the assign defaults, with no
     * DB access. Used by the activity-schedule catalog, which batch-loads the
     * group overrides itself and resolves many (assign, group) pairs in memory
     * rather than one query per pair.
     *
     * @param \stdClass $assign An {assign} row (id, allowsubmissionsfromdate,
     *                          duedate, cutoffdate).
     * @param \stdClass|null $override A single override row, or null.
     * @return array{timeopens:?int, timecloses:?int, timecutoff:?int, hasrule:int}
     */
    public static function merge_override(\stdClass $assign, ?\stdClass $override): array {
        $timeopens = self::nonzero_or_null($assign->allowsubmissionsfromdate ?? null);
        $timecloses = self::nonzero_or_null($assign->duedate ?? null);
        $timecutoff = self::nonzero_or_null($assign->cutoffdate ?? null);

        if ($override !== null) {
            $timeopens = self::override_value($override->allowsubmissionsfromdate ?? null, $timeopens);
            $timecloses = self::override_value($override->duedate ?? null, $timecloses);
            $timecutoff = self::override_value($override->cutoffdate ?? null, $timecutoff);
        }

        return [
            'timeopens' => $timeopens,
            'timecloses' => $timecloses,
            'timecutoff' => $timecutoff,
            'hasrule' => ($timeopens !== null || $timecloses !== null || $timecutoff !== null) ? 1 : 0,
        ];
    }

    /**
     * Treat 0 / null / empty-string as "not set", everything else as an int.
     *
     * @param mixed $raw
     * @return int|null
     */
    private static function nonzero_or_null($raw): ?int {
        if ($raw === null || $raw === '' || $raw === 0 || $raw === '0') {
            return null;
        }
        return (int) $raw;
    }

    /**
     * Override pickup: a non-zero override value wins; otherwise keep the
     * previous value.
     *
     * @param mixed $override
     * @param int|null $previous
     * @return int|null
     */
    private static function override_value($override, ?int $previous): ?int {
        $val = self::nonzero_or_null($override);
        return $val !== null ? $val : $previous;
    }
}
