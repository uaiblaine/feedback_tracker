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
 * External: site-level dashboard summary.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\external;

use block_feedback_tracker\local\calendar\calendar;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Aggregates {block_feedback_tracker_group} rows into a per-course summary
 * for the admin dashboard. One row per course with pending/critical totals,
 * group count, median-of-medians, and an aggregate score band.
 */
class get_dashboard extends external_api {
    /** Cache TTL in seconds. */
    public const CACHE_TTL = 900;

    /**
     * Cache-key version. Bumped whenever the SQL or filtering logic
     * changes shape so stale entries from prior plugin versions are
     * naturally invalidated without a separate purge step. Bump this
     * before deploying any change to execute()'s WHERE clause or
     * aggregate columns.
     */
    public const CACHE_KEY_VERSION = 4;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'band' => new external_value(PARAM_ALPHA, 'Filter by band, "" = no filter', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Run.
     *
     * @param string $band
     * @return array
     */
    public static function execute(string $band = ''): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['band' => $band]);
        $band = trim((string) $params['band']);

        $sysctx = \context_system::instance();
        self::validate_context($sysctx);

        // Authorisation + result scope are centralised in dashboard_scope:
        // active enrolment with a teacher-or-higher role, unless the user is
        // a site admin with enable_admin_view_all on (then the whole site).
        // A non-admin with zero visible courses has no dashboard access.
        $userid = (int) $USER->id;
        $scope = \block_feedback_tracker\local\sla\dashboard_scope::visible_course_ids($userid);
        if ($scope !== null && empty($scope)) {
            throw new \required_capability_exception(
                $sysctx,
                'block/feedback_tracker:viewdashboard',
                'nopermissions',
                'error'
            );
        }
        // Cache key includes the user (so per-user filtering doesn't leak
        // across teachers), the band, and whether the user is in admin
        // view-all mode (so flipping enable_admin_view_all re-keys at once).
        $cache = \cache::make('block_feedback_tracker', 'dashboard_payload');
        $key = 'v' . self::CACHE_KEY_VERSION
            . '_' . calendar::current_version()
            . '_' . $USER->id
            . '_' . ($scope === null ? 'all' : 'scoped')
            . '_' . $band;
        $cached = $cache->get($key);
        if (
            $cached !== false && is_array($cached)
            && isset($cached['lastsynced'])
            && (time() - (int) $cached['lastsynced']) < self::CACHE_TTL
        ) {
            return $cached;
        }

        // Per-course / per-group visibility, centralised in dashboard_scope.
        // Returns MATCH_ALL (admin view-all), MATCH_NONE (nothing visible),
        // or an OR-joined clause over the user's courses + allowed groups —
        // so a SEPARATEGROUPS teacher never sees SUM() across groups they
        // don't belong to.
        [$where, $sqlparams] = \block_feedback_tracker\local\sla\dashboard_scope::sql_visibility(
            $userid,
            'g.courseid',
            'g.groupid',
            'dc'
        );
        if ($where === \block_feedback_tracker\local\sla\dashboard_scope::MATCH_NONE) {
            $result = [
                'success'    => true,
                'lastsynced' => time(),
                'courses'    => [],
            ];
            $cache->set($key, $result);
            return $result;
        }
        if ($band !== '') {
            $where .= ' AND g.score_band = :band';
            $sqlparams['band'] = $band;
        }

        $sql = "SELECT g.courseid,
                       c.fullname AS coursename,
                       COUNT(g.id) AS numgroups,
                       SUM(g.pending) AS pending,
                       SUM(g.critical) AS critical,
                       SUM(g.overgoal) AS overgoal,
                       AVG(g.responsiveness_score) AS avgscore,
                       AVG(g.median_eff_h) AS median_eff_h,
                       AVG(g.median_raw_h) AS perceived_median_hours
                  FROM {block_feedback_tracker_group} g
                  JOIN {course} c ON c.id = g.courseid
                 WHERE $where
              GROUP BY g.courseid, c.fullname
              ORDER BY pending DESC, c.fullname ASC";

        $rows = $DB->get_records_sql($sql, $sqlparams);

