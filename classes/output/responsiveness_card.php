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
 * Responsiveness card renderable.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\output;

use block_feedback_tracker\local\score\responsiveness_calculator;

/**
 * One responsiveness card per (course, group). Composes the score gauge,
 * counts row, metrics row, paused-today strip, and an optional score
 * breakdown. Rendered via Mustache; see templates/responsiveness_card.mustache.
 */
class responsiveness_card implements \renderable, \templatable {
    /** @var int The course ID. */
    public int $courseid;

    /** @var array One element of the responsiveness payload `groups` array. */
    public array $payload;

    /**
     * Constructor for responsiveness_card.
     *
     * @param int $courseid The course ID.
     * @param array $payload One element of the responsiveness payload `groups` array.
     */
    public function __construct(
        int $courseid,
        array $payload
    ) {
        $this->courseid = $courseid;
        $this->payload = $payload;
    }

    /**
     * Build the Mustache template context.
     *
     * @param \renderer_base $output
     * @return array
     */
    public function export_for_template(\renderer_base $output): array {
        $p = $this->payload;

        $title = format_string(($p['groupname'] !== '' ? $p['groupname'] : $p['coursename']));

        $gauge = new score_gauge(
            $p['responsiveness_score'] !== null ? (float) $p['responsiveness_score'] : null,
            $p['score_band'] !== null ? (string) $p['score_band'] : null,
            100
        );

        $showperceived = (int) (get_config('block_feedback_tracker', 'show_perceived_time') ?: 1) === 1;
        $showpause = (int) (get_config('block_feedback_tracker', 'show_paused_today_indicator') ?: 1) === 1;

        $counts = [
            ['label' => get_string('card_pending', 'block_feedback_tracker'), 'value' => (int) $p['pending']],
            ['label' => get_string('card_critical', 'block_feedback_tracker'), 'value' => (int) $p['critical']],
            ['label' => get_string('card_overgoal', 'block_feedback_tracker'), 'value' => (int) $p['overgoal']],
        ];

        $metrics = $this->build_metrics($p, $showperceived);
        $pausestrip = $showpause ? $this->build_pause_strip($p) : '';

        $breakdownctx = $this->build_breakdown($p);
        $sparklinectx = $this->build_sparkline($p, $output);

        $drilldownurl = new \moodle_url('/blocks/feedback_tracker/pages/group_drilldown.php', [
            'courseid' => $this->courseid,
            'groupid'  => (int) $p['groupid'],
        ]);

        return [
            'title'         => $title,
            'band'          => $p['score_band'] !== null ? (string) $p['score_band'] : '',
            'bandlabel'     => self::band_label($p['score_band'] !== null ? (string) $p['score_band'] : ''),
            'gauge'         => $gauge->export_for_template($output),
            'counts'        => $counts,
            'metrics'       => $metrics,
            'haspause'      => $pausestrip !== '',
            'pausestrip'    => $pausestrip,
            'hasbreakdown'  => $breakdownctx !== null,
            'breakdown'     => $breakdownctx ?? [],
            'hassparkline'  => $sparklinectx !== null,
            'sparkline'     => $sparklinectx ?? [],
            'courseid'      => $this->courseid,
            'refreshtext'   => get_string('card_refresh', 'block_feedback_tracker'),
            'drilldownurl'  => $drilldownurl->out(false),
            'drilldowntext' => get_string('card_open_drilldown', 'block_feedback_tracker'),
        ];
    }

    /**
     * Build the metric rows (median eff/perceived, compliance, trend).
     *
     * @param array $p
     * @param bool $showperceived
     * @return array
     */
    private function build_metrics(array $p, bool $showperceived): array {
        $median = $p['median_eff_h'] !== null
            ? format_float((float) $p['median_eff_h'], 1) . ' h'
            : '—';
        if ($showperceived && $p['median_raw_h'] !== null) {
            $median .= ' / ' . format_float((float) $p['median_raw_h'], 1) . ' h';
        }
        $compliance = $p['compliance_pct'] !== null
            ? format_float((float) $p['compliance_pct'], 0) . '%'
            : '—';
        $trendtxt = '—';
        if ($p['trend_pct_30d'] !== null) {
            $trendval = (float) $p['trend_pct_30d'];
            $arrow = $trendval < 0 ? '▼' : ($trendval > 0 ? '▲' : '→');
            $trendtxt = $arrow . ' ' . format_float(abs($trendval), 0) . '%';
        }
        return [
            ['label' => get_string('card_median_eff', 'block_feedback_tracker'), 'value' => $median],
            ['label' => get_string('card_compliance', 'block_feedback_tracker'), 'value' => $compliance],
            ['label' => get_string('card_trend', 'block_feedback_tracker'), 'value' => $trendtxt],
        ];
    }

    /**
     * Build the paused-today / next-pause indicator strip.
     *
     * @param array $p
     * @return string
     */
    private function build_pause_strip(array $p): string {
        $parts = [];
        if ($p['lastpause_endts'] !== null && (int) $p['lastpause_endts'] > 0) {
            $when = userdate((int) $p['lastpause_endts'], get_string('strftimedate', 'core_langconfig'));
            $reason = (string) ($p['lastpause_reason'] ?? '');
            $parts[] = get_string('card_lastpause', 'block_feedback_tracker', (object) [
                'when' => $when, 'reason' => $reason,
            ]);
        }
        if ($p['nextpause_ts'] !== null && (int) $p['nextpause_ts'] > 0) {
            $when = userdate((int) $p['nextpause_ts'], get_string('strftimedate', 'core_langconfig'));
            $reason = (string) ($p['nextpause_reason'] ?? '');
            $note = (string) ($p['nextpause_note'] ?? '');
            $parts[] = get_string('card_nextpause', 'block_feedback_tracker', (object) [
                'when' => $when, 'reason' => $reason, 'note' => $note,
            ]);
        }
        return empty($parts) ? '' : implode(' · ', $parts);
    }

