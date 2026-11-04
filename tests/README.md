# Regression checks

The checks use Zabbix's installed constants and native action collector, with API fixtures. They do not connect to the database or modify monitoring configuration.

With PHP and Node.js installed, set `ZABBIX_UI_ROOT` to the Zabbix frontend directory, then run:

```sh
php tests/regression.php
php tests/input-validation.php
php tests/history-formatting.php
node tests/regression.mjs
```

For the development containers, run the PHP checks in each frontend:

```sh
docker exec zabbix-dev-zabbix-dashboard-1 php /usr/share/zabbix/modules/Custom-Problem-Analysis/tests/regression.php
docker exec zabbix-dev-zabbix-dashboard-1 php /usr/share/zabbix/modules/Custom-Problem-Analysis/tests/input-validation.php
docker exec zabbix-dev-zabbix-dashboard-1 php /usr/share/zabbix/modules/Custom-Problem-Analysis/tests/history-formatting.php
docker exec zabbix-dev-zabbix-dashboard-74-1 php /usr/share/zabbix/modules/Custom-Problem-Analysis/tests/regression.php
```

Set `ZABBIX_CONTAINER` to either frontend container name before running `node tests/regression.mjs`. The JavaScript suite evaluates the actual PHP-generated popup script and external assets, then checks escaping, service severities, calendar buckets, table decoration, lazy loading, request deduplication, cache reuse, retries, and popup cleanup using DOM fixtures. The PHP suite verifies that historical queries and complete acknowledgement records are requested only by their respective tabs.

To render an individual tab with native Zabbix HTML classes, run `php tests/emit-popup-script.php timeline` (or `overview`, `host`, `patterns`, `graphs`, `update`).

These are regression checks, not a substitute for authenticated browser testing against a live Zabbix instance.

Recurrence checks cover column order, duplicate prevention, table separators, viewport-based loading, batching and shared trigger results, zero versus failed counts, retries, cache reuse, stale row cleanup, and request cancellation. They verify that each count opens its own event directly on Timeline while Details keeps Overview. The server checks use the native time parser and validate grouped, permission-scoped counts of problem events over the rolling six-month window, including missing/inaccessible events and count-query failures. Native input checks cover the 40-event request limit and the initial Timeline parameter. Counts reuse `problemanalist.tab.get` with `tab=recurrences`, so no new module action is required.

Popup startup checks render through the installed native `CView`, then execute the actual `script_inline` response without any preloaded popup asset. They also verify that opening another popup reuses the runtime, retains existing instances and pending requests, and mounts the new popup independently. Lazy tab responses contain no runtime or mount call.

Overview action checks cover role restrictions, writable triggers, manual-close settings, resolved/pending-close events, button rendering, native update parameters and CSRF token submission, duplicate-click prevention, error recovery, and cache invalidation after updates. All submissions use fixtures; the checks do not acknowledge or close real events.

Problem details checks cover explicit problem/closing/resolved status, linked event references, multiple trigger hosts, escaped multiline descriptions and tags, native collapsed expressions, immediate status changes after an accepted close, and unknown duration when a resolved event's recovery timestamp is unavailable. The existing acknowledgement, severity, and close-action checks run against the refactored view.

Update comment checks cover the footer Add comment action, opening Update and revealing/focusing its editor, separate add-comments permission, native message limits and CSRF token, blank/multiline comments, duplicate submissions, retained drafts on failure, and clearing/hiding the editor after success. Popup checks verify Add comment immediately before the native Cancel footer action and that Cancel remains enabled during submissions. Refresh checks cover its placement beside Close, request cancellation, discarding inactive tab content, lazy refetching, retry after failure, and preventing an old response from clearing a newer request's loading state.
Update Info checks use native Zabbix icons, hint boxes, and suppression-name enrichment. They cover correlation/manual closure (with metadata read from the recovery event), inaccessible rule/user names, manual and maintenance suppression, indefinite suppression, pending suppress/unsuppress, escaped names, simultaneous information icons, and hiding an empty Info section. Info must precede the Actions table, start expanded, show details as visible table text, and use independent native collapse controls. Suppression data is requested only for Update.

Item-history checks cover three-value batches, timestamp/nanosecond pagination without duplicates, exhaustion, inaccessible or unrelated items, binary values, request deduplication, retained previews on failure, escaped rendering, and cancellation on refresh or close. More reuses `problemanalist.tab.get` with `tab=history`, so no additional module action registration is needed.
Dense text-history fixtures also verify that cursor expansion reads timestamps only within one second and fetches at most four value payloads per page. Query checks ensure Graphs skips last-value/value-map work and historical tabs skip unused trigger/recovery enrichment.

Timeline checks cover initial and appended 20-event pages, one-event lookahead, final partial/empty pages, same-second events, large IDs, out-of-order timestamps, unrelated/invalid cursors, and API failures. Opening any of five matching problems must show all five, including newer occurrences. Paging from the oldest event covers newer open and resolved occurrences across multiple pages, including cursors newer than the opened event. Outgoing paging bounds pass Zabbix's native API ID validator under its configured BC Math precision, preventing decimal suffixes on event IDs. Native view fixtures verify event links, Update actions, row-only fragments, and cursor metadata. Interaction checks cover delegated More clicks, duplicate requests, preserving loaded rows and the active tab, retries, exhaustion, and cancellation on refresh or close. More uses `problemanalist.tab.get` with `tab=timeline_events` and skips monthly comparisons and unrelated tab data.

