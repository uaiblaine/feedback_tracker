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
 * CLI: backfill the daily-trend table for the last N days.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

[$options, $unrecognized] = cli_get_params(
    [
        'help' => false,
        'days' => 30,
    ],
    [
        'h' => 'help',
        'd' => 'days',
    ]
);

if ($unrecognized) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognized)));
}

if ($options['help']) {
    echo <<<HELP
Backfill the trend table for the last N days so the block's sparkline shows
historical data immediately after install / reset.

Options:
  -h, --help       Show this help.
  -d, --days=N     Number of days to backfill (default 30, max 365).

Example:
  php blocks/feedback_tracker/cli/backfill_trends.php --days=60

HELP;
    exit(0);
}

$days = max(1, min((int) $options['days'], 365));

mtrace("Backfilling trend table for the last {$days} days ...");
$total = \block_feedback_tracker\local\sla\trend_service::recompute_last_n_days($days);
mtrace("Wrote {$total} trend rows.");