    /**
     * Build the score-breakdown table context, or null when no components
     * are persisted on the rollup row yet.
     *
     * @param array $p
     * @return array|null
     */
    private function build_breakdown(array $p): ?array {
        $components = [
            'compliance' => $p['comp_compliance'] ?? null,
            'median'     => $p['comp_median'] ?? null,
            'critical'   => $p['comp_critical'] ?? null,
            'pending'    => $p['comp_pending'] ?? null,
            'trend'      => $p['comp_trend'] ?? null,
        ];
        $present = array_keys(array_filter($components, static fn($v) => $v !== null));
        if (empty($present)) {
            return null;
        }
        $adminweights = responsiveness_calculator::load_weights();
        // v1.0.7 — renormalise weights against the terms that carry data so
        // the breakdown's weight + points columns match the score the
        // calculator actually produced. Mirrors the JS-side math in
        // GroupCard::buildBreakdown().
        $available = array_fill_keys(array_keys($components), false);
        foreach ($present as $k) {
            $available[$k] = true;
        }
        $effective = responsiveness_calculator::effective_weights($adminweights, $available);
        $rows = [];
        $totalpts = 0.0;
        $totalmax = 0.0;
        foreach ($present as $key) {
            $value = (float) $components[$key];
            $weight = (float) $effective[$key];
            $maxpts = $weight * 100.0;
            $pts = $value * $maxpts;
            $totalpts += $pts;
            $totalmax += $maxpts;
            $rows[] = [
                'label'     => self::breakdown_label($key),
                'valuestr'  => format_float($value, 2),
                'weightstr' => format_float($weight, 2),
                'ptsstr'    => format_float($pts, 1) . ' / ' . format_float($maxpts, 1),
            ];
        }
        $excluded = array_diff(array_keys($components), $present);
        $footnote = '';
        if (!empty($excluded)) {
            $names = array_map(static fn($k) => self::breakdown_label($k), $excluded);
            $footnote = get_string('breakdown_excluded_prefix', 'block_feedback_tracker')
                . ' ' . implode(', ', $names) . '.';
        }
        return [
            'summary'   => get_string('breakdown_summary', 'block_feedback_tracker'),
            'strterm'   => get_string('breakdown_term', 'block_feedback_tracker'),
            'strvalue'  => get_string('breakdown_value', 'block_feedback_tracker'),
            'strweight' => get_string('breakdown_weight', 'block_feedback_tracker'),
            'strpts'    => get_string('breakdown_pts', 'block_feedback_tracker'),
            'strtotal'  => get_string('breakdown_total', 'block_feedback_tracker'),
            'rows'      => $rows,
            'totalstr'  => format_float($totalpts, 1) . ' / ' . format_float($totalmax, 1),
            'footnote'  => $footnote,
        ];
    }

    /**
     * Build the sparkline context, or null when no trend data is available.
     *
     * @param array $p
     * @param \renderer_base $output
     * @return array|null
     */
    private function build_sparkline(array $p, \renderer_base $output): ?array {
        $series = is_array($p['trend_series'] ?? null) ? $p['trend_series'] : [];
        if (empty($series)) {
            return null;
        }
        $hasdata = false;
        $values = [];
        foreach ($series as $point) {
            $v = $point['value'] ?? null;
            $values[] = $v !== null ? (float) $v : null;
            if ($v !== null) {
                $hasdata = true;
            }
        }
        if (!$hasdata) {
            return null;
        }
        $goal = (float) (get_config('block_feedback_tracker', 'sla_goal_hours') ?: 24);
        $spark = new sparkline($values, $goal);
        return $spark->export_for_template($output);
    }

    /**
     * Localised label for one breakdown term.
     *
     * @param string $key
     * @return string
     */
    private static function breakdown_label(string $key): string {
        switch ($key) {
            case 'compliance':
                return get_string('breakdown_compliance', 'block_feedback_tracker');
            case 'median':
                return get_string('breakdown_median', 'block_feedback_tracker');
            case 'critical':
                return get_string('breakdown_critical', 'block_feedback_tracker');
            case 'pending':
                return get_string('breakdown_pending', 'block_feedback_tracker');
            case 'trend':
                return get_string('breakdown_trend', 'block_feedback_tracker');
            default:
                return $key;
        }
    }

    /**
     * Localised label for an SLA band (literal map for the string-checker).
     *
     * @param string $band
     * @return string
     */
    private static function band_label(string $band): string {
        switch ($band) {
            case 'excellent':
                return get_string('band_excellent', 'block_feedback_tracker');
            case 'good':
                return get_string('band_good', 'block_feedback_tracker');
            case 'regular':
                return get_string('band_regular', 'block_feedback_tracker');
            case 'critical':
                return get_string('band_critical', 'block_feedback_tracker');
            case 'pending':
                return get_string('band_pending', 'block_feedback_tracker');
            default:
                return '';
        }
    }
}
