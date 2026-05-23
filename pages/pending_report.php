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
 * Pending grading · detailed report (Phase 2C) — React-driven full-page
 * view of pending submissions for one course, with bucket / group / sort
 * filters, client-side search, and a pause-timeline modal.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$groupid = optional_param('groupid', 0, PARAM_INT);
$bucket = optional_param('bucket', '', PARAM_ALPHA);
$sortmode = optional_param('sort', 'longestwait', PARAM_ALPHA);
$page = optional_param('page', 0, PARAM_INT);
$perpage = 25;

$course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
require_login($course);
$context = \context_course::instance($courseid);
require_capability('block/feedback_tracker:viewresponsiveness', $context);

$PAGE->set_url('/blocks/feedback_tracker/pages/pending_report.php', [
    'courseid' => $courseid,
    'groupid' => $groupid,
    'bucket' => $bucket,
    'sort' => $sortmode,
    'page' => $page,
]);
$PAGE->set_context($context);
$PAGE->set_title(get_string('pendingreport_title', 'block_feedback_tracker'));
// The heading combines the report-purpose label and the course name so
// the page is self-describing — and so the Behat scenario can assert
// the visible H1 instead of relying on the browser-tab <title>.
$PAGE->set_heading(
    get_string('pendingreport_title', 'block_feedback_tracker')
        . ' — ' . format_string($course->fullname)
);
$PAGE->set_pagelayout('incourse');

// Initial WS fetch server-side — the React tree boots with the first page
// already populated so there's no client-side loading flash on first paint.
$pending = \block_feedback_tracker\external\get_pending_submissions::execute(
    $courseid,
    $groupid,
    $bucket,
    $sortmode,
    $page,
    $perpage
);

// Available groups for the filter dropdown — reuse the responsiveness
// payload's group-access logic so the filter never offers groups the user
// can't see.
global $USER;
$availablegroups = [];
try {
    $result = \block_feedback_tracker\local\payload\responsiveness_payload::for_course(
        $courseid,
        (int) $USER->id
    );
    foreach (($result['groups'] ?? []) as $g) {
        $availablegroups[] = [
            'id' => (int) ($g['groupid'] ?? 0),
            'name' => (string) (($g['groupname'] ?? '') !== ''
                ? $g['groupname']
                : get_string('card_nogroup', 'block_feedback_tracker')),
        ];
    }
} catch (\Throwable $e) {
    debugging('block_feedback_tracker: group list assembly failed: ' . $e->getMessage());
}

// Shared block-level labels (band names, card_*, breakdown_*) + page-
// specific overlay (pendingreport_*, modal_*, pause_reason_*). Both live
// in the autoloaded helper so the block class doesn't have to be
// require_once'd from a standalone page.
$i18n = array_merge(
    \block_feedback_tracker\local\output\bootstrap::i18n_bundle(),
    \block_feedback_tracker\local\output\bootstrap::pending_report_i18n()
);

$initial = [
    'courseid' => (int) $courseid,
    'coursename' => format_string($course->fullname),
    'pending' => array_merge($pending, [
        'bucket' => $bucket,
        'groupid' => $groupid,
        'sort' => $sortmode,
    ]),
    'groups' => $availablegroups,
    'i18n' => $i18n,
    'config' => \block_feedback_tracker\local\output\bootstrap::config_bundle(),
];

$initialjson = json_encode(
    $initial,
    JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);
if ($initialjson === false) {
    $initialjson = '{}';
}

$PAGE->requires->js(
    new \moodle_url('/blocks/feedback_tracker/js/vendor/bft-vendor-10.29.2-3.1.1.min.js'),
    true
);
$PAGE->requires->js_call_amd('block_feedback_tracker/pending_report_app', 'init');

echo $OUTPUT->header();
echo '<div data-bft-pending-report-root>';
echo '<script type="application/json" data-bft-init>' . $initialjson . '</script>';
echo '</div>';
echo $OUTPUT->footer();
