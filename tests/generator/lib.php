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
 * Data generator class
 *
 * @package    block_feedback_tracker
 * @category   test
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Block generator with helpers for seeding plugin-owned tables directly.
 *
 * Usage from tests:
 *   $g = $this->getDataGenerator()->get_plugin_generator('block_feedback_tracker');
 *   $g->seed_default_platform_calendar();
 *   $g->create_ledger_row(['courseid' => $c->id, 'effectivehours' => 12.0, 'timegraded' => time()]);
 */
class block_feedback_tracker_generator extends testing_block_generator {
    /**
     * Seed the platform calendar config + Mon-Fri 08:00-18:00 business hours.
     * Idempotent.
     *
     * @return void
     */
    public function seed_default_platform_calendar(): void {
        global $DB;
        set_config('calver', '1', 'block_feedback_tracker');
        set_config('timezone', 'UTC', 'block_feedback_tracker');
        set_config('excludeweekends', '1', 'block_feedback_tracker');
        set_config('weekendmask', '96', 'block_feedback_tracker');
        set_config('excludeholidays', '1', 'block_feedback_tracker');
        set_config('excluderecesses', '1', 'block_feedback_tracker');
        set_config('enablebusinesshours', '1', 'block_feedback_tracker');
        set_config('grading_during_pause_mode', 'clipped', 'block_feedback_tracker');
        set_config('sla_goal_hours', '24', 'block_feedback_tracker');
        set_config('bucket_thresholds_eff', '24,48,120', 'block_feedback_tracker');
        set_config('weight_compliance', '0.40', 'block_feedback_tracker');
        set_config('weight_median', '0.25', 'block_feedback_tracker');
        set_config('weight_critical', '0.15', 'block_feedback_tracker');
        set_config('weight_pending', '0.10', 'block_feedback_tracker');
        set_config('weight_trend', '0.10', 'block_feedback_tracker');

        if (!$DB->record_exists('block_feedback_tracker_chours', [])) {
            $now = time();
            for ($dow = 0; $dow <= 4; $dow++) {
                $DB->insert_record('block_feedback_tracker_chours', (object) [
                    'dayofweek'    => $dow,
                    'starttime'    => 480,
                    'endtime'      => 1080,
                    'enabled'      => 1,
                    'timecreated'  => $now,
                    'timemodified' => $now,
                ]);
            }
        }
    }

    /**
     * Insert a {block_feedback_tracker_cday} row.
     *
     * @param int $daydate YYYYMMDD as int.
     * @param string $daytype schoolday|holiday|recess|closed|optional.
     * @param string|null $note
     * @return int Row id.
     */
    public function create_calendar_day(int $daydate, string $daytype, ?string $note = null): int {
        global $DB;
        $now = time();
        return (int) $DB->insert_record('block_feedback_tracker_cday', (object) [
            'daydate'      => $daydate,
            'daytype'      => $daytype,
            'note'         => $note,
            'timecreated'  => $now,
            'timemodified' => $now,
        ]);
    }

    /**
     * Insert a {block_feedback_tracker_cpause} row.
     *
     * @param array $overrides Keys: scopelevel, scopeid, contextid, reason, timestart, timeend, note.
     * @return int Row id.
     */
    public function create_pause_window(array $overrides): int {
        global $DB;
        $now = time();
        $defaults = [
            'scopelevel'   => 'site',
            'scopeid'      => 0,
            'contextid'    => \context_system::instance()->id,
            'reason'       => 'other',
            'timestart'    => $now - 86400,
            'timeend'      => $now,
            'note'         => null,
            'timecreated'  => $now,
            'timemodified' => $now,
        ];
        $rec = (object) array_merge($defaults, $overrides);
        return (int) $DB->insert_record('block_feedback_tracker_cpause', $rec);
    }

    /**
     * Insert a {block_feedback_tracker_sub} row with sensible defaults; only
     * the keys in $overrides are set explicitly.
     *
     * @param array $overrides
     * @return int Row id.
     */
    public function create_ledger_row(array $overrides = []): int {
        global $DB;
        static $cmid = 90000;
        $cmid++;
        $now = time();
        $defaults = [
            'courseid'         => 1,
            'groupid'          => 0,
            'cmid'             => $cmid,
            'iteminstance'     => $cmid,
            'userid'           => 1,
            'attemptnumber'    => 0,
            'submissionstatus' => 'submitted',
            'timesubmitted'    => $now - 7200,
            'timegraded'       => null,
            'timeopens'        => null,
            'timecloses'       => null,
            'timecutoff'       => null,
            'hasrule'          => 0,
            'waitinghours'     => 0.0,
            'effectivehours'   => 0.0,
            'effectiveasof'    => $now,
            'effectivecalver'  => 1,
            'slabucket'        => 'pending',
            'timecreated'      => $now,
            'timemodified'     => $now,
        ];
        $rec = (object) array_merge($defaults, $overrides);
        return (int) $DB->insert_record('block_feedback_tracker_sub', $rec);
    }

    /**
     * Seed a scale fixture: N courses × M groups × K submissions per group.
     * Returns aggregate counts so tests can size their assertions.
     *
     * @param int $courses
     * @param int $groupspercourse
     * @param int $subspergroup
     * @return array{courses:int, groups:int, submissions:int}
     */
    public function seed_scale_fixture(int $courses, int $groupspercourse, int $subspergroup): array {
        $totalcourses = 0;
        $totalgroups = 0;
        $totalsubs = 0;
        $now = time();
        $datagen = \phpunit_util::get_data_generator();
        for ($c = 0; $c < $courses; $c++) {
            $course = $datagen->create_course();
            $totalcourses++;
            for ($g = 0; $g < $groupspercourse; $g++) {
                $group = $datagen->create_group(['courseid' => $course->id]);
                $totalgroups++;
                for ($s = 0; $s < $subspergroup; $s++) {
                    $age = ($s + 1) * 3600; // 1h, 2h, ... back
                    $this->create_ledger_row([
                        'courseid'        => (int) $course->id,
                        'groupid'         => (int) $group->id,
                        'userid'          => $s + 1,
                        'timesubmitted'   => $now - $age,
                        'timegraded'      => $now - max(0, $age - 1800),
                        'waitinghours'    => $age / 3600.0,
                        'effectivehours' => min($age / 3600.0, 24.0),
                        'slabucket'       => 'excellent',
                    ]);
                    $totalsubs++;
                }
            }
        }
        return ['courses' => $totalcourses, 'groups' => $totalgroups, 'submissions' => $totalsubs];
    }
}
