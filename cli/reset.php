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
 * CLI: drop every ledger / rollup / trend / site / queue row.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->dirroot . '/blocks/feedback_tracker/lib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'help'     => false,
        'backfill' => false,
    ],
    [
        'h' => 'help',
        'b' => 'backfill',
    ]
);

if ($unrecognized) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognized)));
}

if ($options['help']) {
    echo <<<HELP
Reset all Feedback Flow data (preserves calendar config).

Options:
  -h, --help      Show this help.
  -b, --backfill  Re-enable the backfill task so historical assign
                  submissions are re-ingested.

Example:
  php blocks/feedback_tracker/cli/reset.php --backfill

HELP;
    exit(0);
}

$counts = block_feedback_tracker_reset_data((bool) $options['backfill']);

mtrace("Feedback Flow reset complete:");
foreach ($counts as $name => $count) {
    mtrace(sprintf("  %-8s %d", $name, $count));
}
