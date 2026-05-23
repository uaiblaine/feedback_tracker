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
 * Teacher dashboard view (Phase 2D) — cross-course aggregation page.
 *
 * Three sections:
 *   - Hero: greeting, three KPI tiles (total Pending / Priority / Over
 *     goal), and an overall responsiveness score with band badge.
 *   - Courses table: one row per course, sortable client-side, row click
 *     navigates to pending_report.php?courseid=X.
 *   - School comparison: collapsible section that lazy-fetches
 *     get_school_comparison on first expand (no upfront cost).
 *
 * Initial payload (courses + greeting + i18n + config) comes from the
 * mount-point JSON so the first paint is data-rich.
 *
 * @module    block_feedback_tracker/views/DashboardView
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html, useState, useMemo} from 'block_feedback_tracker/lib/preact';
import Badge from 'block_feedback_tracker/components/Badge';
import ScoreGauge from 'block_feedback_tracker/components/ScoreGauge';
import {getDashboard, getSchoolComparison, getGraderPriorityList}
    from 'block_feedback_tracker/lib/api';
import {formatHours, formatPercent, formatNumber} from 'block_feedback_tracker/lib/format';
import {bandForScore} from 'block_feedback_tracker/lib/bands';
import GradeNowPanel from 'block_feedback_tracker/components/GradeNowPanel';

/**
 * Aggregate per-course rows into the three hero KPI numbers + overall
 * score.
 *
 * @param {Array<object>} courses
 * @returns {{pending: number, critical: number, overgoal: number,
 *            avgscore: number|null}}
 */
const aggregate = (courses) => {
    let pending = 0;
    let critical = 0;
    let overgoal = 0;
    let scoresum = 0;
    let scorecount = 0;
    (courses || []).forEach((c) => {
        pending += Number(c.pending) || 0;
        critical += Number(c.critical) || 0;
        overgoal += Number(c.overgoal) || 0;
        if (c.avgscore !== null && c.avgscore !== undefined) {
            scoresum += Number(c.avgscore);
            scorecount += 1;
        }
    });
    return {
        pending,
        critical,
        overgoal,
        avgscore: scorecount > 0 ? scoresum / scorecount : null,
    };
};

/**
 * Pure client-side sort for the courses table.
 *
 * @param {Array<object>} rows
 * @param {string|null} sortKey
 * @param {string} sortOrder  'asc' | 'desc'
 * @returns {Array<object>}
 */
const sortCourses = (rows, sortKey, sortOrder) => {
    if (!sortKey || !Array.isArray(rows)) {
        return rows;
    }
    const dir = sortOrder === 'asc' ? 1 : -1;
    const numeric = ['numgroups', 'pending', 'critical', 'overgoal', 'avgscore'];
    const numkey = numeric.indexOf(sortKey) !== -1;
    const copy = rows.slice();
    copy.sort((a, b) => {
        const va = a[sortKey];
        const vb = b[sortKey];
        if (numkey) {
            return ((Number(va) || 0) - (Number(vb) || 0)) * dir;
        }
        return String(va || '').localeCompare(String(vb || '')) * dir;
    });
    return copy;
};

/**
 * Build the deep-link to pages/pending_report.php for one course.
 *
 * @param {number} courseid
 * @returns {string}
 */
const courseReportUrl = (courseid) => {
    // eslint-disable-next-line no-undef
    const wwwroot = (typeof M !== 'undefined' && M.cfg && M.cfg.wwwroot) || '';
    return wwwroot + '/blocks/feedback_tracker/pages/pending_report.php'
        + '?courseid=' + encodeURIComponent(String(courseid));
};

/**
 * Sortable column header — same shape as PendingReportView's helper, kept
 * local so Phase 2D doesn't reach across views.
 *
 * @param {object} props
 * @param {string} props.label
 * @param {string} props.sortKey
 * @param {string|null} props.currentKey
 * @param {string} props.currentOrder
 * @param {Function} props.onClick
 * @returns {object} vnode
 */
