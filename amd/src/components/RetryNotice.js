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
 * Friendly connectivity-failure notice with a retry affordance.
 *
 * Shown by every async surface (dashboard, pending report, block, modals)
 * when a web-service call fails because the connection dropped. api.js
 * suppresses the technical exception toast for those errors (tagging them
 * `bftNetwork`), so this component carries the user-facing recovery: a clear
 * message, a "Try again" button that re-runs the failed fetch, and a
 * "Reload page" fallback.
 *
 * Stateless: the parent owns the retry callback and the in-flight flag so the
 * button can disable itself while a retry is running.
 *
 * @module    block_feedback_tracker/components/RetryNotice
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';

/**
 * @param {object} props
 * @param {string} [props.message]   Friendly message. Falls back to i18n.connection_lost.
 * @param {Function} props.onRetry   Invoked when the user clicks "Try again".
 * @param {boolean} [props.retrying] True while a retry is in flight (disables the button).
 * @param {object} [props.i18n]      Localised label map.
 * @param {string} [props.variant]   'block' (standalone, no data yet) | 'banner' (data present).
 * @returns {object} vnode
 */
export default function RetryNotice({message, onRetry, retrying = false, i18n = {}, variant = 'banner'}) {
    const text = message || i18n.connection_lost || 'Connection lost. Check your internet and try again.';
    const retrylabel = i18n.connection_retry || 'Try again';
    const reloadlabel = i18n.connection_reload || 'Reload page';
    const busylabel = i18n.block_loading || 'Loading…';
    return html`
        <div class=${'bft-retry bft-retry-' + variant} role="alert">
            <p class="bft-retry-msg">${text}</p>
            <div class="bft-retry-actions">
                <button
                    type="button"
                    class=${'bft-retry-btn' + (retrying ? ' bft-retry-busy' : '')}
                    disabled=${retrying}
                    onClick=${() => !retrying && onRetry && onRetry()}
                >${retrying ? busylabel : retrylabel}</button>
                <button
                    type="button"
                    class="bft-retry-reload"
                    onClick=${() => window.location.reload()}
                >${reloadlabel}</button>
            </div>
        </div>
    `;
}
