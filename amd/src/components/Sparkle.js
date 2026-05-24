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
 * Decorative four-point star used in the dashboard hero and Insight cards.
 * Filled via CSS currentColor on the parent.
 *
 * @module    block_feedback_tracker/components/Sparkle
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';

/**
 * @param {object} props
 * @param {number} [props.size]  Square pixel size; defaults to 12.
 * @returns {object} vnode
 */
export default function Sparkle({size = 12}) {
    return html`
        <svg width=${size} height=${size} viewBox="0 0 24 24"
             fill="currentColor" class="bft-sparkle" aria-hidden="true">
            <path d="M12 2 L13.6 9.4 L21 11 L13.6 12.6 L12 20 L10.4 12.6 L3 11 L10.4 9.4 Z" />
        </svg>
    `;
}
