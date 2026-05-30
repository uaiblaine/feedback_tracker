<?php
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
 * Submission-status policy constants.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * The set of mod_assign submission statuses the SLA layer recognises, and the
 * single decision about which one counts toward responsiveness.
 *
 * Values mirror mod_assign's ASSIGN_SUBMISSION_STATUS_* defines
 * (mod/assign/locallib.php:30-33). They are stored verbatim in
 * {block_feedback_tracker_sub}.submissionstatus by
 * {@see submission_ledger::upsert_for_cm_user_attempt()}. We mirror the
 * literals here instead of requiring mod_assign's locallib.php into every
 * rollup / web-service class — the values are part of mod_assign's on-disk
 * data contract and never change.
 *
 * Policy: only {@see self::SUBMITTED} is genuinely handed-in work awaiting
 * feedback, so it is the only status the SLA math (pending counts, response
 * time, graded stats, score, trend) operates on. `new` and `reopened` are
 * empty attempts awaiting the student; `draft` has saved content but has not
 * been submitted. Drafts are surfaced read-only and de-emphasised so a
 * teacher can decide whether to grade; they are never counted.
 */
final class submission_status {
    /** Genuinely handed-in work awaiting feedback. Mirrors ASSIGN_SUBMISSION_STATUS_SUBMITTED. */
    public const SUBMITTED = 'submitted';

    /** Saved but not submitted. Mirrors ASSIGN_SUBMISSION_STATUS_DRAFT. */
    public const DRAFT = 'draft';

    /** Empty reopened attempt awaiting the student. Mirrors ASSIGN_SUBMISSION_STATUS_REOPENED. */
    public const REOPENED = 'reopened';

    /** Placeholder attempt before any save. Mirrors ASSIGN_SUBMISSION_STATUS_NEW. */
    public const NEW = 'new';
}
