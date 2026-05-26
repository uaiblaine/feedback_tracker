# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.0.7] - 2026-05-25

### Phase 4A — Trend-term refinements (Lever 1 + Lever 3)

#### Added
- `responsiveness_calculator::effective_weights($weights, $available)` —
  drops unavailable terms and renormalises the kept weights to sum 1.0.
  Used by both `compute()` and the breakdown panel so the displayed
  weights match what actually contributed to the score.
- `responsiveness_calculator::momentum_pct($courseid, $groupid, $now)` —
  week-over-week % change in median effective hours for one group.
  Returns null when either 7-day window has fewer than 5 grades.
- `insight_momentum_eyebrow` / `insight_momentum_body` lang strings
  (en + pt_br) for the dashboard momentum copy.
- `breakdown_excluded_prefix` lang string (en + pt_br) for the new
  breakdown footnote.
- `.bft-breakdown-footnote` CSS rule.

#### Changed
- `responsiveness_calculator::compute()` — when the rollup has no prior
  30-day window (`trend_pct_30d === null`), the trend term is now
  dropped instead of substituted with 0.5. The other four weights
  renormalise to sum 1.0, so a brand-new course can legitimately
  reach 100. Previously a fresh course's theoretical maximum was 95.
- `components.trend` is now `null` (not `0.5`) when no trend data is
  available — the breakdown panel skips the row and prints a footnote
  explaining the exclusion.
- `responsiveness_card::build_breakdown()` mirrors the JS-side math:
  renormalised weights, excluded-rows footnote.
- `get_insights::pick_most_improved()` — first checks for sharp
  week-over-week momentum (`momentum_pct < -40%` with ≥5 grades each
  week); falls back to the existing 30-day heuristic. The returned
  row carries an optional `momentum` boolean so the JS InsightCard
  can swap eyebrow text + body.
- `DashboardView` consumes the `momentum` flag and renders either the
  "This week's momentum" or "Most improved" copy.

#### Notes
- No schema change, no calver bump — payload shape is additive
  (`momentum` is `VALUE_OPTIONAL`), score component shape is
  unchanged (trend was already declared `NULL_ALLOWED`).
- Momentum recognition is dashboard-only — the score formula never
  sees the week-over-week signal, so the headline number stays
  transparent and explainable.
- Weekly-weighted trend in the score formula (Lever 2) was
  considered for this release; deferred to v1.1.0 so the 95-cap fix
  can soak first before changing what `trend_pct_30d` itself means.

## [1.0.6] - 2026-05-24

### Phase 3F — Web-service completion

#### Added
- New WS `get_audit_log` (`classes/external/get_audit_log.php`) —
  paginated read of `block_feedback_tracker_log` with optional
  `courseid` and `actor` filters. Reuses the existing `viewaudit`
  capability. Returns `{success, total, page, perpage, entries[],
  lastsynced}`.
- Five typed JS write wrappers in `amd/src/lib/api.js`:
  `savePauseWindow`, `deletePauseWindow`, `saveCalendarDay`,
  `bulkImportCalendar`, `saveBusinessHours`. Each mirrors the server's
  `execute_parameters()` signature so callers can pass payload-shaped
  options objects.
- Read wrapper `getAuditLog()` added to `amd/src/lib/api.js`.
- Drift-check phpunit test `services_coverage_test.php` — iterates
  every entry in `db/services.php` and asserts the class exists,
  extends `external_api`, defines `execute_parameters()` / `execute()`
  / `execute_returns()`, and references a declared capability.

#### Notes
- Calendar editor migration to React deferred — the existing
  `pages/calendar_editor.php` is pure server-rendered moodleform with
  no JS to migrate. The future React port (documented in
  `docs/future-features.md`) will adopt the new typed wrappers.

## [1.0.5] - 2026-05-24

### Phase 3E — Dashboard redesign

#### Added
- New WS `get_insights` (`classes/external/get_insights.php`)
  returning `{bright_spot, most_improved, gentle_watch}` for the
  dashboard insight cards. Heuristics: bright spot = top-scoring
  group, most improved = largest negative `trend_pct_30d`, gentle
  watch = group with most critical pending. Null insights are omitted
  from the response. Cached 900s per `(calver, userid)`. Capability:
  `viewdashboard`.
- 8 new Preact components: `WaveMark`, `Sparkle`, `InsightCard`,
  `PriorityCard`, `ResponsivenessHero`, `ResponsivenessHeroSlim`,
  `ResponsivenessModule` (toggle wrapper, persisted via
  `localStorage`), `CoursesTable`.
- `docs/future-features.md` documenting the "On-goal academic days"
  deferral, audit-log React-view deferral, mobile reflow, woff2
  self-hosting, and Moodle 5.2 React migration.

#### Changed
- `DashboardView.js` rewritten — brand-tag → time-aware greeting (with
  `WaveMark`) → subline → collapsible `ResponsivenessModule` →
  Insights row → Priority cards → Courses table.
- `get_dashboard::execute_returns()` extended with per-course
  `score_band`, `median_eff_h`, `perceived_median_hours`, and
  `trend_series` (30-day, from `block_feedback_tracker_trend`).
  `CACHE_KEY_VERSION` bumped to 3.
- `pages/teacher_dashboard.php` pre-loads insights server-side + emits
  `greeting_firstname` so the view can build the greeting from local
  clock.
- `bootstrap::dashboard_i18n()` extended with ~30 new keys (greetings,
  insights eyebrows/bodies, priority labels, business-time chip).

