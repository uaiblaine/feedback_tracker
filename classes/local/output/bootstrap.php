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
 * React bootstrap helpers — shared i18n + config bundles for the JS layer.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\output;

/**
 * Static helpers that build the JSON bundle every React-driven view
 * (block, pending report, teacher dashboard) embeds in its mount-point
 * `<script type="application/json" data-bft-init>` tag.
 *
 * Kept in classes/local/output/ so PSR-style autoloading picks them up
 * from standalone pages — the block class itself isn't autoloaded by
 * Moodle's class loader (block classes only load when the blocks
 * subsystem renders one).
 */
class bootstrap {
    /**
     * Localised label bundle used by every React view. Keys mirror the
     * Mustache template contexts so a server-rendered fallback (the
     * existing responsiveness_card.mustache, drilldown.mustache, …) and
     * the React tree consume identical strings.
     *
     * @return array
     */
    public static function i18n_bundle(): array {
        return [
            'card_pending' => get_string('card_pending', 'block_feedback_tracker'),
            'card_critical' => get_string('card_critical', 'block_feedback_tracker'),
            'card_overgoal' => get_string('card_overgoal', 'block_feedback_tracker'),
            'card_median_eff' => get_string('card_median_eff', 'block_feedback_tracker'),
            'card_compliance' => get_string('card_compliance', 'block_feedback_tracker'),
            'card_trend' => get_string('card_trend', 'block_feedback_tracker'),
            'card_refresh' => get_string('card_refresh', 'block_feedback_tracker'),
            'card_open_drilldown' => get_string('card_open_drilldown', 'block_feedback_tracker'),
            'card_empty' => get_string('card_empty', 'block_feedback_tracker'),
            'card_nogroup' => get_string('card_nogroup', 'block_feedback_tracker'),
            'bands' => [
                'excellent' => get_string('band_excellent', 'block_feedback_tracker'),
                'good'      => get_string('band_good', 'block_feedback_tracker'),
                'regular'   => get_string('band_regular', 'block_feedback_tracker'),
                'critical'  => get_string('band_critical', 'block_feedback_tracker'),
                'pending'   => get_string('band_pending', 'block_feedback_tracker'),
            ],
            'breakdown_summary' => get_string('breakdown_summary', 'block_feedback_tracker'),
            'breakdown_term' => get_string('breakdown_term', 'block_feedback_tracker'),
            'breakdown_value' => get_string('breakdown_value', 'block_feedback_tracker'),
            'breakdown_weight' => get_string('breakdown_weight', 'block_feedback_tracker'),
            'breakdown_pts' => get_string('breakdown_pts', 'block_feedback_tracker'),
            'breakdown_total' => get_string('breakdown_total', 'block_feedback_tracker'),
            'breakdown_compliance' => get_string('breakdown_compliance', 'block_feedback_tracker'),
            'breakdown_median' => get_string('breakdown_median', 'block_feedback_tracker'),
            'breakdown_critical' => get_string('breakdown_critical', 'block_feedback_tracker'),
            'breakdown_pending' => get_string('breakdown_pending', 'block_feedback_tracker'),
            'breakdown_trend' => get_string('breakdown_trend', 'block_feedback_tracker'),
            'block_sort_label' => get_string('block_sort_label', 'block_feedback_tracker'),
            'block_sort_default' => get_string('block_sort_default', 'block_feedback_tracker'),
            'block_sort_priority' => get_string('block_sort_priority', 'block_feedback_tracker'),
            'block_sort_wait' => get_string('block_sort_wait', 'block_feedback_tracker'),
            'block_refresh_error' => get_string('block_refresh_error', 'block_feedback_tracker'),
        ];
    }

    /**
     * Score-formula config bundle. Defaults match settings.php fallbacks
     * so a freshly-installed site renders correctly even before the admin
     * visits the settings page.
     *
     * @return array
     */
    public static function config_bundle(): array {
        return [
            'weights' => [
                'compliance' => (float) (get_config('block_feedback_tracker', 'weight_compliance') ?: 0.40),
                'median'     => (float) (get_config('block_feedback_tracker', 'weight_median') ?: 0.25),
                'critical'   => (float) (get_config('block_feedback_tracker', 'weight_critical') ?: 0.15),
                'pending'    => (float) (get_config('block_feedback_tracker', 'weight_pending') ?: 0.10),
                'trend'      => (float) (get_config('block_feedback_tracker', 'weight_trend') ?: 0.10),
            ],
            'sla_goal_hours' => (float) (get_config('block_feedback_tracker', 'sla_goal_hours') ?: 24),
        ];
    }

