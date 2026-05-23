# Claude instructions for `block_feedback_tracker`

This file is auto-loaded as context whenever Claude works in this plugin's
directory tree. It captures the **Moodle development standards** this plugin
follows so future edits stay in the same style and pass CI on the first try.

Plugin context: a Moodle block plugin that measures teacher response time
for `mod_assign` submissions using business/academic time. Supports
Moodle **4.5 through 5.2** (`$plugin->requires = 2024100700`,
`$plugin->supported = [405, 502]`). CI is the
**catalyst/catalyst-moodle-workflows** reusable workflow — it derives
the test matrix (PHP 8.1+/8.2+/8.4+ × PostgreSQL 15 / MariaDB 10) from
the supported range. Development happens on 5.1; cross-version
verification depends on per-stable-branch CI runs.

## Coding style

### File header

Every PHP file starts with:

```php
<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software... [full GPL block]
// ...

/**
 * <One-line file description>.
 *
 * @package    block_feedback_tracker
 * @copyright  2026 Anderson Blaine <anderson@blaine.com.br>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

declare(strict_types=1);   // for namespaced classes

namespace block_feedback_tracker\<sub>;

defined('MOODLE_INTERNAL') || die();
```

Procedural files (settings.php, lib.php, db/*.php) skip `declare(strict_types=1)`
and `namespace`. `defined('MOODLE_INTERNAL') || die()` is required everywhere.

### PHPDoc

Moodle's `phpdoc --max-warnings 0` enforces:

- Every class, method, property, and constant has a `/** */` docblock
- `@param`, `@return`, `@throws` declared explicitly (even when types are
  fully implied by signatures)
- Type hints in PHPDoc use `int|null`, `?int`, `array<int, string>` —
  match the actual PHP type
- File-level docblock has `@package`, `@copyright`, `@license`
- No `@author` tags (Moodle convention)

### Naming

- Classes: `lower_snake_case` (Moodle convention, not PSR-4 PascalCase)
- Methods: `lower_snake_case`
- Constants: `UPPER_SNAKE_CASE`
- Properties: `lowercase` (no camel/snake mixing — single lowercase word
  where possible)
- Frankenstyle prefix on globals: `block_feedback_tracker_*`

### Table prefix

Database tables use the **full frankenstyle**: `block_feedback_tracker_*`.
The longest table name is `block_feedback_tracker_chours` at 29 chars,
inside the 30-char limit. The four calendar tables use a `c` prefix
(`_cday`, `_chours`, `_cpause`, `_cscope`) to stay within the limit.

### Lang strings

`lang/en/block_feedback_tracker.php` strings are **alphabetically sorted**.
The CI's `moodle-plugin-ci validate` step enforces ordering. Insert new
strings in the correct alphabetic position.

Required strings:
- One per capability: `feedback_tracker:<capname>` (`block/` prefix is dropped
  in the lang key)
- One per scheduled / adhoc task: `task_<classname>`
- One per custom event: `event_<eventname>`
- One per cache definition: `cachedef_<name>`
- One per admin setting: `settings_<key>` and `settings_<key>_desc`

### Dynamic string references

The PHPDoc / string-checker can't statically verify dynamically-constructed
string IDs. **Don't** do `get_string('band_' . $band, ...)`. **Do** use a
literal switch / match:

```php
private static function band_label(string $band): string {
    switch ($band) {
        case 'excellent': return get_string('band_excellent', 'block_feedback_tracker');
        case 'good':      return get_string('band_good',      'block_feedback_tracker');
        // ...
        default:          return '';
    }
}
```

### CodeSniffer rules that bite

Four rules from `moodle.*` / `PSR2.*` standards routinely break CI on this
plugin. Pre-empt them at write time.

**1. Variables are lower-case only.** No camelCase, no snake_case — a single
lower-case word (concatenated if needed). Sniff:
`moodle.NamingConventions.ValidVariableName.VariableNameLowerCase`.

```php
// ✘ $cmA, $studentA, $cm_a, $student_a
// ✓ $cma, $studenta
```

**2. PSR-2 multi-line function call layout.** When a call spans lines:
opening `(` is the last content on its line; one argument per line; closing
`)` on its own line at the call's indent level. Sniffs:
`PSR2.Methods.FunctionCallSignature.{ContentAfterOpenBracket,MultipleArguments,Indent,CloseBracketLine}`.

```php
// ✘ Two args on the wrap line:
$DB->set_field('block_feedback_tracker_sub', 'groupid', $groupa->id,
    ['userid' => $studenta->id, 'courseid' => $course->id]);

// ✓ One arg per line, ) on its own line:
$DB->set_field(
    'block_feedback_tracker_sub',
    'groupid',
    $groupa->id,
    ['userid' => $studenta->id, 'courseid' => $course->id]
);
```

**3. Inline `//` comments — one space, capital first letter, no inline
indentation.** Need indented/aligned/list-style commentary? Use a block
comment (`/* ... */`). Sniff: `moodle.Commenting.InlineComment.SpacingBefore`.

```php
// ✘ Inline indentation + dashes inside //:
//   - g.courseid = X       (unrestricted)
//   - g.courseid = X AND g.groupid IN (...)  (restricted)

/*
 * ✓ Same content as a block comment — formatting preserved:
 *   - g.courseid = X       (unrestricted)
 *   - g.courseid = X AND g.groupid IN (...)  (restricted)
 */
```

**4. Property docblocks need `@var`.** A `/** ... */` on a class property
*must* declare the type even when PHP's typed-property syntax already does.
Sniff: `moodle.Commenting.VariableComment.MissingVar`.

```php
// ✘ /** Per-request memo keyed by "courseid:userid". */
//   private static array $memo = [];

/** @var array<string, int[]|null> Per-request memo keyed by "courseid:userid". */
private static array $memo = [];
```

## Database (XMLDB)

- Every `<FIELD>` element needs `SEQUENCE="true"` or `SEQUENCE="false"`
  explicitly. Missing → XSD validation fails.
- Validate locally:
  `xmllint --noout --schema lib/xmldb/xmldb.xsd db/install.xml`
- `db/install.php`'s `xmldb_<plugin>_install()` function uses raw
  `set_config()` and direct `$DB->insert_record()` — these **don't fire
  setting update callbacks**, so they're safe for default seeding.

### Upgrade savepoints

Each upgrade step ends with:
```php
upgrade_block_savepoint(true, <version>, 'feedback_tracker');
```
Match `<version>` to the version.php bump.

### Cross-DB SQL

CI runs against both PostgreSQL 15 and MariaDB 10. Patterns that break:

- `SELECT :literal FROM table` — PG infers the placeholder as text and
  comparisons against bigint columns fail. **Fix**: select from `{context}`
  with `EXISTS()` predicates instead:

  ```sql
  SELECT ctx.id
    FROM {context} ctx
   WHERE ctx.id = :sysctxid
     AND (EXISTS (SELECT 1 FROM {plugin_table} WHERE ...))
  ```

- `ORDER BY col ASC NULLS FIRST` — PG-only. Use `COALESCE(col, 0) ASC`
  for cross-DB.

- `\moodle_database::get_records()` returns string values for numeric
  columns under both drivers. Cast to `(int)` / `(float)` when typing matters.

## Forms (moodleform)

Each form class file sits under `classes/form/` and starts with:

```php
namespace block_feedback_tracker\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');   // moodleform is not autoloaded

class my_form extends \moodleform { ... }
```

Conventions:

- Use moodleform's default button label (`add_action_buttons(false)` →
  "Save changes") unless the form genuinely needs a unique verb (e.g.
  "Import" for bulk CSV).
