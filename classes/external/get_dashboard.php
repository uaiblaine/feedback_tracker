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
    public const CACHE_KEY_VERSION = 2;

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

        // The `viewdashboard` capability is meaningful at course / category context (where
        // teacher roles are usually granted), not just system. Sweep every
        // course the user has the cap in and use that as both the
        // authorisation check and the result filter — same logic the page
        // entrypoint runs.
        $visiblecourses = get_user_capability_course(
            'block/feedback_tracker:viewdashboard',
            (int) $USER->id,
            true,
            '',
            ''
        );
        if (empty($visiblecourses)) {
            throw new \required_capability_exception(
                $sysctx,
                'block/feedback_tracker:viewdashboard',
                'nopermissions',
                'error'
            );
        }
        // Cache key includes the user (so per-user filtering doesn't leak
        // across teachers) and the band; the user-id keying also means a
        // role change naturally hits a cold cache for the affected user.
        $cache = \cache::make('block_feedback_tracker', 'dashboard_payload');
        $key = 'v' . self::CACHE_KEY_VERSION
            . '_' . calendar::current_version()
            . '_' . $USER->id
            . '_' . $band;
        $cached = $cache->get($key);
        if (
            $cached !== false && is_array($cached)
            && isset($cached['lastsynced'])
            && (time() - (int) $cached['lastsynced']) < self::CACHE_TTL
        ) {
            return $cached;
        }

        /*
         * Per-course visibility clauses honouring group mode. For each
         * accessible course we emit one of:
         *   - g.courseid = X            (unrestricted: NOGROUPS or accessallgroups)
         *   - (g.courseid = X AND g.groupid IN (...))  (restricted)
         * Joined with OR. Without this filter a SEPARATEGROUPS teacher
         * would see SUM() across every group in their courses, not just
         * the groups they belong to.
         */
        $clauses = [];
        $sqlparams = [];
        $pix = 0;
        foreach ($visiblecourses as $course) {
            $cid = (int) $course->id;
            $visible = \block_feedback_tracker\local\sla\group_access::visible_group_ids(
                $cid,
                (int) $USER->id
            );
            if ($visible === null) {
                $clauses[] = "g.courseid = :dc{$pix}";
                $sqlparams["dc{$pix}"] = $cid;
            } else if (!empty($visible)) {
                [$gsql, $gparams] = $DB->get_in_or_equal($visible, SQL_PARAMS_NAMED, "dg{$pix}_");
                $clauses[] = "(g.courseid = :dc{$pix} AND g.groupid $gsql)";
                $sqlparams["dc{$pix}"] = $cid;
                $sqlparams += $gparams;
            }
            $pix++;
        }
        if (empty($clauses)) {
            $result = [
                'success'    => true,
                'lastsynced' => time(),
                'courses'    => [],
            ];
            $cache->set($key, $result);
            return $result;
        }
        $where = '(' . implode(' OR ', $clauses) . ')';
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
                       AVG(g.responsiveness_score) AS avgscore
                  FROM {block_feedback_tracker_group} g
                  JOIN {course} c ON c.id = g.courseid
                 WHERE $where
              GROUP BY g.courseid, c.fullname
              ORDER BY pending DESC, c.fullname ASC";

        $rows = $DB->get_records_sql($sql, $sqlparams);

        $courses = [];
        foreach ($rows as $r) {
            $avg = $r->avgscore !== null ? (float) $r->avgscore : null;
            $courses[] = [
                'courseid'  => (int) $r->courseid,
                'coursename' => (string) $r->coursename,
                'numgroups' => (int) $r->numgroups,
                'pending'   => (int) $r->pending,
                'critical'  => (int) $r->critical,
                'overgoal'  => (int) $r->overgoal,
                'avgscore'  => $avg !== null ? round($avg, 2) : null,
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
            ])),
        ]);
    }
}
