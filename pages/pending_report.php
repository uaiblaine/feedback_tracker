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
 * Pending grading · detailed report — React-driven full-page view of pending
 * and graded submissions for one course: a collapsible dashboard-style hero,
 * a last-30-academic-days heatmap, a status distribution with a graded view,
 * and a table with server-side search / sort / paging plus grade actions.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');

$courseid = required_param('courseid', PARAM_INT);
$groupid = optional_param('groupid', 0, PARAM_INT);
$bucket = optional_param('bucket', '', PARAM_ALPHA);
$band = optional_param('band', '', PARAM_ALPHA);
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
    'band' => $band,
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
    $perpage,
    \block_feedback_tracker\local\sla\submission_status::SUBMITTED,
    $band
);

// Drafts (saved but not submitted) are surfaced separately and de-emphasised
// so the teacher can decide whether to grade them. They never count toward the
// SLA, so they ignore the bucket filter and are always shown most-recent-first.
$draftpending = \block_feedback_tracker\external\get_pending_submissions::execute(
    $courseid,
    $groupid,
    '',
    'recent',
    0,
    $perpage,
    \block_feedback_tracker\local\sla\submission_status::DRAFT
);

// Available groups for the filter dropdown + scope-level metrics for the
// design's hero row — reuse the responsiveness payload's group-access
// logic so the filter never offers groups the user can't see, and the
// hero card never shows data from a group the user doesn't have access to.
global $USER;
$availablegroups = [];
$payloadgroups = [];
$groupscopes = [];
try {
    $result = \block_feedback_tracker\local\payload\responsiveness_payload::for_course(
        $courseid,
        (int) $USER->id
    );
    $payloadgroups = $result['groups'] ?? [];
    // Trimmed per-group metrics so PendingReportView can recompute the hero
    // scope (selected group vs whole-course aggregate) on the client when the
    // class filter changes, with no round-trip. Display medians use the
    // include-pending cur_median_* family, matching the block + dashboard.
    foreach ($payloadgroups as $g) {
        $availablegroups[] = [
            'id' => (int) ($g['groupid'] ?? 0),
            'name' => (string) (($g['groupname'] ?? '') !== ''
                ? $g['groupname']
                : get_string('card_nogroup', 'block_feedback_tracker')),
        ];
        $groupscopes[] = [
            'groupid' => (int) ($g['groupid'] ?? 0),
            'responsiveness_score' => $g['responsiveness_score'] ?? null,
            'score_band' => $g['score_band'] ?? null,
            'cur_median_eff_h' => $g['cur_median_eff_h'] ?? null,
            'cur_median_raw_h' => $g['cur_median_raw_h'] ?? null,
            'compliance_pct' => $g['compliance_pct'] ?? null,
            'trend_pct_30d' => $g['trend_pct_30d'] ?? null,
            'pending' => (int) ($g['pending'] ?? 0),
            'critical' => (int) ($g['critical'] ?? 0),
            'overgoal' => (int) ($g['overgoal'] ?? 0),
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
// Draft-section labels (PendingReportView renders these for the muted
// "Not yet submitted" sub-table).
$i18n['drafts_heading'] = get_string('drilldown_drafts_heading', 'block_feedback_tracker');
$i18n['drafts_note'] = get_string('drilldown_drafts_note', 'block_feedback_tracker');
$i18n['drilldown_col_lastsaved'] = get_string('drilldown_col_lastsaved', 'block_feedback_tracker');
$i18n['status_draft'] = get_string('status_draft', 'block_feedback_tracker');

// Collapse state for the hero + academic-days container (per-user pref).
$reportcollapsed = (bool) get_user_preferences(
    'block_feedback_tracker_report_collapsed',
    '0',
    (int) $USER->id
);

$initial = [
    'courseid' => (int) $courseid,
    'coursename' => format_string($course->fullname),
    'pending' => array_merge($pending, [
        'bucket' => $bucket,
        'band' => $band,
        'groupid' => $groupid,
        'sort' => $sortmode,
        'perpage' => $perpage,
    ]),
    'drafts' => $draftpending,
    'groups' => $availablegroups,
    'groupscopes' => $groupscopes,
    'report_collapsed' => $reportcollapsed,
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