- For float fields where users may type non-canonical strings (e.g.
  `"0.40"`), **don't use `PARAM_FLOAT`** — its validator strict-string-
  compares against the `clean_param` result, which normalises `"0.40"` to
  `"0.4"`. Use a regex paramtype: `'/^[0-9]+(\.[0-9]+)?$/'`.
- Custom validation in `validation($data, $files)` — return an array of
  `field => errormsg` strings.

## Custom events

```php
class my_event extends \core\event\base {
    protected function init(): void {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_OTHER;
        // Do NOT set 'objecttable' unless EVERY caller will pass an objectid.
        // The two must appear together or not at all — bulk-operation
        // callers that have no single object id need the event to omit
        // objecttable.
    }
}
```

Pair: `objectid` + `objecttable` must both be present or both absent in
`::create()` data. If you set one without the other, Moodle throws a
`coding_exception`.

## Processing scope (course_access gate)

Since v1.3.0 the plugin is **strict opt-in**: nothing happens for a
course unless a `feedback_tracker` block instance lives on that course's
own context (category- and system-context blocks deliberately don't
count — they don't render on courses). Hidden courses are also skipped
unless the `process_hidden_courses` admin setting is on.

The gate lives in
[`classes/local/sla/course_access.php`](classes/local/sla/course_access.php).
`course_access::is_processable($courseid)` is the single decision
point — call it whenever you add a new event observer or batch job that
writes ledger / rollup data. Don't reimplement the check.

The gate is applied at every **write-path entry**:
- Event observers in `classes/local/sla/observer.php`
  (submission_changed / submission_graded / override_changed /
  group_membership_changed / group_deleted).
- `classes/task/backfill_history.php` per-row filter.

Cleanup paths (`course_deleted`, `course_module_deleted`) skip the gate
so previously-tracked data still gets garbage-collected when its course
goes away. `rollup_service::recompute_group()` deliberately does NOT
gate — it's downstream of the observer + queue and gating there would
force a wide test-fixture rewrite without closing any leak.

When adding a PHPUnit test that fires assign events or invokes
backfill, add a block instance to the course in your setup helper:
```php
$coursectx = \context_course::instance($course->id);
$this->getDataGenerator()->create_block('feedback_tracker', [
    'parentcontextid' => $coursectx->id,
]);
course_access::reset_memo();  // flush memo for recycled courseids
```

## MUC caches

Keys must avoid characters that are unsafe in file paths (no `:`).
Convention used in this plugin: `"{calver}_{<id>}"`. The `calver` site
setting is bumped on every calendar-affecting save so old cache keys
naturally fall out of routing — no explicit purge call is needed for
calver-keyed caches.

## Mustache templates

Every `templates/*.mustache` file must include an `Example context (json):`
block in its docblock. The Mustache lint renders the template against
that context and validates the resulting HTML.

```mustache
{{!
    @template    block_feedback_tracker/my_template

    Description.

    Context variables required:
    * field   Type   What it represents

    Example context (json):
    {
        "field": "example value"
    }
}}
<div>{{field}}</div>
```

When a template renders a table whose `<thead><tr>{{#cols}}<th>...</th>{{/cols}}</tr></thead>`
loop would produce empty `<tr></tr>` with empty cols, the example context
**must** supply non-empty cols — otherwise the HTML validator rejects the
preview render.

For raw HTML insertion (form HTML from `moodleform::render()`), use
triple-stash: `{{{form_html}}}`.

## Renderables

Server-side rendering uses the `templatable` + Mustache pattern, **not**
inline `html_writer`:

```php
class my_renderable implements \renderable, \templatable {
    public function export_for_template(\renderer_base $output): array {
        return [...];
    }
}

// In the renderer:
protected function render_my_renderable(my_renderable $r): string {
    return $this->render_from_template('block_feedback_tracker/my_template',
        $r->export_for_template($this));
}
```

**Zero `html_writer` calls** in plugin code. The only exceptions are
moodleform's own internal markup (which Moodle controls).

## Web services

- All function classes under `classes/external/` extend
  `\core_external\external_api`
- Function paramters: `execute_parameters()` returns an
  `external_function_parameters`
- Return shape: `execute_returns()` returns an `external_single_structure`
- Every read function checks `validate_context()` + `require_capability()`;
  every write function does the same + fires an event
- Don't call WS classes from within `block_base::get_content()` — the WS's
  `validate_context()` calls `$PAGE->set_context()` which adds body
  classes, and the header has already started by then. Use a separate
  data-loading helper (e.g. `responsiveness_payload::for_course()`) that
  both the WS and the block call directly.

## Capability checks

- Always pass an explicit `\context` — never rely on `$PAGE->context`
- `has_capability('mod/assign:grade', $context, $userid)` respects the
  user's **real** role assignments, not Moodle's "Switch role to..."
  temporary state — useful for filtering role-switched test submissions

## Install / upgrade guards

Any function called from a setting's `set_updatedcallback` (the most common
trigger: `block_feedback_tracker_invalidate_rollups`) must short-circuit
while the plugin is bootstrapping. Otherwise `admin_apply_default_settings()`
runs the callback **before** the plugin's MUC stores are registered, and
cache calls fail with a `debugging()` notice that breaks `phpunit
--fail-on-warning`.

