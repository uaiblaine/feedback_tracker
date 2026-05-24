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
 * Small "hand wave" SVG mark used next to the dashboard greeting. Pure
 * presentation, no props beyond size — colour is owned by CSS via
 * currentColor on the parent.
 *
 * @module    block_feedback_tracker/components/WaveMark
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';

/**
 * @param {object} props
 * @param {number} [props.size]  Square pixel size; defaults to 24.
 * @returns {object} vnode
 */
export default function WaveMark({size = 24}) {
    return html`
        <svg width=${size} height=${size} viewBox="0 0 24 24"
             fill="none" stroke="currentColor" stroke-width="2"
             stroke-linecap="round" stroke-linejoin="round"
             class="bft-wave-mark" aria-hidden="true">
            <path d="M3 12 C 6 8, 9 16, 12 12 C 15 8, 18 16, 21 12" />
        </svg>
    `;
}
