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
 * Tests for the get_responsiveness external function.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\external;

use block_feedback_tracker\local\sla\rollup_service;
use core_external\external_api;

/**
 * Tests for retrieving responsiveness data via external functions.
 *
 * @covers \block_feedback_tracker\external\get_responsiveness
 */
final class get_responsiveness_test extends \advanced_testcase {
    /**
     * After seeding ledger + rollup, the WS returns the expected payload
     * shape and surfaces the score, band, and counts.
     */
    public function test_returns_group_card_for_seeded_rollup(): void {
        $this->resetAfterTest();
        $this->seed_config();

        $course = $this->getDataGenerator()->create_course();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $group = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->getDataGenerator()->create_group_member([
            'groupid' => $group->id,
            'userid'  => $teacher->id,
        ]);
        $this->getDataGenerator()->create_group_member([
            'groupid' => $group->id,
            'userid'  => $student->id,
        ]);

        $now = time();
        global $DB;
        $assign = $this->getDataGenerator()->create_module('assign', ['course' => $course->id]);
        $cm = get_coursemodule_from_instance('assign', $assign->id);
        $DB->insert_record('block_feedback_tracker_sub', (object) [
            'courseid'         => $course->id,
            'groupid'          => $group->id,
            'cmid'             => $cm->id,
            'iteminstance'     => $assign->id,
            'userid'           => $student->id,
            'attemptnumber'    => 0,
            'submissionstatus' => 'submitted',
            'timesubmitted'    => $now - 86400 * 2,
            'timegraded'       => $now - 86400,
            'hasrule'          => 0,
            'waitinghours'     => 24.0,
            'effectivehours'   => 16.0,
            'effectiveasof'    => $now,
            'effectivecalver'  => 1,
            'slabucket'        => 'excellent',
            'timecreated'      => $now - 86400 * 2,
            'timemodified'     => $now - 86400,
        ]);
        rollup_service::recompute_group((int) $course->id, (int) $group->id, $now);

        $this->setUser($teacher);
        $result = get_responsiveness::execute((int) $course->id);
        $result = external_api::clean_returnvalue(
            get_responsiveness::execute_returns(),
            $result
        );

        $this->assertTrue($result['success']);
        $this->assertSame((int) $course->id, $result['courseid']);
        $this->assertCount(1, $result['groups']);
        $card = $result['groups'][0];
        $this->assertSame((int) $group->id, $card['groupid']);
        $this->assertSame(0, $card['pending']);
        $this->assertSame(1, $card['numgraded30d']);
        $this->assertEqualsWithDelta(16.0, $card['median_eff_h'], 0.01);
        /*
         * Score for one graded submission with median 16h (renormalised without trend):
         *   compliance=1 (1/1 within 24h), median=1-16/48=0.667,
         *   critical=1, pending=1.
         *   100 * (0.4 + 0.25*0.667 + 0.15 + 0.10) / 0.90 ≈ 90.7 → "excellent".
         */
        $this->assertSame('excellent', $card['score_band']);

        // Phase 3C — payload includes the new perceived / paused / peer
        // keys with sensible defaults. perceived_median_hours mirrors
        // median_raw_h (= waitinghours 24.0 here, the only ledger row).
        $this->assertArrayHasKey('perceived_median_hours', $card);
        $this->assertEqualsWithDelta(24.0, $card['perceived_median_hours'], 0.01);
        $this->assertArrayHasKey('paused_days_30d', $card);
        $this->assertIsInt($card['paused_days_30d']);
        $this->assertGreaterThanOrEqual(0, $card['paused_days_30d']);
        $this->assertArrayHasKey('paused_breakdown_30d', $card);
        $this->assertArrayHasKey('weekend', $card['paused_breakdown_30d']);
        $this->assertArrayHasKey('holiday', $card['paused_breakdown_30d']);
        $this->assertArrayHasKey('recess', $card['paused_breakdown_30d']);
        // V1.0.9 — sub-day optional events sidecar; empty by default.
        $this->assertArrayHasKey('paused_events_30d', $card);
        $this->assertIsArray($card['paused_events_30d']);
        // Single-group fixture < MIN_SAMPLE for peer_stats, so peer
        // benchmarks come back null and the JS PeerContext hides itself.
        $this->assertArrayHasKey('peer_department_score', $card);
        $this->assertNull($card['peer_department_score']);
        $this->assertNull($card['peer_top10_score']);
    }

    /**
     * Strangers (unenrolled, no role) are rejected. Moodle raises a
     * require_login_exception in this case before the explicit
     * require_capability check fires; either is acceptable evidence of
     * the auth gate, so we expect the common base class.
     */
    public function test_capability_enforced(): void {
        $this->resetAfterTest();
        $this->seed_config();

        $course = $this->getDataGenerator()->create_course();
        $stranger = $this->getDataGenerator()->create_user();

        $this->setUser($stranger);

        $this->expectException(\moodle_exception::class);
        get_responsiveness::execute((int) $course->id);
    }

    /**
     * Seed config used by the WS path.
     */
    private function seed_config(): void {
        set_config('calver', '1', 'block_feedback_tracker');
        set_config('timezone', 'UTC', 'block_feedback_tracker');
        set_config('excludeweekends', '1', 'block_feedback_tracker');
        set_config('weekendmask', '96', 'block_feedback_tracker');
        set_config('excludeholidays', '1', 'block_feedback_tracker');
        set_config('excluderecesses', '1', 'block_feedback_tracker');
        set_config('enablebusinesshours', '1', 'block_feedback_tracker');
        set_config('grading_during_pause_mode', 'clipped', 'block_feedback_tracker');
        set_config('sla_goal_hours', '24', 'block_feedback_tracker');
        set_config('bucket_thresholds_eff', '24,48,120', 'block_feedback_tracker');
        set_config('weight_compliance', '0.40', 'block_feedback_tracker');
        set_config('weight_median', '0.25', 'block_feedback_tracker');
        set_config('weight_critical', '0.15', 'block_feedback_tracker');
        set_config('weight_pending', '0.10', 'block_feedback_tracker');
        set_config('weight_trend', '0.10', 'block_feedback_tracker');

        global $DB;
        $now = time();
        for ($dow = 0; $dow <= 4; $dow++) {
            $DB->insert_record('block_feedback_tracker_chours', (object) [
                'dayofweek'    => $dow,
                'starttime'    => 480,
                'endtime'      => 1080,
                'enabled'      => 1,
                'timecreated'  => $now,
                'timemodified' => $now,
            ]);
        }
    }
}
