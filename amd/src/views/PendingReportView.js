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
 * Full-page React view for pages/pending_report.php — redesigned for MVP3.
 *
 * Composition (top → bottom):
 *   1. Breadcrumb (back link + current crumb)
 *   2. Title row (course name H1 + overall status pill)
 *   3. Hero row — 4 cards: Score / Effective / SLA / Trend
 *   4. PausedCallout — transparency about excluded paused periods
 *   5. StatusDistributionBar — click a segment to filter the table
 *   6. Toolbar — class select, segmented status filter, sort select, search, refresh
 *   7. Table — Student / Activity / Class / Submitted / Effective / Perceived / Status / Action
 *
 * State split is unchanged from MVP2:
 *   - Server-driven (re-fetches WS):  groupid, bucket, serverSort, page
 *   - Client-only (no fetch):         search, clientSortKey, clientSortOrder
 *
 * @module    block_feedback_tracker/views/PendingReportView
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html, useState, useMemo, useEffect, useRef} from 'block_feedback_tracker/lib/preact';
import Badge from 'block_feedback_tracker/components/Badge';
import ScoreRing from 'block_feedback_tracker/components/ScoreRing';
import Sparkline from 'block_feedback_tracker/components/Sparkline';
import PausedCallout from 'block_feedback_tracker/components/PausedCallout';
import StatusDistributionBar from 'block_feedback_tracker/components/StatusDistributionBar';
import HeroMetricCard from 'block_feedback_tracker/components/HeroMetricCard';
import SegmentedFilter from 'block_feedback_tracker/components/SegmentedFilter';
import {bandForScore, colourFor} from 'block_feedback_tracker/lib/bands';
import {getPendingSubmissions} from 'block_feedback_tracker/lib/api';
import {formatHours, formatDate, formatPercent} from 'block_feedback_tracker/lib/format';
import * as PauseTimelineModal from 'block_feedback_tracker/components/PauseTimelineModal';

/**
 * Threshold (in business hours) above which the difference between effective
 * and perceived is meaningful enough to surface a "paused" tag on the row.
 */
const PAUSED_TAG_EPSILON = 0.5;

/**
 * Convert effective hours to a rough calendar-day approximation. Uses an
 * 8-hour business day (matches the install.php seed); we round up to keep
 * "perceived" honest (a 1-hour wait still feels like "today").
 *
 * @param {number|null|undefined} effectivehours
 * @returns {string} Formatted "Nd" or "—".
 */
const formatPerceivedDays = (effectivehours) => {
    if (effectivehours === null || effectivehours === undefined) {
        return '—';
    }
    const n = Number(effectivehours);
    if (!Number.isFinite(n) || n <= 0) {
        return '0d';
    }
    const days = Math.max(1, Math.round(n / 24 * 1.35));
    return days + 'd';
};

/**
 * Apply the client-side search + sort to a fetched page of submissions.
 * Pure — no side effects.
 *
 * @param {Array<object>} rows
 * @param {string} search
 * @param {string|null} sortKey
 * @param {string} sortOrder  'asc' or 'desc'
 * @returns {Array<object>}
 */
const decorate = (rows, search, sortKey, sortOrder) => {
    const needle = (search || '').trim().toLowerCase();
    let out = Array.isArray(rows) ? rows.slice() : [];
    if (needle.length > 0) {
        out = out.filter((r) => {
            const hay = (r.studentname + ' ' + r.activityname + ' ' + (r.groupname || '')).toLowerCase();
            return hay.indexOf(needle) !== -1;
        });
    }
    if (sortKey) {
        const dir = sortOrder === 'asc' ? 1 : -1;
        const numeric = sortKey === 'timesubmitted' || sortKey === 'waitinghours' || sortKey === 'effectivehours';
        out.sort((a, b) => {
            const va = a[sortKey];
            const vb = b[sortKey];
            if (numeric) {
                return ((Number(va) || 0) - (Number(vb) || 0)) * dir;
            }
            return String(va || '').localeCompare(String(vb || '')) * dir;
        });
    }
    return out;
};

/**
 * Header cell — renders the column label as a clickable button that
 * toggles client-side sort on the bound key. Stateless; emits onClick.
 *
 * @param {object} props
 * @param {string} props.label         Column label.
 * @param {string} props.sortKey       Key this header sorts by.
 * @param {string|null} props.currentKey Currently-active sort key.
 * @param {string} props.currentOrder  'asc' or 'desc' on the active key.
 * @param {Function} props.onClick     Callback fired with sortKey on click.
 * @returns {object} vnode
 */
