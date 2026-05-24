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
 * External: dashboard insights — bright spot, most improved, gentle watch.
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
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Returns three insight rows for the design's dashboard hero callouts —
 * a bright spot (currently top-scoring group), the most-improved course
 * over the last 30 days, and a gentle watch (course with the oldest
 * critical-band pending submission).
 *
 * All three are sourced from the existing rollup tables — no new
 * schema. Results are cached per (calver, userid) for 900s; admin
 * settings changes that bump calver naturally roll over the cache.
 */
class get_insights extends external_api {
    /** Cache TTL in seconds. */
    public const CACHE_TTL = 900;

    /** Cache-key version. Bump when the result shape changes. */
    public const CACHE_KEY_VERSION = 1;

    /**
     * Parameters — no inputs.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([]);
    }

    /**
     * Run.
     *
     * @return array
     */
    public static function execute(): array {
        global $DB, $USER;

        $sysctx = \context_system::instance();
        self::validate_context($sysctx);

        // The viewdashboard capability is granted at course / category
        // context. Use the same per-course sweep as get_dashboard so the
        // insight pool matches the courses table the user is already
        // looking at.
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

        $cache = \cache::make('block_feedback_tracker', 'dashboard_payload');
        $key = 'insights_v' . self::CACHE_KEY_VERSION
            . '_' . calendar::current_version()
            . '_' . $USER->id;
        $cached = $cache->get($key);
        if (
            $cached !== false && is_array($cached)
            && isset($cached['lastsynced'])
            && (time() - (int) $cached['lastsynced']) < self::CACHE_TTL
        ) {
            return $cached;
        }

        $rows = self::source_rows($visiblecourses, (int) $USER->id);
        $brightspot = self::pick_bright_spot($rows);
        $mostimproved = self::pick_most_improved($rows);
        $gentlewatch = self::pick_gentle_watch($rows);

        // Omit null insight keys entirely — external_single_structure with
        // VALUE_OPTIONAL on the field allows missing keys but not literal
        // null. The JS view treats absence as "no insight to show".
        $result = [
            'success'    => true,
            'lastsynced' => time(),
        ];
        if ($brightspot !== null) {
            $result['bright_spot'] = $brightspot;
        }
        if ($mostimproved !== null) {
            $result['most_improved'] = $mostimproved;
        }
        if ($gentlewatch !== null) {
            $result['gentle_watch'] = $gentlewatch;
        }
        $cache->set($key, $result);
        return $result;
    }

    /**
     * Pull the per-(course, group) rollup rows for the courses the caller
     * can see, with the course name joined in. Mirrors get_dashboard's
     * group-visibility filter so the insight pool is consistent.
     *
     * @param array  $visiblecourses Output of get_user_capability_course().
     * @param int    $userid
     * @return array<int, \stdClass>
     */
    private static function source_rows(array $visiblecourses, int $userid): array {
        global $DB;
        $clauses = [];
        $params = [];
        $pix = 0;
        foreach ($visiblecourses as $course) {
            $cid = (int) $course->id;
            $visible = \block_feedback_tracker\local\sla\group_access::visible_group_ids($cid, $userid);
            if ($visible === null) {
                $clauses[] = "g.courseid = :ic{$pix}";
                $params["ic{$pix}"] = $cid;
            } else if (!empty($visible)) {
                [$gsql, $gparams] = $DB->get_in_or_equal($visible, SQL_PARAMS_NAMED, "ig{$pix}_");
                $clauses[] = "(g.courseid = :ic{$pix} AND g.groupid $gsql)";
                $params["ic{$pix}"] = $cid;
                $params += $gparams;
            }
            $pix++;
        }
        if (empty($clauses)) {
            return [];
        }
        $where = '(' . implode(' OR ', $clauses) . ')';
        $sql = "SELECT g.id, g.courseid, g.groupid, c.fullname AS coursename,
                       g.responsiveness_score, g.score_band, g.trend_pct_30d,
                       g.median_eff_h, g.critical, g.pending
                  FROM {block_feedback_tracker_group} g
                  JOIN {course} c ON c.id = g.courseid
                 WHERE $where";
        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Bright spot — the highest-scoring group, with the oldest tie broken
     * by larger numgraded30d. Returns null when no group has a score yet.
     *
     * @param array $rows
     * @return array|null
     */
    private static function pick_bright_spot(array $rows): ?array {
        $scored = array_values(array_filter($rows, static fn ($r) => $r->responsiveness_score !== null));
        if (empty($scored)) {
            return null;
        }
        usort($scored, static function ($a, $b) {
            return (float) $b->responsiveness_score <=> (float) $a->responsiveness_score;
        });
        $top = $scored[0];
        $score = (float) $top->responsiveness_score;
        return [
            'courseid'     => (int) $top->courseid,
            'coursename'   => (string) $top->coursename,
            'groupid'      => (int) $top->groupid,
            'metric_value' => (string) round($score),
            'metric_suffix' => '/ 100',
        ];
    }

    /**
     * Most improved — the group with the largest negative trend_pct_30d
     * (because negative trend = effective hours dropped = improvement).
     * Returns null when no group has trend data.
     *
     * @param array $rows
     * @return array|null
     */
    private static function pick_most_improved(array $rows): ?array {
        $trended = array_values(array_filter(
            $rows,
            static fn ($r) => $r->trend_pct_30d !== null && (float) $r->trend_pct_30d < -2.0
        ));
        if (empty($trended)) {
            return null;
        }
        usort($trended, static function ($a, $b) {
            return (float) $a->trend_pct_30d <=> (float) $b->trend_pct_30d;
        });
        $top = $trended[0];
        $pct = (float) $top->trend_pct_30d;
        // Surface improvement as a positive number (UX-friendly framing).
        return [
            'courseid'     => (int) $top->courseid,
            'coursename'   => (string) $top->coursename,
            'groupid'      => (int) $top->groupid,
            'metric_value' => '+' . (string) round(abs($pct)) . '%',
            'metric_suffix' => '',
        ];
    }

    /**
     * Gentle watch — the group with the most critical-band pending
     * submissions. Returns null when no group has any critical pending.
     *
     * @param array $rows
     * @return array|null
     */
    private static function pick_gentle_watch(array $rows): ?array {
        $critical = array_values(array_filter(
            $rows,
            static fn ($r) => (int) $r->critical > 0
        ));
        if (empty($critical)) {
            return null;
        }
        usort($critical, static function ($a, $b) {
            return (int) $b->critical <=> (int) $a->critical;
        });
        $top = $critical[0];
        $n = (int) $top->critical;
        return [
            'courseid'     => (int) $top->courseid,
            'coursename'   => (string) $top->coursename,
            'groupid'      => (int) $top->groupid,
            'metric_value' => (string) $n,
            'metric_suffix' => $n === 1 ? 'critical pending' : 'critical pending',
        ];
    }

    /**
     * Returns shape — three nullable insight rows.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        $insight = new external_single_structure([
            'courseid'      => new external_value(PARAM_INT, ''),
            'coursename'    => new external_value(PARAM_TEXT, ''),
            'groupid'       => new external_value(PARAM_INT, ''),
            'metric_value'  => new external_value(PARAM_TEXT, ''),
            'metric_suffix' => new external_value(PARAM_TEXT, ''),
        ], '', VALUE_OPTIONAL);
        return new external_single_structure([
            'success'       => new external_value(PARAM_BOOL, ''),
            'lastsynced'    => new external_value(PARAM_INT, ''),
            'bright_spot'   => $insight,
            'most_improved' => $insight,
            'gentle_watch'  => $insight,
        ]);
    }
}
