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
 * Single-day add / edit form for the calendar editor.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Upserts one row in {block_feedback_tracker_cday}.
 */
class calendar_day_form extends \moodleform {
    /**
     * Form definition.
     */
    protected function definition(): void {
        $mform = $this->_form;
        $plugin = 'block_feedback_tracker';

        $mform->addElement(
            'text',
            'daydate',
            get_string('caleditor_col_date', $plugin),
            ['size' => 10, 'placeholder' => 'YYYYMMDD']
        );
        $mform->setType('daydate', PARAM_INT);
        $mform->addRule('daydate', null, 'required', null, 'client');

        $types = [
            'schoolday' => get_string('caleditor_type_schoolday', $plugin),
            'holiday'   => get_string('caleditor_type_holiday', $plugin),
            'recess'    => get_string('caleditor_type_recess', $plugin),
            'closed'    => get_string('caleditor_type_closed', $plugin),
            'optional'  => get_string('caleditor_type_optional', $plugin),
        ];
        $mform->addElement('select', 'daytype', get_string('caleditor_col_type', $plugin), $types);
        $mform->setDefault('daytype', 'holiday');

        $mform->addElement(
            'text',
            'note',
            get_string('caleditor_col_note', $plugin),
            ['size' => 40, 'maxlength' => 255]
        );
        $mform->setType('note', PARAM_TEXT);

        // Use moodleform's default "Save changes" label — that's the unique
        // label Behat scenarios target. The bulk-import form below has a
        // custom "Import" label, so the two buttons stay distinguishable.
        $this->add_action_buttons(false);
    }

    /**
     * Reject malformed dates.
     *
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files): array {
        $errors = parent::validation($data, $files);
        $ymd = (int) ($data['daydate'] ?? 0);
        if ($ymd < 19700101 || $ymd > 99991231) {
            $errors['daydate'] = get_string('caleditor_err_baddate', 'block_feedback_tracker');
        } else {
            $y = (int) substr((string) $ymd, 0, 4);
            $m = (int) substr((string) $ymd, 4, 2);
            $d = (int) substr((string) $ymd, 6, 2);
            if (!checkdate($m, $d, $y)) {
                $errors['daydate'] = get_string('caleditor_err_baddate', 'block_feedback_tracker');
            }
        }
        return $errors;
    }
}
