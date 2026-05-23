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
 * Band pill — coloured chip showing the responsiveness band label.
 *
 * Renders nothing when both `band` and `label` are falsy, so callers can pass
 * raw payload fields without guarding (the "no data" case is naturally empty).
 *
 * @module    block_feedback_tracker/components/Badge
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';
import {badgeClass} from 'block_feedback_tracker/lib/bands';

/**
 * @param {object} props
 * @param {string|null} props.band   Band slug.
 * @param {string} props.label       Human-readable label (already translated).
 * @returns {object|null} vnode or null
 */
export default function Badge({band, label}) {
    if (!band && !label) {
        return null;
    }
    return html`<span class=${'bft-badge ' + badgeClass(band)}>${label}</span>`;
}
