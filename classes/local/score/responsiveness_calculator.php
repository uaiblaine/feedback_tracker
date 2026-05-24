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
 * Academic Responsiveness Score formula.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\score;

/**
 * Five-term weighted score on a 0-100 scale, mapped to a four-band label.
 *
 * Terms (all in [0, 1]):
 *  - compliance: % of last-30d graded within SLA goal hours
 *  - median:     1 - median_eff_h / (2 * sla_goal); 0.5 at goal, 0 at 2x goal
 *  - critical:   1 - critical / max(pending, 1)
 *  - pending:    1 - pending / max(numgraded30d, 20)
 *  - trend:      0.5 - trend_pct_30d / 200 (negative trend = improvement)
 *
 * Weights default to (0.40, 0.25, 0.15, 0.10, 0.10) summing to 1.0; admin
 * tunable via config_plugins. Missing data is treated charitably (term = 1.0
 * for compliance/median, 0.5 for trend) so a group with no work yet scores
 * high rather than penalised.
 */
class responsiveness_calculator {
    /** Default weight for the compliance term. */
    public const DEFAULT_WEIGHT_COMPLIANCE = 0.40;
    /** Default weight for the median term. */
    public const DEFAULT_WEIGHT_MEDIAN = 0.25;
    /** Default weight for the critical term. */
    public const DEFAULT_WEIGHT_CRITICAL = 0.15;
    /** Default weight for the pending term. */
    public const DEFAULT_WEIGHT_PENDING = 0.10;
    /** Default weight for the trend term. */
    public const DEFAULT_WEIGHT_TREND = 0.10;

    /** Default SLA goal hours. */
    public const DEFAULT_SLA_GOAL_HOURS = 24.0;
    /** Default pending soft cap (used when numgraded30d is small). */
    public const PENDING_SOFT_CAP_MIN = 20;

    /**
     * Compute the score.
     *
     * @param array $metrics {
     * @var float|null $compliance_pct  Percentage 0..100, or null.
     * @var float|null $median_eff_h    Median effective hours (graded), or null.
     * @var int        $critical        Count of critical pending submissions.
     * @var int        $pending         Count of pending submissions.
     * @var int        $numgraded30d    Count of graded in last 30 days.
     * @var float|null $trend_pct_30d   Trend percentage (negative = improving), or null.
     * }
     * @return array{score:float, band:string, components:array<string, float>}
     */
    public static function compute(array $metrics): array {
        $weights = self::load_weights();
        $slagoal = (float) (get_config('block_feedback_tracker', 'sla_goal_hours') ?: self::DEFAULT_SLA_GOAL_HOURS);
        if ($slagoal <= 0.0) {
            $slagoal = self::DEFAULT_SLA_GOAL_HOURS;
        }

        $numgraded = max(0, (int) ($metrics['numgraded30d'] ?? 0));
        $pending = max(0, (int) ($metrics['pending'] ?? 0));
        $critical = max(0, (int) ($metrics['critical'] ?? 0));
        $compliancepct = isset($metrics['compliance_pct']) ? (float) $metrics['compliance_pct'] : null;
        $medianeff = isset($metrics['median_eff_h']) ? (float) $metrics['median_eff_h'] : null;
        $trendpct = isset($metrics['trend_pct_30d']) ? (float) $metrics['trend_pct_30d'] : null;

        $compliance = $numgraded === 0 || $compliancepct === null
            ? 1.0
            : self::clamp01($compliancepct / 100.0);

        $median = $medianeff === null
            ? 1.0
            : self::clamp01(1.0 - $medianeff / (2.0 * $slagoal));

        $criticalterm = self::clamp01(1.0 - $critical / max($pending, 1));

        $softcap = max($numgraded, self::PENDING_SOFT_CAP_MIN);
        $pendingterm = self::clamp01(1.0 - $pending / $softcap);

        $trend = $trendpct === null
            ? 0.5
            : self::clamp01(0.5 - $trendpct / 200.0);

        $score = 100.0 * (
            $weights['compliance'] * $compliance
            + $weights['median'] * $median
            + $weights['critical'] * $criticalterm
            + $weights['pending'] * $pendingterm
            + $weights['trend'] * $trend
        );
        $score = round(max(0.0, min(100.0, $score)), 2);

        return [
            'score' => $score,
            'band'  => self::band_for($score),
            'components' => [
                'compliance' => round($compliance, 4),
                'median'     => round($median, 4),
                'critical'   => round($criticalterm, 4),
                'pending'    => round($pendingterm, 4),
                'trend'      => round($trend, 4),
            ],
        ];
    }

