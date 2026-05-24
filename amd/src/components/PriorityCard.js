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
 * Priority card — used three-up on the dashboard for "GRADE NOW · PICKED
 * FOR YOU" (top-3 urgent pending submissions across all courses).
 *
 * Driven by the existing get_grader_priority_list WS shape.
 *
 * @module    block_feedback_tracker/components/PriorityCard
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';
import Badge from 'block_feedback_tracker/components/Badge';
import {formatHours} from 'block_feedback_tracker/lib/format';

/**
 * Initials from a full name; falls back to "??" on empty input.
 *
 * @param {string} name
 * @returns {string}
 */
const initialsOf = (name) => {
    if (!name) {
        return '??';
    }
    const parts = String(name).trim().split(/\s+/);
    if (parts.length === 1) {
        return parts[0].slice(0, 2).toUpperCase();
    }
    return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
};

/**
 * Rough perceived calendar days, derived from business hours. Mirrors
 * the formula in PendingReportView.
 *
 * @param {number|null|undefined} effectivehours
 * @returns {string}
 */
const perceivedDays = (effectivehours) => {
    const n = Number(effectivehours);
    if (!Number.isFinite(n) || n <= 0) {
        return '0d';
    }
    return Math.max(1, Math.round(n / 24 * 1.35)) + 'd';
};

/**
 * @param {object} props
 * @param {number} props.idx          1-based rank shown in the header.
 * @param {object} props.submission   Row from get_grader_priority_list.
 * @param {object} props.i18n         Label bundle.
 * @returns {object} vnode
 */
export default function PriorityCard({idx, submission, i18n}) {
    const band = submission.slabucket || 'pending';
    const bandLabel = (i18n.bands || {})[band] || '';
    const studentname = submission.studentname || '';
    const initials = initialsOf(studentname);
    const eff = Number(submission.effectivehours) || 0;
    const cmid = Number(submission.cmid) || 0;
    // eslint-disable-next-line no-undef
    const wwwroot = (typeof M !== 'undefined' && M.cfg && M.cfg.wwwroot) || '';
    const gradeUrl = cmid > 0
        ? wwwroot + '/mod/assign/view.php?id=' + encodeURIComponent(String(cmid)) + '&action=grading'
        : '#';

    return html`
        <article class="bft-priority-card">
            <header class="bft-priority-header">
                <span class="bft-priority-idx">#${idx}</span>
                <${Badge} band=${band} label=${bandLabel} />
            </header>
            <div class="bft-priority-title">${submission.activityname || ''}</div>
            <div class="bft-priority-course">${(submission.coursename || '').toUpperCase()}</div>
            <div class="bft-priority-student">
                <span class="bft-priority-avatar" aria-hidden="true">${initials}</span>
                <span class="bft-priority-name">${studentname}</span>
            </div>
            <div class="bft-priority-waits">
                <div class="bft-priority-wait">
                    <div class="bft-priority-wait-label">
                        ${i18n.pendingreport_col_effective || 'Effective'}
                    </div>
                    <div class=${'bft-priority-wait-value bft-mono bft-overall-score-tone-' + band}>
                        ${formatHours(eff)}
                    </div>
                </div>
                <div class="bft-priority-wait">
                    <div class="bft-priority-wait-label">
                        ${i18n.pendingreport_col_perceived || 'Perceived'}
                    </div>
                    <div class="bft-priority-wait-value bft-priority-wait-muted bft-mono">
                        ${perceivedDays(eff)}
                    </div>
                </div>
            </div>
            <a class="bft-priority-cta" href=${gradeUrl}>
                ${i18n.priority_open || 'Open submission'}
            </a>
        </article>
    `;
}
