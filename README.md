# Feedback Flow

`block_feedback_tracker` measures teacher response time for manually graded
classwork in Moodle, using **business / academic time** instead of raw
elapsed time. It produces a configurable **Academic Responsiveness Score
(0-100)** per (course, group) tuple and a per-group dashboard card with
median / compliance / trend and pending-backlog indicators.

The plugin is **assign-only**: only `\mod_assign` submissions are tracked.
Data is tied to `groupid`, so reassigning a teacher to a class never
invalidates the historical record.

## Requirements

- Moodle 5.1 (MOODLE_501_STABLE)
- PHP 8.2 or 8.4
- PostgreSQL 15 (recommended) or MariaDB 10

## Installation

1. Clone or download into `<moodle>/blocks/feedback_tracker`.
2. Run `php admin/cli/upgrade.php` (or follow the web upgrade screen).
3. Site administration → Plugins → Blocks → Feedback Flow: review
   settings, especially the **Calendar behaviour** section.

## What it tracks

- **Effective hours** — business / academic hours between a submission and
  its grading, computed by the academic-time engine using the configured
  weekly schedule, weekend mask, holidays, recesses, closures, and manual
  pause windows.
- **Wall-clock hours** — raw elapsed time, displayed alongside effective
  hours so reviewers can see the underlying calendar duration.
- **SLA buckets** — Excellent ≤ 24 h, Good 24–48 h, Regular 48–120 h,
  Critical > 120 h (effective hours; thresholds configurable).
- **Academic Responsiveness Score (0-100)** — five-term weighted formula
  using compliance %, median effective hours, critical share, pending
  backlog, and 30-day trend. Weights are admin-tunable.

## Configuration

All settings live under *Site admin → Plugins → Blocks → Feedback Flow*:

- **Scoring** — five weights (auto-normalised to sum 1.0), SLA goal hours,
  bucket thresholds.
- **Calendar behaviour** — exclude-weekends / -holidays / -recesses
  switches, weekend bitmask, platform timezone, grading-during-pause mode
  (`clipped` default, `live` for audit-only manual pauses).
- **Performance** — drain batch size, time cap, backfill chunk, trend
  window, retention.
- **Views** — show perceived time, show paused-today indicator, school
  comparison overlay.
- **Tools** — links to the calendar editor, recompute audit log, full
  reset.

The **Calendar editor** (`/blocks/feedback_tracker/pages/calendar_editor.php`)
manages calendar days (add/remove + CSV bulk import), weekly business
hours (split shifts supported), and manual pause windows
(site / course / group scope).

## Architecture

- **Per-submission ledger** (`block_feedback_tracker_sub`) is the source of
  truth, with one row per `(cmid, userid, attemptnumber)`. Both
  `waitinghours` (raw) and `effectivehours` (business) are stored, plus a
  cached `slabucket` and the `effectivecalver` for staleness detection.
- **Per-submission pause audit** (`block_feedback_tracker_pause`) records
  every pause that contributed to a submission's effective time
  (weekend / holiday / outofhours / coursepaused / etc.), enabling
  per-submission timeline rendering and audit trails.
- **Materialised rollup** (`block_feedback_tracker_group`) holds
  pre-computed counts, medians, compliance, score, and pause indicators
  per (course, group). The block reads from here, never from the ledger
  directly.
- **Dirty queue** (`block_feedback_tracker_queue`) defers rollup recompute
  to a scheduled `drain_queue` task (every 5 min).
- **Calendar config tables** (`_cday`, `_chours`, `_cpause`) hold the
  platform academic calendar; the `calver` site setting versions them so
  cache keys naturally invalidate on saves.
- **Events** drive everything: submission_changed, submission_graded,
  override_changed, course / cm / group lifecycle events upsert the
  ledger. Custom plugin events (`cal_day_updated`, `cal_hours_updated`,
  `cal_pause_updated`) drive rollup re-enqueue when admins edit the
  calendar.

## MVP scope

This plugin ships with the following deliberately scoped behaviour:

- **Single platform-level calendar.** No per-department or per-course
  calendar overrides yet — every course shares the institution-wide
  schedule. The schema supports adding scope inheritance later.
- **Pause windows at site / course / group level only.** No per-cmid or
  per-submission overrides.
- **Server-rendered UI.** The block, drilldown page, and calendar editor
  render with plain HTML; no AMD / React modules. The WS surface
  (11 functions) is in place for a future client-side enhancement.
- **Business hours = team active window.** The configured weekly hours
  describe the team's collective active window (the union of all teacher
  shifts), not any individual teacher's schedule.

## CLI

```
# Drop every ledger / rollup / queue row (preserves calendar config).
php blocks/feedback_tracker/cli/reset.php [--backfill]

# Recompute one (course, group) rollup on demand.
php blocks/feedback_tracker/cli/recompute_one.php --courseid=N --groupid=N
```

## License

GNU GPL v3 or later.
