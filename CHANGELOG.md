# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.0] - 2026-05-23

### Added — data layer
- Per-submission SLA ledger (`block_feedback_tracker_sub`) tracking raw
  wall-clock hours and business / academic effective hours for every
  `\mod_assign` submission, keyed by `(cmid, userid, attemptnumber)` and
  tied to `groupid`.
- Materialised rollup (`block_feedback_tracker_group`) with pending /
  critical / over-goal counts, raw and effective medians / p90 / max,
  compliance %, 30-day trend, `nextpause` / `lastpause` indicators, and
  the five score-formula component values (`comp_*`) per (course, group).
- Per-course backfill cursors (`block_feedback_tracker_bfcursor`) with
  round-robin starvation prevention across courses.
- Daily trend table for the 30-day sparkline and a site-wide stats table
  for the school-comparison overlay.
- Calendar configuration tables: `_cday` (per-day exceptions), `_chours`
  (weekly business hours), `_cpause` (manual pause windows).
- Dirty queue + audit log tables.

### Added — engine + score
- Academic-time engine with weekly business hours, exception days
  (schoolday / holiday / recess / closed / optional), site / course /
  group manual pause windows, weekend bitmask, and timezone awareness.
- Academic Responsiveness Score (0-100) — five-term weighted formula
  (compliance / median / critical / pending / trend) with admin-tunable
  weights (auto-normalised to sum 1.0), a four-band mapping (excellent /
  good / regular / critical), and per-term component values persisted on
  the rollup so the card UI can show *why* the score is what it is.
- Two-tier caching: per-request memos + MUC application caches
  (`calendar_effective_day`, `pause_windows_by_course`, plus session
  caches for the WS payloads). `calver` site setting bumps on every
  calendar-affecting write so old cache keys naturally fall out.
- Pause timeline computed on demand — no stored pause table needed.

### Added — events + tasks
- Event observers for the assign + group + course lifecycle that upsert
  the ledger and enqueue rollup recompute.
- Three custom plugin events (`cal_day_updated`, `cal_hours_updated`,
  `cal_pause_updated`) so calendar edits drive cache invalidation +
  rollup re-enqueue.
- Scheduled tasks: drain queue (5 min), recompute pending (hourly),
  recompute trend (daily), recompute site stats (daily), purge calendar
  cache (daily), backfill history (inactive by default), prune audit
  log (daily).
- Drain task uses a dispatcher pattern — fans out `recompute_one` adhoc
  tasks for cluster-wide parallelism.
- Backfill dispatcher fans out `backfill_one_submission` adhoc tasks
  with configurable sub-chunk size.
- Concurrency-safe rollup recompute via Lock API guard.

### Added — course processing gate
- `course_access` helper — `is_processable($courseid)` requires a
  `feedback_tracker` block instance on the course context.
- Admin setting `process_hidden_courses` (default OFF).
- SQL pre-filter for backfill using `processable_course_ids()`.

### Added — web services
- 12 external functions: get_responsiveness, get_pending_submissions,
  get_pause_timeline, get_calendar, save_calendar_day,
  bulk_import_calendar, save_business_hours, save_pause_window,
  delete_pause_window, get_dashboard, get_school_comparison,
  get_grader_priority_list.
- CSV importer with per-line error reporting.

### Added — UI
- React UI built on vendored Preact 10.29.2 + htm 3.1.1.
- Block content: SVG ring gauge, counts row, metrics row,
  paused-today / next-pause strip, 30-day sparkline, refresh button,
  drilldown link, collapsible score-breakdown panel.
- Teacher dashboard with hero KPI tiles, sortable courses table,
  optional site benchmarks section, and Grade Now priority panel.
- Pending grading report with sortable/paginated table, server-side
  filters, client-side search, and pause timeline modal.
- Calendar editor page with days / hours / pauses sections and bulk
  CSV import.
- Group drilldown page with sortable pending-submissions table.
- Recompute audit log viewer, full-reset confirmation page, tools
  landing page.

### Added — admin + access
- Site settings: 5 weights, SLA goal, bucket thresholds, calendar
  behaviour switches, weekend mask, timezone selector,
  grading-during-pause mode, performance knobs, view toggles, Tools
  links.
- 9 capabilities (addinstance / myaddinstance plus viewresponsiveness,
  viewdashboard, viewschoolcomparison, managecalendar,
  managepausewindows, resetdata, viewaudit).
- Group-mode-aware visibility on the block.
- `backfill_active` admin UI toggle.

### Added — privacy
- Full GDPR provider implementing metadata + contextlist + export +
  delete for the ledger table.

### Added — testing
- PHPUnit test classes covering academic-time engine, interval math,
  day-rule resolver, pause lookup, CSV importer, bucket classifier,
  statistics helpers, observer, submission ledger, rollup service,
  pending recomputer, score calculator, privacy provider, drain queue
  task, backfill history task, backfill cursor, course access gate,
  and web service functions.
- Behat features for install check, calendar editor, responsiveness
  block, pending report, and teacher dashboard.
- Plugin generator with helpers for seeding fixtures.

### Added — i18n
- Full EN + PT-BR language packs with complete key parity.
- "Feedback tracker" → "Feedback Flow" branding across user-facing
  strings.

### Supported Moodle range
- Moodle 4.5 through 5.2 (`requires = 2024100700`, `supported = [405, 502]`).

### Notes
- `db/upgrade.php` is a stub — fresh installations only, no upgrade
  path from hypothetical prior versions.
- `db/install.xml` is the single source of truth for the schema.
