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
 * Trend row — arrow + percentage + verbal label + 30-day sparkline.
 *
 * The trend percentage in the payload is "% change in median effective
 * hours over the last 30 days". A negative number is improvement (hours
 * dropped), so the sign mapping is inverted: down = good (green), up = bad
 * (priority/red), within ±2% = flat (muted).
 *
 * @module    block_feedback_tracker/components/TrendRow
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';
import Sparkline from 'block_feedback_tracker/components/Sparkline';

/**
 * Classify a trend percentage into a tone slug.
 *
 * @param {number} pct
 * @returns {'improving'|'declining'|'stable'}
 */
const toneFor = (pct) => {
    if (Math.abs(pct) < 2) {
        return 'stable';
    }
    return pct < 0 ? 'improving' : 'declining';
};

/**
 * @param {object} props
 * @param {number|null|undefined} props.pct  Trend percentage; negative = improving.
 * @param {Array<number|null>} props.series  30-day sparkline values.
 * @param {object} props.i18n  Bundle with trend_improving / trend_declining / trend_stable / trend_window_label.
 * @param {number|null} [props.goal]  Optional SLA goal line on the sparkline.
 * @returns {object|null} vnode
 */
export default function TrendRow({pct, series, i18n, goal}) {
    const safepct = pct === null || pct === undefined || Number.isNaN(Number(pct))
        ? null : Number(pct);
    const tone = safepct === null ? 'stable' : toneFor(safepct);
    const arrow = tone === 'stable' ? '→' : tone === 'improving' ? '↓' : '↑';
    const labelmap = {
        improving: i18n.trend_improving || 'improving',
        declining: i18n.trend_declining || 'declining',
        stable:    i18n.trend_stable    || 'stable',
    };
    const pctText = safepct === null
        ? '—'
        : (safepct > 0 ? '+' : '') + Math.round(safepct) + '%';
    const hasSeries = Array.isArray(series) && series.some((v) => v !== null && v !== undefined);

    return html`
        <div class=${'bft-trend-row bft-trend-tone-' + tone}>
            <div class="bft-trend-row-text">
                <div class="bft-trend-row-eyebrow">${i18n.trend_window_label || 'Last 30 days'}</div>
                <div class="bft-trend-row-line">
                    <span class="bft-trend-row-arrow">${arrow}</span>
                    <span class="bft-trend-row-pct bft-mono">${pctText}</span>
                    <span class="bft-trend-row-label">${labelmap[tone]}</span>
                </div>
            </div>
            ${hasSeries && html`
                <div class="bft-trend-row-spark">
                    <${Sparkline} values=${series} goal=${goal} width=${96} height=${28} />
                </div>
            `}
        </div>
    `;
}