const SortableHeader = ({label, sortKey, currentKey, currentOrder, onClick}) => {
    const active = currentKey === sortKey;
    const arrow = active ? (currentOrder === 'asc' ? ' ▲' : ' ▼') : '';
    const ariaSort = active ? (currentOrder === 'asc' ? 'ascending' : 'descending') : 'none';
    return html`
        <th class=${'bft-th-sortable' + (active ? ' is-active' : '')}>
            <button type="button" class="bft-th-sortable-btn"
                    onClick=${() => onClick(sortKey)} aria-sort=${ariaSort}>
                ${label}${arrow}
            </button>
        </th>
    `;
};

/**
 * Render one day's row in the comparison table.
 *
 * @param {object} props
 * @param {object} props.d  One entry from get_school_comparison.days[].
 * @returns {object} vnode
 */
const ComparisonRow = ({d}) => {
    // get_school_comparison returns `day` as YYYYMMDD integer.
    const ymd = String(d.day);
    const pretty = ymd.length === 8
        ? ymd.slice(0, 4) + '-' + ymd.slice(4, 6) + '-' + ymd.slice(6, 8)
        : ymd;
    return html`
        <tr key=${'d-' + d.day}>
            <td>${pretty}</td>
            <td>${formatHours(d.medianh_eff)}</td>
            <td>${formatHours(d.p10h_eff)}</td>
            <td>${formatHours(d.p90h_eff)}</td>
            <td>${formatPercent(d.compliance_pct_site)}</td>
            <td>${formatNumber(d.numgraded, 0)}</td>
        </tr>
    `;
};

/**
 * Top-level dashboard view.
 *
 * @param {object} props
 * @param {object} props.initial  Mount-point payload: {userid, greeting,
 *                                dashboard: {success, lastsynced, courses[]},
 *                                cancompare, i18n: {...}, config: {...}}.
 * @returns {object} vnode
 */
