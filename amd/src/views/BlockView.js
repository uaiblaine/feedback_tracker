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
