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
 * Tests for rollup_service::recompute_group().
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * Seeds the ledger with a mix of pending + graded rows, runs recompute_group,
 * and verifies pending / critical / overgoal counts, medians, score, and band.
 *
 * @covers \block_feedback_tracker\local\sla\rollup_service
 */
final class rollup_service_test extends \advanced_testcase {
    /**
     * Mix of pending + graded rows produces a rollup row with correct
     * counts, medians, compliance, and a band reflecting the score.
     */
    public function test_recompute_group_with_mixed_rows(): void {
        $this->resetAfterTest();
        $this->seed_config();

        $courseid = 100;
        $groupid = 200;
        $now = time();

        // 3 pending rows: 2 critical (>=120h), 1 over goal (>24h), 1 ok.
        $this->insert_ledger($courseid, $groupid, ['effectivehours' => 150.0, 'timegraded' => null]);
        $this->insert_ledger($courseid, $groupid, ['effectivehours' => 130.0, 'timegraded' => null]);
        $this->insert_ledger($courseid, $groupid, ['effectivehours' => 50.0, 'timegraded' => null]);
        // 1 ok pending (<=goal, no critical, no overgoal).
        $this->insert_ledger($courseid, $groupid, ['effectivehours' => 5.0, 'timegraded' => null]);

        // 5 graded rows in last 30d: effective hours 10, 15, 20, 30, 60.
        // Median = 20, compliance = 60% (3 within 24h).
        $this->insert_ledger($courseid, $groupid, [
            'effectivehours' => 10.0, 'waitinghours' => 12.0, 'timegraded' => $now - 86400,
        ]);
        $this->insert_ledger($courseid, $groupid, [
            'effectivehours' => 15.0, 'waitinghours' => 20.0, 'timegraded' => $now - 86400,
        ]);
        $this->insert_ledger($courseid, $groupid, [
            'effectivehours' => 20.0, 'waitinghours' => 24.0, 'timegraded' => $now - 86400,
        ]);
        $this->insert_ledger($courseid, $groupid, [
            'effectivehours' => 30.0, 'waitinghours' => 50.0, 'timegraded' => $now - 86400,
        ]);
        $this->insert_ledger($courseid, $groupid, [
            'effectivehours' => 60.0, 'waitinghours' => 80.0, 'timegraded' => $now - 86400,
        ]);

        rollup_service::recompute_group($courseid, $groupid, $now);

        global $DB;
        $row = $DB->get_record('block_feedback_tracker_group', [
            'courseid' => $courseid, 'groupid' => $groupid,
        ]);
        $this->assertNotFalse($row);

        $this->assertSame(4, (int) $row->pending);
        $this->assertSame(2, (int) $row->critical);
        $this->assertSame(3, (int) $row->overgoal); // 150, 130, 50 are all > goal=24.
        $this->assertSame(5, (int) $row->numgraded30d);
        $this->assertSame(20.0, (float) $row->median_eff_h);
        $this->assertEqualsWithDelta(60.0, (float) $row->compliance_pct, 0.01);
        $this->assertGreaterThan(0.0, (float) $row->responsiveness_score);
        $this->assertContains($row->score_band, ['excellent', 'good', 'regular', 'critical']);
    }

    /**
     * Empty ledger produces a rollup with zero counts, null medians, and a
     * high (charitable) score.
     */
    public function test_recompute_group_with_no_rows(): void {
        $this->resetAfterTest();
        $this->seed_config();

        rollup_service::recompute_group(101, 201);

        global $DB;
        $row = $DB->get_record('block_feedback_tracker_group', [
            'courseid' => 101, 'groupid' => 201,
        ]);
        $this->assertNotFalse($row);
        $this->assertSame(0, (int) $row->pending);
        $this->assertSame(0, (int) $row->critical);
        $this->assertSame(0, (int) $row->numgraded30d);
        $this->assertNull($row->median_eff_h);
        $this->assertSame(95.0, (float) $row->responsiveness_score);
        $this->assertSame('excellent', $row->score_band);
    }

