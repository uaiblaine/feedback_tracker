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
 * External: paginated drilldown of pending submissions.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Lists pending submissions for one course, optionally narrowed by group and
 * SLA bucket, sorted by waiting time or submission time. Returns student
 * names, activity names, group, submission timestamp, and current
 * effective/wall-clock waits.
 */
class get_pending_submissions extends external_api {
    /** Default page size. */
    public const DEFAULT_PAGE_SIZE = 25;
    /** Maximum page size to discourage all-the-things queries. */
    public const MAX_PAGE_SIZE = 200;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'Course id'),
            'groupid' => new external_value(PARAM_INT, 'Group id, 0 = all', VALUE_DEFAULT, 0),
            'bucket' => new external_value(PARAM_ALPHA, 'Bucket filter', VALUE_DEFAULT, ''),
            'sort' => new external_value(PARAM_ALPHA, 'longestwait|recent', VALUE_DEFAULT, 'longestwait'),
            'page' => new external_value(PARAM_INT, '0-based page index', VALUE_DEFAULT, 0),
            'perpage' => new external_value(PARAM_INT, 'Page size', VALUE_DEFAULT, self::DEFAULT_PAGE_SIZE),
        ]);
    }

    /**
     * Run.
     *
     * @param int $courseid
     * @param int $groupid
     * @param string $bucket
     * @param string $sort
     * @param int $page
     * @param int $perpage
     * @return array
     */
    public static function execute(
        int $courseid,
        int $groupid = 0,
        string $bucket = '',
        string $sort = 'longestwait',
        int $page = 0,
        int $perpage = self::DEFAULT_PAGE_SIZE
    ): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid, 'groupid' => $groupid, 'bucket' => $bucket,
            'sort' => $sort, 'page' => $page, 'perpage' => $perpage,
        ]);
        $courseid = (int) $params['courseid'];
        $groupid = max(0, (int) $params['groupid']);
        $bucket = trim((string) $params['bucket']);
        $sortmode = $params['sort'] === 'recent' ? 'recent' : 'longestwait';
        $page = max(0, (int) $params['page']);
        $perpage = max(1, min((int) $params['perpage'], self::MAX_PAGE_SIZE));

        $context = \context_course::instance($courseid);
        self::validate_context($context);
        require_capability('block/feedback_tracker:viewresponsiveness', $context);

        // Respect Moodle group mode: SEPARATEGROUPS without
        // accessallgroups must NOT leak rows from groups the user
        // doesn't belong to. The helper returns null when unrestricted,
        // an empty list when the user can see nothing, or the visible
        // group IDs otherwise.
        $visibleids = \block_feedback_tracker\local\sla\group_access::visible_group_ids(
            $courseid,
            (int) $USER->id
        );
        if ($visibleids !== null && empty($visibleids)) {
            return [
                'success'      => true,
                'courseid'     => $courseid,
                'total'        => 0,
                'page'         => $page,
                'perpage'      => $perpage,
                'lastsynced'   => time(),
                'submissions'  => [],
            ];
        }

        $where = 'sub.courseid = :courseid AND sub.timegraded IS NULL';
        $sqlparams = ['courseid' => $courseid];
        if ($visibleids !== null) {
            [$gsql, $gparams] = $DB->get_in_or_equal($visibleids, SQL_PARAMS_NAMED, 'gv');
            $where .= " AND sub.groupid $gsql";
            $sqlparams += $gparams;
        }
        if ($groupid > 0) {
            // The visibility IN-clause already constrains this, so a
            // forbidden groupid here naturally yields zero rows.
            $where .= ' AND sub.groupid = :groupid';
            $sqlparams['groupid'] = $groupid;
        }
        if ($bucket !== '') {
            $where .= ' AND sub.slabucket = :bucket';
            $sqlparams['bucket'] = $bucket;
        }
        $orderby = $sortmode === 'recent'
            ? 'sub.timesubmitted DESC'
            : 'sub.effectivehours DESC, sub.timesubmitted ASC';

        $sql = "SELECT sub.id, sub.cmid, sub.userid, sub.groupid, sub.timesubmitted,
                       sub.waitinghours, sub.effectivehours, sub.slabucket,
                       u.firstname, u.lastname, cm.instance AS assignid
                  FROM {block_feedback_tracker_sub} sub
                  JOIN {user} u ON u.id = sub.userid
                  JOIN {course_modules} cm ON cm.id = sub.cmid
                 WHERE $where
              ORDER BY $orderby";

        $countsql = "SELECT COUNT(*) FROM {block_feedback_tracker_sub} sub WHERE $where";

        $total = (int) $DB->count_records_sql($countsql, $sqlparams);
        $rows = $DB->get_records_sql($sql, $sqlparams, $page * $perpage, $perpage);

        $assignids = array_unique(array_map(static fn($r) => (int) $r->assignid, $rows));
        $assignnames = [];
        if (!empty($assignids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($assignids, SQL_PARAMS_NAMED);
            $assignnames = $DB->get_records_select_menu('assign', "id $insql", $inparams, '', 'id, name');
        }

        $groupids = array_unique(array_filter(array_map(static fn($r) => (int) $r->groupid, $rows)));
        $groupnames = [];
        if (!empty($groupids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($groupids, SQL_PARAMS_NAMED);
            $groupnames = $DB->get_records_select_menu('groups', "id $insql", $inparams, '', 'id, name');
        }

        $submissions = [];
        foreach ($rows as $r) {
            $submissions[] = [
                'submissionid'   => (int) $r->id,
                'cmid'           => (int) $r->cmid,
                'userid'         => (int) $r->userid,
                'studentname'    => trim($r->firstname . ' ' . $r->lastname),
                'activityname'   => (string) ($assignnames[(int) $r->assignid] ?? ''),
                'groupid'        => (int) $r->groupid,
                'groupname'      => (string) ($groupnames[(int) $r->groupid] ?? ''),
                'timesubmitted'  => (int) $r->timesubmitted,
                'waitinghours'   => (float) $r->waitinghours,
                'effectivehours' => (float) $r->effectivehours,
                'slabucket'      => (string) $r->slabucket,
            ];
        }

        return [
            'success'      => true,
            'courseid'     => $courseid,
            'total'        => $total,
            'page'         => $page,
            'perpage'      => $perpage,
            'lastsynced'   => time(),
            'submissions'  => $submissions,
        ];
    }

    /**
     * Returns.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success'    => new external_value(PARAM_BOOL, ''),
            'courseid'   => new external_value(PARAM_INT, ''),
            'total'      => new external_value(PARAM_INT, ''),
            'page'       => new external_value(PARAM_INT, ''),
            'perpage'    => new external_value(PARAM_INT, ''),
            'lastsynced' => new external_value(PARAM_INT, ''),
            'submissions' => new external_multiple_structure(new external_single_structure([
                'submissionid'   => new external_value(PARAM_INT, ''),
                'cmid'           => new external_value(PARAM_INT, ''),
                'userid'         => new external_value(PARAM_INT, ''),
                'studentname'    => new external_value(PARAM_TEXT, ''),
                'activityname'   => new external_value(PARAM_TEXT, ''),
                'groupid'        => new external_value(PARAM_INT, ''),
                'groupname'      => new external_value(PARAM_TEXT, ''),
                'timesubmitted'  => new external_value(PARAM_INT, ''),
                'waitinghours'   => new external_value(PARAM_FLOAT, ''),
                'effectivehours' => new external_value(PARAM_FLOAT, ''),
                'slabucket'      => new external_value(PARAM_ALPHA, ''),
            ])),
        ]);
    }
}