        $courses = [];
        $courseids = array_map(static fn ($r) => (int) $r->courseid, $rows);
        $trendseries = self::trend_series_for_courses($courseids);
        foreach ($rows as $r) {
            $avg = $r->avgscore !== null ? (float) $r->avgscore : null;
            $cid = (int) $r->courseid;
            $band = $avg !== null
                ? \block_feedback_tracker\local\score\responsiveness_calculator::band_for($avg)
                : null;
            $courses[] = [
                'courseid'  => $cid,
                'coursename' => (string) $r->coursename,
                'numgroups' => (int) $r->numgroups,
                'pending'   => (int) $r->pending,
                'critical'  => (int) $r->critical,
                'overgoal'  => (int) $r->overgoal,
                'avgscore'  => $avg !== null ? round($avg, 2) : null,
                'score_band' => $band,
                'median_eff_h' => $r->median_eff_h !== null ? round((float) $r->median_eff_h, 2) : null,
                'perceived_median_hours' => $r->perceived_median_hours !== null
                    ? round((float) $r->perceived_median_hours, 2) : null,
                'trend_series' => $trendseries[$cid] ?? [],
            ];
        }

        $result = [
            'success'    => true,
            'lastsynced' => time(),
            'courses'    => $courses,
        ];
        $cache->set($key, $result);
        return $result;
    }

    /**
     * Trend-series fetcher for the courses-table sparkline. Sums effective
     * median across each course's groups per day for the last 30 days,
     * aligned to a YYYYMMDD window. Cross-DB safe — uses the same
     * pattern as responsiveness_payload's per-group fetcher.
     *
     * @param int[] $courseids
     * @return array<int, array<int, array{day:int, value:float|null}>>
     */
    private static function trend_series_for_courses(array $courseids): array {
        global $DB;
        if (empty($courseids)) {
            return [];
        }
        $window = self::trend_window(30);

        [$insql, $params] = $DB->get_in_or_equal($courseids, SQL_PARAMS_NAMED, 'tc');
        $params['oldest'] = (int) $window[0];
        $sql = "SELECT id, courseid, day, medianh_eff
                  FROM {block_feedback_tracker_trend}
                 WHERE courseid $insql
                   AND day >= :oldest";
        $rows = $DB->get_records_sql($sql, $params);

        // Group rows by (courseid, day) — multiple groupids per course
        // contribute to the same day; average across groups.
        $byday = [];
        foreach ($rows as $r) {
            $cid = (int) $r->courseid;
            $day = (int) $r->day;
            $val = $r->medianh_eff !== null ? (float) $r->medianh_eff : null;
            if (!isset($byday[$cid][$day])) {
                $byday[$cid][$day] = [];
            }
            if ($val !== null) {
                $byday[$cid][$day][] = $val;
            }
        }

        $out = [];
        foreach ($courseids as $cid) {
            $series = [];
            foreach ($window as $day) {
                $vals = $byday[$cid][$day] ?? null;
                $series[] = [
                    'day'   => $day,
                    'value' => is_array($vals) && !empty($vals)
                        ? round(array_sum($vals) / count($vals), 2) : null,
                ];
            }
            $out[$cid] = $series;
        }
        return $out;
    }

    /**
     * Produce the YYYYMMDD ints for the last $days days in platform tz.
     *
     * @param int $days
     * @return array<int, int>
     */
    private static function trend_window(int $days): array {
        $tz = calendar::timezone();
        $today = (new \DateTimeImmutable('@' . time()))->setTimezone($tz)->setTime(0, 0, 0);
        $window = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $window[] = (int) $today->modify("-{$i} days")->format('Ymd');
        }
        return $window;
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success'    => new external_value(PARAM_BOOL, ''),
            'lastsynced' => new external_value(PARAM_INT, ''),
            'courses'    => new external_multiple_structure(new external_single_structure([
                'courseid'   => new external_value(PARAM_INT, ''),
                'coursename' => new external_value(PARAM_TEXT, ''),
                'numgroups'  => new external_value(PARAM_INT, ''),
                'pending'    => new external_value(PARAM_INT, ''),
                'critical'   => new external_value(PARAM_INT, ''),
                'overgoal'   => new external_value(PARAM_INT, ''),
                'avgscore'   => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
                'score_band' => new external_value(PARAM_ALPHA, '', VALUE_DEFAULT, null, NULL_ALLOWED),
                'median_eff_h' => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
                'perceived_median_hours' => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
                'trend_series' => new external_multiple_structure(
                    new external_single_structure([
                        'day'   => new external_value(PARAM_INT, ''),
                        'value' => new external_value(PARAM_FLOAT, '', VALUE_DEFAULT, null, NULL_ALLOWED),
                    ]),
                    '',
                    VALUE_DEFAULT,
                    []
                ),
            ])),
        ]);
    }
}