## [1.0.4] - 2026-05-24

### Phase 3D — Pending report page redesign

#### Added
- 4 new Preact components: `PausedCallout` (transparency strip),
  `StatusDistributionBar` (segmented click-to-filter), `HeroMetricCard`
  (children-driven hero card chrome), `SegmentedFilter` (pill row
  with tone-coloured active state).
- Scope-level hero metrics in `pages/pending_report.php` —
  `build_pending_report_scope()` helper picks the active group's
  payload or aggregates across the course when `groupid=0`.

#### Changed
- `PendingReportView.js` rewritten — breadcrumb → title + overall
  band chip → 4-card hero (Score / Effective / SLA / Trend) →
  PausedCallout → StatusDistributionBar → toolbar with segmented
  status filter → table with dual `Effective` (bold colored) +
  `Perceived` columns + per-row `paused` tag where
  `waitinghours − effectivehours > 0.5h`.

## [1.0.3] - 2026-05-24

### Phase 3C — Perceived time + Paused aggregates + Peer data

#### Added
- New `classes/local/calendar/paused_aggregator.php` —
  `for_window(courseid, start, end)` returns `{total_days, weekend,
  holiday, recess}` for the design's transparency callout. Honours
  `excludeweekends` / `excludeholidays` / `excluderecesses` flags;
  `schoolday` override cancels weekend classification; manual pauses
  bucket as recess.
- New `classes/local/score/peer_stats.php` — `for_exclusion(groupid)`
  returns `{department_score, department_hours, top10_score,
  top10_hours}` (p50 / p90 / p10 of group rollups). Returns nulls when
  sample size < 3 so the JS `PeerContext` hides gracefully.
- Tests: `tests/local/calendar/paused_aggregator_test.php` (8 cases
  covering weekends, holiday-overrides-weekend, recess+closed,
  schoolday cancellation, manual pause, course-scope isolation, empty
  window, weekend-exclusion disabled).

#### Changed
- `responsiveness_payload::group_payload()` gains
  `perceived_median_hours` (alias of existing `median_raw_h`),
  `paused_days_30d`, `paused_breakdown_30d`,
  `peer_department_score`, `peer_department_hours`,
  `peer_top10_score`, `peer_top10_hours`.
- `get_responsiveness::execute_returns()` mirrors the new fields
  (strict additive — existing callers ignore unknown keys).
- Upgrade savepoint bumps `calver` via `calendar::bump_version()` so
  MUC keys roll over and cached payloads pick up new keys on first
  read.

## [1.0.2] - 2026-05-24

### Phase 3B — Block view recomposition

#### Added
- 8 new Preact components in `amd/src/components/`: `ScoreRing`,
  `KpiTile`, `TrendRow`, `StatTile`, `PeerContext`, `PausedNote`,
  `TimelineBar`, `OverallBanner`.
- `OverallBanner` mounted at the top of `BlockView.js` — eyebrow +
  ScoreRing + score + band pill + `PausedNote`. Overall score is a
  pending-weighted average across present group scores.
- Activities list inside each `GroupCard` — per-assignment open/close
  `TimelineBar` with urgency colouring and "NO RULE" badge for
  rule-less assignments.

#### Changed
- `GroupCard.js` recomposed — header strip (course + class name +
  status chip) → hero row (`ScoreRing` + score + caption) →
  3-column KPI row (Effective / Perceived / SLA) → `TrendRow`
  (arrow + verbal label + sparkline) → `StatTile` row (Pending /
  At risk / Priority — clickable, deep-link to filtered report) →
  `PeerContext` (optional, hidden when sample too small) →
  `BreakdownPanel` (kept, collapsed) → activities list.
- Mustache SSR fallback templates kept structurally identical;
  visual refresh comes via the CSS tokens introduced in 1.0.1.

## [1.0.1] - 2026-05-24

### Phase 3A — Design tokens & threshold setting

#### Added
- Admin setting `score_thresholds_band` (CSV `"90,70,40"` default,
  mirroring the existing `bucket_thresholds_eff` pattern) routing
  through new helper `responsiveness_calculator::parse_thresholds_band()`.
  PHP `band_for()` reads from the setting; JS
  `bandForScore(score, thresholds)` accepts an explicit thresholds
  arg propagated via `bootstrap::config_bundle()` →
  `initial.config.score_thresholds`.
- `:root` CSS token block — surface / border / text / per-band fg+bg
  pairs / font-family stacks. Per-band tokens are
  `--bft-band-{slug}-fg` / `-bg`.
- `.bft-mono` utility class for numeric cells; `--bft-font-display`
  (Manrope first, system-ui fallback) + `--bft-font-mono`
  (JetBrains Mono first, ui-monospace fallback).

#### Changed
- Band palette across PHP (`score_gauge.php::BAND_COLOURS`), JS
  (`bands.js::BAND_COLOURS`), CSS (badge rules), and gauge stroke now
  uses the calm "Academic Responsiveness" palette: excellent
  `#047857`, good `#0e7490` (teal, replacing amber), regular
  `#b45309`, critical `#be4b25` (softer burnt sienna, replacing
  pure red), pending `#475569`.
- Default font-family promoted to Manrope across `.block_feedback_tracker`
  and `.bft-dashboard`.

#### Notes
- Self-hosting Manrope + JetBrains Mono woff2 files deferred — design
  degrades cleanly to system geometric sans for users without the
  fonts installed.

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