    /**
     * Teacher-dashboard string overlay. Merged on top of i18n_bundle()
     * by pages/teacher_dashboard.php so the hero, courses table, and
     * site-comparison section all share one localised label map.
     *
     * @return array
     */
    public static function dashboard_i18n(): array {
        return [
            'dashboard_title' => get_string('dashboard_title', 'block_feedback_tracker'),
            'dashboard_hero_subtitle' => get_string('dashboard_hero_subtitle', 'block_feedback_tracker'),
            'dashboard_kpi_pending' => get_string('dashboard_kpi_pending', 'block_feedback_tracker'),
            'dashboard_kpi_critical' => get_string('dashboard_kpi_critical', 'block_feedback_tracker'),
            'dashboard_kpi_overgoal' => get_string('dashboard_kpi_overgoal', 'block_feedback_tracker'),
            'dashboard_courses_title' => get_string('dashboard_courses_title', 'block_feedback_tracker'),
            'dashboard_courses_empty' => get_string('dashboard_courses_empty', 'block_feedback_tracker'),
            'dashboard_col_course' => get_string('dashboard_col_course', 'block_feedback_tracker'),
            'dashboard_col_groups' => get_string('dashboard_col_groups', 'block_feedback_tracker'),
            'dashboard_col_pending' => get_string('dashboard_col_pending', 'block_feedback_tracker'),
            'dashboard_col_critical' => get_string('dashboard_col_critical', 'block_feedback_tracker'),
            'dashboard_col_overgoal' => get_string('dashboard_col_overgoal', 'block_feedback_tracker'),
            'dashboard_col_avgscore' => get_string('dashboard_col_avgscore', 'block_feedback_tracker'),
            'dashboard_comparison_title' => get_string('dashboard_comparison_title', 'block_feedback_tracker'),
            'dashboard_comparison_subtitle' => get_string('dashboard_comparison_subtitle', 'block_feedback_tracker'),
            'dashboard_comparison_loading' => get_string('dashboard_comparison_loading', 'block_feedback_tracker'),
            'dashboard_comparison_empty' => get_string('dashboard_comparison_empty', 'block_feedback_tracker'),
            'dashboard_comparison_col_day' => get_string('dashboard_comparison_col_day', 'block_feedback_tracker'),
            'dashboard_comparison_col_median' => get_string('dashboard_comparison_col_median', 'block_feedback_tracker'),
            'dashboard_comparison_col_p10' => get_string('dashboard_comparison_col_p10', 'block_feedback_tracker'),
            'dashboard_comparison_col_p90' => get_string('dashboard_comparison_col_p90', 'block_feedback_tracker'),
            'dashboard_comparison_col_compliance' => get_string('dashboard_comparison_col_compliance', 'block_feedback_tracker'),
            'dashboard_comparison_col_graded' => get_string('dashboard_comparison_col_graded', 'block_feedback_tracker'),
            'dashboard_refresh' => get_string('dashboard_refresh', 'block_feedback_tracker'),
            'dashboard_error' => get_string('dashboard_error', 'block_feedback_tracker'),
            'gradenow_title' => get_string('gradenow_title', 'block_feedback_tracker'),
            'gradenow_subtitle' => get_string('gradenow_subtitle', 'block_feedback_tracker'),
            'gradenow_empty' => get_string('gradenow_empty', 'block_feedback_tracker'),
            'gradenow_loading' => get_string('gradenow_loading', 'block_feedback_tracker'),
            'gradenow_error' => get_string('gradenow_error', 'block_feedback_tracker'),
            'gradenow_open' => get_string('gradenow_open', 'block_feedback_tracker'),
        ];
    }