The canonical guard is in [`lib.php`](lib.php):
`block_feedback_tracker_is_bootstrapping()`, combining three checks:

1. `during_initial_install()` — first-run Moodle install.
2. `!empty($CFG->upgraderunning)` — any plugin install/upgrade (including
   `admin/tool/phpunit/cli/util.php --install`).
3. `!$DB->get_manager()->table_exists('block_feedback_tracker_group')` —
   belt-and-suspenders for partial installs / restored backups.

## PHPUnit tests

- Every test file in `tests/<area>/<thing>_test.php`
- Class: `block_feedback_tracker\<namespace>\<thing>_test extends \advanced_testcase`
- `@covers \block_feedback_tracker\...` annotation on the class docblock
- Call `$this->resetAfterTest()` in every test that touches the DB
- DB rows from `$DB->get_records()` and `getDataGenerator()->create_*()`
  return **string** ids under both drivers. Cast to `(int)` when passing
  to typed-int method signatures, e.g.
  `submission_ledger::upsert_for_cm_user_attempt((int) $cm->id, (int) $student->id, 0)`.
- `submission_graded` events must be constructed via
  `\mod_assign\event\submission_graded::create_from_grade($assigninst, $grade)` —
  direct `::create()` throws `cannot be called directly`. Requires
  `require_once($CFG->dirroot . '/mod/assign/locallib.php')` + instantiating
  `new \assign($context, $cm, $course)`.