const SortableHeader = ({label, sortKey, currentKey, currentOrder, onClick}) => {
    const active = currentKey === sortKey;
    const arrow = active ? (currentOrder === 'asc' ? ' ▲' : ' ▼') : '';
    const ariaSort = active ? (currentOrder === 'asc' ? 'ascending' : 'descending') : 'none';
    return html`
        <th class=${'bft-th-sortable' + (active ? ' is-active' : '')}>
            <button type="button"
                    class="bft-th-sortable-btn"
                    onClick=${() => onClick(sortKey)}
                    aria-sort=${ariaSort}>
                ${label}${arrow}
            </button>
        </th>
    `;
};

/**
 * Bucket counts grouped by band slug.
 *
 * @param {Array<object>} rows
 * @returns {Object<string, number>}
 */
const countByBand = (rows) => {
    const out = {excellent: 0, good: 0, regular: 0, critical: 0};
    if (!Array.isArray(rows)) {
        return out;
    }
    for (const r of rows) {
        const b = r.slabucket;
        if (out[b] !== undefined) {
            out[b]++;
        }
    }
    return out;
};

/**
 * Top-level view.
 *
 * @param {object} props
 * @param {object} props.initial  Mount-point payload: {courseid, coursename,
 *                                pending, groups, scope, i18n, config}.
 * @returns {object} vnode
 */
