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
 * Tests for the responsiveness card renderable.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\output;

/**
 * Verifies the card's display toggles. The two default-ON booleans
 * (show_perceived_time, show_paused_today_indicator) must turn the
 * corresponding output off when the admin sets them to '0' — the stored
 * off-state for an admin_setting_configcheckbox.
 *
 * @covers \block_feedback_tracker\output\responsiveness_card
 */
final class responsiveness_card_test extends \advanced_testcase {
    /**
     * A complete group payload (mirrors responsiveness_payload::group_payload()),
     * with optional per-test overrides.
     *
     * @param array $overrides Keys to replace in the base payload.
     * @return array
     */
    private function sample_payload(array $overrides = []): array {
        return array_merge([
            'groupid'                => 7,
            'groupname'              => 'Group A',
            'groupsubtitle'          => '',
            'coursename'             => 'Course 1',
            'pending'                => 5,
            'critical'               => 1,
            'overgoal'               => 2,
            'numgraded30d'           => 10,
            'compliance_pct'         => 80.0,
            'compliance_pct_days'    => 70.0,
            'median_eff_h'           => 12.0,
            'p90_eff_h'              => 20.0,
            'max_eff_h'              => 30.0,
            'median_raw_h'           => 28.0,
            'p90_raw_h'              => 40.0,
            'max_raw_h'              => 50.0,
            'perceived_median_hours' => 28.0,
            'cur_median_eff_h'       => 12.0,
            'cur_median_raw_h'       => 30.0,
            'cur_median_eff_days'    => 1.0,
            'cur_median_perc_days'   => 2.0,
            'responsiveness_score'   => 75.0,
            'score_band'             => 'good',
            'comp_compliance'        => null,
            'comp_median'            => null,
            'comp_critical'          => null,
            'comp_pending'           => null,
            'comp_trend'             => null,
            'trend_pct_30d'          => -5.0,
            'trend_series'           => [],
            'nextpause_ts'           => 0,
            'nextpause_reason'       => null,
            'nextpause_note'         => null,
            'lastpause_endts'        => 0,
            'lastpause_reason'       => null,
            'paused_days_30d'        => 0,
            'paused_breakdown_30d'   => ['weekend' => 0, 'holiday' => 0, 'recess' => 0],
            'paused_events_30d'      => [],
            'peer_department_score'  => null,
            'peer_department_hours'  => null,
            'peer_top10_score'       => null,
            'peer_top10_hours'       => null,
            'activities'             => [],
        ], $overrides);
    }

    /**
     * Render a card payload to its template context.
     *
     * @param array $payload
     * @return array
     */
    private function export(array $payload): array {
        global $PAGE;
        $output = $PAGE->get_renderer('core');
        $card = new responsiveness_card(1, $payload);
        return $card->export_for_template($output);
    }

    /**
     * With show_perceived_time off, the headline metric must not append the
     * perceived (wall-clock) overlay.
     *
     * @return void
     */
    public function test_perceived_overlay_hidden_when_disabled(): void {
        $this->resetAfterTest();
        set_config('show_perceived_time', '0', 'block_feedback_tracker');

        $ctx = $this->export($this->sample_payload());

        $this->assertStringNotContainsString(' / ', $ctx['metrics'][0]['value']);
    }

    /**
     * With show_perceived_time on, the headline metric appends the perceived
     * overlay after a " / " separator.
     *
     * @return void
     */
    public function test_perceived_overlay_shown_when_enabled(): void {
        $this->resetAfterTest();
        set_config('show_perceived_time', '1', 'block_feedback_tracker');

        $ctx = $this->export($this->sample_payload());

        $this->assertStringContainsString(' / ', $ctx['metrics'][0]['value']);
    }

    /**
     * With show_paused_today_indicator off, the pause strip must be suppressed
     * even when the payload carries pause data.
     *
     * @return void
     */
    public function test_pause_strip_hidden_when_disabled(): void {
        $this->resetAfterTest();
        set_config('show_paused_today_indicator', '0', 'block_feedback_tracker');

        $ctx = $this->export($this->sample_payload([
            'lastpause_endts'  => 1700000000,
            'lastpause_reason' => 'weekend',
        ]));

        $this->assertFalse($ctx['haspause']);
    }

    /**
     * With show_paused_today_indicator on, pause data produces a visible strip.
     *
     * @return void
     */
    public function test_pause_strip_shown_when_enabled(): void {
        $this->resetAfterTest();
        set_config('show_paused_today_indicator', '1', 'block_feedback_tracker');

        $ctx = $this->export($this->sample_payload([
            'lastpause_endts'  => 1700000000,
            'lastpause_reason' => 'weekend',
        ]));

        $this->assertTrue($ctx['haspause']);
    }

    /**
     * In the default (hours) display unit the compliance metric reads the
     * hour-based compliance_pct. metrics[1] is the compliance row.
     *
     * @return void
     */
    public function test_compliance_uses_hours_twin_by_default(): void {
        $this->resetAfterTest();

        $ctx = $this->export($this->sample_payload([
            'compliance_pct'      => 80.0,
            'compliance_pct_days' => 55.0,
        ]));

        $this->assertSame('80%', $ctx['metrics'][1]['value']);
    }

    /**
     * In business-days display mode the compliance metric reads the day-ruler
     * twin (compliance_pct_days), not the hour-based compliance_pct \u2014 the two
     * are independent rulers. Display-only; the score is unaffected.
     *
     * @return void
     */
    public function test_compliance_uses_day_twin_in_business_days_mode(): void {
        $this->resetAfterTest();
        set_config('display_time_unit', 'business_days', 'block_feedback_tracker');

        $ctx = $this->export($this->sample_payload([
            'compliance_pct'      => 80.0,
            'compliance_pct_days' => 55.0,
        ]));

        $this->assertSame('55%', $ctx['metrics'][1]['value']);
    }
}
