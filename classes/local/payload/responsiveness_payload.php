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
 * Pure data loader for the responsiveness payload.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\payload;

use block_feedback_tracker\local\calendar\calendar;
use block_feedback_tracker\local\calendar\paused_aggregator;
use block_feedback_tracker\local\score\peer_stats;

/**
 * Builds the responsiveness payload (groups array + lastsynced) without
 * touching $PAGE / $OUTPUT. Safe to call from inside `get_content()` on a
 * block (which runs after page output has started) — `external_api::
 * validate_context()` calls `$PAGE->set_context()` which fails at that
 * point, so the WS layer cannot be used directly there.
 *
 * Capability checks remain the caller's responsibility.
 */
class responsiveness_payload {
    /** Session cache TTL in seconds. */
    public const CACHE_TTL = 900;

    /**
     * Build the payload for one course as seen by one user.
     *
     * @param int $courseid
     * @param int $userid
     * @param bool $force Skip the session cache read and write a fresh entry.
     * @return array{success:bool, courseid:int, lastsynced:int, groups:array<int, array>}
     */
    public static function for_course(int $courseid, int $userid, bool $force = false): array {
        global $DB;

        $cache = \cache::make('block_feedback_tracker', 'responsiveness_payload');
        $key = calendar::current_version() . '_' . $userid . '_' . $courseid;
        if (!$force) {
            $cached = $cache->get($key);
            if (
                is_array($cached)
                && isset($cached['lastsynced'])
                && (time() - (int) $cached['lastsynced']) < self::CACHE_TTL
            ) {
                return $cached;
            }
        }

        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $groupmode = (int) groups_get_course_groupmode($course);

        $allgroups = groups_get_all_groups($courseid);
        $groupnames = [];
        foreach ($allgroups as $g) {
            $groupnames[(int) $g->id] = (string) $g->name;
        }

        // Delegate the visibility decision (NOGROUPS / accessallgroups /
        // VISIBLEGROUPS / SEPARATEGROUPS) to the shared helper used by the
        // WS endpoints. null = unrestricted; array = named-group whitelist
        // (empty = SEPARATEGROUPS teacher with no group membership →
        // nothing renders).
        $visible = \block_feedback_tracker\local\sla\group_access::visible_group_ids($courseid, $userid);
        $allgroupsvisible = $visible === null;
        $visibleids = $visible ?? [];

        $rollups = $DB->get_records(
            'block_feedback_tracker_group',
            ['courseid' => $courseid],
            'groupid ASC'
        );

        // Pre-compute the last-30-days window once for trend lookups.
        $trendwindow = self::trend_window(30);

        // Course-level paused aggregate for the last 30 days. One call
        // per render — the design's PausedNote + report-page callout
        // share the same numbers.
        $now = time();
        $pausedwindowstart = $now - 30 * 86400;
        $pausedaggregate = paused_aggregator::for_window($courseid, $pausedwindowstart, $now);

        $payloadgroups = [];
        foreach ($rollups as $r) {
            $gid = (int) $r->groupid;
            if (!$allgroupsvisible && !in_array($gid, $visibleids, true)) {
                continue;
            }
            if ($gid === 0) {
                $name = $groupmode === NOGROUPS
                    ? get_string('card_nogroup', 'block_feedback_tracker')
                    : get_string('card_ungrouped', 'block_feedback_tracker');
            } else {
                $name = $groupnames[$gid] ?? sprintf('Group #%d', $gid);
            }
            $series = self::trend_series_for_group($courseid, $gid, $trendwindow);
            $peer = peer_stats::for_exclusion($gid);
            $payloadgroups[] = self::group_payload($gid, $name, $course, $r, $series, $pausedaggregate, $peer);
        }

        $result = [
            'success'    => true,
            'courseid'   => $courseid,
            'lastsynced' => time(),
            'groups'     => $payloadgroups,
        ];

        $cache->set($key, $result);
        return $result;
    }

