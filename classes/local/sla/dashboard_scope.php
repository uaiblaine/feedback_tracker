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
 * Read-path visibility scope for the cross-course dashboard.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * Decides which courses (and, together with {@see group_access}, which
 * groups) a user may see on the teacher dashboard and its web services.
 *
 * Rules:
 *   - A site admin with the `enable_admin_view_all` setting ON sees every
 *     course and group on the site — {@see self::visible_course_ids()}
 *     returns `null` ("no restriction").
 *   - Everyone else — including a site admin with the setting OFF — is
 *     scoped to courses where they hold an ACTIVE enrolment AND a role that
 *     grants `block/feedback_tracker:viewdashboard` (teacher or higher).
 *     `doanything` is deliberately suppressed so a site admin with the
 *     setting OFF is treated exactly like a normal user.
 *
 * Centralised so the page entrypoint and all three dashboard web services
 * (get_dashboard / get_grader_priority_list / get_insights) share one
 * decision point instead of three near-identical capability sweeps.
 */
class dashboard_scope {
    /** WHERE fragment matching every row (admin view-all). */
    public const MATCH_ALL = '1 = 1';

    /** WHERE fragment matching no row (user sees nothing). */
    public const MATCH_NONE = '1 = 0';

    /** @var array<int, int[]|null> Per-request memo of visible_course_ids() keyed by userid. */
    private static array $coursememo = [];

    /**
     * True when the user is a site admin AND the enable_admin_view_all
     * setting is on — the only case that bypasses enrolment/role scoping.
     *
     * @param int $userid
     * @return bool
     */
    public static function admin_sees_all(int $userid): bool {
        if (!is_siteadmin($userid)) {
            return false;
        }
        return (int) (get_config('block_feedback_tracker', 'enable_admin_view_all') ?: 0) === 1;
    }

    /**
     * The course IDs the user may see on the dashboard.
     *
     * @param int $userid
     * @return int[]|null  null = unrestricted (admin view-all); int[] =
     *                     exactly these courseids; [] = the user sees nothing.
     */
    public static function visible_course_ids(int $userid): ?array {
        if (array_key_exists($userid, self::$coursememo)) {
            return self::$coursememo[$userid];
        }
        if (self::admin_sees_all($userid)) {
            return self::$coursememo[$userid] = null;
        }
        // Active enrolment intersected with a real role granting the
        // dashboard capability (teacher or higher). doanything is suppressed
        // so a site admin with the setting OFF is treated as a normal user.
        $courses = enrol_get_users_courses($userid, true, 'id');
        $out = [];
        foreach ($courses as $course) {
            $ctx = \context_course::instance($course->id);
            if (has_capability('block/feedback_tracker:viewdashboard', $ctx, $userid, false)) {
                $out[] = (int) $course->id;
            }
        }
        return self::$coursememo[$userid] = $out;
    }

    /**
     * Whether the user can see every group in the given course context.
     *
     * The enable_admin_view_all setting is an explicit "see everything"
     * escape hatch for site admins — when on it lifts the group-mode
     * restriction regardless of role. Everyone else (and admins when the
     * setting is off) follows the real `moodle/site:accessallgroups`
     * capability, honoured at course, category or system context through
     * normal Moodle inheritance — so a role granting it higher up still
     * applies, and a standard admin keeps Moodle's default behaviour.
     *
     * @param \context_course $ctx
     * @param int $userid
     * @return bool
     */
    public static function can_access_all_groups(\context_course $ctx, int $userid): bool {
        if (self::admin_sees_all($userid)) {
            return true;
        }
        return has_capability('moodle/site:accessallgroups', $ctx, $userid);
    }

    /**
     * Build a SQL WHERE fragment plus params restricting rows to the
     * (course, group) pairs the user may see. Shared by the dashboard web
     * services so the visibility rules live in exactly one place.
     *
     * @param int $userid
     * @param string $coursecol  SQL column holding the course id (e.g. "g.courseid").
     * @param string $groupcol   SQL column holding the group id (e.g. "g.groupid").
     * @param string $prefix     Unique named-param prefix for this call site.
     * @return array{0:string, 1:array<string, mixed>}  [wherefragment, params].
     *                           wherefragment is MATCH_ALL / MATCH_NONE, or an
     *                           OR-joined clause already wrapped in parentheses.
     */
    public static function sql_visibility(int $userid, string $coursecol, string $groupcol, string $prefix): array {
        $scope = self::visible_course_ids($userid);
        if ($scope === null) {
            return [self::MATCH_ALL, []];
        }
        if (empty($scope)) {
            return [self::MATCH_NONE, []];
        }
        global $DB;
        $clauses = [];
        $params = [];
        $i = 0;
        foreach ($scope as $cid) {
            $visible = group_access::visible_group_ids($cid, $userid);
            $ckey = $prefix . 'c' . $i;
            if ($visible === null) {
                $clauses[] = "$coursecol = :$ckey";
                $params[$ckey] = $cid;
            } else if (!empty($visible)) {
                [$gsql, $gparams] = $DB->get_in_or_equal($visible, SQL_PARAMS_NAMED, $prefix . 'g' . $i . '_');
                $clauses[] = "($coursecol = :$ckey AND $groupcol $gsql)";
                $params[$ckey] = $cid;
                $params += $gparams;
            }
            $i++;
        }
        if (empty($clauses)) {
            return [self::MATCH_NONE, []];
        }
        return ['(' . implode(' OR ', $clauses) . ')', $params];
    }

    /**
     * Drop the per-request memo. Test helper.
     *
     * @return void
     */
    public static function reset_memo(): void {
        self::$coursememo = [];
    }
}
