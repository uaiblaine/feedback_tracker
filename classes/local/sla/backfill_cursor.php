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
 * Per-course backfill cursor helper.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * Wraps the {block_feedback_tracker_bfcursor} table.
 *
 * v1.7.0 replaced the single global `backfill_cursor` config key with
 * one row per block-enabled course. Each row tracks where backfill has
 * progressed through `{assign_submission}` for THAT course, allowing:
 *
 *  - Adding the block to a new course later → fresh cursor=0 row → next
 *    backfill tick walks that course from the start, without touching
 *    other courses' progress.
 *  - Re-running backfill on one course without re-walking everything
 *    else (`reset($courseid)` flips cursor=0 + active=1).
 *  - Pausing backfill for a single course (`disable($courseid)`).
 *
 * The dispatcher (`task\backfill_history`) is the only writer of the
 * `cursor` / `active` / `lastrunat` columns under normal operation —
 * the per-row mutex is implicit in the scheduler serialising the
 * dispatcher across the cluster.
 */
class backfill_cursor {
    /**
     * Fetch the cursor row for one course, lazily creating it (with
     * cursor=0, active=1) if none exists. Idempotent.
     *
     * @param int $courseid
     * @return \stdClass The row.
     */
    public static function get_or_create(int $courseid): \stdClass {
        global $DB;
        $row = $DB->get_record('block_feedback_tracker_bfcursor', ['courseid' => $courseid]);
        if ($row) {
            return $row;
        }
        $now = time();
        $row = (object) [
            'courseid'    => $courseid,
            'lastsubid'   => 0,
            'active'      => 1,
            'lastrunat'   => null,
            'timecreated' => $now,
        ];
        $row->id = $DB->insert_record('block_feedback_tracker_bfcursor', $row);
        return $row;
    }

    /**
     * Advance the cursor for one course to the given subid and record
     * the run timestamp. If $complete is true, also flip active=0
     * (no more rows past the cursor — admin can reset to retry).
     *
     * @param int $courseid
     * @param int $newcursor
     * @param bool $complete
     * @return void
     */
    public static function advance(int $courseid, int $newcursor, bool $complete = false): void {
        global $DB;
        $row = self::get_or_create($courseid);
        $DB->update_record('block_feedback_tracker_bfcursor', (object) [
            'id'        => $row->id,
            'lastsubid' => $newcursor,
            'active'    => $complete ? 0 : 1,
            'lastrunat' => time(),
        ]);
    }

    /**
     * Reset one course's cursor to 0 and mark active=1 so the next
     * dispatcher tick walks it from the start. Lazily creates the row
     * if absent. Used by the per-course backfill CLI tool.
     *
     * @param int $courseid
     * @return void
     */
    public static function reset(int $courseid): void {
        global $DB;
        $row = self::get_or_create($courseid);
        $DB->update_record('block_feedback_tracker_bfcursor', (object) [
            'id'        => $row->id,
            'lastsubid' => 0,
            'active'    => 1,
            'lastrunat' => null,
        ]);
    }

    /**
     * Disable backfill for one course — sets active=0 without touching
     * the cursor. Counterpart to enable() if admins want to pause one
     * course's backfill without resetting its progress.
     *
     * @param int $courseid
     * @return void
     */
    public static function disable(int $courseid): void {
        global $DB;
        $row = self::get_or_create($courseid);
        $DB->update_record('block_feedback_tracker_bfcursor', (object) [
            'id'     => $row->id,
            'active' => 0,
        ]);
    }

    /**
     * Re-activate a previously-disabled course without resetting its
     * cursor — the next tick continues from where it left off.
     *
     * @param int $courseid
     * @return void
     */
    public static function enable(int $courseid): void {
        global $DB;
        $row = self::get_or_create($courseid);
        $DB->update_record('block_feedback_tracker_bfcursor', (object) [
            'id'     => $row->id,
            'active' => 1,
        ]);
    }

    /**
     * Return cursor rows for every course currently flagged active.
     * Ordered by courseid ASC for deterministic dispatch order.
     *
     * @return array<int, \stdClass> Keyed by id.
     */
    public static function active_rows(): array {
        global $DB;
        return $DB->get_records(
            'block_feedback_tracker_bfcursor',
            ['active' => 1],
            'courseid ASC'
        );
    }

    /**
     * Return EVERY cursor row (active + complete) for the listing tool.
     *
     * @return array<int, \stdClass>
     */
    public static function all_rows(): array {
        global $DB;
        return $DB->get_records('block_feedback_tracker_bfcursor', null, 'courseid ASC');
    }

    /**
     * Drop the cursor row entirely. Called from the course_deleted
     * observer chain via submission_ledger::delete_for_course().
     *
     * @param int $courseid
     * @return void
     */
    public static function delete(int $courseid): void {
        global $DB;
        $DB->delete_records('block_feedback_tracker_bfcursor', ['courseid' => $courseid]);
    }
}