    /**
     * Build one group card payload from a rollup row.
     *
     * Optional Phase 3C parameters ($pausedaggregate, $peer) are nullable
     * so unit tests that build a payload from a bare rollup row still work
     * without wiring the aggregator + peer_stats. for_course() always
     * passes both.
     *
     * @param int $groupid Group ID.
     * @param string $groupname Group name.
     * @param \stdClass $course Course object.
     * @param \stdClass $row Rollup row.
     * @param array $trendseries Last-30-day median values.
     * @param array<string, int>|null $pausedaggregate Output of paused_aggregator::for_window().
     * @param array<string, float|null>|null $peer Output of peer_stats::for_exclusion().
     * @return array
     */
    public static function group_payload(
        int $groupid,
        string $groupname,
        \stdClass $course,
        \stdClass $row,
        array $trendseries = [],
        ?array $pausedaggregate = null,
        ?array $peer = null
    ): array {
        $pausedaggregate = $pausedaggregate ?? ['total_days' => 0, 'weekend' => 0, 'holiday' => 0, 'recess' => 0, 'events' => []];
        $peer = $peer ?? ['department_score' => null, 'department_hours' => null,
                          'top10_score' => null, 'top10_hours' => null];
        return [
            'groupid'              => $groupid,
            'groupname'            => $groupname,
            'coursename'           => $course->fullname,
            'pending'              => (int) $row->pending,
            'critical'             => (int) $row->critical,
            'overgoal'             => (int) $row->overgoal,
            'numgraded30d'         => (int) $row->numgraded30d,
            'compliance_pct'       => $row->compliance_pct !== null ? (float) $row->compliance_pct : null,
            'median_eff_h'         => $row->median_eff_h !== null ? (float) $row->median_eff_h : null,
            'p90_eff_h'            => $row->p90_eff_h !== null ? (float) $row->p90_eff_h : null,
            'max_eff_h'            => $row->max_eff_h !== null ? (float) $row->max_eff_h : null,
            'median_raw_h'         => $row->median_raw_h !== null ? (float) $row->median_raw_h : null,
            'p90_raw_h'            => $row->p90_raw_h !== null ? (float) $row->p90_raw_h : null,
            'max_raw_h'            => $row->max_raw_h !== null ? (float) $row->max_raw_h : null,
            // Phase 3C — design's "Perceived" KPI; same data source as
            // median_raw_h, renamed for the user-facing language layer.
            // We keep median_raw_h around for back-compat with any caller
            // already binding the old key.
            'perceived_median_hours' => $row->median_raw_h !== null ? (float) $row->median_raw_h : null,
            // Headline "current" medians — graded ∪ currently-pending — so the
            // block's Effective / Perceived KPI tiles reflect the live backlog
            // instead of reading ~0 when little has been graded, matching the
            // dashboard. The score still uses graded-only median_eff_h above.
            'cur_median_eff_h'     => isset($row->cur_median_eff_h) && $row->cur_median_eff_h !== null
                ? (float) $row->cur_median_eff_h : null,
            'cur_median_raw_h'     => isset($row->cur_median_raw_h) && $row->cur_median_raw_h !== null
                ? (float) $row->cur_median_raw_h : null,
            'responsiveness_score' => $row->responsiveness_score !== null ? (float) $row->responsiveness_score : null,
            'score_band'           => $row->score_band !== null ? (string) $row->score_band : null,
            'comp_compliance'      => isset($row->comp_compliance) && $row->comp_compliance !== null
                ? (float) $row->comp_compliance : null,
            'comp_median'          => isset($row->comp_median) && $row->comp_median !== null
                ? (float) $row->comp_median : null,
            'comp_critical'        => isset($row->comp_critical) && $row->comp_critical !== null
                ? (float) $row->comp_critical : null,
            'comp_pending'         => isset($row->comp_pending) && $row->comp_pending !== null
                ? (float) $row->comp_pending : null,
            'comp_trend'           => isset($row->comp_trend) && $row->comp_trend !== null
                ? (float) $row->comp_trend : null,
            'trend_pct_30d'        => $row->trend_pct_30d !== null ? (float) $row->trend_pct_30d : null,
            'trend_series'         => $trendseries,
            'nextpause_ts'         => $row->nextpause_ts !== null ? (int) $row->nextpause_ts : null,
            'nextpause_reason'     => $row->nextpause_reason !== null ? (string) $row->nextpause_reason : null,
            'nextpause_note'       => $row->nextpause_note !== null ? (string) $row->nextpause_note : null,
            'lastpause_endts'      => $row->lastpause_endts !== null ? (int) $row->lastpause_endts : null,
            'lastpause_reason'     => $row->lastpause_reason !== null ? (string) $row->lastpause_reason : null,
            // Phase 3C — paused-window transparency aggregate (course scope).
            'paused_days_30d'      => (int) $pausedaggregate['total_days'],
            'paused_breakdown_30d' => [
                'weekend' => (int) $pausedaggregate['weekend'],
                'holiday' => (int) $pausedaggregate['holiday'],
                'recess'  => (int) $pausedaggregate['recess'],
            ],
            /* v1.0.9 — sub-day optional events sidecar list. Each entry
             * is {date: YYYYMMDD, starttime: min, endtime: min, label: str}.
             * Label is already format_string()-sanitised by paused_aggregator. */
            'paused_events_30d' => is_array($pausedaggregate['events'] ?? null)
                ? array_map(static fn ($e) => [
                    'date'      => (int) $e['date'],
                    'starttime' => (int) $e['starttime'],
                    'endtime'   => (int) $e['endtime'],
                    'label'     => (string) $e['label'],
                ], $pausedaggregate['events'])
                : [],
            // Phase 3C — peer comparison (excluding this group).
            'peer_department_score' => $peer['department_score'] !== null ? (float) $peer['department_score'] : null,
            'peer_department_hours' => $peer['department_hours'] !== null ? (float) $peer['department_hours'] : null,
            'peer_top10_score'      => $peer['top10_score'] !== null ? (float) $peer['top10_score'] : null,
            'peer_top10_hours'      => $peer['top10_hours'] !== null ? (float) $peer['top10_hours'] : null,
        ];
    }

