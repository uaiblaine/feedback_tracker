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
 * Per-group responsiveness card — composes the six Phase 2A atoms from a
 * single element of the 25-key group_payload shape.
 *
 * Stateless. The refresh button moved to BlockView (one per block, not one
 * per group), so this is purely presentational; the only locally-stateful
 * piece is the BreakdownPanel inside it, which holds its own open/closed
 * toggle.
 *
 * Props mirror what classes/local/payload/responsiveness_payload.php's
 * `group_payload()` returns; nothing in here transforms shape, so a future
 * payload-shape change ripples here in one place.
 *
 * @module    block_feedback_tracker/components/GroupCard
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';
import ScoreGauge from 'block_feedback_tracker/components/ScoreGauge';
import Sparkline from 'block_feedback_tracker/components/Sparkline';
import Badge from 'block_feedback_tracker/components/Badge';
import Counts from 'block_feedback_tracker/components/Counts';
import MetricsRow from 'block_feedback_tracker/components/MetricsRow';
import BreakdownPanel from 'block_feedback_tracker/components/BreakdownPanel';
import {formatHours, formatPercent, formatTrend} from 'block_feedback_tracker/lib/format';

/**
 * Build the localised counts row from a group_payload element.
 *
 * @param {object} group
 * @param {object} i18n
 * @returns {Array<{label: string, value: number}>}
 */
const buildCounts = (group, i18n) => [
    {label: i18n.card_pending,  value: Number(group.pending) || 0},
    {label: i18n.card_critical, value: Number(group.critical) || 0},
    {label: i18n.card_overgoal, value: Number(group.overgoal) || 0},
];

/**
 * Build the localised metrics row from a group_payload element.
 *
 * @param {object} group
 * @param {object} i18n
 * @returns {Array<{label: string, value: string}>}
 */
const buildMetrics = (group, i18n) => [
    {label: i18n.card_median_eff, value: formatHours(group.median_eff_h)},
    {label: i18n.card_compliance, value: formatPercent(group.compliance_pct)},
    {label: i18n.card_trend,      value: formatTrend(group.trend_pct_30d)},
];

/**
 * Build the trend-series values array Sparkline expects.
 *
 * @param {Array<{day: string, value: number|null}>|undefined} series
 * @returns {Array<number|null>}
 */
const buildSeries = (series) => Array.isArray(series)
    ? series.map((p) => (p && p.value !== null && p.value !== undefined ? Number(p.value) : null))
    : [];

/**
 * Build the breakdown sub-context if at least one component is populated.
 * Mirrors classes/output/responsiveness_card.php::build_breakdown().
 *
 * @param {object} group
 * @param {object} i18n
 * @param {object} config  Contains `weights` map (compliance, median, ...)
 * @returns {object|null} Props for BreakdownPanel, or null when no data.
 */
const buildBreakdown = (group, i18n, config) => {
    const components = {
        compliance: group.comp_compliance,
        median:     group.comp_median,
        critical:   group.comp_critical,
        pending:    group.comp_pending,
        trend:      group.comp_trend,
    };
    const labels = {
        compliance: i18n.breakdown_compliance,
        median:     i18n.breakdown_median,
        critical:   i18n.breakdown_critical,
        pending:    i18n.breakdown_pending,
        trend:      i18n.breakdown_trend,
    };
    const present = Object.values(components).filter((v) => v !== null && v !== undefined);
    if (present.length === 0) {
        return null;
    }
    const weights = (config && config.weights) || {};
    let totalpts = 0;
    let totalmax = 0;
    const rows = Object.keys(components).map((key) => {
        const value = components[key];
        const weight = Number(weights[key] || 0);
        const maxpts = weight * 100;
        const pts = value !== null && value !== undefined ? Number(value) * maxpts : 0;
        totalpts += pts;
        totalmax += maxpts;
        return {
            label:     labels[key],
            valuestr:  value !== null && value !== undefined ? Number(value).toFixed(2) : '—',
            weightstr: weight.toFixed(2),
            ptsstr:    pts.toFixed(1) + ' / ' + maxpts.toFixed(1),
        };
    });
    return {
        summary:   i18n.breakdown_summary,
        strterm:   i18n.breakdown_term,
        strvalue:  i18n.breakdown_value,
        strweight: i18n.breakdown_weight,
        strpts:    i18n.breakdown_pts,
        strtotal:  i18n.breakdown_total,
        rows,
        totalstr:  totalpts.toFixed(1) + ' / ' + totalmax.toFixed(1),
    };
};

/**
 * Build the drilldown URL pointing at the Phase 2C pending-report page.
 * Phase 2A pointed at the legacy group_drilldown.php; the new page is a
 * superset (filters / search / timeline modal) so it replaces it here.
 *
 * @param {number} courseid
 * @param {number} groupid
 * @returns {string}
 */
const buildDrilldownUrl = (courseid, groupid) => {
    // eslint-disable-next-line no-undef
    const wwwroot = (typeof M !== 'undefined' && M.cfg && M.cfg.wwwroot) || '';
    return wwwroot + '/blocks/feedback_tracker/pages/pending_report.php'
        + '?courseid=' + encodeURIComponent(String(courseid))
        + '&groupid=' + encodeURIComponent(String(groupid));
};

/**
 * One per-group card.
 *
 * @param {object} props
 * @param {object} props.group     One element of payload.groups (25-key shape).
 * @param {number} props.courseid  Course id (for the drilldown URL).
 * @param {object} props.i18n      Localised label map.
 * @param {object} props.config    Block config (weights, sla_goal_hours, ...).
 * @returns {object} vnode
 */
export default function GroupCard({group, courseid, i18n, config}) {
    const score = group.responsiveness_score !== null && group.responsiveness_score !== undefined
        ? Number(group.responsiveness_score) : null;
    const band = group.score_band || 'pending';
    const title = group.groupname && String(group.groupname).length > 0
        ? group.groupname
        : (group.coursename || i18n.card_nogroup);
    const bandLabel = i18n.bands && i18n.bands[band] ? i18n.bands[band] : '';
    const counts = buildCounts(group, i18n);
    const metrics = buildMetrics(group, i18n);
    const series = buildSeries(group.trend_series);
    const hasSeries = series.some((v) => v !== null);
    const breakdown = buildBreakdown(group, i18n, config);
    const drilldownUrl = buildDrilldownUrl(courseid, Number(group.groupid) || 0);
    const goal = config && config.sla_goal_hours ? Number(config.sla_goal_hours) : null;

    return html`
        <div class="bft-card">
            <div class="bft-card-head">
                <strong class="bft-card-title">${title}</strong>
                <${Badge} band=${band} label=${bandLabel} />
            </div>
            <div class="bft-card-body">
                <div class="bft-card-gauge">
                    <${ScoreGauge} score=${score} band=${band} size=${100} />
                </div>
                <div class="bft-card-data">
                    <${Counts} items=${counts} />
                    <${MetricsRow} items=${metrics} />
                    ${hasSeries && html`
                        <div class="bft-card-sparkline">
                            <${Sparkline} values=${series} goal=${goal} width=${240} height=${30} />
                        </div>
                    `}
                    ${breakdown && html`<${BreakdownPanel} ...${breakdown} />`}
                    <div class="bft-card-foot">
                        <a class="bft-drilldown" href=${drilldownUrl}>${i18n.card_open_drilldown}</a>
                    </div>
                </div>
            </div>
        </div>
    `;
}
