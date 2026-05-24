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
 * Activity open/close timeline. Shows the position of "today" between an
 * activity's open and close timestamps as a coloured progress bar with
 * dates flanking the track.
 *
 * Renders an "EMPTY" pill when the activity has no open/close rule rather
 * than guessing — a "no rule" assignment is information, not an error.
 *
 * @module    block_feedback_tracker/components/TimelineBar
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';

/**
 * Format a unix-seconds timestamp as DD/MM.
 *
 * @param {number} ts
 * @returns {string}
 */
const fmtDay = (ts) => {
    const d = new Date(Number(ts) * 1000);
    const dd = String(d.getDate()).padStart(2, '0');
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    return dd + '/' + mm;
};

/**
 * Pick the urgency slug for the activity based on how close the close date is.
 *
 * @param {number} opens
 * @param {number} closes
 * @param {number} now
 * @returns {'on-track'|'soon'|'urgent'|'overdue'}
 */
const urgencyFor = (opens, closes, now) => {
    if (now >= closes) {
        return 'overdue';
    }
    const total = closes - opens;
    if (total <= 0) {
        return 'on-track';
    }
    const remainingpct = (closes - now) / total;
    if (remainingpct < 0.15) {
        return 'urgent';
    }
    if (remainingpct < 0.35) {
        return 'soon';
    }
    return 'on-track';
};

/**
 * @param {object} props
 * @param {number|null} props.opens   Unix seconds, or null when no rule.
 * @param {number|null} props.closes  Unix seconds, or null when no rule.
 * @param {string} [props.norulelabel]
 * @returns {object} vnode
 */
export default function TimelineBar({opens, closes, norulelabel}) {
    const safeopens = Number(opens) || 0;
    const safecloses = Number(closes) || 0;
    if (safeopens <= 0 || safecloses <= 0 || safecloses <= safeopens) {
        return html`
            <span class="bft-timeline-bar bft-timeline-bar-empty">
                ${norulelabel || 'no rule'}
            </span>
        `;
    }
    const now = Math.floor(Date.now() / 1000);
    const total = safecloses - safeopens;
    const elapsed = Math.max(0, Math.min(total, now - safeopens));
    const pct = (elapsed / total) * 100;
    const urgency = urgencyFor(safeopens, safecloses, now);

    return html`
        <div class=${'bft-timeline-bar bft-timeline-bar-' + urgency}>
            <span class="bft-timeline-bar-date bft-mono">${fmtDay(safeopens)}</span>
            <div class="bft-timeline-bar-track">
                <div class="bft-timeline-bar-fill"
                     style=${'width: ' + Math.min(100, pct).toFixed(1) + '%;'}></div>
                <div class="bft-timeline-bar-thumb"
                     style=${'left: calc(' + Math.min(100, pct).toFixed(1) + '% - 4px);'}></div>
            </div>
            <span class=${'bft-timeline-bar-date bft-mono'
                + (urgency === 'overdue' ? ' bft-timeline-bar-date-overdue' : '')}>
                ${fmtDay(safecloses)}
            </span>
        </div>
    `;
}
