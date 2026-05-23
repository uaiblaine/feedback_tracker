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
 * Submission ledger writer.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

use block_feedback_tracker\local\calendar\academic_time;
use block_feedback_tracker\local\calendar\calendar;

/**
 * Idempotent upserts into {block_feedback_tracker_sub} keyed by
 * (cmid, userid, attemptnumber). Reads the live {assign_submission} /
 * {assign_grades} / {assign_overrides} state, invokes the academic-time
 * engine to compute effective hours, and enqueues the (courseid, groupid)
 * tuple for rollup recompute.
 *
 * All write paths are O(1) in DB queries beyond the engine call, suitable
 * to run inline from event observers.
 */
class submission_ledger {
    /**
     * Upsert one ledger row for (cmid, userid, attemptnumber).
     *
     * Reads the current submission + grade + overrides from the assign
     * tables, computes raw/effective hours, classifies the bucket, rewrites
     * the per-submission pause audit ledger, and enqueues the (course, group)
     * tuple. Returns the ledger row id or null if the inputs are not
     * resolvable (cm missing, not an assign, etc.).
     *
     * @param int $cmid
     * @param int $userid
     * @param int $attemptnumber
     * @return int|null
     */
    public static function upsert_for_cm_user_attempt(
        int $cmid,
        int $userid,
        int $attemptnumber
    ): ?int {
        global $DB;

        $cm = $DB->get_record_sql(
            "SELECT cm.id, cm.course, cm.instance, m.name AS modulename
               FROM {course_modules} cm
               JOIN {modules} m ON m.id = cm.module
              WHERE cm.id = :cmid",
            ['cmid' => $cmid]
        );
        if (!$cm || $cm->modulename !== 'assign') {
            return null;
        }

        // Filter out submissions from users who actually hold grading
        // capability in this course (role-switched teachers, dual-role
        // staff, content-QA admins, etc.). Their submissions are almost
        // always internal testing rather than real student work.
        if (self::should_skip_submitter((int) $cm->course, $userid)) {
            return null;
        }

        $assign = $DB->get_record('assign', ['id' => $cm->instance]);
        if (!$assign) {
            return null;
        }

        $submission = $DB->get_record('assign_submission', [
            'assignment' => $assign->id,
            'userid' => $userid,
            'attemptnumber' => $attemptnumber,
        ]);
        if (!$submission) {
            return null;
        }

        $grade = $DB->get_record('assign_grades', [
            'assignment' => $assign->id,
            'userid' => $userid,
            'attemptnumber' => $attemptnumber,
        ]);

        $groupid = group_resolver::resolve_group_for_user((int) $cm->course, $userid);
        $rule = rule_resolver::resolve_rule($assign, $userid, $groupid);

        $timesubmitted = (int) $submission->timemodified;
        $timegraded = null;
        // Moodle creates {assign_grades} rows with `grade` NULL or `-1` in
        // several edge cases that are *not* real gradings — workflow init,
        // a teacher opening the grading page, plagiarism plugins touching
        // the row, etc. Mirror mod_assign's own `is_graded()` convention
        // and require a real grade (>=0) before treating the row as graded.
        // Without this check, fresh submissions show up as "already graded"
        // and never appear in the pending list.
        if (
            $grade
            && (int) $grade->timemodified > 0
            && (int) $grade->timemodified >= $timesubmitted
            && $grade->grade !== null
            && (float) $grade->grade >= 0
        ) {
            $timegraded = (int) $grade->timemodified;
        }

        $now = time();
        $upperbound = $timegraded ?? $now;
        $waitinghours = $timesubmitted > 0
            ? round(max(0.0, ($upperbound - $timesubmitted) / 3600.0), 2)
            : 0.0;

        $audit = ($timesubmitted > 0 && $upperbound > $timesubmitted)
            ? academic_time::elapsed_with_audit((int) $cm->course, $groupid, $timesubmitted, $upperbound)
            : ['hours' => 0.0, 'pauses' => []];
        $effectivehours = $audit['hours'];

        $existing = $DB->get_record('block_feedback_tracker_sub', [
            'cmid' => $cmid,
            'userid' => $userid,
            'attemptnumber' => $attemptnumber,
        ], 'id');

        $record = (object) [
            'courseid'         => (int) $cm->course,
            'groupid'          => $groupid,
            'cmid'             => $cmid,
            'iteminstance'     => (int) $assign->id,
            'userid'           => $userid,
            'attemptnumber'    => $attemptnumber,
            'submissionstatus' => isset($submission->status) ? (string) $submission->status : 'new',
            'timesubmitted'    => $timesubmitted,
            'timegraded'       => $timegraded,
            'timeopens'        => $rule['timeopens'],
            'timecloses'       => $rule['timecloses'],
            'timecutoff'       => $rule['timecutoff'],
            'hasrule'          => $rule['hasrule'],
            'waitinghours'     => $waitinghours,
            'effectivehours'   => $effectivehours,
            'effectiveasof'    => $now,
            'effectivecalver'  => calendar::current_version(),
            'slabucket'        => bucket::for_effective($effectivehours),
            'timemodified'     => $now,
        ];

        if ($existing) {
            $record->id = $existing->id;
            $subid = (int) $existing->id;
            $DB->update_record('block_feedback_tracker_sub', $record);
        } else {
            $record->timecreated = $now;
            $subid = (int) $DB->insert_record('block_feedback_tracker_sub', $record);
        }

        // V2.0.0+: the per-submission pause ledger was removed. The
        // pause timeline is recomputed on demand by get_pause_timeline.
        // $audit['pauses'] is intentionally unused here.

        dirty_queue::enqueue(
            (int) $cm->course,
            $groupid,
            $timegraded !== null ? dirty_queue::REASON_GRADE : dirty_queue::REASON_SUBMISSION
        );

        return $subid;
    }

