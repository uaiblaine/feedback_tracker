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

// The `block/feedback_tracker:viewdashboard` capability may be assigned at the system,
// category, or course level. Teacher roles in particular are usually
// granted at course context, so a system-only `require_capability()` would
// lock out the very people the dashboard targets. Use Moodle's
// per-course capability sweep instead: as long as the user has the
// capability in at least one course, the dashboard renders (filtered to
// those courses by get_dashboard::execute).
global $USER;
$visiblecourses = get_user_capability_course(
    'block/feedback_tracker:viewdashboard',
    (int) $USER->id,
    true,
    'shortname, fullname',
    'fullname ASC'
);
if (empty($visiblecourses)) {
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

// Initial fetch server-side — first paint is data-rich. The WS applies the
// same per-course capability filter so admins and teachers see exactly the
// courses they're entitled to.
try {
    $dashboard = \block_feedback_tracker\external\get_dashboard::execute('');
} catch (\Throwable $e) {
    debugging('block_feedback_tracker: dashboard fetch failed: ' . $e->getMessage());
    $dashboard = ['success' => false, 'courses' => [], 'lastsynced' => time()];
}

// Grade Now panel pre-load — same per-course capability filter, top-10
// most-urgent pending submissions. Failure isn't fatal; the panel renders
// its own empty / error state.
try {
    $gradenow = \block_feedback_tracker\external\get_grader_priority_list::execute(10, '');
} catch (\Throwable $e) {
    debugging('block_feedback_tracker: gradenow fetch failed: ' . $e->getMessage());
    $gradenow = ['success' => false, 'submissions' => [], 'lastsynced' => time()];
}

// Whether to expose the comparison overlay — gated by its own capability so
// teachers with viewdashboard but not viewschoolcomparison only see the
// per-course aggregate, not the site-wide benchmarks.
$cancompare = has_capability('block/feedback_tracker:viewschoolcomparison', $sysctx);

$initial = [
    'userid' => (int) $USER->id,
    'greeting' => get_string(
        'dashboard_hero_greeting',
        'block_feedback_tracker',
        (object) ['firstname' => format_string($USER->firstname)]
    ),
    'dashboard' => $dashboard,
    'gradenow' => $gradenow,
    'cancompare' => $cancompare,
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

echo $OUTPUT->header();
echo '<div data-bft-dashboard-root>';
echo '<script type="application/json" data-bft-init>' . $initialjson . '</script>';
echo '</div>';
echo $OUTPUT->footer();
