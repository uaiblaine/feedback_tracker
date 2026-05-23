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
 * Adhoc task to recompute a single (courseid, groupid) rollup.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\task;

use block_feedback_tracker\local\sla\rollup_service;

/**
 * Queued by the submission_graded observer for fast turnaround on freshly
 * graded submissions. Calls rollup_service::recompute_group() directly and
 * removes the queue entry if one was waiting for the same tuple, so the
 * 5-minute drain task doesn't redo the work.
 *
 * `core\task\manager::queue_adhoc_task($task, true)` deduplicates bursts of
 * grading events on the same tuple.
 */
class recompute_one extends \core\task\adhoc_task {
    /**
     * Task display name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_recompute_one', 'block_feedback_tracker');
    }

    /**
     * Run the rollup recompute.
     *
     * @return void
     */
    public function execute(): void {
        global $DB;
        $data = (array) $this->get_custom_data();
        $courseid = (int) ($data['courseid'] ?? 0);
        $groupid = (int) ($data['groupid'] ?? 0);
        if ($courseid <= 0) {
            return;
        }
        rollup_service::recompute_group($courseid, $groupid);
        $DB->delete_records('block_feedback_tracker_queue', [
            'courseid' => $courseid,
            'groupid' => $groupid,
        ]);
    }
}