    /**
     * Produce an ordered list of YYYYMMDD ints for the last $days days
     * (today inclusive), in platform tz.
     *
     * @param int $days
     * @return array<int, int>
     */
    private static function trend_window(int $days): array {
        $tz = \block_feedback_tracker\local\calendar\calendar::timezone();
        $today = (new \DateTimeImmutable('@' . time()))->setTimezone($tz)->setTime(0, 0, 0);
        $window = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $window[] = (int) $today->modify("-{$i} days")->format('Ymd');
        }
        return $window;
    }

    /**
     * Look up the trend rows for one (course, group) and return them aligned
     * to the supplied date window, with null for missing days.
     *
     * @param int $courseid Course ID.
     * @param int $groupid Group ID.
     * @param array $window YYYYMMDD ints, oldest → newest.
     * @return array
     */
    private static function trend_series_for_group(int $courseid, int $groupid, array $window): array {
        global $DB;

        $first = (int) reset($window);
        $last = (int) end($window);
        $rows = $DB->get_records_select(
            'block_feedback_tracker_trend',
            'courseid = :courseid AND groupid = :groupid AND day >= :first AND day <= :last',
            [
                'courseid' => $courseid,
                'groupid'  => $groupid,
                'first'    => $first,
                'last'     => $last,
            ],
            'day ASC',
            'id, day, medianh_eff'
        );

        $bymd = [];
        foreach ($rows as $r) {
            $bymd[(int) $r->day] = $r->medianh_eff !== null ? (float) $r->medianh_eff : null;
        }

        $series = [];
        foreach ($window as $ymd) {
            $series[] = [
                'day'   => $ymd,
                'value' => $bymd[$ymd] ?? null,
            ];
        }
        return $series;
    }
}
