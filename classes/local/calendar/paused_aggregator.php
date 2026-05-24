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
 * Paused-days aggregator — counts days in a window where the calendar
 * marked time as not counting toward the SLA, broken down by reason.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\calendar;

/**
 * Counts paused days in a rolling window by reason (weekend / holiday /
 * recess). Drives the design's "Paused periods excluded from this report"
 * transparency callout on the report page and the per-block PausedNote.
 *
 * Reasons are bucketed for display, not for SLA arithmetic:
 *  - weekend  — day matches the configured weekend mask AND weekend
 *               exclusion is enabled, AND no schoolday override exists in
 *               {block_feedback_tracker_cday}.
 *  - holiday  — daytype = 'holiday'.
 *  - recess   — daytype = 'recess' OR 'closed' OR any active manual pause
 *               (from {block_feedback_tracker_cpause}) covers the day.
 *
 * A "schoolday" override in {block_feedback_tracker_cday} cancels the
 * weekend classification — admins explicitly opting a Saturday into the
 * working week. Manual pauses include site / course / group scopes;
 * scoping is filtered by the caller's courseid.
 */
class paused_aggregator {
    /**
     * Count paused days in a [start, end) window.
     *
     * @param int $courseid Course context for manual-pause scoping; 0 for site-wide.
     * @param int $start    Unix seconds; inclusive lower bound.
     * @param int $end      Unix seconds; exclusive upper bound. Must be > $start.
     * @return array{total_days:int, weekend:int, holiday:int, recess:int}
     */
    public static function for_window(int $courseid, int $start, int $end): array {
        if ($end <= $start) {
            return ['total_days' => 0, 'weekend' => 0, 'holiday' => 0, 'recess' => 0];
        }

        $tz = calendar::timezone();
        $excludeweekends = calendar::excludeweekends();
        $excludeholidays = calendar::excludeholidays();
        $excluderecesses = calendar::excluderecesses();

        // Walk each day in the window in the platform timezone. Window is
        // small (typically 30 days) so per-day iteration is cheap.
        $cur = (new \DateTimeImmutable('@' . $start))->setTimezone($tz)->setTime(0, 0, 0);
        $endboundary = (new \DateTimeImmutable('@' . $end))->setTimezone($tz);
        $days = [];
        $dayymds = [];
        while ($cur < $endboundary) {
            $ymd = (int) $cur->format('Ymd');
            $dow = (int) $cur->format('w');
            $days[$ymd] = $dow;
            $dayymds[] = $ymd;
            $cur = $cur->modify('+1 day');
        }
        if (empty($dayymds)) {
            return ['total_days' => 0, 'weekend' => 0, 'holiday' => 0, 'recess' => 0];
        }

        $overrides = self::day_overrides($dayymds);
        $pausespans = self::pause_spans($courseid, $start, $end);

        $weekend = 0;
        $holiday = 0;
        $recess = 0;

        foreach ($days as $ymd => $dow) {
            $override = $overrides[$ymd] ?? null;
            $isweekend = $excludeweekends && calendar::is_weekend($dow);

            // schoolday overrides cancel the weekend classification.
            if ($override === 'schoolday') {
                $isweekend = false;
            }

            // Priority: holiday > recess/closed/manual-pause > weekend.
            if ($override === 'holiday' && $excludeholidays) {
                $holiday++;
                continue;
            }
            if (($override === 'recess' || $override === 'closed') && $excluderecesses) {
                $recess++;
                continue;
            }
            if (self::ymd_in_pause_span($ymd, $tz, $pausespans)) {
                $recess++;
                continue;
            }
            if ($isweekend) {
                $weekend++;
            }
        }

        return [
            'total_days' => $weekend + $holiday + $recess,
            'weekend'    => $weekend,
            'holiday'    => $holiday,
            'recess'     => $recess,
        ];
    }

    /**
     * Fetch the day-override types for the supplied YYYYMMDD ints.
     *
     * @param int[] $ymds
     * @return array<int, string> Map of ymd → daytype.
     */
    private static function day_overrides(array $ymds): array {
        global $DB;
        if (empty($ymds)) {
            return [];
        }
        [$insql, $params] = $DB->get_in_or_equal($ymds, SQL_PARAMS_NAMED, 'd');
        $rows = $DB->get_records_select(
            'block_feedback_tracker_cday',
            "daydate $insql",
            $params,
            '',
            'id, daydate, daytype'
        );
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->daydate] = (string) $r->daytype;
        }
        return $out;
    }

    /**
     * Fetch [start, end) manual pause windows scoped to the course (or
     * site-wide for $courseid = 0). Group-scoped pauses are folded in via
     * any group belonging to the course — the design's aggregate is
     * per-course, not per-group.
     *
     * @param int $courseid
     * @param int $windowstart
     * @param int $windowend
     * @return array<int, array{start:int, end:int}>
     */
    private static function pause_spans(int $courseid, int $windowstart, int $windowend): array {
        global $DB;

        $sql = "SELECT id, scopelevel, scopeid, timestart, timeend
                  FROM {block_feedback_tracker_cpause}
                 WHERE timestart < :wend
                   AND (timeend IS NULL OR timeend > :wstart)
                   AND (
                       scopelevel = :sitescope
                       OR (scopelevel = :coursescope AND scopeid = :courseid)
                       OR (scopelevel = :groupscope AND scopeid IN (
                           SELECT g.id FROM {groups} g WHERE g.courseid = :groupcourseid
                       ))
                   )";
        $params = [
            'wend'          => $windowend,
            'wstart'        => $windowstart,
            'sitescope'     => 'site',
            'coursescope'   => 'course',
            'groupscope'    => 'group',
            'courseid'      => $courseid,
            'groupcourseid' => $courseid,
        ];
        $rows = $DB->get_records_sql($sql, $params);
        $spans = [];
        foreach ($rows as $r) {
            $spans[] = [
                'start' => (int) $r->timestart,
                'end'   => $r->timeend !== null ? (int) $r->timeend : $windowend,
            ];
        }
        return $spans;
    }

    /**
     * Whether the given calendar day overlaps any pause span. A day is
     * "paused" if any second of its 24-hour window in the platform tz is
     * inside a pause span.
     *
     * @param int $ymd
     * @param \DateTimeZone $tz
     * @param array<int, array{start:int, end:int}> $spans
     * @return bool
     */
    private static function ymd_in_pause_span(int $ymd, \DateTimeZone $tz, array $spans): bool {
        if (empty($spans)) {
            return false;
        }
        $datestr = sprintf('%04d-%02d-%02d', (int) ($ymd / 10000), (int) (($ymd / 100) % 100), $ymd % 100);
        $daystart = (new \DateTimeImmutable($datestr, $tz))->getTimestamp();
        $dayend = $daystart + 86400;
        foreach ($spans as $span) {
            if ($span['start'] < $dayend && $span['end'] > $daystart) {
                return true;
            }
        }
        return false;
    }
}
