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
 * Tests for the bucket classifier.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);

namespace block_feedback_tracker\local\sla;

/**
 * Boundary checks for the four-band bucket classifier.
 *
 * @covers \block_feedback_tracker\local\sla\bucket
 */
final class bucket_test extends \advanced_testcase {
    public function test_default_thresholds_classify(): void {
        $this->resetAfterTest();
        set_config('bucket_thresholds_eff', '24,48,120', 'block_feedback_tracker');

        $this->assertSame('excellent', bucket::for_effective(0.0));
        $this->assertSame('excellent', bucket::for_effective(23.99));
        $this->assertSame('good', bucket::for_effective(24.0));
        $this->assertSame('good', bucket::for_effective(47.99));
        $this->assertSame('regular', bucket::for_effective(48.0));
        $this->assertSame('regular', bucket::for_effective(119.99));
        $this->assertSame('critical', bucket::for_effective(120.0));
        $this->assertSame('critical', bucket::for_effective(9999.0));
    }

    public function test_null_hours_returns_pending(): void {
        $this->resetAfterTest();
        $this->assertSame('pending', bucket::for_effective(null));
    }

    public function test_custom_thresholds(): void {
        $this->resetAfterTest();
        set_config('bucket_thresholds_eff', '8,16,40', 'block_feedback_tracker');

        $this->assertSame('excellent', bucket::for_effective(4.0));
        $this->assertSame('good', bucket::for_effective(10.0));
        $this->assertSame('regular', bucket::for_effective(30.0));
        $this->assertSame('critical', bucket::for_effective(100.0));
    }

    public function test_malformed_thresholds_fall_back_to_defaults(): void {
        $this->resetAfterTest();
        set_config('bucket_thresholds_eff', 'garbage', 'block_feedback_tracker');

        $thresholds = bucket::parse_thresholds_eff();
        $this->assertSame([24.0, 48.0, 120.0], $thresholds);
    }
}
