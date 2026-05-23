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
 * Phase 2A spike harness — admin-only page that mounts every shared
 * Preact component into two roots, so we can smoke-test that the vendored
 * bundle + AMD shim + components all work end-to-end after every build.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');

require_login();
if (!is_siteadmin()) {
    throw new \moodle_exception('nopermissions', 'error', '', 'block_feedback_tracker spike');
}

$PAGE->set_url('/blocks/feedback_tracker/pages/spike_react.php');
$PAGE->set_context(\context_system::instance());
$PAGE->set_title(get_string('spike_react_title', 'block_feedback_tracker'));
$PAGE->set_heading(get_string('spike_react_title', 'block_feedback_tracker'));
$PAGE->set_pagelayout('admin');

// Load the vendored Preact + htm bundle into the document head before any
// AMD module resolves. The `true` second arg places the script in <head>
// and runs synchronously, so window.bftPreact is set before lib/preact.js
// is evaluated.
$PAGE->requires->js(
    new \moodle_url('/blocks/feedback_tracker/js/vendor/bft-vendor-10.29.2-3.1.1.min.js'),
    true
);
$PAGE->requires->js_call_amd('block_feedback_tracker/spike_react', 'init');

echo $OUTPUT->header();
// Two mount points so the spike exercises the multi-root querySelectorAll
// branch — every future view (block / report / dashboard) will rely on it.
echo '<div data-bft-spike-root></div>';
echo '<hr aria-hidden="true">';
echo '<div data-bft-spike-root></div>';
echo $OUTPUT->footer();