    /**
     * Re-resolve the rule columns for every ledger row tied to (assignid,
     * groupid). Used when a group override is created / updated / deleted.
     *
     * @param int $assignid
     * @param int $groupid
     * @return void
     */
    public static function re_resolve_rules_for_assign_group(int $assignid, int $groupid): void {
        global $DB;

        $assign = $DB->get_record('assign', ['id' => $assignid]);
        if (!$assign) {
            return;
        }

        $rows = $DB->get_records('block_feedback_tracker_sub', [
            'iteminstance' => $assignid,
            'groupid' => $groupid,
        ], '', 'id, userid, courseid');

        $now = time();
        foreach ($rows as $row) {
            $rule = rule_resolver::resolve_rule($assign, (int) $row->userid, $groupid);
            $DB->update_record('block_feedback_tracker_sub', (object) [
                'id'           => $row->id,
                'timeopens'    => $rule['timeopens'],
                'timecloses'   => $rule['timecloses'],
                'timecutoff'   => $rule['timecutoff'],
                'hasrule'      => $rule['hasrule'],
                'timemodified' => $now,
            ]);
        }

        if (!empty($rows)) {
            $any = reset($rows);
            dirty_queue::enqueue((int) $any->courseid, $groupid, dirty_queue::REASON_PAUSE);
        }
    }