- `submission_ledger::upsert_for_cm_user_attempt()` requires an existing
  `{assign_submission}` row — without one it returns `null` and creates
  no ledger row.
- `assertContains` is strict (`===`) by default. When asserting against
  DB-derived arrays (which carry string ids), normalise the haystack:
  `array_map('intval', $contextlist->get_contextids())`.

## Behat scenarios

- Multi-line text into fields: use the `... to multiline:` step + PyString,
  not `\n` escapes in a quoted string (Behat treats `\n` as literal).
- `I press "Save changes"` matches moodleform's default submit-button label
  (`add_action_buttons(false)` with no second arg).

## Settings (settings.php) reset pattern

- Per-form-write settings carry `set_updatedcallback('block_feedback_tracker_invalidate_rollups')`
- The five score weights do **not** chain through any normalisation
  callback. Normalisation happens at read time in
  `responsiveness_calculator::load_weights()`, not at save time —
  save-time normalisation races with `admin_apply_default_settings()`
  and corrupts values mid-install.

## Score formula

Normalisation is **read-time only**. `load_weights()` rescales values
to sum 1.0 if the stored sum is outside `[0.95, 1.05]`. Stored values
are kept as the admin typed them.

## React conventions (Phase 2A foundation)

Moodle 5.1 doesn't ship React. The plugin vendors **Preact + htm**
(API-compatible with React, no JSX build step) and exposes them through
a single AMD shim. Moodle 5.2's native React subsystem will replace this
with a one-file change to the shim.

### Vendor layout

- Vendored UMD code lives in [`js/vendor/`](js/vendor/), **never** under
  `amd/`. Moodle's grunt only globs `amd/src/**/*.js`, so anything in
  `js/vendor/` is left alone by ESLint and Babel.
- Declared in [`thirdpartylibs.xml`](thirdpartylibs.xml) (three library
  entries, all pointing to the single concatenated bundle).
- Loaded into `<head>` via `$PAGE->requires->js(..., $inhead = true)` so
  the bundle's globals are set before the AMD loader resolves any module.
- The bundle is wrapped in an outer IIFE that shadows `define`, forcing
  the upstream UMDs to take their global-script branch instead of
  registering as anonymous AMD modules.

