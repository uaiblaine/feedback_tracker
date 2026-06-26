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
 * Teacher dashboard (Phase 2D) — React-driven cross-course overview.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');

require_login();
$sysctx = \context_system::instance();

// Access gate, centralised in dashboard_scope: a user may open the
// dashboard if they hold an active enrolment with a teacher-or-higher role
// in at least one course — or hold a full-site grant (the viewalldata
// capability at system context, or a site admin with enable_admin_view_all
// on; then visible_course_ids() returns null, "the whole site"). A
// non-admin with zero visible courses is locked out. The same helper feeds
// the web services so page and data agree on scope.
global $USER;
$scope = \block_feedback_tracker\local\sla\dashboard_scope::visible_course_ids((int) $USER->id);
if ($scope !== null && empty($scope)) {
    throw new \required_capability_exception(
        $sysctx,
        'block/feedback_tracker:viewdashboard',
        'nopermissions',
        'error'
    );
}

$PAGE->set_url('/blocks/feedback_tracker/pages/teacher_dashboard.php');
$PAGE->set_context($sysctx);
$PAGE->set_title(get_string('dashboard_title', 'block_feedback_tracker'));
$PAGE->set_heading(get_string('dashboard_title', 'block_feedback_tracker'));
$PAGE->set_pagelayout('admin');

// Data loads asynchronously from the Preact app after mount (see
// amd/src/views/DashboardView.js) so the first byte ships immediately and the
// page never blocks on per-course / per-group aggregation — previously the
// three execute() calls below ran synchronously here, and on a site with
// dozens of courses/groups get_insights' per-group momentum pass alone issued
// thousands of ledger queries before the page was sent. The app now fetches
// the course rows first (cheap rollup read; the global score is computed
// client-side from them) and lazy-loads the grade-now list + insights after
// the first paint. Each web service re-applies the same dashboard_scope gate,
// so moving the fetch to the client does not widen visibility.
$dashboard = null;
$gradenow = null;
$insights = null;

// V1.0.11 — site-scope events sidecar so the dashboard subline can
// surface the most recent named optional event. The calendar is
// site-wide, so a single aggregator pass at courseid=0 reaches every
// event an admin has configured. Window matches the per-group payload
// (last 30 days) for symmetry with the block + report.
$events = [];
try {
    $now = time();
    $aggregate = \block_feedback_tracker\local\calendar\paused_aggregator::for_window(
        0,
        $now - 30 * 86400,
        $now
    );
    $events = $aggregate['events'] ?? [];
} catch (\Throwable $e) {
    debugging('block_feedback_tracker: dashboard events fetch failed: ' . $e->getMessage());
}

// Scheduled-pause notice ("Pausa prevista") — up to 3 pauses visible now
// (3 days before → day after), site scope so a single pass reaches every
// platform-wide calendar pause an admin has configured. Same lazy-tolerant
// pattern as the events sidecar above.
$upcoming = [];
try {
    $upcoming = \block_feedback_tracker\local\calendar\upcoming_pauses::for_display(0, 0, time());
} catch (\Throwable $e) {
    debugging('block_feedback_tracker: dashboard upcoming-pauses fetch failed: ' . $e->getMessage());
}

// Whether to expose the comparison overlay — gated by its own capability so
// teachers with viewdashboard but not viewschoolcomparison only see the
// per-course aggregate, not the site-wide benchmarks.
$cancompare = has_capability('block/feedback_tracker:viewschoolcomparison', $sysctx);

// V1.0.8 — persist the dashboard hero+insights collapse state per user
// via the core preferences API. Default '0' (expanded) matches a new
// user's expectation that the rich hero is visible on first visit.
// Preference declared in lib.php::block_feedback_tracker_user_preferences().
$dashboardcollapsed = (bool) get_user_preferences(
    'block_feedback_tracker_dashboard_collapsed',
    '0',
    (int) $USER->id
);

$initial = [
    'userid' => (int) $USER->id,
    'greeting_firstname' => format_string($USER->firstname),
    'dashboard' => $dashboard,
    'gradenow' => $gradenow,
    'insights' => $insights,
    'events' => $events,
    'upcoming' => $upcoming,
    'cancompare' => $cancompare,
    'dashboard_collapsed' => $dashboardcollapsed,
    'i18n' => array_merge(
        \block_feedback_tracker\local\output\bootstrap::i18n_bundle(),
        \block_feedback_tracker\local\output\bootstrap::dashboard_i18n()
    ),
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
$PAGE->requires->js_call_amd('block_feedback_tracker/dashboard_app', 'init');

// Log this page view to the standard site log; user, IP and origin are
// captured automatically. Fired once per navigation, not in the web services.
$event = \block_feedback_tracker\event\report_viewed::create([
    'context' => $sysctx,
    'other' => ['report' => 'dashboard'],
]);
$event->trigger();

echo $OUTPUT->header();
echo '<div data-bft-dashboard-root>';
echo '<script type="application/json" data-bft-init>' . $initialjson . '</script>';
echo '</div>';
echo $OUTPUT->footer();
