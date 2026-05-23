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
 * Tools landing page for Feedback Flow.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

require_login();
$context = context_system::instance();
require_capability('block/feedback_tracker:viewdashboard', $context);

$PAGE->set_url('/blocks/feedback_tracker/manage.php');
$PAGE->set_context($context);
$PAGE->set_title(get_string('manage_title', 'block_feedback_tracker'));
$PAGE->set_heading(get_string('manage_title', 'block_feedback_tracker'));
$PAGE->set_pagelayout('admin');

$links = [];
if (has_capability('block/feedback_tracker:managecalendar', $context)) {
    $links[] = [
        'url'   => (new moodle_url('/blocks/feedback_tracker/pages/calendar_editor.php'))->out(false),
        'label' => get_string('manage_link_calendar', 'block_feedback_tracker'),
    ];
}
if (has_capability('block/feedback_tracker:viewaudit', $context)) {
    $links[] = [
        'url'   => (new moodle_url('/blocks/feedback_tracker/pages/audit_log.php'))->out(false),
        'label' => get_string('manage_link_audit', 'block_feedback_tracker'),
    ];
}
if (has_capability('block/feedback_tracker:resetdata', $context)) {
    $links[] = [
        'url'   => (new moodle_url('/blocks/feedback_tracker/pages/reset.php'))->out(false),
        'label' => get_string('manage_link_reset', 'block_feedback_tracker'),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('block_feedback_tracker/manage', [
    'heading' => get_string('manage_title', 'block_feedback_tracker'),
    'links'   => $links,
]);
echo $OUTPUT->footer();
