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

Set `ZABBIX_CONTAINER` to either frontend container name before running `node tests/regression.mjs`. The JavaScript suite evaluates the actual PHP-generated popup script, then checks escaping, service severities, error handling, calendar buckets, and table decoration using a small DOM fixture.

These are regression checks, not a substitute for authenticated browser testing against a live Zabbix instance.
