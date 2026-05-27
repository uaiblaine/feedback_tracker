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
 * Block-level React view — owns refresh + sort state for the in-course block.
 *
 * Initial groups come from the server-rendered mount-point payload so the
 * first paint is data-rich without any WS round-trip. Refresh fires
 * api.getResponsiveness({force: true}) and re-renders in place.
 *
 * One refresh button per block (not per-card — the WS is per-course so the
 * old per-card buttons all triggered the same fetch). Sort dropdown is
 * hidden when there's only one group to avoid noise.
 *
 * @module    block_feedback_tracker/views/BlockView
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html, useState, useMemo} from 'block_feedback_tracker/lib/preact';
import GroupCard from 'block_feedback_tracker/components/GroupCard';
import OverallBanner from 'block_feedback_tracker/components/OverallBanner';
import {bandForScore} from 'block_feedback_tracker/lib/bands';
import {getResponsiveness} from 'block_feedback_tracker/lib/api';

/**
 * Pure sort function. 'default' preserves the server's ordering (already
 * group-mode aware); other keys produce stable client-side reorderings.
 *
 * @param {Array<object>} groups
 * @param {string} sortKey
 * @returns {Array<object>}
 */
const sortGroups = (groups, sortKey) => {
    if (sortKey === 'default' || !Array.isArray(groups)) {
        return groups;
    }
    const copy = groups.slice();
    if (sortKey === 'priority') {
        copy.sort((a, b) => (Number(b.critical) || 0) - (Number(a.critical) || 0));
    } else if (sortKey === 'wait') {
        copy.sort((a, b) => (Number(b.median_eff_h) || 0) - (Number(a.median_eff_h) || 0));
    }
    return copy;
};

/**
 * Pending-weighted average of per-group scores. A group with no pending
 * work doesn't drag the headline figure — its score still counts but with
 * a minimum weight of 1 so courses early in the term don't end up with
 * "no overall score" simply because nobody has submitted yet.
 *
 * @param {Array<object>} groups
 * @param {{excellent?: number, good?: number, regular?: number}|null} thresholds
 * @returns {{score: number|null, band: string}}
 */
const overallScore = (groups, thresholds) => {
    if (!Array.isArray(groups) || groups.length === 0) {
        return {score: null, band: 'pending'};
    }
    let totalw = 0;
    let totalv = 0;
    groups.forEach((g) => {
        if (g.responsiveness_score === null || g.responsiveness_score === undefined) {
            return;
        }
        const weight = Math.max(1, Number(g.pending) || 0);
        totalv += Number(g.responsiveness_score) * weight;
        totalw += weight;
    });
    if (totalw === 0) {
        return {score: null, band: 'pending'};
    }
    const score = totalv / totalw;
    return {score, band: bandForScore(score, thresholds)};
};

/**
 * Format minutes-since-midnight as HH:MM (24-hour).
 *
 * @param {number} min
 * @returns {string}
 */
const fmtMin = (min) => {
    const h = Math.floor(min / 60);
    const m = min % 60;
    return String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
};

/**
 * Format a YYYYMMDD int as "DD/MM".
 *
 * @param {number} ymd
 * @returns {string}
 */
const fmtYmd = (ymd) => {
    const n = Number(ymd) || 0;
    const m = Math.floor((n / 100) % 100);
    const d = n % 100;
    return String(d).padStart(2, '0') + '/' + String(m).padStart(2, '0');
};

/**
 * Render an event entry's "DD/MM HH:MM-HH:MM: label" string.
 *
 * @param {{date:number, starttime:number, endtime:number, label:string}} ev
 * @returns {string}
 */
const fmtEvent = (ev) => {
    const head = fmtYmd(ev.date) + ' ' + fmtMin(ev.starttime) + '-' + fmtMin(ev.endtime);
    return ev.label ? head + ': ' + ev.label : head;
};

/**
 * Best-effort localiser for a pause-reason slug. Falls back to the slug
 * itself when the i18n bundle doesn't carry the key. Mirrors the
 * `pause_reason_*` family already shipped in pending_report_i18n().
 *
 * @param {string} reason
 * @param {object} i18n
 * @returns {string}
 */
const reasonLabel = (reason, i18n) => {
    const key = 'pause_reason_' + reason;
    return i18n[key] || reason;
};

/**
 * Pull the "paused current" + "next paused" strings from the most relevant
 * group's payload (any non-empty group works — the calendar is platform-wide).
 *
 * v1.0.11 — prefer the paused_events_30d sidecar when present so the
 * block surfaces the event date/window/label directly, independent of
 * the next/last pause rollup state (which may be stale until the drain
 * queue catches up). Falls back to nextpause_* / lastpause_* for
 * non-event pause reasons.
 *
 * @param {Array<object>} groups
 * @param {object} i18n
 * @returns {{current: string|null, next: string|null}}
 */
