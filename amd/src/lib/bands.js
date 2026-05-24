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
 * Responsiveness-band → colour and CSS-class lookups.
 *
 * Mirrors the PHP constants in
 * classes/output/score_gauge.php::BAND_COLOURS — keep them in lockstep when
 * a band is added or renamed.
 *
 * @module    block_feedback_tracker/lib/bands
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Band slug → primary stroke / chip colour. Mirrors
 * classes/output/score_gauge.php::BAND_COLOURS.
 *
 * @type {Object<string, string>}
 */
export const BAND_COLOURS = {
    excellent: '#047857',
    good:      '#0e7490',
    regular:   '#b45309',
    critical:  '#be4b25',
    pending:   '#475569',
};

/**
 * Look up the colour for a band, falling back to "pending" grey when the
 * band is unknown or null.
 *
 * @param {string|null|undefined} band
 * @returns {string}
 */
export const colourFor = (band) => BAND_COLOURS[band] || BAND_COLOURS.pending;

/**
 * The CSS class used by the existing styles.css badge pills
 * (.bft-badge-excellent, .bft-badge-good, ...). Returns the "pending" suffix
 * when the band is null/unknown so styling stays defined.
 *
 * @param {string|null|undefined} band
 * @returns {string} e.g. "bft-badge-good"
 */
export const badgeClass = (band) => 'bft-badge-' + (BAND_COLOURS[band] ? band : 'pending');

/**
 * Default score-band cutoffs. Match the design palette and PHP
 * responsiveness_calculator::parse_thresholds_band().
 *
 * @type {{excellent: number, good: number, regular: number}}
 */
export const DEFAULT_SCORE_THRESHOLDS = {
    excellent: 90,
    good:      70,
    regular:   40,
};

/**
 * Classify a 0..100 responsiveness score into a band slug. The cutoffs
 * normally come from the server via the bootstrap config payload
 * (`config.score_thresholds`); when no cutoffs are supplied we fall back
 * to the design defaults. The block + report pages receive band labels
 * straight from the server payload, so this client-side helper only
 * matters for client-derived aggregates (e.g. the overall-banner score
 * averaged across groups).
 *
 * @param {number|null|undefined} score
 * @param {{excellent?: number, good?: number, regular?: number}} [thresholds]
 * @returns {string} band slug
 */
export const bandForScore = (score, thresholds) => {
    if (score === null || score === undefined || Number.isNaN(Number(score))) {
        return 'pending';
    }
    const t = thresholds || DEFAULT_SCORE_THRESHOLDS;
    const excellent = Number(t.excellent ?? DEFAULT_SCORE_THRESHOLDS.excellent);
    const good = Number(t.good ?? DEFAULT_SCORE_THRESHOLDS.good);
    const regular = Number(t.regular ?? DEFAULT_SCORE_THRESHOLDS.regular);
    const n = Number(score);
    if (n >= excellent) {
        return 'excellent';
    }
    if (n >= good) {
        return 'good';
    }
    if (n >= regular) {
        return 'regular';
    }
    return 'critical';
};
