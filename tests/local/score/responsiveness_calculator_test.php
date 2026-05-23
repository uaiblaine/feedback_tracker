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
 * Tests for the Academic Responsiveness Score formula.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\score;

/**
 * Boundary checks for each term, band thresholds, and weights normalisation.
 *
 * @covers \block_feedback_tracker\local\score\responsiveness_calculator
 */
final class responsiveness_calculator_test extends \advanced_testcase {
    /**
     * Empty metrics (no graded, no pending) should score very high — no work
     * to penalise. With default weights the score is 95 (every term = 1.0
     * except trend which defaults to 0.5).
     */
    public function test_no_data_scores_high(): void {
        $this->resetAfterTest();
        $this->seed_defaults();

        $r = responsiveness_calculator::compute([]);

        $this->assertSame(95.0, $r['score']);
        $this->assertSame('excellent', $r['band']);
    }

    /**
     * Perfect metrics: 100% compliant, median = 0, no pending or critical,
     * trend strongly improving (-20%). Should hit or near 100.
     */
    public function test_perfect_metrics_score_100(): void {
        $this->resetAfterTest();
        $this->seed_defaults();

        // The trend_term saturates at 1.0 when trend_pct_30d <= -100 (median
        // collapsed by 100% or more vs prior window). Anything less negative
        // gives a smaller trend term and the score caps below 100. With
        // every other term at 1.0, the formula produces exactly 100.0.
        $r = responsiveness_calculator::compute([
            'compliance_pct' => 100.0,
            'median_eff_h'   => 0.0,
            'critical'       => 0,
            'pending'        => 0,
            'numgraded30d'   => 100,
            'trend_pct_30d'  => -100.0,
        ]);

        $this->assertSame(100.0, $r['score']);
        $this->assertSame('excellent', $r['band']);
    }

    /**
     * Worst metrics: 0% compliance, median way over goal, all pending are
     * critical, trend strongly worsening. Should hit 0 and band 'critical'.
     */
    public function test_worst_metrics_score_zero(): void {
        $this->resetAfterTest();
        $this->seed_defaults();

        $r = responsiveness_calculator::compute([
            'compliance_pct' => 0.0,
            'median_eff_h'   => 9999.0,
            'critical'       => 100,
            'pending'        => 100,
            'numgraded30d'   => 100,
            'trend_pct_30d'  => 200.0,
        ]);

        $this->assertSame(0.0, $r['score']);
        $this->assertSame('critical', $r['band']);
    }

    /**
     * Each band threshold maps cleanly: 90 → excellent, 70 → good, 40 →
     * regular, 39 → critical.
     */
    public function test_band_thresholds(): void {
        $this->assertSame('excellent', responsiveness_calculator::band_for(90.0));
        $this->assertSame('excellent', responsiveness_calculator::band_for(100.0));
        $this->assertSame('good', responsiveness_calculator::band_for(89.99));
        $this->assertSame('good', responsiveness_calculator::band_for(70.0));
        $this->assertSame('regular', responsiveness_calculator::band_for(69.99));
        $this->assertSame('regular', responsiveness_calculator::band_for(40.0));
        $this->assertSame('critical', responsiveness_calculator::band_for(39.99));
        $this->assertSame('critical', responsiveness_calculator::band_for(0.0));
    }

    /**
     * Median term equals 0.5 when median == sla_goal_hours.
     */
    public function test_median_term_half_at_goal(): void {
        $this->resetAfterTest();
        $this->seed_defaults();

        $r = responsiveness_calculator::compute([
            'compliance_pct' => 100.0,
            'median_eff_h'   => 24.0,
            'critical'       => 0,
            'pending'        => 0,
            'numgraded30d'   => 50,
            'trend_pct_30d'  => 0.0,
        ]);

        // 0.40 * 1.0 + 0.25 * 0.5 + 0.15 * 1.0 + 0.10 * 1.0 + 0.10 * 0.5 = 0.825 -> 82.5.
        $this->assertSame(82.5, $r['score']);
    }

    /**
     * Trend > 0 (median rising = getting worse) drives trend term below 0.5,
     * lowering the score.
     */
    public function test_worsening_trend_lowers_score(): void {
        $this->resetAfterTest();
        $this->seed_defaults();

        $r1 = responsiveness_calculator::compute([
            'compliance_pct' => 100.0,
            'median_eff_h'   => 0.0,
            'critical'       => 0,
            'pending'        => 0,
            'numgraded30d'   => 50,
            'trend_pct_30d'  => 0.0,
        ]);
        $r2 = responsiveness_calculator::compute([
            'compliance_pct' => 100.0,
            'median_eff_h'   => 0.0,
            'critical'       => 0,
            'pending'        => 0,
            'numgraded30d'   => 50,
            'trend_pct_30d'  => 100.0,
        ]);

        $this->assertGreaterThan($r2['score'], $r1['score']);
    }

    /**
     * Critical / pending ratio drives the critical term to 0 when every
     * pending is critical.
     */
    public function test_critical_term_zero_when_all_pending_critical(): void {
        $this->resetAfterTest();
        $this->seed_defaults();

        $r = responsiveness_calculator::compute([
            'compliance_pct' => 100.0,
            'median_eff_h'   => 0.0,
            'critical'       => 5,
            'pending'        => 5,
            'numgraded30d'   => 50,
            'trend_pct_30d'  => 0.0,
        ]);

        $this->assertSame(0.0, $r['components']['critical']);
    }

    /**
     * load_weights() normalises a non-unit weight set to sum 1.0.
     */
    public function test_load_weights_normalises_when_out_of_range(): void {
        $this->resetAfterTest();
        set_config('weight_compliance', '2.0', 'block_feedback_tracker');
        set_config('weight_median', '2.0', 'block_feedback_tracker');
        set_config('weight_critical', '2.0', 'block_feedback_tracker');
        set_config('weight_pending', '2.0', 'block_feedback_tracker');
        set_config('weight_trend', '2.0', 'block_feedback_tracker');

        $w = responsiveness_calculator::load_weights();

        $this->assertEqualsWithDelta(1.0, array_sum($w), 1e-9);
        foreach ($w as $v) {
            $this->assertEqualsWithDelta(0.2, $v, 1e-9);
        }
    }

    /**
     * load_weights() keeps a weight set that already sums to ~1.0 unchanged.
     */
    public function test_load_weights_passes_through_when_summing_close_to_one(): void {
        $this->resetAfterTest();
        set_config('weight_compliance', '0.30', 'block_feedback_tracker');
        set_config('weight_median', '0.30', 'block_feedback_tracker');
        set_config('weight_critical', '0.20', 'block_feedback_tracker');
        set_config('weight_pending', '0.10', 'block_feedback_tracker');
        set_config('weight_trend', '0.10', 'block_feedback_tracker');

        $w = responsiveness_calculator::load_weights();

        $this->assertSame(0.30, $w['compliance']);
        $this->assertSame(0.30, $w['median']);
        $this->assertSame(0.20, $w['critical']);
        $this->assertSame(0.10, $w['pending']);
        $this->assertSame(0.10, $w['trend']);
    }

    /**
     * Seed the five defaults plus sla_goal_hours.
     */
    private function seed_defaults(): void {
        set_config('weight_compliance', '0.40', 'block_feedback_tracker');
        set_config('weight_median', '0.25', 'block_feedback_tracker');
        set_config('weight_critical', '0.15', 'block_feedback_tracker');
        set_config('weight_pending', '0.10', 'block_feedback_tracker');
        set_config('weight_trend', '0.10', 'block_feedback_tracker');
        set_config('sla_goal_hours', '24', 'block_feedback_tracker');
    }
}
