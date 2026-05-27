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

// Available groups for the filter dropdown + scope-level metrics for the
// design's hero row — reuse the responsiveness payload's group-access
// logic so the filter never offers groups the user can't see, and the
// hero card never shows data from a group the user doesn't have access to.
global $USER;
$availablegroups = [];
$payloadgroups = [];
$scope = null;
try {
    $result = \block_feedback_tracker\local\payload\responsiveness_payload::for_course(
        $courseid,
        (int) $USER->id
    );
    $payloadgroups = $result['groups'] ?? [];
    foreach ($payloadgroups as $g) {
        $availablegroups[] = [
            'id' => (int) ($g['groupid'] ?? 0),
            'name' => (string) (($g['groupname'] ?? '') !== ''
                ? $g['groupname']
                : get_string('card_nogroup', 'block_feedback_tracker')),
        ];
    }
    $scope = build_pending_report_scope($payloadgroups, (int) $groupid);
} catch (\Throwable $e) {
    debugging('block_feedback_tracker: group list assembly failed: ' . $e->getMessage());
}

/**
 * Pick a single hero-row payload for the report. When the user has filtered
 * to one group, surface that group's stats verbatim. When the report is
 * unfiltered ("all groups"), average the scored groups and sum the counts
 * so the hero shows a course-level picture.
 *
 * @param array $groups        Group payloads from responsiveness_payload.
 * @param int   $activegroupid 0 = whole course.
 * @return array|null
 */
function build_pending_report_scope(array $groups, int $activegroupid): ?array {
    if (empty($groups)) {
        return null;
    }
    if ($activegroupid > 0) {
        foreach ($groups as $g) {
            if ((int) ($g['groupid'] ?? -1) === $activegroupid) {
                return [
                    'score'                => $g['responsiveness_score'],
                    'band'                 => $g['score_band'] ?? 'pending',
                    'median_eff_h'         => $g['median_eff_h'],
                    'perceived_median_hours' => $g['perceived_median_hours'],
                    'compliance_pct'       => $g['compliance_pct'],
                    'trend_pct_30d'        => $g['trend_pct_30d'],
                    'trend_series'         => $g['trend_series'] ?? [],
                    'paused_days_30d'      => $g['paused_days_30d'] ?? 0,
                    'paused_breakdown_30d' => $g['paused_breakdown_30d']
                        ?? ['weekend' => 0, 'holiday' => 0, 'recess' => 0],
                    'paused_events_30d'    => $g['paused_events_30d'] ?? [],
                    'total_pending'        => (int) ($g['pending'] ?? 0),
                    'total_critical'       => (int) ($g['critical'] ?? 0),
                    'total_overgoal'       => (int) ($g['overgoal'] ?? 0),
                ];
            }
        }
        return null;
    }

    // Whole-course aggregate. Average scored groups (pending-weighted to
    // match the OverallBanner math from BlockView), sum counts, take any
    // group's paused aggregate (course-level).
    $totalpending = 0;
    $totalcritical = 0;
    $totalovergoal = 0;
    $scoresum = 0.0;
    $scoreweight = 0.0;
    $effsum = 0.0;
    $effcount = 0;
    $rawsum = 0.0;
    $rawcount = 0;
    $compsum = 0.0;
    $compcount = 0;
    $trendsum = 0.0;
    $trendcount = 0;
    foreach ($groups as $g) {
        $totalpending += (int) ($g['pending'] ?? 0);
        $totalcritical += (int) ($g['critical'] ?? 0);
        $totalovergoal += (int) ($g['overgoal'] ?? 0);
        if (isset($g['responsiveness_score']) && $g['responsiveness_score'] !== null) {
            $weight = max(1, (int) ($g['pending'] ?? 0));
            $scoresum += (float) $g['responsiveness_score'] * $weight;
            $scoreweight += $weight;
        }
        if (isset($g['median_eff_h']) && $g['median_eff_h'] !== null) {
            $effsum += (float) $g['median_eff_h'];
            $effcount++;
        }
        if (isset($g['perceived_median_hours']) && $g['perceived_median_hours'] !== null) {
            $rawsum += (float) $g['perceived_median_hours'];
            $rawcount++;
        }
        if (isset($g['compliance_pct']) && $g['compliance_pct'] !== null) {
            $compsum += (float) $g['compliance_pct'];
            $compcount++;
        }
        if (isset($g['trend_pct_30d']) && $g['trend_pct_30d'] !== null) {
            $trendsum += (float) $g['trend_pct_30d'];
            $trendcount++;
        }
    }
    $first = reset($groups);
    return [
        'score'                => $scoreweight > 0 ? round($scoresum / $scoreweight, 2) : null,
        'band'                 => null,
        'median_eff_h'         => $effcount > 0 ? round($effsum / $effcount, 2) : null,
        'perceived_median_hours' => $rawcount > 0 ? round($rawsum / $rawcount, 2) : null,
        'compliance_pct'       => $compcount > 0 ? round($compsum / $compcount, 2) : null,
        'trend_pct_30d'        => $trendcount > 0 ? round($trendsum / $trendcount, 2) : null,
        'trend_series'         => is_array($first) ? ($first['trend_series'] ?? []) : [],
        'paused_days_30d'      => is_array($first) ? (int) ($first['paused_days_30d'] ?? 0) : 0,
        'paused_breakdown_30d' => is_array($first)
            ? ($first['paused_breakdown_30d'] ?? ['weekend' => 0, 'holiday' => 0, 'recess' => 0])
            : ['weekend' => 0, 'holiday' => 0, 'recess' => 0],
        'paused_events_30d'    => is_array($first) ? ($first['paused_events_30d'] ?? []) : [],
        'total_pending'        => $totalpending,
        'total_critical'       => $totalcritical,
        'total_overgoal'       => $totalovergoal,
    ];
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
    'scope' => $scope,
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
