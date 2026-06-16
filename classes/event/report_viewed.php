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
 * Feedback tracker report viewed event.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\event;

/**
 * Fired once, server-side, when a teacher opens one of the data-bearing report
 * surfaces: the cross-course dashboard, the pending report, or the group
 * drill-down. The viewed surface is in `other['report']`
 * ('dashboard' | 'pending' | 'drilldown').
 *
 * This is a read-only access event with no observer of its own — Moodle's
 * standard logstore subscribes to every event, so triggering it is all that is
 * needed for the access to land in the site logs. It is deliberately fired at
 * page render (not in the backing web services, which re-run on every
 * refresh / filter / sort / page) so the log grows linearly with genuine
 * navigations rather than with in-page interactions.
 */
class report_viewed extends \core\event\base {
    /**
     * Initialise the event metadata.
     *
     * @return void
     */
    protected function init(): void {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        // No 'objecttable': the reports list many submissions, there is no
        // single object id to pair with one (Moodle requires both or neither).
    }

    /**
     * Localised event name for the site log report.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('event_report_viewed', 'block_feedback_tracker');
    }

    /**
     * Human-readable description of one occurrence.
     *
     * @return string
     */
    public function get_description(): string {
        $report = isset($this->other['report']) ? (string) $this->other['report'] : '?';
        return "The user with id '{$this->userid}' viewed the feedback tracker '{$report}' report.";
    }

    /**
     * Link back to the viewed surface.
     *
     * @return \moodle_url
     */
    public function get_url(): \moodle_url {
        $groupid = isset($this->other['groupid']) ? (int) $this->other['groupid'] : 0;
        switch ($this->other['report'] ?? '') {
            case 'pending':
                return new \moodle_url(
                    '/blocks/feedback_tracker/pages/pending_report.php',
                    ['courseid' => $this->courseid, 'groupid' => $groupid]
                );
            case 'drilldown':
                return new \moodle_url(
                    '/blocks/feedback_tracker/pages/group_drilldown.php',
                    ['courseid' => $this->courseid, 'groupid' => $groupid]
                );
            default:
                return new \moodle_url('/blocks/feedback_tracker/pages/teacher_dashboard.php');
        }
    }
}
