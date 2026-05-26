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
 * Per-group responsiveness card. Phase 3B recomposition: header strip →
 * hero (ring + numeric score + caption) → KPI tile row → trend row → stat
 * tile row → optional peer context → breakdown panel → optional activities.
 *
 * Stateless except for whatever local toggles the children own. Props
 * mirror responsiveness_payload::group_payload() — peer + perceived +
 * activities fields are optional and the component hides those sections
 * gracefully until the server starts emitting them in Phase 3C.
 *
 * @module    block_feedback_tracker/components/GroupCard
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';
import ScoreRing from 'block_feedback_tracker/components/ScoreRing';
import Badge from 'block_feedback_tracker/components/Badge';
import KpiTile from 'block_feedback_tracker/components/KpiTile';
import TrendRow from 'block_feedback_tracker/components/TrendRow';
import StatTile from 'block_feedback_tracker/components/StatTile';
import PeerContext from 'block_feedback_tracker/components/PeerContext';
import BreakdownPanel from 'block_feedback_tracker/components/BreakdownPanel';
import TimelineBar from 'block_feedback_tracker/components/TimelineBar';
import {colourFor} from 'block_feedback_tracker/lib/bands';

/**
 * Build the breakdown sub-context if at least one component value is
 * populated. Mirrors classes/output/responsiveness_card.php::build_breakdown().
 *
 * @param {object} group
 * @param {object} i18n
 * @param {object} config Contains `weights` map (compliance, median, ...).
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
    const presentkeys = Object.keys(components).filter(
        (k) => components[k] !== null && components[k] !== undefined
    );
    if (presentkeys.length === 0) {
        return null;
    }
    const adminweights = (config && config.weights) || {};
    /*
     * Renormalise the admin-configured weights to only the terms that
     * carry data, mirroring the PHP responsiveness_calculator::
     * effective_weights() helper. Without this the displayed weights
     * wouldn't sum to 1.0 when the trend term is excluded — the table
     * is supposed to explain the score, so the maths must agree.
     */
    const keepsum = presentkeys.reduce(
        (s, k) => s + Number(adminweights[k] || 0), 0
    );
    const effective = {};
    Object.keys(components).forEach((k) => {
        const w = Number(adminweights[k] || 0);
        effective[k] = presentkeys.indexOf(k) !== -1 && keepsum > 0 ? w / keepsum : 0;
    });
    let totalpts = 0;
    let totalmax = 0;
    const rows = presentkeys.map((key) => {
        const value = components[key];
        const weight = effective[key];
        const maxpts = weight * 100;
        const pts = Number(value) * maxpts;
        totalpts += pts;
        totalmax += maxpts;
        return {
            label:     labels[key],
            valuestr:  Number(value).toFixed(2),
            weightstr: weight.toFixed(2),
            ptsstr:    pts.toFixed(1) + ' / ' + maxpts.toFixed(1),
        };
    });
    const excluded = Object.keys(components).filter((k) => presentkeys.indexOf(k) === -1);
    let footnote = '';
    if (excluded.length > 0) {
        const names = excluded.map((k) => labels[k]).join(', ');
        footnote = (i18n.breakdown_excluded_prefix || 'Excluded — insufficient data:')
            + ' ' + names + '.';
    }
    return {
        summary:   i18n.breakdown_summary,
        strterm:   i18n.breakdown_term,
        strvalue:  i18n.breakdown_value,
        strweight: i18n.breakdown_weight,
        strpts:    i18n.breakdown_pts,
        strtotal:  i18n.breakdown_total,
        rows,
        totalstr:  totalpts.toFixed(1) + ' / ' + totalmax.toFixed(1),
        footnote,
    };
};

/**
 * Drilldown URL builder. Optional `bucket` pre-applies the status filter on
 * the report page (so a StatTile click lands on the right rows directly).
 *
 * @param {number} courseid
 * @param {number} groupid
 * @param {string} [bucket]
 * @returns {string}
 */
const buildDrilldownUrl = (courseid, groupid, bucket) => {
    // eslint-disable-next-line no-undef
    const wwwroot = (typeof M !== 'undefined' && M.cfg && M.cfg.wwwroot) || '';
    const params = ['courseid=' + encodeURIComponent(String(courseid)),
                    'groupid=' + encodeURIComponent(String(groupid))];
    if (bucket) {
        params.push('bucket=' + encodeURIComponent(bucket));
    }
    return wwwroot + '/blocks/feedback_tracker/pages/pending_report.php?' + params.join('&');
};

/**
 * Format hours as "Xh" or "—".
 *
 * @param {number|null|undefined} h
 * @returns {string}
 */
const fmtHours = (h) => (h === null || h === undefined ? '—' : Math.round(Number(h)));

/**
 * Format days as "Xd" or "—".
 *
 * @param {number|null|undefined} h
 * @returns {string}
 */
const fmtDaysFromHours = (h) => (h === null || h === undefined ? '—' : Math.max(1, Math.round(Number(h) / 24)));