    /**
     * Re-attribute every ledger row for a user in a course to whatever group
     * they belong to now. Used when the user joins / leaves a group.
     *
     * Enqueues both the old (now-stale) and the new group.
     *
     * @param int $courseid
     * @param int $userid
     * @return void
     */
    public static function reattribute_user(int $courseid, int $userid): void {
        global $DB;

        group_resolver::reset_memo();
        $newgroupid = group_resolver::resolve_group_for_user($courseid, $userid);

        $rows = $DB->get_records('block_feedback_tracker_sub', [
            'courseid' => $courseid,
            'userid' => $userid,
        ], '', 'id, groupid');

        $now = time();
        $oldgroupids = [];
        foreach ($rows as $row) {
            $oldid = (int) $row->groupid;
            $oldgroupids[$oldid] = true;
            if ($oldid !== $newgroupid) {
                $DB->update_record('block_feedback_tracker_sub', (object) [
                    'id'           => $row->id,
                    'groupid'      => $newgroupid,
                    'timemodified' => $now,
                ]);
            }
        }

        foreach (array_keys($oldgroupids) as $oldgroupid) {
            dirty_queue::enqueue($courseid, $oldgroupid, dirty_queue::REASON_SUBMISSION);
        }
        if (!empty($rows) && !isset($oldgroupids[$newgroupid])) {
            dirty_queue::enqueue($courseid, $newgroupid, dirty_queue::REASON_SUBMISSION);
        }
    }

    /**
     * Delete all ledger rows for one course-module (and cascade pause rows).
     *
     * @param int $cmid
     * @return void
     */
    public static function delete_for_cm(int $cmid): void {
        global $DB;
        $rows = $DB->get_records(
            'block_feedback_tracker_sub',
            ['cmid' => $cmid],
            '',
            'id, courseid, groupid'
        );
        if (empty($rows)) {
            return;
        }
        $DB->delete_records('block_feedback_tracker_sub', ['cmid' => $cmid]);

        $tuples = [];
        foreach ($rows as $row) {
            $tuples[(int) $row->courseid . ':' . (int) $row->groupid] = [
                (int) $row->courseid, (int) $row->groupid,
            ];
        }
        foreach ($tuples as [$courseid, $groupid]) {
            dirty_queue::enqueue($courseid, $groupid, dirty_queue::REASON_SUBMISSION);
        }
    }

    /**
     * Delete all ledger + queue + rollup + trend rows for one course.
     *
     * @param int $courseid
     * @return void
     */
    public static function delete_for_course(int $courseid): void {
        global $DB;

        $DB->delete_records('block_feedback_tracker_sub', ['courseid' => $courseid]);
        $DB->delete_records('block_feedback_tracker_group', ['courseid' => $courseid]);
        $DB->delete_records('block_feedback_tracker_trend', ['courseid' => $courseid]);
        $DB->delete_records('block_feedback_tracker_queue', ['courseid' => $courseid]);
        $DB->delete_records('block_feedback_tracker_bfcursor', ['courseid' => $courseid]);
    }

    /**
     * Per-request memoised cache for the grader-filter capability check.
     * Keyed by "courseid:userid".
     *
     * @var array<string, bool>
     */
    private static array $skipsubmittermemo = [];

    /**
     * Returns true if the submission for ($courseid, $userid) should be
     * skipped because the submitter actually holds the grading capability
     * — most commonly a role-switched teacher / administrator running an
     * internal test rather than a real student. Gated by the
     * `exclude_grader_submissions` site setting (default ON).
     *
     * @param int $courseid
     * @param int $userid
     * @return bool
     */
    private static function should_skip_submitter(int $courseid, int $userid): bool {
        if ((int) (get_config('block_feedback_tracker', 'exclude_grader_submissions') ?? 1) !== 1) {
            return false;
        }
        $key = $courseid . ':' . $userid;
        if (!isset(self::$skipsubmittermemo[$key])) {
            try {
                $context = \context_course::instance($courseid);
                self::$skipsubmittermemo[$key] = has_capability('mod/assign:grade', $context, $userid);
            } catch (\Throwable $e) {
                // Context resolution failure — fail open (record the row).
                self::$skipsubmittermemo[$key] = false;
            }
        }
        return self::$skipsubmittermemo[$key];
    }

    /**
     * Drop the per-request grader-filter memo. Test helper; not called by
     * production code.
     *
     * @return void
     */
    public static function reset_memos(): void {
        self::$skipsubmittermemo = [];
    }
}
