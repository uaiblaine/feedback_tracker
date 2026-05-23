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
 * SLA event observers.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * Thin observer handlers that turn assign / group / course events into
 * ledger upserts plus dirty-queue entries. All heavy lifting (academic-time
 * engine, rollup, score) runs out-of-band: the observer's job is just to
 * record that something changed.
 *
 * Every handler is idempotent: replaying the same event leaves the ledger
 * in the same state (the unique key on cmid/userid/attemptnumber enforces
 * this).
 */
class observer {
    /**
     * Submission state change (created, status updated, assessable submitted).
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function submission_changed(\core\event\base $event): void {
        $cmid = (int) ($event->contextinstanceid ?? 0);
        if ($cmid <= 0) {
            return;
        }
        $submissionid = (int) ($event->objectid ?? 0);
        if ($submissionid <= 0) {
            return;
        }

        global $DB;
        $courseid = (int) $DB->get_field('course_modules', 'course', ['id' => $cmid], IGNORE_MISSING);
        if (!course_access::is_processable($courseid)) {
            return;
        }

        $sub = $DB->get_record(
            'assign_submission',
            ['id' => $submissionid],
            'userid, attemptnumber'
        );
        if (!$sub) {
            return;
        }
        submission_ledger::upsert_for_cm_user_attempt(
            $cmid,
            (int) $sub->userid,
            (int) $sub->attemptnumber
        );
    }

    /**
     * Submission graded. Upserts the ledger and queues an adhoc rollup task.
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function submission_graded(\core\event\base $event): void {
        $cmid = (int) ($event->contextinstanceid ?? 0);
        if ($cmid <= 0) {
            return;
        }
        $gradeid = (int) ($event->objectid ?? 0);
        if ($gradeid <= 0) {
            return;
        }

        global $DB;
        $courseid = (int) $DB->get_field('course_modules', 'course', ['id' => $cmid], IGNORE_MISSING);
        if (!course_access::is_processable($courseid)) {
            return;
        }

        $grade = $DB->get_record(
            'assign_grades',
            ['id' => $gradeid],
            'userid, attemptnumber'
        );
        if (!$grade) {
            return;
        }
        $subid = submission_ledger::upsert_for_cm_user_attempt(
            $cmid,
            (int) $grade->userid,
            (int) $grade->attemptnumber
        );
        if ($subid === null) {
            return;
        }

        $row = $DB->get_record(
            'block_feedback_tracker_sub',
            ['id' => $subid],
            'courseid, groupid'
        );
        if (!$row) {
            return;
        }

        $task = new \block_feedback_tracker\task\recompute_one();
        $task->set_custom_data([
            'courseid' => (int) $row->courseid,
            'groupid' => (int) $row->groupid,
        ]);
        \core\task\manager::queue_adhoc_task($task, true);
    }

    /**
     * Group override created / updated / deleted on an assign.
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function override_changed(\core\event\base $event): void {
        $other = $event->other;
        if (is_object($other)) {
            $other = (array) $other;
        }
        $assignid = (int) ($other['assignid'] ?? 0);
        $groupid = (int) ($other['groupid'] ?? 0);
        if ($assignid <= 0 || $groupid <= 0) {
            return;
        }
        global $DB;
        $courseid = (int) $DB->get_field('assign', 'course', ['id' => $assignid], IGNORE_MISSING);
        if (!course_access::is_processable($courseid)) {
            return;
        }
        submission_ledger::re_resolve_rules_for_assign_group($assignid, $groupid);
    }

    /**
     * Course-module deleted. Only handles assign cms; others are ignored.
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function course_module_deleted(\core\event\base $event): void {
        $cmid = (int) ($event->objectid ?? 0);
        if ($cmid <= 0) {
            return;
        }
        $other = $event->other;
        if (is_object($other)) {
            $other = (array) $other;
        }
        $modulename = $other['modulename'] ?? null;
        if ($modulename !== null && $modulename !== 'assign') {
            return;
        }
        submission_ledger::delete_for_cm($cmid);
    }

    /**
     * Course deleted. Drops all ledger / rollup / trend / queue rows.
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function course_deleted(\core\event\base $event): void {
        $courseid = (int) ($event->objectid ?? 0);
        if ($courseid <= 0) {
            return;
        }
        submission_ledger::delete_for_course($courseid);
    }

    /**
     * Group member added / removed. Re-attribute the user's ledger rows.
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function group_membership_changed(\core\event\base $event): void {
        $courseid = (int) ($event->courseid ?? 0);
        $userid = (int) ($event->relateduserid ?? 0);
        if ($courseid <= 0 || $userid <= 0) {
            return;
        }
        if (!course_access::is_processable($courseid)) {
            return;
        }
        submission_ledger::reattribute_user($courseid, $userid);
    }

    /**
     * Group deleted. Reattribute affected users' ledger rows to their new
     * latest-joined groups (which excludes the now-deleted group).
     *
     * @param \core\event\base $event
     * @return void
     */
    public static function group_deleted(\core\event\base $event): void {
        $groupid = (int) ($event->objectid ?? 0);
        $courseid = (int) ($event->courseid ?? 0);
        if ($groupid <= 0 || $courseid <= 0) {
            return;
        }
        if (!course_access::is_processable($courseid)) {
            return;
        }
        global $DB;
        $affected = $DB->get_records(
            'block_feedback_tracker_sub',
            ['courseid' => $courseid, 'groupid' => $groupid],
            '',
            'id, userid',
            0,
            10000
        );
        if (empty($affected)) {
            return;
        }
        $userids = array_unique(array_map(static fn($r) => (int) $r->userid, $affected));
        group_resolver::reset_memo();
        foreach ($userids as $userid) {
            submission_ledger::reattribute_user($courseid, $userid);
        }
    }
}
