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

/** @type {Object<string, string>} Band slug → primary stroke / chip colour. */
export const BAND_COLOURS = {
    excellent: '#10b981',
    good:      '#f59e0b',
    regular:   '#f97316',
    critical:  '#ef4444',
    pending:   '#94a3b8',
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
 * Classify a 0..100 responsiveness score into a band slug. Mirrors the
 * server-side cutoffs in classes/local/sla/bucket.php — when the admin
 * changes them in settings.php the PHP-side band moves first; we follow.
 * The hardcoded defaults match the shipped defaults; admins who customise
 * them only see drift on cross-course aggregates (block + report pages
 * receive band labels straight from the server, so they're unaffected).
 *
 * @param {number|null|undefined} score
 * @returns {string} band slug
 */
export const bandForScore = (score) => {
    if (score === null || score === undefined || Number.isNaN(Number(score))) {
        return 'pending';
    }
    const n = Number(score);
    if (n >= 85) {
        return 'excellent';
    }
    if (n >= 70) {
        return 'good';
    }
    if (n >= 50) {
        return 'regular';
    }
    return 'critical';
};
