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
 * Plugin renderer.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\output;

/**
 * Renders via Mustache. All HTML lives in templates/*.mustache; this class
 * only orchestrates which template handles which renderable.
 */
class renderer extends \plugin_renderer_base {
    /**
     * Render an SVG ring gauge.
     *
     * @param score_gauge $gauge
     * @return string
     */
    protected function render_score_gauge(score_gauge $gauge): string {
        return $this->render_from_template(
            'block_feedback_tracker/score_gauge',
            $gauge->export_for_template($this)
        );
    }

    /**
     * Render one responsiveness card.
     *
     * @param responsiveness_card $card
     * @return string
     */
    protected function render_responsiveness_card(responsiveness_card $card): string {
        return $this->render_from_template(
            'block_feedback_tracker/responsiveness_card',
            $card->export_for_template($this)
        );
    }

    /**
     * Render every group card for one course (with an empty-state fallback).
     *
     * @param int $courseid
     * @param array $groups The `groups` array from the responsiveness payload.
     * @return string
     */
    public function render_course_responsiveness(int $courseid, array $groups): string {
        $cards = [];
        foreach ($groups as $g) {
            $card = new responsiveness_card($courseid, $g);
            $cards[] = $card->export_for_template($this);
        }
        return $this->render_from_template('block_feedback_tracker/responsiveness_block', [
            'empty'     => empty($cards),
            'emptytext' => get_string('card_empty', 'block_feedback_tracker'),
            'cards'     => $cards,
        ]);
    }
}
