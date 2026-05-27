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

/**
 * Dashboard insights — bright spot, most improved, gentle watch. Each
 * key is omitted from the response when no row qualifies, so callers
 * should check for presence (not nullness) before rendering.
 *
 * @returns {Promise<object>}
 */
export const getInsights = () =>
    call('block_feedback_tracker_get_insights', {});

/**
 * Paginated audit-log read. Powers the future React audit-log view;
 * existing pages/audit_log.php still renders server-side from
 * block_feedback_tracker_log directly.
 *
 * @param {object} [options]
 * @param {number} [options.page]      0-based page index.
 * @param {number} [options.perpage]   Page size (max 200).
 * @param {number} [options.courseid]  Optional course filter (0 = all).
 * @param {number} [options.actor]     Optional actor userid filter (0 = all).
 * @returns {Promise<object>}
 */
export const getAuditLog = ({page = 0, perpage = 50, courseid = 0, actor = 0} = {}) =>
    call('block_feedback_tracker_get_audit_log', {page, perpage, courseid, actor});

/* ============================================================================
 * Write WS wrappers — calendar editor + pause-window management.
 *
 * Each wrapper exposes the same field names as the server's
 * execute_parameters() so callers can pass payload-shaped objects directly.
 * Errors propagate through the shared call() helper (toast + rethrow).
 * ========================================================================= */

/**
 * Upsert a manual pause window (site / course / group scope).
 *
 * @param {object} options
 * @param {number} [options.id]         Existing row id, 0 = new.
 * @param {string} options.scopelevel   'site' | 'course' | 'group'.
 * @param {number} options.scopeid      courseid / groupid; 0 for site.
 * @param {string} [options.reason]     Reason slug; defaults to 'other'.
 * @param {number} options.timestart    Unix seconds.
 * @param {number} [options.timeend]    Unix seconds; 0 = open-ended.
 * @param {string} [options.note]
 * @returns {Promise<object>}
 */
export const savePauseWindow = ({
    id = 0, scopelevel, scopeid, reason = 'other', timestart, timeend = 0, note = '',
}) => call('block_feedback_tracker_save_pause_window',
    {id, scopelevel, scopeid, reason, timestart, timeend, note});

/**
 * Delete a manual pause window by id. Fires the cal_pause_updated event
 * server-side which re-enqueues rollups.
 *
 * @param {object} options
 * @param {number} options.id  cpause.id
 * @returns {Promise<object>}
 */
export const deletePauseWindow = ({id}) =>
    call('block_feedback_tracker_delete_pause_window', {id});

/**
 * Upsert one calendar-day override. Use daytype = 'remove' to clear a
 * previously-overridden day back to the weekday default. When daytype
 * is 'optional', `starttime` + `endtime` (minutes since midnight) can be
 * passed to create a sub-day event window; both null / omitted means a
 * legacy full-day optional rule.
 *
 * @param {object} options
 * @param {number} options.daydate     YYYYMMDD integer.
 * @param {string} options.daytype     'schoolday' | 'holiday' | 'recess' | 'closed' | 'optional' | 'remove'.
 * @param {string} [options.note]
 * @param {number|null} [options.starttime]  Minutes since midnight (0-1439); null = full-day.
 * @param {number|null} [options.endtime]    Minutes since midnight (1-1440); null = full-day.
 * @returns {Promise<object>}
 */
export const saveCalendarDay = ({daydate, daytype, note = '', starttime = null, endtime = null}) =>
    call('block_feedback_tracker_save_calendar_day',
        {daydate, daytype, note, starttime, endtime});

/**
 * Bulk-import calendar days from a CSV payload. CSV columns mirror
 * csv_importer's contract (date,type[,note]).
 *
 * @param {object} options
 * @param {string} options.csv  Raw CSV text.
 * @returns {Promise<object>}
 */
export const bulkImportCalendar = ({csv}) =>
    call('block_feedback_tracker_bulk_import_calendar', {csv});

/**
 * Replace the business-hours slots for one weekday. The server clears
 * existing rows for the dayofweek then inserts the supplied slots in
 * one atomic transaction.
 *
 * @param {object} options
 * @param {number} options.dayofweek   0..6 (Mon=0).
 * @param {Array<{starttime: number, endtime: number}>} [options.slots]
 *                                     Replacement slots; empty list disables the day.
 * @returns {Promise<object>}
 */
export const saveBusinessHours = ({dayofweek, slots = []}) =>
    call('block_feedback_tracker_save_business_hours', {dayofweek, slots});
