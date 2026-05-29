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
 * Tests for the get_pending_submissions external function.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\external;

use core_external\external_api;

/**
 * Covers submission-status filtering: the default listing returns only
 * genuinely "submitted" work, the draft listing returns only drafts, and
 * new / reopened attempts never surface in either.
 *
 * @covers \block_feedback_tracker\external\get_pending_submissions
 */
final class get_pending_submissions_test extends \advanced_testcase {
    /**
     * The default (submitted) listing excludes draft / new / reopened rows and
     * exposes the submissionstatus field on each returned row.
     *
     * @return void
     */
    public function test_default_lists_submitted_only(): void {
        $this->resetAfterTest();

        [$course, $teacher] = $this->seed_course_with_teacher();
        $this->seed_pending_row($course, 'submitted', 5.0);
        $this->seed_pending_row($course, 'draft', 5.0);
        $this->seed_pending_row($course, 'new', 5.0);
        $this->seed_pending_row($course, 'reopened', 5.0);

        $this->setUser($teacher);
        $result = external_api::clean_returnvalue(
            get_pending_submissions::execute_returns(),
            get_pending_submissions::execute((int) $course->id, 0, '', 'longestwait', 0, 25)
        );

        $this->assertSame(1, (int) $result['total']);
        $this->assertCount(1, $result['submissions']);
        $this->assertSame('submitted', $result['submissions'][0]['submissionstatus']);
    }

    /**
     * status = draft lists only drafts; new / reopened never surface.
     *
     * @return void
     */
    public function test_status_draft_lists_drafts_only(): void {
        $this->resetAfterTest();

        [$course, $teacher] = $this->seed_course_with_teacher();
        $this->seed_pending_row($course, 'submitted', 5.0);
        $this->seed_pending_row($course, 'draft', 5.0);
        $this->seed_pending_row($course, 'new', 5.0);
        $this->seed_pending_row($course, 'reopened', 5.0);

        $this->setUser($teacher);
        $result = external_api::clean_returnvalue(
            get_pending_submissions::execute_returns(),
            get_pending_submissions::execute((int) $course->id, 0, '', 'recent', 0, 25, 'draft')
        );

        $this->assertSame(1, (int) $result['total']);
        $this->assertCount(1, $result['submissions']);
        $this->assertSame('draft', $result['submissions'][0]['submissionstatus']);
    }

    /**
     * An unknown status falls back to the submitted listing rather than
     * widening the result set.
     *
     * @return void
     */
    public function test_unknown_status_falls_back_to_submitted(): void {
        $this->resetAfterTest();

        [$course, $teacher] = $this->seed_course_with_teacher();
        $this->seed_pending_row($course, 'submitted', 5.0);
        $this->seed_pending_row($course, 'draft', 5.0);

        $this->setUser($teacher);
        $result = external_api::clean_returnvalue(
            get_pending_submissions::execute_returns(),
            get_pending_submissions::execute((int) $course->id, 0, '', 'longestwait', 0, 25, 'bogus')
        );

        $this->assertSame(1, (int) $result['total']);
        $this->assertSame('submitted', $result['submissions'][0]['submissionstatus']);
    }

    // Helpers.

    /**
     * Create a course with a feedback_tracker block instance and an editing
     * teacher who can view the responsiveness data.
     *
     * @return array{0: \stdClass, 1: \stdClass} [course, teacher]
     */
    private function seed_course_with_teacher(): array {
        $course = $this->getDataGenerator()->create_course();
        $coursectx = \context_course::instance($course->id);
        $this->getDataGenerator()->create_block('feedback_tracker', [
            'parentcontextid' => $coursectx->id,
        ]);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        return [$course, $teacher];
    }

    /**
     * Insert one pending ledger row with a real assign cm + enrolled student
     * (the WS joins {course_modules} and {user}, so both must exist).
     *
     * @param \stdClass $course
     * @param string $status submitted|draft|new|reopened
     * @param float $effective Effective wait in hours.
     * @return void
     */
    private function seed_pending_row(\stdClass $course, string $status, float $effective): void {
        global $DB;

        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);

        $now = time();
        $DB->insert_record('block_feedback_tracker_sub', (object) [
            'courseid'         => (int) $course->id,
            'groupid'          => 0,
            'cmid'             => (int) $cm->id,
            'iteminstance'     => (int) $assign->id,
            'userid'           => (int) $student->id,
            'attemptnumber'    => 0,
            'submissionstatus' => $status,
            'timesubmitted'    => $now - 7200,
            'timegraded'       => null,
            'hasrule'          => 0,
            'waitinghours'     => $effective * 1.2,
            'effectivehours'   => $effective,
            'effectiveasof'    => $now,
            'effectivecalver'  => 1,
            'slabucket'        => 'good',
            'timecreated'      => $now - 7200,
            'timemodified'     => $now,
        ]);
    }
}
