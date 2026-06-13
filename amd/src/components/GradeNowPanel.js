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
 * Grade Now panel — top-N most-urgent pending submissions across the
 * caller's accessible courses, rendered in the teacher dashboard between
 * the hero and the courses table.
 *
 * Stateless presentational component: parent (DashboardView) owns the
 * data + refresh + error state and passes them as props. This keeps the
 * panel testable in isolation and lets the dashboard's single refresh
 * button refetch both the courses table and this list in one round-trip
 * pair.
 *
 * @module    block_feedback_tracker/components/GradeNowPanel
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {html} from 'block_feedback_tracker/lib/preact';
import Badge from 'block_feedback_tracker/components/Badge';
import {formatHours} from 'block_feedback_tracker/lib/format';

/**
 * Build the grader-UI URL for one submission. Targets Moodle's standard
 * single-user grading interface so the teacher lands on the grade form
 * with one click. The cmid + userid pair is enough; mod_assign resolves
 * the rest.
 *
 * @param {number} cmid
 * @param {number} userid
 * @returns {string}
 */
const buildGraderUrl = (cmid, userid) => {

    const wwwroot = (typeof M !== 'undefined' && M.cfg && M.cfg.wwwroot) || '';
    return wwwroot + '/mod/assign/view.php'
        + '?id=' + encodeURIComponent(String(cmid))
        + '&action=grader&userid=' + encodeURIComponent(String(userid));
};

/**
 * One row in the priority list.
 *
 * @param {object} props
 * @param {object} props.submission  Element of get_grader_priority_list.submissions[].
 * @param {object} props.i18n
 * @returns {object} vnode
 */
const PriorityItem = ({submission, i18n}) => {
    const bands = i18n.bands || {};
    const url = buildGraderUrl(submission.cmid, submission.userid);
    const bandLabel = bands[submission.slabucket] || submission.slabucket;
    return html`
        <li class="bft-gradenow-item" key=${'gn-' + submission.submissionid}>
            <div class="bft-gradenow-item-head">
                <a class="bft-gradenow-item-name"
                   href=${url}
                   aria-label=${(i18n.gradenow_open || 'Open in grader')
                       + ' — ' + submission.studentname + ' — ' + submission.activityname}>
                    ${submission.studentname}
                </a>
                <${Badge} band=${submission.slabucket} label=${bandLabel} />
            </div>
            <div class="bft-gradenow-item-meta">
                <span class="bft-gradenow-item-activity">${submission.activityname}</span>
                <span class="bft-gradenow-item-course">${submission.coursename}</span>
                <span class="bft-gradenow-item-wait">${formatHours(submission.effectivehours)}</span>
            </div>
        </li>
    `;
};

/**
 * Top-level panel.
 *
 * @param {object} props
 * @param {object} props.data   get_grader_priority_list WS payload (or null
 *                              before the first fetch).
 * @param {boolean} props.loading
 * @param {string|null} props.error
 * @param {object} props.i18n
 * @returns {object} vnode
 */
export default function GradeNowPanel({data, loading, error, i18n}) {
    const submissions = Array.isArray(data && data.submissions) ? data.submissions : [];
    let body;
    if (loading && !submissions.length) {
        body = html`<div class="bft-empty">${i18n.gradenow_loading || 'Loading…'}</div>`;
    } else if (submissions.length === 0) {
        body = html`<div class="bft-empty">${i18n.gradenow_empty || 'Everyone is caught up.'}</div>`;
    } else {
        body = html`
            <ol class="bft-gradenow-list">
                ${submissions.map((s) => html`
                    <${PriorityItem} submission=${s} i18n=${i18n} />
                `)}
            </ol>
        `;
    }
    return html`
        <section class="bft-dashboard-section bft-gradenow"
                 aria-labelledby="bft-gradenow-title">
            <header class="bft-gradenow-head">
                <h3 id="bft-gradenow-title" class="bft-dashboard-section-title">
                    ${i18n.gradenow_title || 'Grade now'}
                </h3>
                <p class="bft-dashboard-section-subtitle">
                    ${i18n.gradenow_subtitle || ''}
                </p>
            </header>
            ${error && html`<div class="bft-error" role="alert">${error}</div>`}
            ${body}
        </section>
    `;
}