Monthly Comparison checks cover 3/6/12-month presets, Custom-only Apply, native date controls, period validation, frontend DST, clipped boundary months, zero-count months and percentage changes. API probes verify one aggregate `countOutput` query per displayed month and no raw-event transfer or unrelated tab queries. Interaction checks verify that `tab=monthly` responses update only the comparison, preserve loaded event pages and the selected tab, retain valid data after failures, and support refresh and cancellation. The live fixture compares all three presets with actual event counts.
Ordering checks ensure all Problem events precede other states across the complete history, including older open events beyond the first chronological page. They cover pending closure, pages spanning both groups, more than twenty open problems, same-second ordering in each group, and entirely resolved histories. A validated group cursor continues pagination in the correct group. Native Problem/Event APIs fetch metadata for ordering and full action data only for the displayed twenty rows. The live fixture also checks this order through every More page.
The input-validation check uses the installed Zabbix controller and validator to verify that More retains its item ID and cursor after validation and rejects missing or invalid paging parameters. It requires no authentication or database connection.
The history-formatting check uses the actual view helper and installed Zabbix formatter to verify bps, B, Bps, s, uptime, unixtime, disabled unit scaling, zero, floating-point precision, exact uint64 raw values, value maps, escaping, and untrimmed text. Native mapped values retain their raw value once; unit-converted values append the original raw representation.

Graph checks verify native select/calendar/collapsible markup, unique control IDs, individual versus combined images, escaped item names, matching native graph/value links, empty states, custom input visibility, request deduplication, preserving graphs after invalid input, active-tab stability, refresh behavior, and cancellation on refresh or close. The generated image URLs are resolved with Zabbix's actual `getTimeSelectorPeriod()` helper to ensure the chart honors the selected period, including exactly six hours ending at problem start. This catches missing `profileIdx`, which makes native charts use the default period despite explicit `from` and `to`. They also verify reserved image dimensions, frontend-relative URLs, native popup layout updates after late image loads or failures, batched layout callbacks, and cleanup on close. Native input checks cover graph parameters, preset boundaries, recovery timestamps, frontend timezone/DST, and Zabbix's period limits.

Manual-close checks cover delayed recovery, transient read failures, displaying the actual recovery event timestamp and Recovery time row, preserving the selected tab, and cancelling recovery tracking when the popup closes.
They also verify an immediate Pending row after an accepted close and automatic recovery tracking when a popup mounts with a server-reported pending closure.

SLA checks cover configured names and targets, multiple SLAs, disabled SLAs, lookup failures versus absent configuration, unavailable/zero SLI, period-first response indexing, service ID mapping, reporting periods, negative error budgets, escaping, and request-local cache isolation.
Services interaction checks cover per-card hierarchies, escaped tag-rule explanations, search by service or ancestor name, empty search results, expanded selected cards, removal of redundant refresh controls, and reloading Services with the shared popup Refresh button.

Time Patterns checks cover all four calendar-month presets, custom absolute/relative ranges, range validation, and frontend timezone/DST. View fixtures verify selects, calendars, the original chart/grid presentation, Expand controls, range metadata, and valid month lengths. Interaction checks cover immediate preset changes, Custom-only Apply, preserving charts after errors, refresh/cancellation, composed month/day/hour filters, a stable colour scale, keyboard movement, closing/reopening day drilldowns, empty states, independent popups, and listener cleanup. The renderer retains the original visual design without a D3 dependency.
Compact-payload checks cover repeated timestamps, both occurrences of the DST fallback hour, and 43,200 events reduced to hourly bins. Filtering must reuse mounted chart nodes, and malformed responses must preserve the working heatmap.

Event-name checks cover distinct macro expansions on the same trigger, exact matching rather than prefix search, separate recurrence caches, shared queries for matching names, and rejecting a Timeline cursor belonging to another name. Patterns and Monthly Comparison must apply the same stored-name filter.

## Opt-in live log fixture

`event-name-live.py` creates a dedicated host **CPA Event Name Test** and template **CPA Event Name Template** through the Zabbix API. The template uses a log trapper with the key `eventlog[System,,Error,,,,skip]`, expression `logseverity(...)=4`, multiple problem-event generation, and event name `Error Notification - ID {ITEM.LOG.EVENTID}`. It simulates Windows log metadata through Zabbix's sender protocol; no Windows agent is needed.

Run against the local development instance:

```sh
python tests/event-name-live.py setup
docker exec zabbix-dev-zabbix-server-1 zabbix_server -R config_cache_reload
python tests/event-name-live.py push 1001 --count 25
python tests/event-name-live.py push 1002 --count 7
python tests/event-name-live.py push 10010 --count 2
python tests/event-name-live.py verify
```

Allow the server to load the configuration before pushing (the runtime command requests an immediate reload). The script defaults to `http://127.0.0.1`, sender port `10051`, and the development `Admin`/`zabbix` login. Override credentials with `ZABBIX_USER` and `ZABBIX_PASSWORD`; `--url`, `--server`, and `--port` precede the subcommand. Setup reuses only objects carrying this fixture's ownership description. The host and template remain available for manual inspection.

`push` submits real log values, severity, source, and Windows event ID; it can be run repeatedly to generate additional occurrences. `verify` compares real events to authenticated module responses for Recurrences, Recent Events including More, Monthly Comparison, and Time Patterns. The 25-event sample exercises a second Timeline page; `10010` checks that filtering does not use a `1001` prefix. This live check creates monitoring configuration and problem events; the regression suites above remain fixture-only.