export default function PendingReportView({initial}) {
    const i18n = initial.i18n || {};
    const config = initial.config || {};
    const scoreThresholds = config.score_thresholds || null;
    const courseid = Number(initial.courseid) || 0;
    const availableGroups = Array.isArray(initial.groups) ? initial.groups : [];
    const initialPending = initial.pending || {};
    const scope = initial.scope || null;

    // --- Server-driven filters (each change refetches the WS). ---
    const [page, setPage] = useState(Number(initialPending.page) || 0);
    const [perpage] = useState(Number(initialPending.perpage) || 25);
    const [bucket, setBucket] = useState(String(initialPending.bucket || ''));
    const [band, setBand] = useState(String(initialPending.band || ''));
    const [groupid, setGroupid] = useState(Number(initialPending.groupid) || 0);
    const [serverSort, setServerSort] = useState(String(initialPending.sort || 'longestwait'));

    // --- Page data, refreshed by every WS call. ---
    const [submissions, setSubmissions] = useState(
        Array.isArray(initialPending.submissions) ? initialPending.submissions : []
    );
    const [total, setTotal] = useState(Number(initialPending.total) || 0);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);

    // Drafts (not-yet-submitted work) — a separate, de-emphasised list that
    // never counts toward the SLA. Seeded server-side; re-fetched only when
    // the class filter changes (bucket / sort / paging don't apply to drafts).
    const initialDrafts = initial.drafts || {};
    const [drafts, setDrafts] = useState(
        Array.isArray(initialDrafts.submissions) ? initialDrafts.submissions : []
    );

    // --- Client-only state (no refetch). ---
    const [search, setSearch] = useState('');
    const [clientSortKey, setClientSortKey] = useState(null);
    const [clientSortOrder, setClientSortOrder] = useState('asc');

    // Skip the first fetch — the initial payload is already on the screen.
    const firstRun = useRef(true);

    /**
     * Fetch a page from the WS using the current server-side filters.
     */
    const fetchPage = async () => {
        setLoading(true);
        setError(null);
        try {
            const result = await getPendingSubmissions({
                courseid, groupid, bucket, band, sort: serverSort, page, perpage,
            });
            setSubmissions(Array.isArray(result.submissions) ? result.submissions : []);
            setTotal(Number(result.total) || 0);
        } catch (e) {
            setError(i18n.pendingreport_error || 'Failed to load.');
        } finally {
            setLoading(false);
        }
    };

    // Re-fetch when any server-driven knob changes.
    useEffect(() => {
        if (firstRun.current) {
            firstRun.current = false;
            return;
        }
        fetchPage();
    }, [groupid, bucket, band, serverSort, page]);

    // Drafts depend only on the class filter. Re-fetch on groupid change; the
    // initial list is already seeded from the server payload.
    const firstRunDrafts = useRef(true);
    const fetchDrafts = async () => {
        try {
            const result = await getPendingSubmissions({
                courseid, groupid, status: 'draft', sort: 'recent', perpage,
            });
            setDrafts(Array.isArray(result.submissions) ? result.submissions : []);
        } catch (e) {
            // Drafts are a secondary aid — the main list already surfaces load
            // failures, so fail quiet here.
            setDrafts([]);
        }
    };
    useEffect(() => {
        if (firstRunDrafts.current) {
            firstRunDrafts.current = false;
            return;
        }
        fetchDrafts();
    }, [groupid]);

    // Derived: filtered + client-sorted submissions + distribution counts.
    const visible = useMemo(
        () => decorate(submissions, search, clientSortKey, clientSortOrder),
        [submissions, search, clientSortKey, clientSortOrder]
    );
    const distCounts = useMemo(() => countByBand(submissions), [submissions]);
    const visibleDrafts = useMemo(
        () => decorate(drafts, search, null, 'asc'),
        [drafts, search]
    );

    const totalPages = Math.max(1, Math.ceil(total / perpage));

    /**
     * Toggle client-side sort on the clicked column header.
     *
     * @param {string} key
     */
    const handleHeaderClick = (key) => {
        if (clientSortKey === key) {
            setClientSortOrder(clientSortOrder === 'asc' ? 'desc' : 'asc');
        } else {
            setClientSortKey(key);
            setClientSortOrder('asc');
        }
    };

    /**
     * Open the pause timeline modal for one row.
     *
     * @param {object} submission
     */
    const openTimeline = (submission) => {
        PauseTimelineModal.open({submission, i18n});
    };

    // --- Scope-derived hero values. ---
    const scopeScore = scope && scope.score !== null && scope.score !== undefined
        ? Number(scope.score) : null;
    const scopeBand = (scope && scope.band) || bandForScore(scopeScore, scoreThresholds);
    const scopeBandLabel = (i18n.bands || {})[scopeBand] || '';
    const trendSeries = (scope && Array.isArray(scope.trend_series))
        ? scope.trend_series.map((p) => (p && p.value !== null && p.value !== undefined ? Number(p.value) : null))
        : [];
    const trendPct = scope && scope.trend_pct_30d !== null && scope.trend_pct_30d !== undefined
        ? Number(scope.trend_pct_30d) : null;
    const trendTone = trendPct === null || Math.abs(trendPct) < 2
        ? 'pending'
        : trendPct < 0 ? 'excellent' : 'critical';
    const effective = scope && scope.median_eff_h !== null && scope.median_eff_h !== undefined
        ? Number(scope.median_eff_h) : null;
    const perceivedDays = formatPerceivedDays(effective);

    // --- Status filter options. ---
    const labels = i18n.bands || {};
    const statusOptions = [
        {value: '',          label: i18n.pendingreport_filter_bucket_all || 'All'},
        {value: 'excellent', label: labels.excellent || 'Excellent', tone: 'excellent'},
        {value: 'good',      label: labels.good      || 'Good',      tone: 'good'},
        {value: 'regular',   label: labels.regular   || 'Up Next',   tone: 'regular'},
        {value: 'critical',  label: labels.critical  || 'Priority',  tone: 'critical'},
    ];

    // Build a course-page URL for the "← Block" breadcrumb.
    // eslint-disable-next-line no-undef
    const wwwroot = (typeof M !== 'undefined' && M.cfg && M.cfg.wwwroot) || '';
    const courseUrl = wwwroot + '/course/view.php?id=' + encodeURIComponent(String(courseid));

    return html`
        <div class="bft-report">
            <nav class="bft-breadcrumb" aria-label="breadcrumb">
                <a class="bft-crumb-link" href=${courseUrl}>
                    ${i18n.pendingreport_breadcrumb_block || '← Block'}
                </a>
                <span class="bft-crumb-sep">/</span>
                <span class="bft-crumb-current">
                    ${i18n.pendingreport_crumb_current || 'Pending grading · detailed report'}
                </span>
            </nav>

            <div class="bft-report-title-row">
                <div class="bft-report-title-text">
                    <h1 class="bft-report-h1">${initial.coursename}</h1>
                    <div class="bft-report-subline">
                        <strong>${total}</strong>
                        ${' ' + (i18n.pendingreport_search_placeholder ? '' : '')}
                        ${availableGroups.length > 0 && html`
                            <span class="bft-report-subline-classes">
                                · ${availableGroups.length} ${availableGroups.length === 1 ? 'class' : 'classes'}
                            </span>
                        `}
                    </div>
                </div>
                ${scopeBand && scopeBandLabel && html`
                    <${Badge} band=${scopeBand} label=${scopeBandLabel} />
                `}
            </div>

            ${scope && html`
                <div class="bft-hero-row">
                    <${HeroMetricCard}
                        wide=${true}
                        eyebrow=${i18n.hero_score_eyebrow || 'Academic Responsiveness'}
                        tip=${i18n.hero_score_tip}>
                        <div class="bft-hero-score">
                            <${ScoreRing} score=${scopeScore} band=${scopeBand} size=${72} thickness=${7} />
                            <div class="bft-hero-score-text">
                                <div class="bft-hero-score-row">
                                    <span class=${'bft-hero-score-val bft-mono bft-overall-score-tone-' + scopeBand}>
                                        ${scopeScore === null ? '—' : Math.round(scopeScore)}
                                    </span>
                                    <span class="bft-hero-score-of bft-mono">/ 100</span>
                                </div>
                                <span class="bft-hero-score-note">
                                    ${scopeBandLabel} · ${i18n.hero_score_note || 'business-time'}
                                </span>
                            </div>
                        </div>
                    <//>
                    <${HeroMetricCard}
                        eyebrow=${i18n.hero_effective_eyebrow || 'Effective feedback time'}
                        tip=${i18n.hero_effective_tip}>
                        <div class="bft-hero-kpi">
                            <span class=${'bft-hero-kpi-val bft-mono bft-overall-score-tone-' + scopeBand}>
                                ${effective === null ? '—' : formatHours(effective)}
                            </span>
                            <span class="bft-hero-kpi-unit">${i18n.hero_effective_unit || 'business hrs'}</span>
                        </div>
                        <div class="bft-hero-kpi-sub">
                            <span class="bft-hero-kpi-sub-label">${i18n.hero_perceived_label || 'Perceived wait'}</span>
                            <span class="bft-hero-kpi-sub-value bft-mono">
                                ${perceivedDays} ${i18n.hero_perceived_unit || 'calendar days'}
                            </span>
                        </div>
                    <//>
                    <${HeroMetricCard}
                        eyebrow=${i18n.hero_sla_eyebrow || 'SLA compliance'}
                        tip=${i18n.hero_sla_tip}>
                        <div class="bft-hero-kpi">
                            <span class=${'bft-hero-kpi-val bft-mono bft-overall-score-tone-' + scopeBand}>
                                ${scope.compliance_pct === null || scope.compliance_pct === undefined
                                    ? '—' : formatPercent(scope.compliance_pct)}
                            </span>
                            <span class="bft-hero-kpi-unit">${i18n.hero_sla_unit || 'within'}</span>
                        </div>
                        <div class="bft-hero-kpi-chips">
                            ${(scope.total_overgoal || 0) > 0 && html`
                                <span class="bft-hero-chip bft-hero-chip-regular">
                                    ${scope.total_overgoal} ${i18n.hero_sla_atrisk || 'at risk'}
                                </span>
                            `}
                            ${(scope.total_critical || 0) > 0 && html`
                                <span class="bft-hero-chip bft-hero-chip-critical">
                                    ${scope.total_critical} ${i18n.hero_sla_critical || 'critical'}
                                </span>
                            `}
                        </div>
                    <//>
                    <${HeroMetricCard}
                        eyebrow=${i18n.hero_trend_eyebrow || 'Trend'}
                        tip=${i18n.hero_trend_tip}>
                        <div class="bft-hero-kpi">
                            <span class=${'bft-hero-kpi-val bft-mono bft-overall-score-tone-' + trendTone}>
                                ${trendPct === null ? '—' : (trendPct > 0 ? '+' : '') + Math.round(trendPct) + '%'}
                            </span>
                            <span class="bft-hero-kpi-unit">${i18n.hero_trend_unit || 'vs last month'}</span>
                        </div>
                        ${trendSeries.length > 0 && html`
                            <div class="bft-hero-spark">
                                <${Sparkline}
                                    values=${trendSeries}
                                    width=${120}
                                    height=${26} />
                            </div>
                        `}
                    <//>
                </div>
            `}

            ${scope && html`
                <${PausedCallout}
                    totaldays=${scope.paused_days_30d || 0}
                    breakdown=${scope.paused_breakdown_30d || {}}
                    events=${scope.paused_events_30d || []}
                    i18n=${i18n} />
            `}

            <${StatusDistributionBar}
                counts=${distCounts}
                activeband=${bucket}
                onSelect=${(b) => { setPage(0); setBucket(b); }}
                i18n=${i18n} />

            <div class="bft-report-controls">
                ${availableGroups.length > 1 && html`
                    <label class="bft-filter-label">
                        <span>${i18n.pendingreport_filter_class_label || 'Class'}</span>
                        <select value=${String(groupid)}
                                onChange=${(e) => { setPage(0); setGroupid(Number(e.target.value)); }}>
                            <option value="0">${i18n.pendingreport_filter_group_all || 'All groups'}</option>
                            ${availableGroups.map((g) => html`
                                <option value=${String(g.id)} key=${'g-' + g.id}>${g.name}</option>
                            `)}
                        </select>
                    </label>
                `}
                <div class="bft-filter-label">
                    <span>${i18n.pendingreport_filter_bucket_label || 'Status'}</span>
                    <${SegmentedFilter}
                        options=${statusOptions}
                        value=${bucket}
                        onChange=${(v) => { setPage(0); setBand(''); setBucket(v); }}
                        ariaLabel=${i18n.pendingreport_filter_bucket_label || 'Status'} />
                </div>
                <div class="bft-report-controls-spacer"></div>
                <input type="search"
                       class="bft-search"
                       placeholder=${i18n.pendingreport_search_placeholder || 'Search by name…'}
                       aria-label=${i18n.pendingreport_search_placeholder || 'Search by name'}
                       value=${search}
                       onInput=${(e) => setSearch(e.target.value)} />
                <label class="bft-filter-label">
                    <span>${i18n.pendingreport_filter_serversort_label || 'Order'}</span>
                    <select value=${serverSort}
                            onChange=${(e) => { setPage(0); setServerSort(e.target.value); }}>
                        <option value="longestwait">
                            ${i18n.pendingreport_serversort_longestwait || 'Longest wait first'}
                        </option>
                        <option value="recent">
                            ${i18n.pendingreport_serversort_recent || 'Most recent first'}
                        </option>
                    </select>
                </label>
                <button type="button"
                        class=${'bft-refresh' + (loading ? ' bft-refresh-busy' : '')}
                        disabled=${loading}
                        title=${i18n.card_refresh}
                        aria-label=${i18n.card_refresh}
                        onClick=${fetchPage}>⟳</button>
            </div>

            ${error && html`<div class="bft-error" role="alert">${error}</div>`}

            ${visible.length === 0 && !loading
                ? html`
                    <div class="bft-empty">
                        ${i18n.pendingreport_empty || 'No pending submissions match the current filter.'}
                    </div>
                `
                : html`
                    <table class="bft-report-table">
                        <thead>
                            <tr>
                                <${SortableHeader} label=${i18n.drilldown_col_student}
                                    sortKey="studentname" currentKey=${clientSortKey}
                                    currentOrder=${clientSortOrder} onClick=${handleHeaderClick} />
                                <${SortableHeader} label=${i18n.drilldown_col_activity}
                                    sortKey="activityname" currentKey=${clientSortKey}
                                    currentOrder=${clientSortOrder} onClick=${handleHeaderClick} />
                                <${SortableHeader} label=${(i18n.pendingreport_filter_class_label || 'Class')}
                                    sortKey="groupname" currentKey=${clientSortKey}
                                    currentOrder=${clientSortOrder} onClick=${handleHeaderClick} />
                                <${SortableHeader} label=${i18n.drilldown_col_submitted}
                                    sortKey="timesubmitted" currentKey=${clientSortKey}
                                    currentOrder=${clientSortOrder} onClick=${handleHeaderClick} />
                                <${SortableHeader} label=${i18n.pendingreport_col_effective || 'Effective'}
                                    sortKey="effectivehours" currentKey=${clientSortKey}
                                    currentOrder=${clientSortOrder} onClick=${handleHeaderClick} />
                                <${SortableHeader} label=${i18n.pendingreport_col_perceived || 'Perceived'}
                                    sortKey="waitinghours" currentKey=${clientSortKey}
                                    currentOrder=${clientSortOrder} onClick=${handleHeaderClick} />
                                <th>${i18n.drilldown_col_status}</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${visible.map((row) => {
                                const paused = Number(row.waitinghours || 0)
                                    - Number(row.effectivehours || 0) > PAUSED_TAG_EPSILON;
                                const rowColor = colourFor(row.slabucket);
                                return html`
                                    <tr class="bft-report-row"
                                        key=${'r-' + row.submissionid}
                                        tabindex="0"
                                        role="button"
                                        aria-label=${(row.studentname || '') + ' — ' + (row.activityname || '')}
                                        onClick=${() => openTimeline(row)}
                                        onKeyDown=${(e) => {
                                            if (e.key === 'Enter' || e.key === ' ') {
                                                e.preventDefault();
                                                openTimeline(row);
                                            }
                                        }}>
                                        <td>${row.studentname}</td>
                                        <td>${row.activityname}</td>
                                        <td>${row.groupname || '-'}</td>
                                        <td class="bft-mono">${formatDate(row.timesubmitted)}</td>
                                        <td class="bft-report-effective bft-mono"
                                            style=${'color: ' + rowColor + ';'}>
                                            ${formatHours(row.effectivehours)}
                                        </td>
                                        <td class="bft-report-perceived bft-mono">
                                            ${formatHours(row.waitinghours)}
                                            ${paused && html`
                                                <span class="bft-row-paused-tag"
                                                      title=${i18n.pendingreport_row_paused_tip || ''}>
                                                    <span class="bft-row-paused-swatch" aria-hidden="true"></span>
                                                    ${i18n.pendingreport_row_paused || 'paused'}
                                                </span>
                                            `}
                                        </td>
                                        <td>
                                            <${Badge} band=${row.slabucket}
                                                      label=${(i18n.bands || {})[row.slabucket] || row.slabucket} />
                                        </td>
                                    </tr>
                                `;
                            })}
                        </tbody>
                    </table>
                `}

            ${total > perpage && html`
                <div class="bft-pagination">
                    <button type="button"
                            disabled=${page === 0 || loading}
                            onClick=${() => setPage(Math.max(0, page - 1))}>
                        ${i18n.pendingreport_page_prev || '« Prev'}
                    </button>
                    <span class="bft-pagination-state">
                        ${(i18n.pendingreport_page_template || 'Page {$a->page} of {$a->total}')
                            .replace('{$a->page}', String(page + 1))
                            .replace('{$a->total}', String(totalPages))
                        }
                    </span>
                    <button type="button"
                            disabled=${page >= totalPages - 1 || loading}
                            onClick=${() => setPage(page + 1)}>
                        ${i18n.pendingreport_page_next || 'Next »'}
                    </button>
                </div>
            `}

            ${visibleDrafts.length > 0 && html`
                <div class="bft-report-drafts">
                    <h2 class="bft-report-drafts-heading">
                        ${i18n.drafts_heading || 'Not yet submitted'}
                    </h2>
                    ${i18n.drafts_note && html`
                        <p class="bft-report-drafts-note">${i18n.drafts_note}</p>
                    `}
                    <table class="bft-report-table bft-report-drafts-table">
                        <thead>
                            <tr>
                                <th>${i18n.drilldown_col_student}</th>
                                <th>${i18n.drilldown_col_activity}</th>
                                <th>${i18n.pendingreport_filter_class_label || 'Class'}</th>
                                <th>${i18n.drilldown_col_lastsaved || 'Last saved'}</th>
                                <th>${i18n.drilldown_col_status}</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${visibleDrafts.map((row) => html`
                                <tr class="bft-report-row bft-report-row--draft"
                                    key=${'d-' + row.submissionid}>
                                    <td>${row.studentname}</td>
                                    <td>${row.activityname}</td>
                                    <td>${row.groupname || '-'}</td>
                                    <td class="bft-mono">${formatDate(row.timesubmitted)}</td>
                                    <td>
                                        <span class="bft-badge bft-badge-draft">
                                            ${i18n.status_draft || 'Draft'}
                                        </span>
                                    </td>
                                </tr>
                            `)}
                        </tbody>
                    </table>
                </div>
            `}
        </div>
    `;
}
