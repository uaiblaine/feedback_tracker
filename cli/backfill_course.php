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
 * CLI: per-course backfill cursor operations.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use block_feedback_tracker\local\sla\backfill_cursor;
use block_feedback_tracker\local\sla\course_access;

[$options, $unrecognized] = cli_get_params(
    [
        'help'     => false,
        'courseid' => null,
        'reset'    => false,
        'disable'  => false,
        'enable'   => false,
        'status'   => false,
        'list'     => false,
    ],
    [
        'h' => 'help',
        'c' => 'courseid',
        'r' => 'reset',
        'd' => 'disable',
        'e' => 'enable',
        's' => 'status',
        'l' => 'list',
    ]
);

if ($unrecognized) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognized)));
}

if ($options['help'] || (!$options['list'] && $options['courseid'] === null)) {
    echo <<<HELP
Per-course backfill cursor management.

Each block-enabled course has its own row in
{block_feedback_tracker_bfcursor} tracking how far backfill_history has
walked {assign_submission}.id for that course. Use this tool to inspect
or override one course's cursor without touching others.

Options:
  -h, --help              Show this help.
  -l, --list              Print every cursor row (courseid, cursor,
                          active, last run time). No --courseid needed.
  -c, --courseid=NNN      Operate on this courseid.
  -s, --status            Print the cursor row for --courseid.
  -r, --reset             Reset --courseid's cursor to 0 + active=1.
                          Next backfill tick walks it from the start.
  -d, --disable           Set --courseid's active=0 (preserves cursor).
  -e, --enable            Set --courseid's active=1 (preserves cursor).

Without --status / --reset / --disable / --enable, the tool just prints
the current row for --courseid.

Examples:
  php blocks/feedback_tracker/cli/backfill_course.php --list
  php blocks/feedback_tracker/cli/backfill_course.php --courseid=42 --status
  php blocks/feedback_tracker/cli/backfill_course.php --courseid=42 --reset
  php blocks/feedback_tracker/cli/backfill_course.php --courseid=42 --disable

After --reset the next scheduled run of backfill_history will dispatch
the course's submissions as adhoc tasks. Run cron, or trigger directly:

  php admin/cli/scheduled_task.php \\
    --execute='\\block_feedback_tracker\\task\\backfill_history'
  php admin/cli/adhoc_task.php --execute

HELP;
    exit(0);
}

// Run in --list mode.
if ($options['list']) {
    $rows = backfill_cursor::all_rows();
    if (empty($rows)) {
        cli_writeln('No per-course backfill cursors exist yet.');
        cli_writeln('Run backfill_history at least once to lazily create them for processable courses.');
        exit(0);
    }
    cli_writeln(sprintf('%-10s %-15s %-7s %s', 'COURSEID', 'CURSOR', 'ACTIVE', 'LASTRUNAT'));
    foreach ($rows as $r) {
        cli_writeln(sprintf(
            '%-10d %-15d %-7s %s',
            (int) $r->courseid,
            (int) $r->lastsubid,
            ((int) $r->active === 1 ? 'yes' : 'no'),
            $r->lastrunat ? userdate((int) $r->lastrunat) : '(never)'
        ));
    }
    exit(0);
}

// All remaining modes need --courseid.
$courseid = (int) $options['courseid'];
if ($courseid <= 0) {
    cli_error('--courseid must be a positive integer.');
}

global $DB;
if (!$DB->record_exists('course', ['id' => $courseid])) {
    cli_error("Course id $courseid does not exist.");
}

if ($options['reset']) {
    backfill_cursor::reset($courseid);
    cli_writeln("Course $courseid: cursor reset to 0, active=1.");
    cli_writeln('Next backfill_history tick will walk this course from the start.');
    if (!course_access::is_processable($courseid)) {
        cli_writeln('WARNING: course is not currently processable (block missing, or course hidden + setting off).');
        cli_writeln('         The cursor row exists but the dispatcher will skip it until the course passes the gate.');
    }
    exit(0);
}

if ($options['disable']) {
    backfill_cursor::disable($courseid);
    cli_writeln("Course $courseid: active=0. Dispatcher will skip this course; cursor preserved.");
    exit(0);
}

if ($options['enable']) {
    backfill_cursor::enable($courseid);
    cli_writeln("Course $courseid: active=1. Dispatcher resumes from the current cursor.");
    exit(0);
}

// Default: --status (or no action flag) → print the row.
$row = $DB->get_record('block_feedback_tracker_bfcursor', ['courseid' => $courseid]);
if (!$row) {
    cli_writeln("Course $courseid: no cursor row exists yet.");
    cli_writeln('It will be lazily created on the next backfill tick if the course is processable.');
    cli_writeln('Processable now: ' . (course_access::is_processable($courseid) ? 'yes' : 'no'));
    exit(0);
}
cli_writeln("Course $courseid:");
cli_writeln('  lastsubid:   ' . (int) $row->lastsubid);
cli_writeln('  active:      ' . ((int) $row->active === 1 ? 'yes' : 'no'));
cli_writeln('  lastrunat:   ' . ($row->lastrunat ? userdate((int) $row->lastrunat) : '(never)'));
cli_writeln('  timecreated: ' . userdate((int) $row->timecreated));
cli_writeln('  processable: ' . (course_access::is_processable($courseid) ? 'yes' : 'no (block missing or hidden + setting off)'));
