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
 * Group-mode access helper.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * Resolves which group rows a given user is allowed to see in a course,
 * honouring Moodle's three group modes + the accessallgroups capability.
 *
 * Shared by `responsiveness_payload::for_course()`, the pending-report WS,
 * the cross-course Grade Now WS, and the dashboard aggregate. Centralising
 * the rules here prevents the "block correctly hides other groups but the
 * report page leaks them" class of bugs.
 *
 * Returned shapes:
 *   - `null`    → unrestricted. NOGROUPS course, or the user holds
 *                 `moodle/site:accessallgroups`. Callers must NOT add any
 *                 group filter to their SQL.
 *   - `int[]`   → the user can see exactly these group IDs (named groups
 *                 only — `groupid = 0` "ungrouped" never appears outside
 *                 NOGROUPS or accessallgroups). An empty array means the
 *                 user has zero group access in this course — callers
 *                 must short-circuit to an empty result.
 */
class group_access {
    /** @var array Per-request memo keyed by "courseid:userid". */
    private static array $memo = [];

    /**
     * Compute the visible group IDs for one (courseid, userid) pair.
     *
     * @param int $courseid
     * @param int $userid
     * @return int[]|null
     */
    public static function visible_group_ids(int $courseid, int $userid): ?array {
        $key = $courseid . ':' . $userid;
        if (array_key_exists($key, self::$memo)) {
            return self::$memo[$key];
        }

        global $DB;
        $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
        $ctx = \context_course::instance($courseid);
        $groupmode = (int) groups_get_course_groupmode($course);
        $canaccessall = has_capability('moodle/site:accessallgroups', $ctx, $userid);

        if ($groupmode === NOGROUPS || $canaccessall) {
            return self::$memo[$key] = null;
        }

        if ($groupmode === VISIBLEGROUPS) {
            $allnamed = groups_get_all_groups($courseid);
            return self::$memo[$key] = array_map(static fn($g) => (int) $g->id, $allnamed);
        }

        // SEPARATEGROUPS — only the user's own groups.
        $usergroupings = groups_get_user_groups($courseid, $userid);
        $own = [];
        foreach ($usergroupings as $gids) {
            foreach ($gids as $gid) {
                $own[(int) $gid] = true;
            }
        }
        return self::$memo[$key] = array_keys($own);
    }

    /**
     * True when the user can see everything in the course (NOGROUPS or
     * accessallgroups). Sugar for the common branch.
     *
     * @param int $courseid
     * @param int $userid
     * @return bool
     */
    public static function is_unrestricted(int $courseid, int $userid): bool {
        return self::visible_group_ids($courseid, $userid) === null;
    }

    /**
     * True when the user can see the given group in the course.
     * `groupid = 0` ("ungrouped") is admin-only — only unrestricted users see it.
     *
     * @param int $courseid
     * @param int $userid
     * @param int $groupid
     * @return bool
     */
    public static function can_see_group(int $courseid, int $userid, int $groupid): bool {
        $visible = self::visible_group_ids($courseid, $userid);
        if ($visible === null) {
            return true;
        }
        return in_array($groupid, $visible, true);
    }

    /**
     * Drop the per-request memo. Test helper.
     *
     * @return void
     */
    public static function reset_memo(): void {
        self::$memo = [];
    }
}
