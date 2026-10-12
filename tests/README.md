# Regression checks

The checks use Zabbix's installed constants and native action collector, with API fixtures. They do not connect to the database or modify monitoring configuration.

With PHP and Node.js installed, set `ZABBIX_UI_ROOT` to the Zabbix frontend directory, then run:

```sh
php tests/regression.php
node tests/regression.mjs
```

For the development containers, run the PHP checks in each frontend:

```sh
docker exec zabbix-dev-zabbix-dashboard-1 php /usr/share/zabbix/modules/Custom-Problem-Analysis/tests/regression.php
docker exec zabbix-dev-zabbix-dashboard-74-1 php /usr/share/zabbix/modules/Custom-Problem-Analysis/tests/regression.php
```

Set `ZABBIX_CONTAINER` to either frontend container name before running `node tests/regression.mjs`. The JavaScript suite evaluates the actual PHP-generated popup script and external assets, then checks escaping, service severities, calendar buckets, table decoration, lazy loading, request deduplication, cache reuse, retries, and popup cleanup using DOM fixtures. The PHP suite verifies that historical queries and complete acknowledgement records are requested only by their respective tabs.

To render an individual tab with native Zabbix HTML classes, run `php tests/emit-popup-script.php timeline` (or `overview`, `host`, `patterns`, `graphs`, `update`).

These are regression checks, not a substitute for authenticated browser testing against a live Zabbix instance.

Overview action checks cover role restrictions, writable triggers, manual-close settings, resolved/pending-close events, button rendering, native update parameters and CSRF token submission, duplicate-click prevention, error recovery, and cache invalidation after updates. All submissions use fixtures; the checks do not acknowledge or close real events.

Graph checks verify reserved image dimensions, frontend-relative URLs, a native popup layout update after late image loads or failures, batched layout callbacks, and cleanup of image listeners and pending callbacks on close.
