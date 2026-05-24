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
 * Status distribution bar. Four segmented buttons sized proportionally to
 * the count in each band (excellent / good / regular / critical). Click
 * a segment to filter the table to just that band.
 *
 * @module    block_feedback_tracker/components/StatusDistributionBar
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';

/** @type {string[]} Bands shown in the distribution; order matters (worst → best is reversed by design). */
const BANDS = ['excellent', 'good', 'regular', 'critical'];

/**
 * @param {object} props
 * @param {Object<string, number>} props.counts  {excellent, good, regular, critical}
 * @param {string} props.activeband              Currently filtered band ('' = none).
 * @param {(band: string) => void} props.onSelect Click handler — passes '' to clear.
 * @param {object} props.i18n                    Label bundle (bands, distribution_title, distribution_hint).
 * @returns {object|null} vnode
 */
export default function StatusDistributionBar({counts, activeband, onSelect, i18n}) {
    const safecounts = counts || {};
    const total = BANDS.reduce((sum, b) => sum + (Number(safecounts[b]) || 0), 0);
    if (total === 0) {
        return null;
    }
    const labels = i18n.bands || {};
    const titles = {
        excellent: labels.excellent || 'Excellent',
        good:      labels.good      || 'Good',
        regular:   labels.regular   || 'Up Next',
        critical:  labels.critical  || 'Priority',
    };
    return html`
        <div class="bft-dist">
            <div class="bft-dist-head">
                <span class="bft-dist-title">
                    ${i18n.distribution_title || 'Distribution by status'}
                </span>
                <span class="bft-dist-hint">
                    ${i18n.distribution_hint || 'Click a band to filter the table'}
                </span>
            </div>
            <div class="bft-dist-bar" role="group">
                ${BANDS.map((band) => {
                    const n = Number(safecounts[band]) || 0;
                    const pct = (n / total) * 100;
                    const dim = activeband !== '' && activeband !== band;
                    return html`
                        <button type="button"
                                key=${'d-' + band}
                                class=${'bft-dist-seg bft-dist-seg-' + band + (dim ? ' bft-dist-seg-dim' : '')}
                                style=${'flex: ' + Math.max(pct, 1).toFixed(2) + ' 1 0;'}
                                aria-pressed=${activeband === band}
                                aria-label=${titles[band] + ' — ' + n}
                                onClick=${() => onSelect(activeband === band ? '' : band)}>
                            <span class="bft-dist-seg-n bft-mono">${n}</span>
                            <span class="bft-dist-seg-l">${titles[band]}</span>
                        </button>
                    `;
                })}
            </div>
            <div class="bft-dist-scale bft-mono">
                <span>0%</span>
                <span class="bft-dist-scale-spacer"></span>
                <span>${i18n.distribution_scale_max || '100% of pending'}</span>
            </div>
        </div>
    `;
}
