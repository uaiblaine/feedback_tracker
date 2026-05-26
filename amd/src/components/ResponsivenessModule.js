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
 * Renders the dashboard hero in either its full or slim variant.
 *
 * Pure presentational component as of v1.0.8 — the parent
 * (DashboardView) owns the collapsed state because the Insights
 * section needs to react to the same toggle. The module just maps
 * `collapsed` to a hero variant and routes the user's click to the
 * parent's `onToggle` callback.
 *
 * Persistence moved server-side to a Moodle user preference; the
 * localStorage round-trip used in v1.0.5–1.0.7 is gone.
 *
 * @module    block_feedback_tracker/components/ResponsivenessModule
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';
import ResponsivenessHero from 'block_feedback_tracker/components/ResponsivenessHero';
import ResponsivenessHeroSlim from 'block_feedback_tracker/components/ResponsivenessHeroSlim';

/**
 * @param {object} props
 * @param {boolean} props.collapsed              True = slim, false = full.
 * @param {(next: boolean) => void} props.onToggle Receives the next collapsed value.
 * @param {object} props.heroprops               Forwarded to both hero variants.
 * @returns {object} vnode
 */
export default function ResponsivenessModule({collapsed, onToggle, heroprops}) {
    if (collapsed) {
        return html`<${ResponsivenessHeroSlim} ...${heroprops}
            onExpand=${() => onToggle(false)} />`;
    }
    return html`<${ResponsivenessHero} ...${heroprops}
        onCollapse=${() => onToggle(true)} />`;
}