    /**
     * Trend pct is computed as % change between this 30-day window and
     * the prior 30-day window.
     */
    public function test_trend_calculation(): void {
        $this->resetAfterTest();
        $this->seed_config();

        $courseid = 102;
        $groupid = 202;
        $now = time();
        $thirtydays = 30 * 86400;

        // Prior window (30-60 days ago): median 50.
        for ($i = 0; $i < 5; $i++) {
            $this->insert_ledger($courseid, $groupid, [
                'effectivehours' => 50.0,
                'waitinghours' => 50.0,
                'timegraded' => $now - $thirtydays - 86400 * ($i + 1),
            ]);
        }
        // Recent window (last 30 days): median 25 (improving = negative trend).
        for ($i = 0; $i < 5; $i++) {
            $this->insert_ledger($courseid, $groupid, [
                'effectivehours' => 25.0,
                'waitinghours' => 25.0,
                'timegraded' => $now - 86400 * ($i + 1),
            ]);
        }

        rollup_service::recompute_group($courseid, $groupid, $now);

        global $DB;
        $row = $DB->get_record('block_feedback_tracker_group', [
            'courseid' => $courseid, 'groupid' => $groupid,
        ]);
        // Math: (25 - 50) / 50 * 100 = -50% (improving).
        $this->assertEqualsWithDelta(-50.0, (float) $row->trend_pct_30d, 0.01);
    }

    /**
     * Subsequent calls update the same rollup row, not insert a duplicate.
     */
    public function test_recompute_is_idempotent(): void {
        $this->resetAfterTest();
        $this->seed_config();

        rollup_service::recompute_group(103, 203);
        rollup_service::recompute_group(103, 203);
        rollup_service::recompute_group(103, 203);

        global $DB;
        $rows = $DB->get_records('block_feedback_tracker_group', [
            'courseid' => 103, 'groupid' => 203,
        ]);
        $this->assertCount(1, $rows);
    }

    // Note (v1.0.0): the prior test_recompute_skips_on_lock_collision
    // test was removed. The Lock API factory provisioned in Moodle's
    // PHPUnit environment is per-process and doesn't enforce mutual
    // exclusion when the same process acquires the lock twice — making
    // it impossible to simulate a "concurrent worker holds the lock"
    // scenario without mocking the factory (which is more test
    // infrastructure than the verification justifies). The lock
    // semantics themselves are exercised by Moodle core's lock-factory
    // tests; we rely on \core\lock\lock_config::get_lock_factory()
    // behaving correctly in production.

    // Helpers.

    /**
     * Seed score/SLA config.
     */
    private function seed_config(): void {
        set_config('calver', '1', 'block_feedback_tracker');
        set_config('sla_goal_hours', '24', 'block_feedback_tracker');
        set_config('bucket_thresholds_eff', '24,48,120', 'block_feedback_tracker');
        set_config('weight_compliance', '0.40', 'block_feedback_tracker');
        set_config('weight_median', '0.25', 'block_feedback_tracker');
        set_config('weight_critical', '0.15', 'block_feedback_tracker');
        set_config('weight_pending', '0.10', 'block_feedback_tracker');
        set_config('weight_trend', '0.10', 'block_feedback_tracker');
        set_config('trend_window_days', '30', 'block_feedback_tracker');
    }

    /**
     * Insert a fully-formed ledger row with sensible defaults; only the keys
     * provided in $overrides are set explicitly.
     *
     * @param int $courseid
     * @param int $groupid
     * @param array $overrides
     * @return int New row id.
     */
    private function insert_ledger(int $courseid, int $groupid, array $overrides): int {
        global $DB;
        static $cmid = 10000;
        $cmid++;
        $now = time();
        $defaults = [
            'courseid'         => $courseid,
            'groupid'          => $groupid,
            'cmid'             => $cmid,
            'iteminstance'     => $cmid,
            'userid'           => 1,
            'attemptnumber'    => 0,
            'submissionstatus' => 'submitted',
            'timesubmitted'    => $now - 7200,
            'timegraded'       => null,
            'timeopens'        => null,
            'timecloses'       => null,
            'timecutoff'       => null,
            'hasrule'          => 0,
            'waitinghours'     => 0.0,
            'effectivehours'   => 0.0,
            'effectiveasof'    => $now,
            'effectivecalver'  => 1,
            'slabucket'        => 'excellent',
            'timecreated'      => $now,
            'timemodified'     => $now,
        ];
        $rec = (object) array_merge($defaults, $overrides);
        return (int) $DB->insert_record('block_feedback_tracker_sub', $rec);
    }
}
