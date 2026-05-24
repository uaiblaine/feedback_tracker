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
 * External: paginated recompute audit log read.
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
 * Paginated read of {block_feedback_tracker_log} — the recompute audit
 * trail that pages/audit_log.php renders server-side today. Reuses the
 * existing `viewaudit` capability so role assignments don't change.
 *
 * Returns the same fields the Mustache template consumes, with
 * triggeredby resolved to a display name via \core_user. The optional
 * courseid / actor filters narrow the result set without changing
 * shape.
 */
class get_audit_log extends external_api {
    /** Default page size. */
    public const DEFAULT_PAGE_SIZE = 50;
    /** Maximum page size — keeps a single fetch under ~200KB even with verbose details. */
    public const MAX_PAGE_SIZE = 200;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'page'     => new external_value(PARAM_INT, '0-based page', VALUE_DEFAULT, 0),
            'perpage'  => new external_value(PARAM_INT, 'Page size', VALUE_DEFAULT, self::DEFAULT_PAGE_SIZE),
            'courseid' => new external_value(PARAM_INT, 'Filter by course (0 = all)', VALUE_DEFAULT, 0),
            'actor'    => new external_value(PARAM_INT, 'Filter by triggering userid (0 = all)', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Run.
     *
     * @param int $page
     * @param int $perpage
     * @param int $courseid
     * @param int $actor
     * @return array
     */
    public static function execute(
        int $page = 0,
        int $perpage = self::DEFAULT_PAGE_SIZE,
        int $courseid = 0,
        int $actor = 0
    ): array {
        global $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'page' => $page, 'perpage' => $perpage,
            'courseid' => $courseid, 'actor' => $actor,
        ]);
        $page = max(0, (int) $params['page']);
        $perpage = max(1, min((int) $params['perpage'], self::MAX_PAGE_SIZE));
        $courseid = max(0, (int) $params['courseid']);
        $actor = max(0, (int) $params['actor']);

        $sysctx = \context_system::instance();
        self::validate_context($sysctx);
        require_capability('block/feedback_tracker:viewaudit', $sysctx);

        $where = '1=1';
        $sqlparams = [];
        if ($actor > 0) {
            $where .= ' AND triggeredby = :actor';
            $sqlparams['actor'] = $actor;
        }
        // The log table doesn't carry courseid directly; filter is applied
        // post-decode against the JSON `details` field. SQL-side filtering
        // would require schema and isn't worth it for a <90-day audit
        // window that's rarely queried with a course filter.
        $total = (int) $DB->count_records_select(
            'block_feedback_tracker_log',
            $where,
            $sqlparams
        );
        $dbrows = $DB->get_records_select(
            'block_feedback_tracker_log',
            $where,
            $sqlparams,
            'timestarted DESC, id DESC',
            '*',
            $page * $perpage,
            $perpage
        );

        $entries = [];
        foreach ($dbrows as $r) {
            $triggeredby = '';
            if ($r->triggeredby) {
                $user = \core_user::get_user((int) $r->triggeredby);
                $triggeredby = $user ? fullname($user) : (string) $r->triggeredby;
            }
            $detailscourseid = 0;
            $details = '';
            if ($r->details) {
                $decoded = json_decode((string) $r->details, true);
                if (is_array($decoded)) {
                    if (isset($decoded['courseid'])) {
                        $detailscourseid = (int) $decoded['courseid'];
                    }
                    $parts = [];
                    foreach ($decoded as $k => $v) {
                        $parts[] = $k . '=' . (is_scalar($v) ? (string) $v : json_encode($v));
                    }
                    $details = implode(', ', $parts);
                }
            }
            // Apply the post-decode courseid filter.
            if ($courseid > 0 && $detailscourseid !== $courseid) {
                continue;
            }
            $entries[] = [
                'id'              => (int) $r->id,
                'reason'          => (string) $r->reason,
                'affectedrows'    => (int) $r->affectedrows,
                'triggeredby'     => $triggeredby,
                'triggeredbyid'   => (int) ($r->triggeredby ?? 0),
                'timestarted'     => (int) $r->timestarted,
                'timefinished'    => (int) $r->timefinished,
                'details'         => $details,
                'details_courseid' => $detailscourseid,
            ];
        }

        return [
            'success'    => true,
            'total'      => $total,
            'page'       => $page,
            'perpage'    => $perpage,
            'entries'    => $entries,
            'lastsynced' => time(),
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
            'total'      => new external_value(PARAM_INT, ''),
            'page'       => new external_value(PARAM_INT, ''),
            'perpage'    => new external_value(PARAM_INT, ''),
            'lastsynced' => new external_value(PARAM_INT, ''),
            'entries'    => new external_multiple_structure(new external_single_structure([
                'id'              => new external_value(PARAM_INT, ''),
                'reason'          => new external_value(PARAM_ALPHANUMEXT, ''),
                'affectedrows'    => new external_value(PARAM_INT, ''),
                'triggeredby'     => new external_value(PARAM_TEXT, ''),
                'triggeredbyid'   => new external_value(PARAM_INT, ''),
                'timestarted'     => new external_value(PARAM_INT, ''),
                'timefinished'    => new external_value(PARAM_INT, ''),
                'details'         => new external_value(PARAM_RAW, ''),
                'details_courseid' => new external_value(PARAM_INT, ''),
            ])),
        ]);
    }
}
