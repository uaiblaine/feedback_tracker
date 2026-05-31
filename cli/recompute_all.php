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
 * CLI: recompute every existing (courseid, groupid) rollup in place.
 *
 * The scheduled tasks only revisit tuples with pending or freshly-graded
 * ledger activity, so a group with no submitted work is never recomputed
 * automatically — its rollup keeps whatever it was last written with. After
 * a scoring-rule change (notably empty groups moving from a charitable 100
 * to the neutral "nodata" band) this one-off pass rewrites every stored
 * rollup so the dashboard reflects the new rule without waiting for fresh
 * events.
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
        'help'     => false,
        'courseid' => 0,
        'dryrun'   => false,
    ],
    [
        'h' => 'help',
        'c' => 'courseid',
    ]
);

if ($unrecognized) {
    cli_error(get_string('cliunknowoption', 'admin', implode("\n  ", $unrecognized)));
}

if ($options['help']) {
    echo <<<HELP
Recompute every existing (courseid, groupid) rollup row in place.

Use after a scoring-rule change to refresh stored rollups that the
scheduled tasks won't revisit on their own (notably groups with no
submitted work). Purge caches afterwards so the dashboard and block read
the fresh values immediately:
  php admin/cli/purge_caches.php

Options:
  -h, --help          Show this help.
  -c, --courseid=ID   Limit to one course (0 / omitted = every course).
  --dryrun            List the tuples that would be recomputed, change nothing.

Examples:
  php blocks/feedback_tracker/cli/recompute_all.php
  php blocks/feedback_tracker/cli/recompute_all.php --courseid=42
  php blocks/feedback_tracker/cli/recompute_all.php --dryrun

HELP;
    exit(0);
}

$courseid = (int) $options['courseid'];
$dryrun = (bool) $options['dryrun'];

$conditions = $courseid > 0 ? ['courseid' => $courseid] : [];
$rollups = $DB->get_records(
    'block_feedback_tracker_group',
    $conditions,
    'courseid ASC, groupid ASC',
    'id, courseid, groupid'
);

$total = count($rollups);
if ($total === 0) {
    mtrace('No rollup rows to recompute.');
    exit(0);
}

mtrace(sprintf(
    '%s %d rollup row(s)%s ...',
    $dryrun ? 'Would recompute' : 'Recomputing',
    $total,
    $courseid > 0 ? " for courseid=$courseid" : ''
));

$done = 0;
foreach ($rollups as $r) {
    $cid = (int) $r->courseid;
    $gid = (int) $r->groupid;
    if ($dryrun) {
        mtrace("  courseid=$cid groupid=$gid");
        continue;
    }
    \block_feedback_tracker\local\sla\rollup_service::recompute_group($cid, $gid);
    $done++;
    if ($done % 100 === 0) {
        mtrace("  ... $done / $total");
    }
}

if ($dryrun) {
    mtrace('Dry run complete — nothing changed.');
} else {
    mtrace("Done — recomputed $done / $total rollup row(s).");
    mtrace('Now run "php admin/cli/purge_caches.php" so the dashboard reads the fresh values.');
}
