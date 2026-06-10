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
 * PauseTimelineModal — Moodle modal chrome + Preact-rendered body showing
 * the pause-record timeline that contributed to one submission's
 * effective-wait calculation.
 *
 * Public surface:
 *   - default export: PauseTimeline (Preact function component, testable
 *     in isolation; takes {submission, data, i18n} props).
 *   - named export: open({submission, i18n}) — opens the modal, fetches the
 *     timeline via get_pause_timeline, mounts the component into the body.
 *
 * The split lets unit tests render the component without instantiating a
 * Moodle modal, while production callers use open() and get the full UX.
 *
 * @module    block_feedback_tracker/components/PauseTimelineModal
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Modal from 'core/modal';
import {render, html} from 'block_feedback_tracker/lib/preact';
import {getPauseTimeline} from 'block_feedback_tracker/lib/api';
import {formatHours, formatDate} from 'block_feedback_tracker/lib/format';
import RetryNotice from 'block_feedback_tracker/components/RetryNotice';

/**
 * Translate a pause-reason slug to its localised label. Literal switch so
 * the string-checker can verify every key exists (mirrors the PHP-side
 * band_label() pattern from CLAUDE.md).
 *
 * @param {string} reason
 * @param {object} i18n
 * @returns {string}
 */
const reasonLabel = (reason, i18n) => {
    switch (reason) {
        case 'weekend':      return i18n.pause_reason_weekend || reason;
        case 'holiday':      return i18n.pause_reason_holiday || reason;
        case 'recess':       return i18n.pause_reason_recess || reason;
        case 'closed':       return i18n.pause_reason_closed || reason;
        case 'outofhours':   return i18n.pause_reason_outofhours || reason;
        case 'coursepaused': return i18n.pause_reason_coursepaused || reason;
        case 'grouppaused':  return i18n.pause_reason_grouppaused || reason;
        case 'sitepaused':   return i18n.pause_reason_sitepaused || reason;
        default:             return reason;
    }
};

/**
 * Format a Unix timestamp range as "{start} → {end}" using formatDate.
 *
 * @param {number} start
 * @param {number} end
 * @returns {string}
 */
const formatRange = (start, end) => formatDate(start) + ' → ' + formatDate(end);

/**
 * Pure component: renders the submission summary + pause timeline. No
 * side effects — testable in any DOM-bearing JS runtime.
 *
 * @param {object} props
 * @param {object} props.submission  Row data (studentname, activityname,
 *                                    timesubmitted, slabucket, ...).
 * @param {object} props.data        get_pause_timeline WS payload
 *                                    (effectivehours, pauses[]).
 * @param {object} props.i18n        Localised labels.
 * @returns {object} vnode
 */
export default function PauseTimeline({submission, data, i18n}) {
    const pauses = Array.isArray(data && data.pauses) ? data.pauses : [];
    return html`
        <div class="bft-timeline">
            <div class="bft-timeline-summary">
                <div class="bft-timeline-summary-head">
                    <strong>${submission.studentname || ''}</strong>
                    ${submission.activityname && html`
                        <span class="bft-timeline-summary-activity">${submission.activityname}</span>
                    `}
                </div>
                <dl class="bft-timeline-summary-meta">
                    <dt>${i18n.modal_submittedat || 'Submitted'}</dt>
                    <dd>${formatDate(submission.timesubmitted)}</dd>
                    <dt>${i18n.modal_effectivewait || 'Effective wait'}</dt>
                    <dd>${formatHours(data && data.effectivehours)}</dd>
                    <dt>${i18n.modal_wallclockwait || 'Wall-clock wait'}</dt>
                    <dd>${formatHours(data && data.waitinghours)}</dd>
                </dl>
            </div>
            ${pauses.length === 0
                ? html`
                    <div class="bft-timeline-empty">
                        ${i18n.modal_pauses_empty || 'No pause records on this submission.'}
                    </div>
                `
                : html`
                    <ol class="bft-timeline-list">
                        ${pauses.map((p) => html`
                            <li class="bft-timeline-item" key=${'p-' + p.id}>
                                <div class="bft-timeline-reason">${reasonLabel(p.reason, i18n)}</div>
                                <div class="bft-timeline-range">${formatRange(p.timestart, p.timeend)}</div>
                                ${p.note && html`<div class="bft-timeline-note">${p.note}</div>`}
                            </li>
                        `)}
                    </ol>
                `}
        </div>
    `;
}

/**
 * Open the modal for one submission row. Creates a Moodle `core/modal`
 * (the modern non-deprecated API; `core/modal_factory` was deprecated in
 * 4.3 — see https://moodledev.io/docs/5.2/guides/javascript/modal), shows
 * a loading placeholder, fetches the timeline, then mounts the Preact
 * tree into the modal body.
 *
 * `removeOnClose: true` lets Moodle tear down the modal DOM when the user
 * dismisses it — the Preact tree inside is garbage-collected with it, so
 * no explicit unmount is needed.
 *
 * @param {object} options
 * @param {object} options.submission  Row data from get_pending_submissions
 *                                      (must include submissionid).
 * @param {object} options.i18n        Localised label map.
 * @returns {Promise<void>}
 */
export const open = async ({submission, i18n}) => {
    const submissionid = Number(submission && submission.submissionid) || 0;
    if (submissionid <= 0) {
        return;
    }
    const modal = await Modal.create({
        title: i18n.modal_pauses_title || 'Pause timeline',
        body: '<div class="bft-modal-loading">'
            + (i18n.modal_pauses_loading || 'Loading…')
            + '</div>',
        large: true,
        show: true,
        removeOnClose: true,
    });

    // Resolve the modal body element each time — the modal owns its DOM and
    // re-rendering into the same node lets Preact reconcile across retries.
    const bodyElement = () => {
        const root = modal.getRoot();
        return root && root.find ? root.find('.modal-body')[0] : null;
    };

    // Fetch the timeline and mount it into the modal body. A connectivity drop
    // renders an inline RetryNotice that re-runs this same loader (api.js
    // suppresses the toast for network errors); other failures show the
    // generic message.
    const load = async () => {
        const loadingel = bodyElement();
        if (loadingel) {
            render(
                html`<div class="bft-modal-loading">${i18n.modal_pauses_loading || 'Loading…'}</div>`,
                loadingel
            );
        }
        let data;
        try {
            data = await getPauseTimeline({submissionid});
        } catch (e) {
            const errel = bodyElement();
            if (errel) {
                const msg = (e && e.bftNetwork)
                    ? (i18n.connection_lost || 'Connection lost. Check your internet and try again.')
                    : (i18n.modal_pauses_error || 'Failed to load timeline.');
                render(
                    html`<${RetryNotice} message=${msg} onRetry=${load} i18n=${i18n} variant="block" />`,
                    errel
                );
            }
            return;
        }
        const okel = bodyElement();
        if (okel) {
            render(html`<${PauseTimeline} submission=${submission} data=${data} i18n=${i18n} />`, okel);
        }
    };

    await load();
};
