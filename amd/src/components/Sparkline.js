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
 * Compact SVG line chart for a daily trend series.
 *
 * Direct port of templates/sparkline.mustache + classes/output/sparkline.php.
 * Null values represent "no data that day" and are skipped — the resulting
 * polyline runs through the days that do have values.
 *
 * @module    block_feedback_tracker/components/Sparkline
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';

/**
 * @param {object} props
 * @param {Array<number|null>} props.values  Ordered (oldest → newest).
 * @param {number|null} [props.goal]         Optional goal line.
 * @param {number} [props.width]
 * @param {number} [props.height]
 * @returns {object} vnode
 */
export default function Sparkline({values, goal = null, width = 120, height = 30}) {
    const valid = (values || []).filter((v) => v !== null && v !== undefined);
    if (valid.length === 0) {
        return html`
            <svg class="bft-sparkline"
                 viewBox=${'0 0 ' + width + ' ' + height}
                 width=${width} height=${height}
                 role="img" aria-label="30-day trend">
                <line x1="0" y1=${height} x2=${width} y2=${height}
                      stroke="#cbd5e1" stroke-width="0.5" />
            </svg>
        `;
    }

    const min = 0;
    let max = Math.max.apply(null, valid);
    if (goal !== null && goal !== undefined) {
        max = Math.max(max, Number(goal));
    }
    if (max <= min) {
        max = min + 1;
    }

    const n = values.length;
    const stride = n > 1 ? width / (n - 1) : 0;
    const points = [];
    values.forEach((v, i) => {
        if (v === null || v === undefined) {
            return;
        }
        const x = (i * stride).toFixed(2);
        const y = (height - ((Number(v) - min) / (max - min)) * height).toFixed(2);
        points.push(x + ',' + y);
    });

    const hasgoal = goal !== null && goal !== undefined;
    const goaly = hasgoal
        ? (height - ((Number(goal) - min) / (max - min)) * height).toFixed(2)
        : null;

    return html`
        <svg class="bft-sparkline"
             viewBox=${'0 0 ' + width + ' ' + height}
             width=${width} height=${height}
             role="img" aria-label="30-day trend">
            ${hasgoal && html`
                <line x1="0" y1=${goaly} x2=${width} y2=${goaly}
                      stroke="#cbd5e1" stroke-width="0.5" stroke-dasharray="2 2" />
            `}
            <polyline fill="none" stroke="#6366f1" stroke-width="1.5"
                      stroke-linecap="round" stroke-linejoin="round"
                      points=${points.join(' ')} />
        </svg>
    `;
}
