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
 * Full-page React view for pages/pending_report.php — paginated table of
 * pending submissions in a course, with server-side bucket / group / sort
 * filters and client-side search + column sort. Row click opens the pause
 * timeline modal.
 *
 * State split:
 *   - Server-driven (re-fetches WS):  groupid, bucket, serverSort, page
 *   - Client-only (no fetch):         search, clientSortKey, clientSortOrder
 *
 * Initial payload (submissions + total + filters) comes from the server-
 * rendered mount-point JSON so the first paint is data-rich.
 *
 * @module    block_feedback_tracker/views/PendingReportView
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html, useState, useMemo, useEffect, useRef} from 'block_feedback_tracker/lib/preact';
import Badge from 'block_feedback_tracker/components/Badge';
import {getPendingSubmissions} from 'block_feedback_tracker/lib/api';
import {formatHours, formatDate} from 'block_feedback_tracker/lib/format';
import * as PauseTimelineModal from 'block_feedback_tracker/components/PauseTimelineModal';

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
 * Top-level view. The initial payload mirrors the bootstrap shape used by
 * the block (see block_feedback_tracker.php::build_block_payload), with
 * the addition of a `pending` sub-object holding the first page of
 * submissions and a `groups` list for the group filter.
 *
 * @param {object} props
 * @param {object} props.initial  Mount-point payload: {courseid, coursename,
 *                                pending: {submissions, total, page, perpage,
 *                                lastsynced, bucket, groupid, sort},
 *                                groups: [{id, name}], i18n, config}.
 * @returns {object} vnode
 */
export default function PendingReportView({initial}) {
    const i18n = initial.i18n || {};
    const courseid = Number(initial.courseid) || 0;
    const availableGroups = Array.isArray(initial.groups) ? initial.groups : [];
    const initialPending = initial.pending || {};

    // --- Server-driven filters (each change refetches the WS). ---
    const [page, setPage] = useState(Number(initialPending.page) || 0);
    const [perpage] = useState(Number(initialPending.perpage) || 25);
    const [bucket, setBucket] = useState(String(initialPending.bucket || ''));
    const [groupid, setGroupid] = useState(Number(initialPending.groupid) || 0);
    const [serverSort, setServerSort] = useState(String(initialPending.sort || 'longestwait'));

    // --- Page data, refreshed by every WS call. ---
    const [submissions, setSubmissions] = useState(
        Array.isArray(initialPending.submissions) ? initialPending.submissions : []
    );
    const [total, setTotal] = useState(Number(initialPending.total) || 0);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState(null);

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
                courseid, groupid, bucket, sort: serverSort, page, perpage,
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
    }, [groupid, bucket, serverSort, page]);

    // Derived: filtered + client-sorted submissions.
    const visible = useMemo(
        () => decorate(submissions, search, clientSortKey, clientSortOrder),
        [submissions, search, clientSortKey, clientSortOrder]
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

    return html`
        <div class="bft-report">
            <div class="bft-report-controls">
                <input type="search"
                       class="bft-search"
                       placeholder=${i18n.pendingreport_search_placeholder || 'Search by name…'}
                       aria-label=${i18n.pendingreport_search_placeholder || 'Search by name'}
                       value=${search}
                       onInput=${(e) => setSearch(e.target.value)} />
                ${availableGroups.length > 1 && html`
                    <label class="bft-filter-label">
                        <span>${i18n.pendingreport_filter_group_label || 'Group'}</span>
                        <select value=${String(groupid)}
                                onChange=${(e) => { setPage(0); setGroupid(Number(e.target.value)); }}>
                            <option value="0">${i18n.pendingreport_filter_group_all || 'All groups'}</option>
                            ${availableGroups.map((g) => html`
                                <option value=${String(g.id)} key=${'g-' + g.id}>${g.name}</option>
                            `)}
                        </select>
                    </label>
                `}
                <label class="bft-filter-label">
                    <span>${i18n.pendingreport_filter_bucket_label || 'Bucket'}</span>
                    <select value=${bucket}
                            onChange=${(e) => { setPage(0); setBucket(e.target.value); }}>
                        <option value="">${i18n.pendingreport_filter_bucket_all || 'All bands'}</option>
                        <option value="excellent">${(i18n.bands || {}).excellent || 'Excellent'}</option>
                        <option value="good">${(i18n.bands || {}).good || 'Good'}</option>
                        <option value="regular">${(i18n.bands || {}).regular || 'Up Next'}</option>
                        <option value="critical">${(i18n.bands || {}).critical || 'Priority'}</option>
                    </select>
                </label>
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
                                <${SortableHeader} label=${i18n.drilldown_col_group}
                                    sortKey="groupname" currentKey=${clientSortKey}
                                    currentOrder=${clientSortOrder} onClick=${handleHeaderClick} />
                                <${SortableHeader} label=${i18n.drilldown_col_submitted}
                                    sortKey="timesubmitted" currentKey=${clientSortKey}
                                    currentOrder=${clientSortOrder} onClick=${handleHeaderClick} />
                                <${SortableHeader} label=${i18n.drilldown_col_waiting}
                                    sortKey="waitinghours" currentKey=${clientSortKey}
                                    currentOrder=${clientSortOrder} onClick=${handleHeaderClick} />
                                <${SortableHeader} label=${i18n.drilldown_col_effective}
                                    sortKey="effectivehours" currentKey=${clientSortKey}
                                    currentOrder=${clientSortOrder} onClick=${handleHeaderClick} />
                                <th>${i18n.drilldown_col_status}</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${visible.map((row) => html`
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
                                    <td>${formatDate(row.timesubmitted)}</td>
                                    <td>${formatHours(row.waitinghours)}</td>
                                    <td>${formatHours(row.effectivehours)}</td>
                                    <td>
                                        <${Badge} band=${row.slabucket}
                                                  label=${(i18n.bands || {})[row.slabucket] || row.slabucket} />
                                    </td>
                                </tr>
                            `)}
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
        </div>
    `;
}