const pausedSummary = (groups, i18n) => {
    if (!Array.isArray(groups)) {
        return {current: null, next: null};
    }
    for (const g of groups) {
        // Event sidecar takes precedence — has full date + window + label
        // regardless of whether the rollup pause indicators are fresh.
        const events = Array.isArray(g.paused_events_30d) ? g.paused_events_30d : [];
        if (events.length > 0) {
            const latest = events[events.length - 1];
            return {
                current: fmtEvent(latest),
                next: null,
            };
        }
        const lastreason = g.lastpause_reason ? String(g.lastpause_reason) : null;
        const nextreason = g.nextpause_reason ? String(g.nextpause_reason) : null;
        if (!lastreason && !nextreason) {
            continue;
        }
        let current = lastreason ? reasonLabel(lastreason, i18n) : null;
        let next = nextreason ? reasonLabel(nextreason, i18n) : null;
        // Optional rows carry the event label in nextpause_note; surface
        // it on the upcoming line when the sidecar didn't already win.
        if (nextreason === 'optional' && g.nextpause_note) {
            next = next + ': ' + g.nextpause_note;
        }
        if (current || next) {
            return {current, next};
        }
    }
    return {current: null, next: null};
};

/**
 * Top-level block view.
 *
 * @param {object} props
 * @param {object} props.initial  Mount-point payload: {courseid, lastsynced,
 *                                groups, i18n: {bands, card_*, breakdown_*,
 *                                block_*}, config: {weights, sla_goal_hours}}.
 * @returns {object} vnode
 */
export default function BlockView({initial}) {
    const i18n = initial.i18n || {};
    const config = initial.config || {};
    const courseid = Number(initial.courseid) || 0;

    const [groups, setGroups] = useState(Array.isArray(initial.groups) ? initial.groups : []);
    const [sort, setSort] = useState('default');
    const [refreshing, setRefreshing] = useState(false);
    const [error, setError] = useState(null);

    const sorted = useMemo(() => sortGroups(groups, sort), [groups, sort]);
    const overall = useMemo(
        () => overallScore(groups, config && config.score_thresholds),
        [groups, config]
    );
    const paused = useMemo(() => pausedSummary(groups, i18n), [groups, i18n]);
    const overallBandLabel = i18n.bands && i18n.bands[overall.band] ? i18n.bands[overall.band] : '';

    const handleRefresh = async () => {
        if (refreshing) {
            return;
        }
        setRefreshing(true);
        setError(null);
        try {
            const result = await getResponsiveness({courseid, force: true});
            if (result && Array.isArray(result.groups)) {
                setGroups(result.groups);
            } else if (result && result.success === false) {
                setError(i18n.block_refresh_error || 'Refresh failed.');
            }
        } catch (e) {
            // getResponsiveness already routed the toast through core/notification;
            // surface a banner here so the user sees the state without scrolling.
            setError(i18n.block_refresh_error || 'Refresh failed.');
        } finally {
            setRefreshing(false);
        }
    };

    const showSort = groups.length > 1;

    return html`
        <div class="bft-block-root">
            <div class="bft-block-controls">
                <button type="button"
                        class=${'bft-refresh' + (refreshing ? ' bft-refresh-busy' : '')}
                        disabled=${refreshing}
                        title=${i18n.card_refresh}
                        aria-label=${i18n.card_refresh}
                        onClick=${handleRefresh}>⟳</button>
                ${showSort && html`
                    <label class="bft-sort-label">
                        <span class="bft-sort-label-text">${i18n.block_sort_label}</span>
                        <select class="bft-sort-select"
                                value=${sort}
                                onChange=${(e) => setSort(e.target.value)}>
                            <option value="default">${i18n.block_sort_default}</option>
                            <option value="priority">${i18n.block_sort_priority}</option>
                            <option value="wait">${i18n.block_sort_wait}</option>
                        </select>
                    </label>
                `}
            </div>
            ${error && html`
                <div class="bft-error" role="alert">${error}</div>
            `}
            ${groups.length === 0
                ? html`<div class="bft-empty">${i18n.card_empty}</div>`
                : html`
                    <${OverallBanner}
                        score=${overall.score}
                        band=${overall.band}
                        bandlabel=${overallBandLabel}
                        i18n=${i18n}
                        pausedcurrent=${paused.current}
                        pausednext=${paused.next} />
                    <div class="bft-card-list">
                        ${sorted.map((group) => html`
                            <${GroupCard}
                                key=${'g-' + (group.groupid || 0)}
                                group=${group}
                                courseid=${courseid}
                                i18n=${i18n}
                                config=${config} />
                        `)}
                    </div>
                `}
        </div>
    `;
}
