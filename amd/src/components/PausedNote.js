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
 * Single-line "paused today" indicator. Renders the current pause reason
 * (weekend / holiday / recess / outside hours) and the next scheduled pause.
 *
 * Both `current` and `next` are optional — when neither is set the component
 * renders nothing rather than an empty strip. The hatched swatch is a CSS
 * background gradient defined in styles.css.
 *
 * @module    block_feedback_tracker/components/PausedNote
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';

/**
 * @param {object} props
 * @param {string|null} [props.current]  Description of the active pause.
 * @param {string|null} [props.next]     Description of the upcoming pause.
 * @param {string} [props.label]         Optional eyebrow label.
 * @returns {object|null} vnode
 */
export default function PausedNote({current, next, label}) {
    if (!current && !next) {
        return null;
    }
    return html`
        <div class="bft-paused-note">
            <span class="bft-paused-swatch" aria-hidden="true"></span>
            <span class="bft-paused-text">
                ${label && html`<span class="bft-paused-eyebrow">${label}: </span>`}
                ${current && html`<strong>${current}</strong>`}
                ${current && next && ' · '}
                ${next && html`<span class="bft-paused-next">${next}</span>`}
            </span>
        </div>
    `;
}
