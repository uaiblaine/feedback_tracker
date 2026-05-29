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
 * Tests for the site-wide daily stats writer.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * Tests that the site aggregate counts only genuinely "submitted" graded work.
 *
 * @covers \block_feedback_tracker\local\sla\site_stats_service
 */
final class site_stats_service_test extends \advanced_testcase {
    /**
     * A graded draft on the same day is excluded from the site aggregate.
     *
     * @return void
     */
    public function test_excludes_non_submitted(): void {
        global $DB;
        $this->resetAfterTest();
        $gen = $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
        $gen->seed_default_platform_calendar();

        $course = $this->getDataGenerator()->create_course();
        $tz = new \DateTimeZone('UTC');
        $dt = new \DateTimeImmutable('2026-03-15 10:00:00', $tz);
        $ts = $dt->getTimestamp();
        $day = (int) $dt->format('Ymd');

        $gen->create_ledger_row([
            'courseid' => (int) $course->id, 'groupid' => 0,
            'submissionstatus' => submission_status::SUBMITTED,
            'timegraded' => $ts, 'effectivehours' => 8.0,
        ]);
        $gen->create_ledger_row([
            'courseid' => (int) $course->id, 'groupid' => 0,
            'submissionstatus' => submission_status::DRAFT,
            'timegraded' => $ts, 'effectivehours' => 80.0,
        ]);

        site_stats_service::recompute_for_day($day);

        $row = $DB->get_record('block_feedback_tracker_site', ['day' => $day]);
        $this->assertNotFalse($row);
        $this->assertSame(1, (int) $row->numgraded);
        $this->assertEqualsWithDelta(8.0, (float) $row->medianh_eff, 0.01);
    }
}
