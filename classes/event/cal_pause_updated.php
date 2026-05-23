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
 * Pause window updated event.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\event;

/**
 * Fired by the calendar editor when a row in {block_feedback_tracker_cpause}
 * is created, updated, or deleted. The `other` payload carries scopelevel +
 * scopeid + time window so the observer can target re-enqueue precisely.
 */
class cal_pause_updated extends \core\event\base {
    /**
     * Initialise the event.
     *
     * @return void
     */
    protected function init(): void {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        // No 'objecttable' — single-row save/delete callers still pass
        // 'objectid', but it's no longer mandatory at validate time.
    }

    /**
     * Display name for the events log.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('event_cal_pause_updated', 'block_feedback_tracker');
    }

    /**
     * Human-readable description of one event instance.
     *
     * @return string
     */
    public function get_description(): string {
        $scopelevel = isset($this->other['scopelevel']) ? (string) $this->other['scopelevel'] : '?';
        $scopeid = isset($this->other['scopeid']) ? (string) $this->other['scopeid'] : '?';
        return "Pause window updated for scope {$scopelevel}:{$scopeid}.";
    }
}
