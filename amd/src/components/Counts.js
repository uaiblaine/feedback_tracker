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
 * Pending / Priority / Over-goal counts row.
 *
 * Stateless. Props mirror the `counts` array built by
 * classes/output/responsiveness_card.php (label + value pairs).
 *
 * @module    block_feedback_tracker/components/Counts
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';

/**
 * @typedef {object} CountItem
 * @property {string} label
 * @property {number|string} value
 */

/**
 * @param {object} props
 * @param {Array<CountItem>} props.items
 * @returns {object} vnode
 */
export default function Counts({items}) {
    return html`
        <div class="bft-counts">
            ${(items || []).map((it) => html`
                <div class="bft-count" key=${it.label}>
                    <span class="bft-count-value">${it.value}</span>
                    <span class="bft-count-label">${it.label}</span>
                </div>
            `)}
        </div>
    `;
}
