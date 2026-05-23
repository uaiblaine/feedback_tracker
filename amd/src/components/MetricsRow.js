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
 * Median / compliance / trend row.
 *
 * Stateless. Same label/value shape as Counts but rendered with the
 * .bft-metric* class family so the existing styles.css typography (smaller,
 * letter-spaced labels) applies.
 *
 * @module    block_feedback_tracker/components/MetricsRow
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';

/**
 * @typedef {object} MetricItem
 * @property {string} label
 * @property {string} value  Already-formatted (e.g. "12.3 h", "78%", "▲ 5%").
 */

/**
 * @param {object} props
 * @param {Array<MetricItem>} props.items
 * @returns {object} vnode
 */
export default function MetricsRow({items}) {
    return html`
        <div class="bft-metrics">
            ${(items || []).map((it) => html`
                <div class="bft-metric" key=${it.label}>
                    <span class="bft-metric-label">${it.label}</span>
                    <span class="bft-metric-value">${it.value}</span>
                </div>
            `)}
        </div>
    `;
}
