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
 * Block Feedback Flow
 *
 * Documentation: {@link https://moodledev.io/docs/apis/plugintypes/blocks}
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Feedback Flow block class definition.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_feedback_tracker extends block_base {
    /** Vendored Preact + htm bundle (path relative to plugin root). */
    private const VENDOR_BUNDLE = '/blocks/feedback_tracker/js/vendor/bft-vendor-10.29.2-3.1.1.min.js';

    /**
     * Block initialisation
     */
    public function init() {
        $this->title = get_string('pluginname', 'block_feedback_tracker');
    }

    /**
     * Get content
     *
     * On a course page, emits a React mount-point div carrying the initial
     * payload + i18n + config as JSON. The existing server-rendered Mustache
     * cards are wrapped inside <noscript> for graceful degradation. On the
     * site front page or dashboard the block emits a short hint instead —
     * those surfaces aren't supported in this MVP.
     *
     * @return stdClass
     */
    public function get_content() {
        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = (object) ['text' => '', 'footer' => ''];

        $courseid = (int) ($this->page->course->id ?? SITEID);
        if ($courseid <= 0 || $courseid === SITEID) {
            $renderer = $this->page->get_renderer('block_feedback_tracker');
            $this->content->text = $renderer->render_from_template(
                'block_feedback_tracker/empty_hint',
                ['message' => get_string('block_addtocoursehint', 'block_feedback_tracker')]
            );
            return $this->content;
        }

        $context = context_course::instance($courseid);
        if (!has_capability('block/feedback_tracker:viewresponsiveness', $context)) {
            return $this->content;
        }

        global $USER;
        $groups = [];
        $lastsynced = 0;
        try {
            $result = \block_feedback_tracker\local\payload\responsiveness_payload::for_course(
                $courseid,
                (int) $USER->id
            );
            $groups = is_array($result['groups'] ?? null) ? $result['groups'] : [];
            $lastsynced = (int) ($result['lastsynced'] ?? 0);
        } catch (\Throwable $e) {
            debugging('block_feedback_tracker: payload assembly failed: ' . $e->getMessage());
        }

        $renderer = $this->page->get_renderer('block_feedback_tracker');
        $ssrhtml = $renderer->render_course_responsiveness($courseid, $groups);

        // Bootstrap payload for the React tree — initial groups + every
        // localised label + the score-formula config. Phase 2C/2D will reuse
        // the same shape from their own bootstrap helpers.
        $payload = $this->build_block_payload($courseid, $groups, $lastsynced);
        // JSON_HEX_TAG protects against "</script>" appearing inside a
        // payload string from breaking out of the inline JSON island.
        $payloadjson = json_encode(
            $payload,
            JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if ($payloadjson === false) {
            $payloadjson = '{}';
        }

        $this->content->text = '<div data-bft-block-root data-courseid="' . $courseid . '">'
            . '<script type="application/json" data-bft-init>' . $payloadjson . '</script>'
            . '<noscript>' . $ssrhtml . '</noscript>'
            . '</div>';

        // Load the Preact bundle into <head> (inhead=true) so its globals
        // are set before any AMD module factory resolves.
        $this->page->requires->js(new \moodle_url(self::VENDOR_BUNDLE), true);
        $this->page->requires->js_call_amd('block_feedback_tracker/block_app', 'init');

        return $this->content;
    }

    /**
     * Assemble the JSON bootstrap consumed by block_app.js. The shared
     * i18n + config bundles live in classes/local/output/bootstrap.php so
     * standalone pages (pending_report.php, teacher_dashboard.php) can
     * autoload them — Moodle doesn't autoload block classes outside the
     * blocks subsystem.
     *
     * @param int $courseid
     * @param array $groups Group payload entries (25-key shape).
     * @param int $lastsynced Unix timestamp of the last rollup compute.
     * @return array
     */
    private function build_block_payload(int $courseid, array $groups, int $lastsynced): array {
        return [
            'courseid' => $courseid,
            'lastsynced' => $lastsynced,
            'groups' => $groups,
            'i18n' => \block_feedback_tracker\local\output\bootstrap::i18n_bundle(),
            'config' => \block_feedback_tracker\local\output\bootstrap::config_bundle(),
        ];
    }

    /**
     * Expose settings.php in the site administration tree.
     *
     * @return bool
     */
    public function has_config() {
        return true;
    }

    /**
     * Where this block can be placed. Course pages are the supported surface;
     * site front and my-dashboard render a short hint.
     *
     * @return array<string, bool>
     */
    public function applicable_formats() {
        return [
            'course-view' => true,
            'site' => false,
            'my' => false,
            'admin' => false,
        ];
    }
}