/**
 * Format compliance percentage as integer "X" (caller adds the % unit).
 *
 * @param {number|null|undefined} p
 * @returns {string}
 */
const fmtPct = (p) => (p === null || p === undefined ? '—' : Math.round(Number(p)));

/**
 * @param {object} props
 * @param {object} props.group      One element of payload.groups.
 * @param {number} props.courseid   Course id (for drilldown URLs).
 * @param {object} props.i18n       Localised label map.
 * @param {object} props.config     Block config (weights, sla_goal_hours, score_thresholds).
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
    const series = Array.isArray(group.trend_series)
        ? group.trend_series.map((p) => (p && p.value !== null && p.value !== undefined ? Number(p.value) : null))
        : [];
    const breakdown = buildBreakdown(group, i18n, config);
    const groupid = Number(group.groupid) || 0;
    const goal = config && config.sla_goal_hours ? Number(config.sla_goal_hours) : null;

    // Effective median (business hours) is always present; perceived comes
    // online in Phase 3C — until then render an em-dash so the slot is
    // visible but honest.
    const perceived = group.perceived_median_hours !== undefined && group.perceived_median_hours !== null
        ? Number(group.perceived_median_hours) : null;

    const pendingHref  = buildDrilldownUrl(courseid, groupid);
    const overgoalHref = buildDrilldownUrl(courseid, groupid, 'regular');
    const criticalHref = buildDrilldownUrl(courseid, groupid, 'critical');

    const hasActivities = Array.isArray(group.activities) && group.activities.length > 0;

    return html`
        <div class="bft-card">
            <div class="bft-card-head">
                <strong class="bft-card-title">${title}</strong>
                <${Badge} band=${band} label=${bandLabel} />
            </div>
            <div class="bft-card-hero">
                <${ScoreRing} score=${score} band=${band} size=${52} thickness=${5} />
                <div class="bft-card-hero-text">
                    <div class="bft-card-hero-score-row">
                        <span class=${'bft-card-hero-score bft-mono'
                            + ' bft-overall-score-tone-' + band}>
                            ${score === null ? '—' : Math.round(score)}
                        </span>
                        <span class="bft-card-hero-score-of bft-mono">/ 100</span>
                    </div>
                    <span class="bft-card-hero-caption">${i18n.card_score_caption}</span>
                </div>
            </div>

            <div class="bft-kpi-row">
                <${KpiTile}
                    label=${i18n.card_effective}
                    value=${fmtHours(group.median_eff_h)}
                    unit="h"
                    sub=${i18n.card_effective_sub}
                    tone=${band} />
                <${KpiTile}
                    label=${i18n.card_perceived}
                    value=${fmtDaysFromHours(perceived)}
                    unit="d"
                    sub=${i18n.card_perceived_sub}
                    tone="muted" />
                <${KpiTile}
                    label=${i18n.card_sla}
                    value=${fmtPct(group.compliance_pct)}
                    unit="%"
                    sub=${i18n.card_sla_sub}
                    tone=${band} />
            </div>

            <${TrendRow}
                pct=${group.trend_pct_30d}
                series=${series}
                i18n=${i18n}
                goal=${goal} />

            <div class="bft-stat-row">
                <${StatTile}
                    label=${i18n.card_pending}
                    value=${group.pending}
                    tone="neutral"
                    href=${pendingHref} />
                <${StatTile}
                    label=${i18n.card_overgoal}
                    value=${group.overgoal}
                    tone="warn"
                    href=${overgoalHref} />
                <${StatTile}
                    label=${i18n.card_critical}
                    value=${group.critical}
                    tone="critical"
                    href=${criticalHref} />
            </div>

            <${PeerContext}
                you=${score}
                youband=${band}
                youhours=${group.median_eff_h}
                department=${group.peer_department_score}
                departmenthours=${group.peer_department_hours}
                top10=${group.peer_top10_score}
                top10hours=${group.peer_top10_hours}
                i18n=${i18n} />

            ${breakdown && html`<${BreakdownPanel} ...${breakdown} />`}

            ${hasActivities && html`
                <div class="bft-activities">
                    <div class="bft-activities-head">${i18n.card_activities_head}</div>
                    ${group.activities.map((act) => html`
                        <div class="bft-activity" key=${'act-' + (act.id || act.cmid || act.name)}>
                            <div class="bft-activity-row">
                                <span class="bft-activity-title">${act.name}</span>
                                ${act.hasrule
                                    ? html`<span class="bft-activity-rule-on">${i18n.rule_on}</span>`
                                    : html`<span class="bft-activity-rule-off">${i18n.rule_off}</span>`}
                            </div>
                            <${TimelineBar}
                                opens=${act.opens}
                                closes=${act.closes}
                                norulelabel=${i18n.timeline_norule} />
                        </div>
                    `)}
                </div>
            `}

            <div class="bft-card-foot">
                <a class="bft-drilldown" href=${pendingHref} style=${'color: ' + colourFor(band) + ';'}>
                    ${i18n.card_open_drilldown}
                </a>
            </div>
        </div>
    `;
}
