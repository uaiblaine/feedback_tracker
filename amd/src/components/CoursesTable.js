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
 * Dashboard courses table — one row per course with a small ScoreRing,
 * pending / critical / effective counts, and an inline 30-day sparkline.
 *
 * Stateless. The parent owns sort state + click navigation.
 *
 * @module    block_feedback_tracker/components/CoursesTable
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';
import ScoreRing from 'block_feedback_tracker/components/ScoreRing';
import Sparkline from 'block_feedback_tracker/components/Sparkline';
import {bandForScore, colourFor} from 'block_feedback_tracker/lib/bands';
import {formatHours} from 'block_feedback_tracker/lib/format';

/**
 * Header cell that toggles sort when clicked.
 *
 * @param {object} props
 * @param {string} props.label
 * @param {string} props.sortKey
 * @param {string|null} props.currentKey
 * @param {string} props.currentOrder
 * @param {Function} props.onClick
 * @returns {object} vnode
 */
const SortHeader = ({label, sortKey, currentKey, currentOrder, onClick}) => {
    const active = currentKey === sortKey;
    const arrow = active ? (currentOrder === 'asc' ? ' ▲' : ' ▼') : '';
    return html`
        <th class=${'bft-th-sortable' + (active ? ' is-active' : '')}>
            <button type="button"
                    class="bft-th-sortable-btn"
                    onClick=${() => onClick(sortKey)}>
                ${label}${arrow}
            </button>
        </th>
    `;
};

/**
 * @param {object} props
 * @param {Array<object>} props.rows  Sorted by the caller.
 * @param {object} props.i18n
 * @param {string|null} props.sortKey
 * @param {string} props.sortOrder
 * @param {Function} props.onSort     Receives sortKey on header click.
 * @param {object|null} props.thresholds
 * @returns {object} vnode
 */
export default function CoursesTable({rows, i18n, sortKey, sortOrder, onSort, thresholds}) {
    if (!Array.isArray(rows) || rows.length === 0) {
        return html`
            <div class="bft-empty">
                ${i18n.dashboard_courses_empty || 'No courses with feedback data yet.'}
            </div>
        `;
    }
    // eslint-disable-next-line no-undef
    const wwwroot = (typeof M !== 'undefined' && M.cfg && M.cfg.wwwroot) || '';
    const reportUrl = (cid) =>
        wwwroot + '/blocks/feedback_tracker/pages/pending_report.php?courseid='
        + encodeURIComponent(String(cid));

    return html`
        <table class="bft-courses-table">
            <thead>
                <tr>
                    <${SortHeader} label=${i18n.dashboard_col_course || 'Course'}
                        sortKey="coursename" currentKey=${sortKey} currentOrder=${sortOrder} onClick=${onSort} />
                    <${SortHeader} label=${i18n.dashboard_col_avgscore || 'Score'}
                        sortKey="avgscore" currentKey=${sortKey} currentOrder=${sortOrder} onClick=${onSort} />
                    <${SortHeader} label=${i18n.dashboard_col_pending || 'Pending'}
                        sortKey="pending" currentKey=${sortKey} currentOrder=${sortOrder} onClick=${onSort} />
                    <${SortHeader} label=${i18n.dashboard_col_critical || 'Priority'}
                        sortKey="critical" currentKey=${sortKey} currentOrder=${sortOrder} onClick=${onSort} />
                    <${SortHeader} label=${i18n.hero_effective_eyebrow || 'Effective'}
                        sortKey="median_eff_h" currentKey=${sortKey} currentOrder=${sortOrder} onClick=${onSort} />
                    <th>${i18n.trend_window_label || '30 days'}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                ${rows.map((row) => {
                    const isIdle = row.avgscore === null || row.avgscore === undefined;
                    const band = row.score_band || bandForScore(row.avgscore, thresholds);
                    const trendSeries = (Array.isArray(row.trend_series)
                        ? row.trend_series.map((p) =>
                            (p && p.value !== null && p.value !== undefined ? Number(p.value) : null))
                        : []);
                    const hasSeries = trendSeries.some((v) => v !== null);
                    const trendColour = colourFor(band);
                    return html`
                        <tr key=${'cr-' + row.courseid} class="bft-courses-row">
                            <td class="bft-courses-name">${row.coursename}</td>
                            <td class="bft-courses-score">
                                ${isIdle
                                    ? html`<span class="bft-courses-dim">—</span>`
                                    : html`
                                        <span class="bft-courses-score-inline">
                                            <${ScoreRing} score=${row.avgscore} band=${band} size=${26} thickness=${3} />
                                            <span class=${'bft-mono bft-overall-score-tone-' + band}>
                                                ${Math.round(Number(row.avgscore))}
                                            </span>
                                            <span class="bft-courses-score-of bft-mono">/ 100</span>
                                        </span>
                                    `}
                            </td>
                            <td class="bft-mono bft-courses-num">${Number(row.pending) || 0}</td>
                            <td class=${'bft-mono bft-courses-num'
                                + (Number(row.critical) > 0 ? ' bft-courses-num-alert' : '')}>
                                ${Number(row.critical) || 0}
                            </td>
                            <td class=${'bft-mono bft-courses-num bft-overall-score-tone-' + band}>
                                ${row.median_eff_h === null || row.median_eff_h === undefined
                                    ? '—'
                                    : formatHours(row.median_eff_h)}
                            </td>
                            <td class="bft-courses-spark">
                                ${hasSeries
                                    ? html`<${Sparkline}
                                              values=${trendSeries}
                                              width=${72}
                                              height=${20}
                                              color=${trendColour} />`
                                    : html`<span class="bft-courses-dim">—</span>`}
                            </td>
                            <td class="bft-courses-cta">
                                <a class="bft-courses-open" href=${reportUrl(row.courseid)}>
                                    ${i18n.dashboard_open_course || 'Open'}
                                </a>
                            </td>
                        </tr>
                    `;
                })}
            </tbody>
        </table>
    `;
}