    /**
     * Pending-report-page string overlay. Merged on top of i18n_bundle()
     * by pages/pending_report.php so the React filter row / table /
     * timeline modal all share one localised label map.
     *
     * @return array
     */
    public static function pending_report_i18n(): array {
        return [
            'pendingreport_title' => get_string('pendingreport_title', 'block_feedback_tracker'),
            'pendingreport_empty' => get_string('pendingreport_empty', 'block_feedback_tracker'),
            'pendingreport_error' => get_string('pendingreport_error', 'block_feedback_tracker'),
            'pendingreport_loading' => get_string('pendingreport_loading', 'block_feedback_tracker'),
            'pendingreport_search_placeholder' => get_string('pendingreport_search_placeholder', 'block_feedback_tracker'),
            'pendingreport_filter_group_all' => get_string('pendingreport_filter_group_all', 'block_feedback_tracker'),
            'pendingreport_filter_group_label' => get_string('pendingreport_filter_group_label', 'block_feedback_tracker'),
            'pendingreport_filter_bucket_all' => get_string('pendingreport_filter_bucket_all', 'block_feedback_tracker'),
            'pendingreport_filter_bucket_label' => get_string('pendingreport_filter_bucket_label', 'block_feedback_tracker'),
            'pendingreport_filter_serversort_label' => get_string(
                'pendingreport_filter_serversort_label',
                'block_feedback_tracker'
            ),
            'pendingreport_serversort_longestwait' => get_string('pendingreport_serversort_longestwait', 'block_feedback_tracker'),
            'pendingreport_serversort_recent' => get_string('pendingreport_serversort_recent', 'block_feedback_tracker'),
            'pendingreport_page_prev' => get_string('pendingreport_page_prev', 'block_feedback_tracker'),
            'pendingreport_page_next' => get_string('pendingreport_page_next', 'block_feedback_tracker'),
            'pendingreport_page_template' => get_string('pendingreport_page_template', 'block_feedback_tracker'),
            'drilldown_col_student' => get_string('drilldown_col_student', 'block_feedback_tracker'),
            'drilldown_col_activity' => get_string('drilldown_col_activity', 'block_feedback_tracker'),
            'drilldown_col_group' => get_string('drilldown_col_group', 'block_feedback_tracker'),
            'drilldown_col_submitted' => get_string('drilldown_col_submitted', 'block_feedback_tracker'),
            'drilldown_col_waiting' => get_string('drilldown_col_waiting', 'block_feedback_tracker'),
            'drilldown_col_effective' => get_string('drilldown_col_effective', 'block_feedback_tracker'),
            'drilldown_col_status' => get_string('drilldown_col_status', 'block_feedback_tracker'),
            'modal_pauses_title' => get_string('modal_pauses_title', 'block_feedback_tracker'),
            'modal_pauses_empty' => get_string('modal_pauses_empty', 'block_feedback_tracker'),
            'modal_pauses_loading' => get_string('modal_pauses_loading', 'block_feedback_tracker'),
            'modal_pauses_error' => get_string('modal_pauses_error', 'block_feedback_tracker'),
            'modal_submittedat' => get_string('modal_submittedat', 'block_feedback_tracker'),
            'modal_effectivewait' => get_string('modal_effectivewait', 'block_feedback_tracker'),
            'modal_wallclockwait' => get_string('modal_wallclockwait', 'block_feedback_tracker'),
            'pause_reason_weekend' => get_string('pause_reason_weekend', 'block_feedback_tracker'),
            'pause_reason_holiday' => get_string('pause_reason_holiday', 'block_feedback_tracker'),
            'pause_reason_recess' => get_string('pause_reason_recess', 'block_feedback_tracker'),
            'pause_reason_closed' => get_string('pause_reason_closed', 'block_feedback_tracker'),
            'pause_reason_outofhours' => get_string('pause_reason_outofhours', 'block_feedback_tracker'),
            'pause_reason_coursepaused' => get_string('pause_reason_coursepaused', 'block_feedback_tracker'),
            'pause_reason_grouppaused' => get_string('pause_reason_grouppaused', 'block_feedback_tracker'),
            'pause_reason_sitepaused' => get_string('pause_reason_sitepaused', 'block_feedback_tracker'),
        ];
    }
}
