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
 * Per-group responsiveness card. Collapsible: a clickable header (chevron +
 * title/subtitle + band badge) toggles the body — hero (ring + score) → KPI
 * row → trend row → stat-tile row (the three exclusive pending bands) →
 * optional peer context → optional activities → drilldown foot.
 *
 * Local open/closed state only (default open). Props mirror
 * responsiveness_payload::group_payload(); optional fields hide gracefully.
 *
 * @module    block_feedback_tracker/components/GroupCard
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html, useState} from 'block_feedback_tracker/lib/preact';
import ScoreRing from 'block_feedback_tracker/components/ScoreRing';
import Badge from 'block_feedback_tracker/components/Badge';
import KpiTile from 'block_feedback_tracker/components/KpiTile';
import TrendRow from 'block_feedback_tracker/components/TrendRow';
import StatTile from 'block_feedback_tracker/components/StatTile';
import PeerContext from 'block_feedback_tracker/components/PeerContext';
import TimelineBar from 'block_feedback_tracker/components/TimelineBar';
import {colourFor} from 'block_feedback_tracker/lib/bands';

/**
 * Drilldown URL builder. Optional `band` pre-applies the pending-band filter
 * (aguardando / atencao / prioridade) on the report page.
 *
 * @param {number} courseid
 * @param {number} groupid
 * @param {string} [band]
 * @returns {string}
 */
const buildDrilldownUrl = (courseid, groupid, band) => {
    // eslint-disable-next-line no-undef
    const wwwroot = (typeof M !== 'undefined' && M.cfg && M.cfg.wwwroot) || '';
    const params = ['courseid=' + encodeURIComponent(String(courseid)),
                    'groupid=' + encodeURIComponent(String(groupid))];
    if (band) {
        params.push('band=' + encodeURIComponent(band));
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
    const [open, setOpen] = useState(true);
    const score = group.responsiveness_score !== null && group.responsiveness_score !== undefined
        ? Number(group.responsiveness_score) : null;
    const band = group.score_band || 'pending';
    const title = group.groupname && String(group.groupname).length > 0
        ? group.groupname
        : (group.coursename || i18n.card_nogroup);
    const subtitle = group.groupsubtitle && String(group.groupsubtitle).length > 0
        ? group.groupsubtitle : null;
    const bandLabel = i18n.bands && i18n.bands[band] ? i18n.bands[band] : '';
    const series = Array.isArray(group.trend_series)
        ? group.trend_series.map((p) => (p && p.value !== null && p.value !== undefined ? Number(p.value) : null))
        : [];
    const groupid = Number(group.groupid) || 0;
    const goal = config && config.sla_goal_hours ? Number(config.sla_goal_hours) : null;

    // Headline Effective / Perceived use the include-pending "current" medians
    // (cur_median_*) so the block reflects the live backlog, matching the
    // dashboard. The score keeps using graded-only median_eff_h.
    const perceived = group.cur_median_raw_h !== undefined && group.cur_median_raw_h !== null
        ? Number(group.cur_median_raw_h) : null;

    // Mutually-exclusive pending bands (sum = total pending): prioridade
    // (critical) | atenção (overgoal) | aguardando (the within-SLA remainder).
    const prioridade = Number(group.critical) || 0;
    const atencao = Number(group.overgoal) || 0;
    const aguardando = Math.max(0, (Number(group.pending) || 0) - atencao - prioridade);

    const allhref = buildDrilldownUrl(courseid, groupid);
    const aguardandohref = buildDrilldownUrl(courseid, groupid, 'aguardando');
    const atencaohref = buildDrilldownUrl(courseid, groupid, 'atencao');
    const prioridadehref = buildDrilldownUrl(courseid, groupid, 'prioridade');

    const hasActivities = Array.isArray(group.activities) && group.activities.length > 0;

    return html`
        <div class=${'bft-card' + (open ? ' bft-card-open' : '')}>
            <button type="button"
                    class="bft-card-head"
                    aria-expanded=${open ? 'true' : 'false'}
                    onClick=${() => setOpen(!open)}>
                <span class="bft-card-head-chevron" aria-hidden="true">${open ? '▾' : '▸'}</span>
                <span class="bft-card-head-titles">
                    <span class="bft-card-title" title=${title}>${title}</span>
                    ${subtitle && html`<span class="bft-card-subtitle">${subtitle}</span>`}
                </span>
                <${Badge} band=${band} label=${bandLabel} />
            </button>

            ${open && html`
                <div class="bft-card-stack">
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
                            value=${fmtHours(group.cur_median_eff_h)}
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
                            value=${aguardando}
                            tone="neutral"
                            href=${aguardandohref} />
                        <${StatTile}
                            label=${i18n.card_overgoal}
                            value=${atencao}
                            tone="warn"
                            href=${atencaohref} />
                        <${StatTile}
                            label=${i18n.card_critical}
                            value=${prioridade}
                            tone="critical"
                            href=${prioridadehref} />
                    </div>

                    <${PeerContext}
                        you=${score}
                        youband=${band}
                        department=${group.peer_department_score}
                        top10=${group.peer_top10_score}
                        i18n=${i18n} />

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
                        <a class="bft-drilldown" href=${allhref} style=${'color: ' + colourFor(band) + ';'}>
                            ${i18n.card_open_drilldown}
                        </a>
                    </div>
                </div>
            `}
        </div>
    `;
}
