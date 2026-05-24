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
 * Two-circle SVG ring. Track + dasharray-filled arc, no centred text.
 *
 * Sister to `ScoreGauge` which centres the numeric label inside the ring; this
 * variant is used where the score appears next to the ring (banner, hero row,
 * courses table) rather than inside it.
 *
 * @module    block_feedback_tracker/components/ScoreRing
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';
import {colourFor} from 'block_feedback_tracker/lib/bands';

/**
 * @param {object} props
 * @param {number|null} props.score   0..100, or null for "no data".
 * @param {string|null} props.band    Band slug — drives the stroke colour.
 * @param {number} [props.size]       Square pixel size; defaults to 52.
 * @param {number} [props.thickness]  Stroke width; defaults to 5.
 * @returns {object} vnode
 */
export default function ScoreRing({score, band, size = 52, thickness = 5}) {
    const r = (size - thickness) / 2;
    const c = 2 * Math.PI * r;
    const clamped = score === null || score === undefined ? null
        : Math.max(0, Math.min(100, Number(score)));
    const dash = clamped === null ? 0 : (clamped / 100) * c;
    const colour = colourFor(band);
    const cx = size / 2;

    return html`
        <svg class="bft-score-ring"
             viewBox="0 0 ${size} ${size}"
             width=${size} height=${size}
             role="img" aria-hidden="true">
            <circle cx=${cx} cy=${cx} r=${r.toFixed(2)}
                    fill="none" stroke="var(--bft-border-soft, #eef0f3)"
                    stroke-width=${thickness} />
            <circle cx=${cx} cy=${cx} r=${r.toFixed(2)}
                    fill="none" stroke=${colour}
                    stroke-width=${thickness}
                    stroke-linecap="round"
                    stroke-dasharray=${dash.toFixed(2) + ' ' + c.toFixed(2)}
                    transform=${'rotate(-90 ' + cx + ' ' + cx + ')'} />
        </svg>
    `;
}
