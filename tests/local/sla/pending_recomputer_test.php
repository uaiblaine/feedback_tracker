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
 * Tests for pending_recomputer.
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
 * Verifies that stale pending ledger rows are re-bucketed by the recomputer.
 *
 * @covers \block_feedback_tracker\local\sla\pending_recomputer
 */
final class pending_recomputer_test extends \advanced_testcase {
    /**
     * A pending row with effectiveasof in the past gets its hours and bucket
     * updated.
     */
    public function test_stale_pending_row_is_updated(): void {
        $this->resetAfterTest();
        $this->seed_calendar();

        global $DB;
        $now = time();
        $rowid = $DB->insert_record('block_feedback_tracker_sub', (object) [
            'courseid'         => 50,
            'groupid'          => 60,
            'cmid'             => 70,
            'iteminstance'     => 80,
            'userid'           => 1,
            'attemptnumber'    => 0,
            'submissionstatus' => 'submitted',
            // Submitted 4 days ago = 96 raw hours; ~48 effective (Mon-Fri 08:00-18:00).
            'timesubmitted'    => $now - 4 * 86400,
            'timegraded'       => null,
            'hasrule'          => 0,
            'waitinghours'     => 0.0,
            'effectivehours'   => 0.0,
            'effectiveasof'    => $now - 7200,
            'effectivecalver'  => calendar::current_version(),
            'slabucket'        => 'excellent',
            'timecreated'      => $now - 4 * 86400,
            'timemodified'     => $now - 7200,
        ]);

        $result = pending_recomputer::recompute_stale(1000, 50, $now);

        $this->assertSame(1, $result['count']);
        $this->assertSame(1, $result['tuples']);

        $row = $DB->get_record('block_feedback_tracker_sub', ['id' => $rowid]);
        $this->assertSame($now, (int) $row->effectiveasof);
        $this->assertGreaterThan(0.0, (float) $row->effectivehours);
        $this->assertGreaterThan(0.0, (float) $row->waitinghours);

        $queue = $DB->get_records('block_feedback_tracker_queue', [
            'courseid' => 50, 'groupid' => 60,
        ]);
        $this->assertCount(1, $queue);
    }

    /**
     * Already-fresh rows (effectiveasof within the last hour AND calver
     * current) are skipped.
     */
    public function test_fresh_row_is_skipped(): void {
        $this->resetAfterTest();
        $this->seed_calendar();

        global $DB;
        $now = time();
        $DB->insert_record('block_feedback_tracker_sub', (object) [
            'courseid'         => 51,
            'groupid'          => 61,
            'cmid'             => 71,
            'iteminstance'     => 81,
            'userid'           => 1,
            'attemptnumber'    => 0,
            'submissionstatus' => 'submitted',
            'timesubmitted'    => $now - 100,
            'timegraded'       => null,
            'hasrule'          => 0,
            'waitinghours'     => 0.0,
            'effectivehours'   => 0.0,
            'effectiveasof'    => $now - 60,
            'effectivecalver'  => calendar::current_version(),
            'slabucket'        => 'excellent',
            'timecreated'      => $now - 100,
            'timemodified'     => $now - 60,
        ]);

        $result = pending_recomputer::recompute_stale(1000, 50, $now);

        $this->assertSame(0, $result['count']);
    }

    /**
     * A calver bump triggers re-computation even if the row is fresh by time.
     */
    public function test_calver_bump_triggers_recompute(): void {
        $this->resetAfterTest();
        $this->seed_calendar();

        global $DB;
        $now = time();
        $rowid = $DB->insert_record('block_feedback_tracker_sub', (object) [
            'courseid'         => 52,
            'groupid'          => 62,
            'cmid'             => 72,
            'iteminstance'     => 82,
            'userid'           => 1,
            'attemptnumber'    => 0,
            'submissionstatus' => 'submitted',
            'timesubmitted'    => $now - 100,
            'timegraded'       => null,
            'hasrule'          => 0,
            'waitinghours'     => 0.0,
            'effectivehours'   => 0.0,
            'effectiveasof'    => $now - 60,
            'effectivecalver'  => 0,
            'slabucket'        => 'excellent',
            'timecreated'      => $now - 100,
            'timemodified'     => $now - 60,
        ]);

        $result = pending_recomputer::recompute_stale(1000, 50, $now);

        $this->assertSame(1, $result['count']);
        $row = $DB->get_record('block_feedback_tracker_sub', ['id' => $rowid]);
        $this->assertSame(calendar::current_version(), (int) $row->effectivecalver);
    }

    /**
     * Seed Mon-Fri 08:00-18:00 calendar.
     */
    private function seed_calendar(): void {
        set_config('calver', '1', 'block_feedback_tracker');
        set_config('timezone', 'UTC', 'block_feedback_tracker');
        set_config('excludeweekends', '1', 'block_feedback_tracker');
        set_config('weekendmask', '96', 'block_feedback_tracker');
        set_config('excludeholidays', '1', 'block_feedback_tracker');
        set_config('excluderecesses', '1', 'block_feedback_tracker');
        set_config('enablebusinesshours', '1', 'block_feedback_tracker');
        set_config('grading_during_pause_mode', 'clipped', 'block_feedback_tracker');
        set_config('bucket_thresholds_eff', '24,48,120', 'block_feedback_tracker');

        global $DB;
        $now = time();
        for ($dayofweek = 0; $dayofweek <= 4; $dayofweek++) {
            $DB->insert_record('block_feedback_tracker_chours', (object) [
                'dayofweek'    => $dayofweek,
                'starttime'    => 480,
                'endtime'      => 1080,
                'enabled'      => 1,
                'timecreated'  => $now,
                'timemodified' => $now,
            ]);
        }

        academic_time::reset_memos();
    }
}