export default function DashboardView({initial}) {
    const i18n = initial.i18n || {};
    const dashboard = initial.dashboard || {};
    const canCompare = Boolean(initial.cancompare);

    const [courses, setCourses] = useState(
        Array.isArray(dashboard.courses) ? dashboard.courses : []
    );
    const [refreshing, setRefreshing] = useState(false);
    const [error, setError] = useState(null);
    const [sortKey, setSortKey] = useState('pending');
    const [sortOrder, setSortOrder] = useState('desc');
    const [comparisonOpen, setComparisonOpen] = useState(false);
    const [comparisonDays, setComparisonDays] = useState(null);
    const [comparisonLoading, setComparisonLoading] = useState(false);
    // Grade Now panel state — pre-loaded from the initial payload so the
    // first paint is data-rich, refreshed in sync with the courses table.
    const [gradenow, setGradenow] = useState(initial.gradenow || null);
    const [gradenowError, setGradenowError] = useState(null);

    const sorted = useMemo(() => sortCourses(courses, sortKey, sortOrder), [courses, sortKey, sortOrder]);
    const totals = useMemo(() => aggregate(courses), [courses]);
    const heroBand = bandForScore(totals.avgscore);

    /**
     * Refresh the dashboard payload via the WS.
     */
    const handleRefresh = async () => {
        if (refreshing) {
            return;
        }
        setRefreshing(true);
        setError(null);
        setGradenowError(null);
        // Two WS calls in parallel — the courses aggregate and the Grade
        // Now top-N list. Promise.allSettled keeps one failure from
        // dropping the other panel's update.
        const [dashRes, gradenowRes] = await Promise.allSettled([
            getDashboard({}),
            getGraderPriorityList({}),
        ]);
        if (dashRes.status === 'fulfilled'
            && dashRes.value && Array.isArray(dashRes.value.courses)) {
            setCourses(dashRes.value.courses);
        } else {
            setError(i18n.dashboard_error || 'Failed to load dashboard.');
        }
        if (gradenowRes.status === 'fulfilled' && gradenowRes.value) {
            setGradenow(gradenowRes.value);
        } else {
            setGradenowError(i18n.gradenow_error || 'Failed to load priority list.');
        }
        setRefreshing(false);
    };

    /**
     * Toggle the school-comparison section. First open triggers a fetch.
     */
    const toggleComparison = async () => {
        const opening = !comparisonOpen;
        setComparisonOpen(opening);
        if (!opening || comparisonDays !== null || comparisonLoading) {
            return;
        }
        setComparisonLoading(true);
        try {
            const result = await getSchoolComparison();
            setComparisonDays(Array.isArray(result && result.days) ? result.days : []);
        } catch (e) {
            setComparisonDays([]);
        } finally {
            setComparisonLoading(false);
        }
    };

    /**
     * Toggle client-side sort.
     *
     * @param {string} key
     */
    const handleHeaderClick = (key) => {
        if (sortKey === key) {
            setSortOrder(sortOrder === 'asc' ? 'desc' : 'asc');
        } else {
            setSortKey(key);
            // Numbers default to descending (most-pending-first feels right).
            const numeric = ['numgroups', 'pending', 'critical', 'overgoal', 'avgscore'];
            setSortOrder(numeric.indexOf(key) !== -1 ? 'desc' : 'asc');
        }
    };

    return html`
        <div class="bft-dashboard">
            <header class="bft-dashboard-hero">
                <div class="bft-dashboard-hero-text">
                    <h2 class="bft-dashboard-hero-greeting">${initial.greeting || ''}</h2>
                    <p class="bft-dashboard-hero-subtitle">${i18n.dashboard_hero_subtitle || ''}</p>
                </div>
                <div class="bft-dashboard-hero-stats">
                    <div class="bft-dashboard-kpi">
                        <span class="bft-dashboard-kpi-value">${totals.pending}</span>
                        <span class="bft-dashboard-kpi-label">${i18n.dashboard_kpi_pending}</span>
                    </div>
                    <div class="bft-dashboard-kpi">
                        <span class="bft-dashboard-kpi-value">${totals.critical}</span>
                        <span class="bft-dashboard-kpi-label">${i18n.dashboard_kpi_critical}</span>
                    </div>
                    <div class="bft-dashboard-kpi">
                        <span class="bft-dashboard-kpi-value">${totals.overgoal}</span>
                        <span class="bft-dashboard-kpi-label">${i18n.dashboard_kpi_overgoal}</span>
                    </div>
                    <div class="bft-dashboard-score">
                        <${ScoreGauge} score=${totals.avgscore}
                                        band=${heroBand}
                                        size=${140} />
                        <${Badge} band=${heroBand}
                                  label=${(i18n.bands || {})[heroBand] || heroBand} />
                    </div>
                    <button type="button"
                            class=${'bft-refresh' + (refreshing ? ' bft-refresh-busy' : '')}
                            disabled=${refreshing}
                            title=${i18n.dashboard_refresh || i18n.card_refresh}
                            aria-label=${i18n.dashboard_refresh || i18n.card_refresh}
                            onClick=${handleRefresh}>⟳</button>
                </div>
            </header>

            ${error && html`<div class="bft-error" role="alert">${error}</div>`}

            <${GradeNowPanel}
                data=${gradenow}
                loading=${refreshing}
                error=${gradenowError}
                i18n=${i18n} />

            <section class="bft-dashboard-section">
                <h3 class="bft-dashboard-section-title">${i18n.dashboard_courses_title}</h3>
                ${courses.length === 0
                    ? html`<div class="bft-empty">${i18n.dashboard_courses_empty}</div>`
                    : html`
                        <table class="bft-report-table">
                            <thead>
                                <tr>
                                    <${SortableHeader} label=${i18n.dashboard_col_course}
                                        sortKey="coursename" currentKey=${sortKey}
                                        currentOrder=${sortOrder} onClick=${handleHeaderClick} />
                                    <${SortableHeader} label=${i18n.dashboard_col_groups}
                                        sortKey="numgroups" currentKey=${sortKey}
                                        currentOrder=${sortOrder} onClick=${handleHeaderClick} />
                                    <${SortableHeader} label=${i18n.dashboard_col_pending}
                                        sortKey="pending" currentKey=${sortKey}
                                        currentOrder=${sortOrder} onClick=${handleHeaderClick} />
                                    <${SortableHeader} label=${i18n.dashboard_col_critical}
                                        sortKey="critical" currentKey=${sortKey}
                                        currentOrder=${sortOrder} onClick=${handleHeaderClick} />
                                    <${SortableHeader} label=${i18n.dashboard_col_overgoal}
                                        sortKey="overgoal" currentKey=${sortKey}
                                        currentOrder=${sortOrder} onClick=${handleHeaderClick} />
                                    <${SortableHeader} label=${i18n.dashboard_col_avgscore}
                                        sortKey="avgscore" currentKey=${sortKey}
                                        currentOrder=${sortOrder} onClick=${handleHeaderClick} />
                                </tr>
                            </thead>
                            <tbody>
                                ${sorted.map((row) => {
                                    const band = bandForScore(row.avgscore);
                                    const navigate = () => { window.location.href = courseReportUrl(row.courseid); };
                                    return html`
                                        <tr class="bft-report-row"
                                            key=${'c-' + row.courseid}
                                            tabindex="0"
                                            role="link"
                                            aria-label=${row.coursename}
                                            onClick=${navigate}
                                            onKeyDown=${(e) => {
                                                if (e.key === 'Enter' || e.key === ' ') {
                                                    e.preventDefault();
                                                    navigate();
                                                }
                                            }}>
                                            <td>${row.coursename}</td>
                                            <td>${row.numgroups}</td>
                                            <td>${row.pending}</td>
                                            <td>${row.critical}</td>
                                            <td>${row.overgoal}</td>
                                            <td>
                                                ${row.avgscore === null
                                                    ? '—'
                                                    : html`
                                                        <span class="bft-dashboard-score-inline">
                                                            ${Math.round(row.avgscore)}
                                                        </span>
                                                        <${Badge} band=${band}
                                                                  label=${(i18n.bands || {})[band] || band} />
                                                    `}
                                            </td>
                                        </tr>
                                    `;
                                })}
                            </tbody>
                        </table>
                    `}
            </section>

            ${canCompare && html`
                <section class="bft-dashboard-section">
                    <button type="button"
                            class="bft-breakdown-toggle"
                            aria-expanded=${comparisonOpen ? 'true' : 'false'}
                            onClick=${toggleComparison}>
                        ${(comparisonOpen ? '▾ ' : '▸ ') + (i18n.dashboard_comparison_title || 'Site benchmarks')}
                    </button>
                    ${comparisonOpen && html`
                        <p class="bft-dashboard-section-subtitle">
                            ${i18n.dashboard_comparison_subtitle || ''}
                        </p>
                        ${comparisonLoading
                            ? html`<div class="bft-empty">${i18n.dashboard_comparison_loading || 'Loading…'}</div>`
                            : (comparisonDays === null || comparisonDays.length === 0
                                ? html`<div class="bft-empty">${i18n.dashboard_comparison_empty || 'No data.'}</div>`
                                : html`
                                    <table class="bft-report-table">
                                        <thead>
                                            <tr>
                                                <th>${i18n.dashboard_comparison_col_day}</th>
                                                <th>${i18n.dashboard_comparison_col_median}</th>
                                                <th>${i18n.dashboard_comparison_col_p10}</th>
                                                <th>${i18n.dashboard_comparison_col_p90}</th>
                                                <th>${i18n.dashboard_comparison_col_compliance}</th>
                                                <th>${i18n.dashboard_comparison_col_graded}</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            ${comparisonDays.map((d) => html`
                                                <${ComparisonRow} key=${'d-' + d.day} d=${d} />
                                            `)}
                                        </tbody>
                                    </table>
                                `)
                        }
                    `}
                </section>
            `}
        </div>
    `;
}
