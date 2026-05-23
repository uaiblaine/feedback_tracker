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
 * Event observer registrations.
 *
 * Wires assign / group / course events to the SLA observer, and the three
 * plugin custom events to the calendar observer. Observers are lightweight:
 * they upsert one ledger row plus enqueue one dirty-queue entry; the rollup
 * recompute happens out-of-band.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    // Submission state changes.
    [
        'eventname' => '\mod_assign\event\assessable_submitted',
        'callback' => '\block_feedback_tracker\local\sla\observer::submission_changed',
    ],
    [
        'eventname' => '\mod_assign\event\submission_status_updated',
        'callback' => '\block_feedback_tracker\local\sla\observer::submission_changed',
    ],
    [
        'eventname' => '\assignsubmission_onlinetext\event\submission_created',
        'callback' => '\block_feedback_tracker\local\sla\observer::submission_changed',
    ],
    [
        'eventname' => '\assignsubmission_file\event\submission_created',
        'callback' => '\block_feedback_tracker\local\sla\observer::submission_changed',
    ],

    // Grading.
    [
        'eventname' => '\mod_assign\event\submission_graded',
        'callback' => '\block_feedback_tracker\local\sla\observer::submission_graded',
    ],

    // Group overrides on assign.
    [
        'eventname' => '\mod_assign\event\group_override_created',
        'callback' => '\block_feedback_tracker\local\sla\observer::override_changed',
    ],
    [
        'eventname' => '\mod_assign\event\group_override_updated',
        'callback' => '\block_feedback_tracker\local\sla\observer::override_changed',
    ],
    [
        'eventname' => '\mod_assign\event\group_override_deleted',
        'callback' => '\block_feedback_tracker\local\sla\observer::override_changed',
    ],

    // Course / cm lifecycle.
    [
        'eventname' => '\core\event\course_module_deleted',
        'callback' => '\block_feedback_tracker\local\sla\observer::course_module_deleted',
    ],
    [
        'eventname' => '\core\event\course_deleted',
        'callback' => '\block_feedback_tracker\local\sla\observer::course_deleted',
    ],

    // Group membership / lifecycle.
    [
        'eventname' => '\core\event\group_member_added',
        'callback' => '\block_feedback_tracker\local\sla\observer::group_membership_changed',
    ],
    [
        'eventname' => '\core\event\group_member_removed',
        'callback' => '\block_feedback_tracker\local\sla\observer::group_membership_changed',
    ],
    [
        'eventname' => '\core\event\group_deleted',
        'callback' => '\block_feedback_tracker\local\sla\observer::group_deleted',
    ],

    // Plugin custom calendar events.
    [
        'eventname' => '\block_feedback_tracker\event\cal_day_updated',
        'callback' => '\block_feedback_tracker\local\calendar\observer::day_updated',
    ],
    [
        'eventname' => '\block_feedback_tracker\event\cal_hours_updated',
        'callback' => '\block_feedback_tracker\local\calendar\observer::hours_updated',
    ],
    [
        'eventname' => '\block_feedback_tracker\event\cal_pause_updated',
        'callback' => '\block_feedback_tracker\local\calendar\observer::pause_updated',
    ],
];
