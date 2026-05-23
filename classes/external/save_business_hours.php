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
 * External: replace business-hours slots for one weekday.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\external;

use block_feedback_tracker\event\cal_hours_updated;
use block_feedback_tracker\local\calendar\calendar;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Atomically replaces every {block_feedback_tracker_chours} row for one
 * dayofweek with the caller's provided slots. Validates each slot
 * (`end > start`, 0..1440), rejects intra-day overlaps, and fires
 * `cal_hours_updated` on success so the observer bumps calver and
 * re-enqueues every rollup.
 */
class save_business_hours extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'dayofweek' => new external_value(PARAM_INT, '0=Mon..6=Sun'),
            'slots' => new external_multiple_structure(
                new external_single_structure([
                    'starttime' => new external_value(PARAM_INT, 'minutes 0..1439'),
                    'endtime'   => new external_value(PARAM_INT, 'minutes 1..1440'),
                ]),
                'Replacement slots',
                VALUE_DEFAULT,
                []
            ),
        ]);
    }

    /**
     * Run.
     *
     * @param int $dayofweek
     * @param array $slots
     * @return array
     */
    public static function execute(int $dayofweek, array $slots = []): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'dayofweek' => $dayofweek, 'slots' => $slots,
        ]);
        $dayofweek = (int) $params['dayofweek'];
        $slots = $params['slots'];

        if ($dayofweek < 0 || $dayofweek > 6) {
            throw new \invalid_parameter_exception('dayofweek must be 0..6');
        }

        $sysctx = \context_system::instance();
        self::validate_context($sysctx);
        require_capability('block/feedback_tracker:managecalendar', $sysctx);

        $normalised = [];
        foreach ($slots as $s) {
            $start = (int) $s['starttime'];
            $end = (int) $s['endtime'];
            if ($start < 0 || $start > 1439 || $end < 1 || $end > 1440 || $end <= $start) {
                throw new \invalid_parameter_exception(
                    "Invalid slot: starttime=$start endtime=$end"
                );
            }
            $normalised[] = [$start, $end];
        }
        usort($normalised, static fn($a, $b) => $a[0] <=> $b[0]);
        for ($i = 1; $i < count($normalised); $i++) {
            if ($normalised[$i][0] < $normalised[$i - 1][1]) {
                throw new \invalid_parameter_exception('Overlapping slots are not allowed');
            }
        }

        $now = time();
        $trans = $DB->start_delegated_transaction();
        $DB->delete_records('block_feedback_tracker_chours', ['dayofweek' => $dayofweek]);
        foreach ($normalised as [$start, $end]) {
            $DB->insert_record('block_feedback_tracker_chours', (object) [
                'dayofweek'    => $dayofweek,
                'starttime'    => $start,
                'endtime'      => $end,
                'enabled'      => 1,
                'usermodified' => (int) $USER->id,
                'timecreated'  => $now,
                'timemodified' => $now,
            ]);
        }
        $trans->allow_commit();

        $event = cal_hours_updated::create([
            'context' => $sysctx,
            'other'   => ['dayofweek' => $dayofweek, 'slots' => count($normalised)],
        ]);
        $event->trigger();

        return [
            'success'   => true,
            'dayofweek' => $dayofweek,
            'slots'     => count($normalised),
            'calver'    => calendar::current_version(),
        ];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success'   => new external_value(PARAM_BOOL, ''),
            'dayofweek' => new external_value(PARAM_INT, ''),
            'slots'     => new external_value(PARAM_INT, ''),
            'calver'    => new external_value(PARAM_INT, ''),
        ]);
    }
}
