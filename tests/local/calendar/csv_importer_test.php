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
 * Tests for the CSV calendar importer.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\calendar;

/**
 * Format + edge-case coverage for the bulk import parser.
 *
 * @covers \block_feedback_tracker\local\calendar\csv_importer
 */
final class csv_importer_test extends \advanced_testcase {
    public function test_blank_and_comment_lines_skipped(): void {
        $this->resetAfterTest();
        $csv = "\n\n# this is a comment\n2026-04-03, holiday\n";
        $result = csv_importer::import($csv, 0);
        $this->assertSame(1, $result['saved']);
        $this->assertSame([], $result['errors']);
    }

    public function test_semicolon_separator_accepted(): void {
        $this->resetAfterTest();
        $result = csv_importer::import('2026-04-03; holiday; Good Friday', 0);
        $this->assertSame(1, $result['saved']);

        global $DB;
        $row = $DB->get_record('block_feedback_tracker_cday', ['daydate' => 20260403]);
        $this->assertSame('holiday', $row->daytype);
        $this->assertSame('Good Friday', $row->note);
    }

    public function test_invalid_date_reports_error(): void {
        $this->resetAfterTest();
        $result = csv_importer::import('2026-02-30, holiday', 0);
        $this->assertSame(0, $result['saved']);
        $this->assertCount(1, $result['errors']);
        $this->assertSame(1, $result['errors'][0]['line']);
    }

    public function test_unknown_daytype_reports_error(): void {
        $this->resetAfterTest();
        $result = csv_importer::import('2026-04-03, banana', 0);
        $this->assertSame(0, $result['saved']);
        $this->assertCount(1, $result['errors']);
    }

    public function test_missing_fields_reports_error(): void {
        $this->resetAfterTest();
        $result = csv_importer::import('only one field', 0);
        $this->assertSame(0, $result['saved']);
        $this->assertCount(1, $result['errors']);
    }

    public function test_reimport_upserts_same_date(): void {
        $this->resetAfterTest();
        csv_importer::import("2026-04-03, holiday, First", 0);
        csv_importer::import("2026-04-03, recess, Second", 0);

        global $DB;
        $rows = $DB->get_records('block_feedback_tracker_cday', ['daydate' => 20260403]);
        $this->assertCount(1, $rows);
        $row = reset($rows);
        $this->assertSame('recess', $row->daytype);
        $this->assertSame('Second', $row->note);
    }

    public function test_case_insensitive_daytype(): void {
        $this->resetAfterTest();
        $result = csv_importer::import('2026-04-03, HOLIDAY', 0);
        $this->assertSame(1, $result['saved']);
    }
}