    /**
     * Map a score to one of the four bands using the configured cutoffs.
     *
     * @param float $score
     * @return string excellent|good|regular|critical
     */
    public static function band_for(float $score): string {
        [$excellent, $good, $regular] = self::parse_thresholds_band();
        if ($score >= $excellent) {
            return 'excellent';
        }
        if ($score >= $good) {
            return 'good';
        }
        if ($score >= $regular) {
            return 'regular';
        }
        return 'critical';
    }

    /**
     * Parse the score-band thresholds setting (CSV) into a descending
     * three-element float array [excellent_min, good_min, regular_min].
     * Returns the design defaults (90, 70, 40) if the setting is malformed.
     * Mirrors the bucket::parse_thresholds_eff() shape so settings.php can
     * follow the same pattern for both.
     *
     * @return array{0:float, 1:float, 2:float}
     */
    public static function parse_thresholds_band(): array {
        $raw = (string) (get_config('block_feedback_tracker', 'score_thresholds_band') ?: '90,70,40');
        $parts = array_map('trim', explode(',', $raw));
        $t1 = isset($parts[0]) && is_numeric($parts[0]) ? (float) $parts[0] : 90.0;
        $t2 = isset($parts[1]) && is_numeric($parts[1]) ? (float) $parts[1] : 70.0;
        $t3 = isset($parts[2]) && is_numeric($parts[2]) ? (float) $parts[2] : 40.0;
        return [$t1, $t2, $t3];
    }

    /**
     * Load the five weights from config_plugins. Normalise to sum 1.0 if the
     * configured sum is outside [0.95, 1.05]; falls back to defaults if any
     * weight is missing or non-numeric.
     *
     * @return array{compliance:float, median:float, critical:float, pending:float, trend:float}
     */
    public static function load_weights(): array {
        $raw = [
            'compliance' => get_config('block_feedback_tracker', 'weight_compliance'),
            'median'     => get_config('block_feedback_tracker', 'weight_median'),
            'critical'   => get_config('block_feedback_tracker', 'weight_critical'),
            'pending'    => get_config('block_feedback_tracker', 'weight_pending'),
            'trend'      => get_config('block_feedback_tracker', 'weight_trend'),
        ];
        $defaults = [
            'compliance' => self::DEFAULT_WEIGHT_COMPLIANCE,
            'median'     => self::DEFAULT_WEIGHT_MEDIAN,
            'critical'   => self::DEFAULT_WEIGHT_CRITICAL,
            'pending'    => self::DEFAULT_WEIGHT_PENDING,
            'trend'      => self::DEFAULT_WEIGHT_TREND,
        ];
        $weights = [];
        foreach ($defaults as $k => $def) {
            $v = $raw[$k];
            $weights[$k] = (is_numeric($v) && (float) $v >= 0.0) ? (float) $v : $def;
        }
        $sum = array_sum($weights);
        if ($sum <= 0.0) {
            return $defaults;
        }
        if ($sum < 0.95 || $sum > 1.05) {
            foreach ($weights as $k => $v) {
                $weights[$k] = $v / $sum;
            }
        }
        return $weights;
    }

    /**
     * Clamp a value into [0, 1].
     *
     * @param float $v
     * @return float
     */
    private static function clamp01(float $v): float {
        if ($v < 0.0) {
            return 0.0;
        }
        if ($v > 1.0) {
            return 1.0;
        }
        return $v;
    }
}