### AMD shim ([`amd/src/lib/preact.js`](amd/src/lib/preact.js))

The only file that reads `window.bftPreact` / `window.bftPreactHooks` /
`window.bftHtm`. Every component imports through it:

```js
import {html, useState} from 'block_feedback_tracker/lib/preact';
```

Never import from a `preact` / `react` specifier (that path doesn't
exist) and never reach for `window.bft*` directly outside the shim.

### Markup syntax

`htm` tagged templates — no JSX, no Babel preset:

```js
return html`
    <div class="bft-card">
        <${ScoreGauge} score=${score} band=${band} />
        ${items.map((it) => html`<span key=${it.id}>${it.label}</span>`)}
    </div>
`;
```

Component references use `<${ComponentName}>`. Children that are
arrays must carry a `key` attribute (Preact's reconciliation rule).

### Component conventions

- Files in `amd/src/components/*.js` are default-exported function
  components. One component per file. PascalCase filenames match the
  component name.
- Components are **stateless** unless local state is genuinely needed.
  Where state is required, use hooks from the shim
  (`useState`, `useEffect`, `useReducer`, etc.).
- Props match the **existing** payload shape from
  `responsiveness_payload::group_payload()` / `responsiveness_card.php`
  so Phase 2B can feed them without transforms. Don't invent new keys.
- CSS classes are `bft-*` BEM from [`styles.css`](styles.css). No
  inline styles except SVG geometry attributes (cx, r, viewBox, etc.).
- Band colours and slugs are defined once in
  [`amd/src/lib/bands.js`](amd/src/lib/bands.js) and mirror the PHP
  constants in `classes/output/score_gauge.php::BAND_COLOURS`. Keep
  them in lockstep.

### Mount-point convention

Entrypoints find their roots via a `data-bft-<role>-root` attribute and
mount each one — never assume a single root, course pages can host
multiple block instances:

```js
document.querySelectorAll('[data-bft-spike-root]').forEach((el) => {
    render(html`<${App} />`, el);
});
```

### Idempotent init

Mirror the existing pattern from
[`amd/src/responsiveness.js`](amd/src/responsiveness.js):

```js
export const init = () => {
    if (window.bftXxxInitDone) { return; }
    window.bftXxxInitDone = true;
    // ...
};
```

### Web-service calls

Always through [`amd/src/lib/api.js`](amd/src/lib/api.js) — one named
export per WS. Errors flow through `core/notification.exception()` then
re-throw so the caller's UI can react. Don't call `Ajax.call([...])`
inline in a component.

### Build artefacts

Every new `amd/src/**/*.js` file must have its `amd/build/**/*.min.js`
counterpart committed in the same PR. Build with
`npx grunt amd --root=public/blocks/feedback_tracker` from Moodle root.

### Dev loop

- *Site admin → Development → Debug messages = DEVELOPER* and
  *cachejs = off* — Moodle loads `amd/src/*.js` directly (no rebuild
  needed on save).
- Visit `/blocks/feedback_tracker/pages/spike_react.php` as site admin
  for the canonical smoke test (mounts all six shared components).

### Forward migration (Moodle 5.2+)

When the plugin's required Moodle version bumps to 5.2+ (which ships
React natively via `react`/`react-dom` import-map specifiers), the
migration is mechanical:

- `js/vendor/bft-vendor-*.min.js` → delete.
- `amd/src/lib/preact.js` → re-export from `react` / `react/jsx-runtime`.
- Move `amd/src/components/*.js` → `js/esm/src/components/*.tsx`,
  optionally rename `html\`...\`` to JSX (htm still works in 5.2).
- `$PAGE->requires->js(..., true)` of the bundle → remove.
- Spike page's raw mount-point divs → `{{#react}}` Mustache helper.

Component logic, hook usage, props shapes, and CSS classes stay the same.

## CI workflow

The plugin uses **catalyst/catalyst-moodle-workflows** as a reusable
workflow. The single job in [`.github/workflows/gha.yml`](.github/workflows/gha.yml)
delegates to that workflow.

## When in doubt

Follow the patterns in existing files. The codebase is internally
consistent — if a new file feels like it doesn't match any existing
shape, that's a signal to re-examine the approach.
