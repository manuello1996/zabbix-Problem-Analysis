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

Popup startup checks render through the installed native `CView`, then execute the actual `script_inline` response without any preloaded popup asset. They also verify that opening another popup reuses the runtime, retains existing instances and pending requests, and mounts the new popup independently. Lazy tab responses contain no runtime or mount call.

Overview action checks cover role restrictions, writable triggers, manual-close settings, resolved/pending-close events, button rendering, native update parameters and CSRF token submission, duplicate-click prevention, error recovery, and cache invalidation after updates. All submissions use fixtures; the checks do not acknowledge or close real events.

Problem details checks cover explicit problem/closing/resolved status, linked event references, multiple trigger hosts, escaped multiline descriptions and tags, native collapsed expressions, immediate status changes after an accepted close, and unknown duration when a resolved event's recovery timestamp is unavailable. The existing acknowledgement, severity, and close-action checks run against the refactored view.

Update comment checks cover the footer Add comment action, opening Update and revealing/focusing its editor, separate add-comments permission, native message limits and CSRF token, blank/multiline comments, duplicate submissions, retained drafts on failure, and clearing/hiding the editor after success. Popup checks verify Add comment immediately before the native Cancel footer action and that Cancel remains enabled during submissions. Refresh checks cover its placement beside Close, request cancellation, discarding inactive tab content, lazy refetching, retry after failure, and preventing an old response from clearing a newer request's loading state.

Item-history checks cover three-value batches, timestamp/nanosecond pagination without duplicates, exhaustion, inaccessible or unrelated items, binary values, request deduplication, retained previews on failure, escaped rendering, and cancellation on refresh or close. More reuses `problemanalist.tab.get` with `tab=history`, so no additional module action registration is needed.
The input-validation check uses the installed Zabbix controller and validator to verify that More retains its item ID and cursor after validation and rejects missing or invalid paging parameters. It requires no authentication or database connection.
The history-formatting check uses the actual view helper and installed Zabbix formatter to verify bps, B, Bps, s, uptime, unixtime, disabled unit scaling, zero, floating-point precision, exact uint64 raw values, value maps, escaping, and untrimmed text. Native mapped values retain their raw value once; unit-converted values append the original raw representation.

Graph checks verify native select/calendar/collapsible markup, unique control IDs, individual versus combined images, escaped item names, matching native graph/value links, empty states, custom input visibility, request deduplication, preserving graphs after invalid input, active-tab stability, refresh behavior, and cancellation on refresh or close. The generated image URLs are resolved with Zabbix's actual `getTimeSelectorPeriod()` helper to ensure the chart honors the selected period, including exactly six hours ending at problem start. This catches missing `profileIdx`, which makes native charts use the default period despite explicit `from` and `to`. They also verify reserved image dimensions, frontend-relative URLs, native popup layout updates after late image loads or failures, batched layout callbacks, and cleanup on close. Native input checks cover graph parameters, preset boundaries, recovery timestamps, frontend timezone/DST, and Zabbix's period limits.

Manual-close checks cover delayed recovery, transient read failures, displaying the actual recovery event timestamp and Recovery time row, preserving the selected tab, and cancelling recovery tracking when the popup closes.
They also verify an immediate Pending row after an accepted close and automatic recovery tracking when a popup mounts with a server-reported pending closure.

SLA checks cover configured names and targets, multiple SLAs, disabled SLAs, lookup failures versus absent configuration, unavailable/zero SLI, period-first response indexing, service ID mapping, reporting periods, negative error budgets, escaping, and request-local cache isolation.
Services interaction checks cover per-card hierarchies, escaped tag-rule explanations, search by service or ancestor name, empty search results, expanded selected cards, removal of redundant refresh controls, and reloading Services with the shared popup Refresh button.
