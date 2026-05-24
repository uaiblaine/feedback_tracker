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
 * Wraps the dashboard hero in a full ↔ slim toggle. The slim variant
 * collapses the hero to the height of the old streak banner, revealing
 * the Insights row and Grade Now cards without a scroll.
 *
 * Hold state in localStorage so the user's choice persists across page
 * loads — the design's interaction is meant to be a long-lived
 * preference, not a per-visit toggle.
 *
 * @module    block_feedback_tracker/components/ResponsivenessModule
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html, useState, useEffect} from 'block_feedback_tracker/lib/preact';
import ResponsivenessHero from 'block_feedback_tracker/components/ResponsivenessHero';
import ResponsivenessHeroSlim from 'block_feedback_tracker/components/ResponsivenessHeroSlim';

const STORAGE_KEY = 'bft.dashboard.hero.collapsed';

/**
 * @param {object} props
 * @param {object} props.heroprops   Forwarded to both hero variants.
 * @returns {object} vnode
 */
export default function ResponsivenessModule({heroprops}) {
    const [collapsed, setCollapsed] = useState(false);

    // Read once from localStorage; the SSR / first paint always shows the
    // full hero (better default for first-time visitors).
    useEffect(() => {
        try {
            if (window.localStorage && window.localStorage.getItem(STORAGE_KEY) === '1') {
                setCollapsed(true);
            }
        } catch (e) {
            // localStorage can be blocked (Safari private mode etc.) — ignore.
        }
    }, []);

    const persist = (value) => {
        try {
            if (window.localStorage) {
                window.localStorage.setItem(STORAGE_KEY, value ? '1' : '0');
            }
        } catch (e) {
            // Ignore storage write failures.
        }
    };

    const handleCollapse = () => {
        setCollapsed(true);
        persist(true);
    };
    const handleExpand = () => {
        setCollapsed(false);
        persist(false);
    };

    if (collapsed) {
        return html`<${ResponsivenessHeroSlim} ...${heroprops} onExpand=${handleExpand} />`;
    }
    return html`<${ResponsivenessHero} ...${heroprops} onCollapse=${handleCollapse} />`;
}
