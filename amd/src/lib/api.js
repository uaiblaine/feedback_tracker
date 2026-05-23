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
 * Typed wrappers around the plugin's web-service surface.
 *
 * One named export per WS, each accepting a single options object. The
 * `Ajax.call([...])` plumbing and `Notification.exception` error routing
 * are centralised here so views can `await getResponsiveness({courseid})`
 * without worrying about the underlying RequireJS shape.
 *
 * Function names mirror the corresponding methodname (Moodle convention)
 * minus the `block_feedback_tracker_` frankenstyle prefix.
 *
 * @module    block_feedback_tracker/lib/api
 * @copyright 2026 Anderson Blaine <anderson@blaine.com.br>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';

/**
 * Generic single-WS caller. Routes errors through core/notification so
 * unhandled rejections surface as a toast, then re-throws so the caller's
 * own catch / try can react.
 *
 * @param {string} methodname  Moodle WS function name (with prefix).
 * @param {object} args        Argument bag as the WS expects.
 * @returns {Promise<*>}
 */
const call = (methodname, args) => Ajax.call([{methodname, args}])[0]
    .catch((error) => {
        Notification.exception(error);
        throw error;
    });

/**
 * Get the responsiveness payload for one course.
 *
 * @param {object} options
 * @param {number} options.courseid
 * @param {boolean} [options.force]  Bypass the session cache.
 * @returns {Promise<object>}
 */
export const getResponsiveness = ({courseid, force = false}) =>
    call('block_feedback_tracker_get_responsiveness',
        {courseid, force: force ? 1 : 0});

/**
 * Paginated list of pending submissions in a course.
 *
 * @param {object} options
 * @param {number} options.courseid
 * @param {number} [options.groupid]
 * @param {string} [options.bucket]   "excellent" | "good" | "regular" | "critical"
 * @param {string} [options.sort]     "longestwait" | "recent"
 * @param {number} [options.page]
 * @param {number} [options.perpage]
 * @returns {Promise<object>}
 */
export const getPendingSubmissions = ({
    courseid, groupid = 0, bucket = '', sort = 'longestwait', page = 0, perpage = 25,
}) => call('block_feedback_tracker_get_pending_submissions',
    {courseid, groupid, bucket, sort, page, perpage});

/**
 * Pause timeline for one submission (weekend / holiday / manual pause
 * records that contributed to its effective wait). The WS infers the
 * course context from the submission row, so the caller only needs the id.
 *
 * @param {object} options
 * @param {number} options.submissionid  block_feedback_tracker_sub.id
 * @returns {Promise<object>}
 */
export const getPauseTimeline = ({submissionid}) =>
    call('block_feedback_tracker_get_pause_timeline', {submissionid});

/**
 * Get the academic calendar payload (days, hours, pauses) for an admin
 * scope.
 *
 * @param {object} options
 * @param {string} options.scope  "site" | "course" | "group"
 * @param {number} [options.scopeid]
 * @returns {Promise<object>}
 */
export const getCalendar = ({scope, scopeid = 0}) =>
    call('block_feedback_tracker_get_calendar', {scope, scopeid});

/**
 * Site / cross-course dashboard payload. The WS has its own internal
 * 900-second cache keyed on (calver, userid, band), so there's no
 * client-driven force flag — bypass happens via calver bumps or natural
 * TTL expiry.
 *
 * @param {object} [options]
 * @param {string} [options.band]  Optional band filter ('' = no filter).
 * @returns {Promise<object>}
 */
export const getDashboard = ({band = ''} = {}) =>
    call('block_feedback_tracker_get_dashboard', {band});

/**
 * School-comparison overlay payload (site-wide medians / percentiles).
 *
 * @returns {Promise<object>}
 */
export const getSchoolComparison = () =>
    call('block_feedback_tracker_get_school_comparison', {});

/**
 * Cross-course "Grade Now" prioritised list — top-N most-urgent pending
 * submissions across every course the caller can view, sorted by
 * effective wait DESC. Powers the dashboard's Grade Now panel.
 *
 * @param {object} [options]
 * @param {number} [options.limit]  1..50, default 10.
 * @param {string} [options.bucket] Optional band filter.
 * @returns {Promise<object>}
 */
export const getGraderPriorityList = ({limit = 10, bucket = ''} = {}) =>
    call('block_feedback_tracker_get_grader_priority_list', {limit, bucket});
