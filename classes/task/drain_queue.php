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
 * Scheduled task: dispatch dirty-queue tuples to adhoc recompute tasks.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\task;

use block_feedback_tracker\local\audit\recompute_log;
use block_feedback_tracker\local\sla\dirty_queue;

/**
 * Every five minutes, pop up to `recompute_batch_size` rows from
 * {block_feedback_tracker_queue} in FIFO order and queue one
 * `recompute_one` adhoc task per tuple. Moodle's task scheduler serialises
 * identical scheduled tasks across the cluster, so the previous inline
 * model was capped by a single worker's per-tick capacity. Adhoc tasks
 * parallelise naturally — different cron workers claim different
 * `{task_adhoc}` rows — so a cluster of N workers now drains at ~Nx the
 * old rate.
 *
 * Dedup: `queue_adhoc_task($task, true)` collapses any pending adhoc with
 * the same component + classname + custom_data, so bursts of grading on
 * the same (courseid, groupid) tuple still produce a single recompute,
 * even when the submission_graded observer has already queued one.
 *
 * Queue-row lifecycle is now owned by `recompute_one::execute()`: it
 * deletes the row after a successful `rollup_service::recompute_group()`.
 * Failures leave the queue row in place for retry — same retry semantic
 * as the previous inline drain.
 */
class drain_queue extends \core\task\scheduled_task {
    /** Default soft time cap (seconds). */
    public const DEFAULT_TIME_CAP = 50;
    /** Default batch size per run. */
    public const DEFAULT_BATCH_SIZE = 200;

    /**
     * Task display name.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('task_drain_queue', 'block_feedback_tracker');
    }

    /**
     * Pop a batch of queue rows and dispatch one adhoc recompute task per
     * tuple. Returns quickly — actual rollup work happens on whichever
     * cron worker picks up each adhoc task.
     *
     * @return void
     */
    public function execute(): void {
        $started = time();
        $timecap = (int) (get_config('block_feedback_tracker', 'drain_time_cap_seconds') ?: self::DEFAULT_TIME_CAP);
        $batchsize = (int) (get_config('block_feedback_tracker', 'recompute_batch_size') ?: self::DEFAULT_BATCH_SIZE);
        $deadline = $started + $timecap;

        $batch = dirty_queue::pop_batch($batchsize);
        if (empty($batch)) {
            return;
        }

        $ok = 0;
        $fail = 0;
        foreach ($batch as $row) {
            if (time() > $deadline) {
                break;
            }
            try {
                $task = new recompute_one();
                /*
                 * Custom-data key order must match the submission_graded
                 * observer (classes/local/sla/observer.php) so the dedup
                 * hash collides and the two producers collapse into one
                 * adhoc task per tuple.
                 */
                $task->set_custom_data([
                    'courseid' => (int) $row->courseid,
                    'groupid'  => (int) $row->groupid,
                ]);
                \core\task\manager::queue_adhoc_task($task, true);
                $ok++;
            } catch (\Throwable $e) {
                debugging(sprintf(
                    'drain_queue dispatch failed for courseid=%d groupid=%d: %s',
                    (int) $row->courseid,
                    (int) $row->groupid,
                    $e->getMessage()
                ));
                $fail++;
            }
        }

        if ($ok > 0 || $fail > 0) {
            recompute_log::record(
                recompute_log::REASON_DRAIN,
                $ok,
                null,
                [
                    'failures' => $fail,
                    'took_ms'  => (time() - $started) * 1000,
                    'mode'     => 'dispatch',
                ],
                $started,
                time()
            );
        }
    }
}
