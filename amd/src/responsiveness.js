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
 * Block Feedback Flow — responsiveness card client interactions.
 *
 * Wires the "refresh" button on each responsiveness card. Clicking it calls
 * the get_responsiveness web service with `force=1`, which skips the session
 * cache + writes a fresh entry, then reloads the page so the block re-reads
 * the new payload.
 *
 * @module    block_feedback_tracker/responsiveness
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';

const SELECTOR = '.bft-refresh';

/**
 * Click handler shared by every refresh button on the page.
 *
 * @param {MouseEvent} event
 */
const onClick = async(event) => {
    const btn = event.target.closest(SELECTOR);
    if (!btn) {
        return;
    }
    event.preventDefault();

    const courseid = parseInt(btn.dataset.courseid, 10);
    if (!courseid) {
        return;
    }

    btn.disabled = true;
    btn.classList.add('bft-refresh-busy');

    try {
        await Ajax.call([{
            methodname: 'block_feedback_tracker_get_responsiveness',
            args: {courseid, force: 1},
        }])[0];
        // Cache rewritten — reload so the block renders the fresh payload.
        window.location.reload();
    } catch (error) {
        Notification.exception(error);
        btn.disabled = false;
        btn.classList.remove('bft-refresh-busy');
    }
};

/**
 * Initialise the click listener. Idempotent — multiple init() calls are safe.
 */
export const init = () => {
    if (window.bftResponsivenessInitDone) {
        return;
    }
    window.bftResponsivenessInitDone = true;
    document.addEventListener('click', onClick);
};
