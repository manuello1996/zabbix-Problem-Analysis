<?php declare(strict_types = 0);

/**
 * Controller for analist problem popup
 */

namespace Modules\AnalistProblem\Actions;

use CController;
use CControllerResponseData;
use API;
use CArrayHelper;
use CMacrosResolverHelper;
use CSettingsHelper;
use CSeverityHelper;
use CRoleHelper;
use CRangeTimeParser;
use CParser;
use CTimePeriodHelper;
use Manager;

/**
 * Controller for event details popup
 */
class CControllerAnalistProblemPopup extends CController {

    protected function init(): void {
        $this->disableCsrfValidation();
    }

    protected function checkInput(): bool {
        $fields = [
            'eventid'       => 'required|id',
            'tab'           => 'string|in overview,host,timeline,patterns,graphs,update,history',
            'triggerid'     => 'id',
            'hostid'        => 'id',
            'hostname'      => 'string',
            'problem_name'  => 'string',
            'severity'      => 'int32',
            'clock'         => 'int32',
            'acknowledged'  => 'int32',
            'itemid'        => 'id',
            'before_clock'  => 'int32|ge 0',
            'before_ns'     => 'int32|ge 0|le 999999999',
            'graph_period'  => 'in around,before,duration,day,custom',
            'graph_layout'  => 'in items,combined',
            'graph_from'    => 'range_time',
            'graph_to'      => 'range_time'
        ];

        $ret = $this->validateInput($fields);
        if ($ret && $this->getInput('tab', 'overview') === 'history') {
            $ret = $this->validateInput(array_replace($fields, [
                'itemid' => 'required|id',
                'before_clock' => 'required|int32|ge 0',
                'before_ns' => 'required|int32|ge 0|le 999999999'
            ]));
        }
        if ($ret && $this->getInput('tab', 'overview') === 'graphs'
                && $this->getInput('graph_period', 'around') === 'custom') {
            $ret = $this->validateInput(array_replace($fields, [
                'graph_from' => 'required|range_time', 'graph_to' => 'required|range_time'
            ]));
        }

        if (!$ret) {
            $this->setResponse(
                (new CControllerResponseData(['main_block' => json_encode([
                    'error' => [
                        'messages' => array_column(get_and_clear_messages(), 'message')
                    ]
                ])]))->disableView()
            );
        }

        return $ret;
    }

    protected function checkPermissions(): bool {
        return $this->checkAccess('ui.monitoring.problems');
    }

    protected function doAction(): void {
        $eventid = $this->getInput('eventid');
        $hostid = $this->getInput('hostid', 0);
        $tab = $this->getInput('tab', 'overview');



        // Get event details
        $events = API::Event()->get([
            'output' => ['eventid', 'source', 'object', 'objectid', 'clock', 'ns', 'value', 'acknowledged', 'name',
                'severity', 'opdata', 'r_eventid'
            ],
            'eventids' => $eventid,
            'selectTags' => ['tag', 'value'],
            'selectAcknowledges' => $tab === 'update' ? ['acknowledgeid', 'clock', 'userid', 'message', 'action',
                'old_severity', 'new_severity', 'suppress_until'
            ] : null
        ]);

        $event = $events ? $events[0] : [];

        if (!$event || (int) $event['source'] !== EVENT_SOURCE_TRIGGERS
                || (int) $event['object'] !== EVENT_OBJECT_TRIGGER
                || (int) $event['value'] !== TRIGGER_VALUE_TRUE) {
            $this->setResponse((new CControllerResponseData(['main_block' => json_encode([
                'error' => ['messages' => [_('Problem event not found or access denied.')]]
            ])]))->disableView());
            return;
        }

        if ($tab === 'history') {
            $this->getMoreHistory($event);
            return;
        }

        if (!empty($event['acknowledges'])) {
            CArrayHelper::sort($event['acknowledges'], ['clock', 'acknowledgeid']);

            foreach ($event['acknowledges'] as $acknowledge) {
                $action = (int) ($acknowledge['action'] ?? 0);

                if (($action & ZBX_PROBLEM_UPDATE_ACKNOWLEDGE) === ZBX_PROBLEM_UPDATE_ACKNOWLEDGE) {
                    $event['acknowledged'] = EVENT_ACKNOWLEDGED;
                }
                elseif (($action & ZBX_PROBLEM_UPDATE_UNACKNOWLEDGE) === ZBX_PROBLEM_UPDATE_UNACKNOWLEDGE) {
                    $event['acknowledged'] = EVENT_NOT_ACKNOWLEDGED;
                }
            }
        }

        $recovery_eventid = (int) ($event['r_eventid'] ?? 0);

        if ($recovery_eventid > 0) {
            $recovery_events = API::Event()->get([
                'output' => ['eventid', 'clock'],
                'eventids' => $recovery_eventid
            ]);

            if ($recovery_events) {
                $event['recovery_clock'] = $recovery_events[0]['clock'];
            }
        }

        // The event determines the trigger; request hints must not override it.
        $trigger = null;
        $actual_triggerid = $event['objectid'];

        if ($actual_triggerid > 0 && in_array($tab, ['overview', 'host', 'timeline', 'graphs'], true)) {
            $triggers = API::Trigger()->get([
                'output' => ['triggerid', 'description', 'expression', 'comments', 'priority', 'opdata', 'manual_close',
                    'recovery_mode', 'recovery_expression', 'type', 'status'
                ],
                'triggerids' => $actual_triggerid,
                'selectHosts' => ['hostid', 'host', 'name'],
                'selectItems' => in_array($tab, ['overview', 'graphs'], true)
                    ? ['itemid', 'hostid', 'name', 'key_'] : null,
                'expandExpression' => true
            ]);
            $trigger = $triggers ? $triggers[0] : null;
        }

        $can_acknowledge = $tab === 'overview' && $this->checkAccess(CRoleHelper::ACTIONS_ACKNOWLEDGE_PROBLEMS);
        $can_comment = in_array($tab, ['overview', 'update'], true)
            && $this->checkAccess(CRoleHelper::ACTIONS_ADD_PROBLEM_COMMENTS);
        $can_change_severity = false;
        $editable_triggers = [];
        if ($tab === 'overview' && $trigger
                && ($this->checkAccess(CRoleHelper::ACTIONS_CHANGE_SEVERITY)
                    || (!$recovery_eventid && (int) ($trigger['manual_close'] ?? 0) === ZBX_TRIGGER_MANUAL_CLOSE_ALLOWED
                        && $this->checkAccess(CRoleHelper::ACTIONS_CLOSE_PROBLEMS)))) {
            $editable_triggers = API::Trigger()->get([
                'output' => ['triggerid'], 'triggerids' => $actual_triggerid, 'editable' => true
            ]);
            $can_change_severity = (bool) $editable_triggers
                && $this->checkAccess(CRoleHelper::ACTIONS_CHANGE_SEVERITY);
        }
        $can_close = false;
        $closing_pending = false;
        if ($tab === 'overview' && !$recovery_eventid && $trigger
                && (int) ($trigger['manual_close'] ?? 0) === ZBX_TRIGGER_MANUAL_CLOSE_ALLOWED
                && $this->checkAccess(CRoleHelper::ACTIONS_CLOSE_PROBLEMS)) {
            // Manual closure requires a writable trigger and no pending close action, as in the native popup.
            if ($editable_triggers) {
                $close_events = API::Event()->get([
                    'output' => ['eventid'], 'eventids' => $eventid, 'selectAcknowledges' => ['action']
                ]);
                $can_close = !empty($close_events);
                foreach ($close_events[0]['acknowledges'] ?? [] as $acknowledge) {
                    if (((int) $acknowledge['action'] & ZBX_PROBLEM_UPDATE_CLOSE) !== 0) {
                        $can_close = false;
                        $closing_pending = true;
                        break;
                    }
                }
            }
        }

        // Overview needs only visible names and inventory notes for this trigger's hosts.
        if ($tab === 'overview' && !empty($trigger['hosts'])) {
            $overview_hosts = API::Host()->get([
                'output' => ['hostid', 'name'],
                'hostids' => array_column($trigger['hosts'], 'hostid'),
                'selectInventory' => ['notes'],
                'preservekeys' => true
            ]);
            foreach ($trigger['hosts'] as $index => $trigger_host) {
                if (isset($overview_hosts[$trigger_host['hostid']])) {
                    $trigger['hosts'][$index] = array_replace($trigger_host, $overview_hosts[$trigger_host['hostid']]);
                }
            }
        }

        // Get host details with comprehensive data for hostcard
        $host = null;
        $actual_hostid = 0;

        // Only select a host belonging to the event's trigger.
        if ($trigger && !empty($trigger['hosts'])) {
            $actual_hostid = $trigger['hosts'][0]['hostid'];
            // Preserve the selected host only when it belongs to this event's trigger.
            foreach ($trigger['hosts'] as $trigger_host) {
                if ((string) $trigger_host['hostid'] === (string) $hostid) {
                    $actual_hostid = $trigger_host['hostid'];
                    break;
                }
            }

        }

        if ($actual_hostid > 0) {
            if ($tab === 'host') {
                $host = $this->getHostCardData($actual_hostid);
            }
            else {
                foreach ($trigger['hosts'] as $trigger_host) {
                    if ((string) $trigger_host['hostid'] === (string) $actual_hostid) {
                        $host = $trigger_host;
                        break;
                    }
                }
            }
        }

        // Get problem events for Time Patterns.
        $pattern_events = [];
        if ($tab === 'patterns' && $actual_triggerid > 0) {
            // Get problem events from the last 12 months for Time Patterns/Heatmap.
            $pattern_time_till = isset($event['clock']) ? (int)$event['clock'] : time();
            $pattern_time_from = strtotime('-11 months', strtotime(date('Y-m-01 00:00:00', $pattern_time_till)));

            $pattern_events = API::Event()->get([
                'output'    => ['clock'],
                'source'    => 0, // EVENT_SOURCE_TRIGGERS
                'object'    => 0, // EVENT_OBJECT_TRIGGER
                'objectids' => $actual_triggerid,
                'time_from' => $pattern_time_from,
                'time_till' => $pattern_time_till,
                'value'     => 1, // Only problem events
                'sortfield' => 'clock',
                'sortorder' => 'DESC'
            ]);
            if ($pattern_events === false) {
                $this->setResponse((new CControllerResponseData(['main_block' => json_encode([
                    'error' => ['messages' => [_('Unable to load time patterns.')]]
                ])]))->disableView());
                return;
            }
        }

        // Get items for graphs - usar itemids do selectItems diretamente
        $items = [];
        $trigger_items = [];

        if ($trigger && $actual_triggerid > 0 && in_array($tab, ['overview', 'graphs'], true)) {
            if (isset($trigger['items']) && !empty($trigger['items'])) {
                // Get itemids from selectItems and ensure uniqueness
                $trigger_itemids = array_column($trigger['items'], 'itemid');
                $unique_itemids = array_unique($trigger_itemids);

                $trigger_items = API::Item()->get([
                    'output' => ['itemid', 'name', 'key_', 'hostid', 'value_type', 'units', 'lastvalue', 'lastclock',
                        'valuemapid'
                    ],
                    'itemids' => $unique_itemids,
                    'monitored' => true,
                    'selectValueMap' => ['mappings']
                ]);

                // Use itemid as key to prevent any potential duplicates.
                $items_by_id = [];
                foreach ($trigger_items as $item) {
                    if (in_array((int) $item['value_type'], [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64], true)) {
                        $items_by_id[$item['itemid']] = $item;
                    }
                }

                $items = array_values($items_by_id);
            }
        }

        // Get monthly comparison data (last 6 months)
        $monthly_comparison = [];

        if ($tab === 'timeline' && $actual_triggerid > 0 && isset($event['clock'])) {
            $event_timestamp = (int)$event['clock'];

            // Base at the first day of the event's month 00:00:00
            $base_month_start = strtotime(date('Y-m-01 00:00:00', $event_timestamp));

            $months = [];

            // Build last 6 months: 0 = current month, 1..5 = back in time.
            for ($i = 0; $i < 6; $i++) {
                $start = strtotime("-{$i} month", $base_month_start);
                $end = mktime(23, 59, 59, (int)date('n', $start), (int)date('t', $start), (int)date('Y', $start));

                $months[$i] = [
                    'name'   => date('F Y', $start),
                    'count'  => 0,
                    'events' => [],
                    'start'  => $start,
                    'end'    => $end
                ];
            }

            $comparison_events = API::Event()->get([
                'output'    => ['clock'],
                'source'    => 0,
                'object'    => 0,
                'objectids' => $actual_triggerid,
                'time_from' => $months[5]['start'],
                'time_till' => $months[0]['end'],
                'value'     => 1
            ]);
            if ($comparison_events === false) {
                $this->setResponse((new CControllerResponseData(['main_block' => json_encode([
                    'error' => ['messages' => [_('Unable to load monthly comparison.')]]
                ])]))->disableView());
                return;
            }

            foreach ($comparison_events as $comparison_event) {
                $clock = (int) $comparison_event['clock'];

                foreach ($months as &$month) {
                    if ($clock >= $month['start'] && $clock <= $month['end']) {
                        $month['events'][] = $comparison_event;
                        $month['count']++;
                        break;
                    }
                }
                unset($month);
            }

            // Add month-over-month percentage change for each entry where we have a previous month
            for ($i = 0; $i < 6; $i++) {
                if (!isset($months[$i + 1])) {
                    $months[$i]['change_percentage'] = null; // no previous to compare to
                    continue;
                }

                $prev = (int)$months[$i + 1]['count'];
                $cur  = (int)$months[$i]['count'];

                if ($prev > 0) {
                    $months[$i]['change_percentage'] = round((($cur - $prev) / $prev) * 100, 1);
                } else {
                    $months[$i]['change_percentage'] = ($cur > 0) ? 100.0 : 0.0;
                }
            }

            $monthly_comparison = [
                'months' => $months,

                // Backward compatibility (your current view code uses these)
                'current_month'    => $months[0],
                'previous_month'   => $months[1] ?? ['name' => '', 'count' => 0, 'events' => [], 'start' => null, 'end' => null],
                'change_percentage'=> $months[0]['change_percentage'] ?? 0
            ];
        }
        // Get monthly comparison data


        $operational_data = $tab === 'overview'
            ? $this->getOperationalData($trigger, $event, $trigger_items)
            : ['value' => '', 'history' => []];

        $graph_timeline = null;
        if ($tab === 'graphs') {
            try {
                $graph_timeline = $this->getGraphTimeline($event);
            }
            catch (\Exception $exception) {
                $this->setResponse((new CControllerResponseData(['main_block' => json_encode([
                    'error' => ['messages' => [$exception->getMessage()]]
                ])]))->disableView());
                return;
            }
        }

        // Prepare data for view
        $data = [
            'tab' => $tab,
            'is_fragment' => $this->getAction() === 'problemanalist.tab.get',
            'can_acknowledge' => $can_acknowledge,
            'can_comment' => $can_comment,
            'can_change_severity' => $can_change_severity,
            'can_close' => $can_close,
            'closing_pending' => $closing_pending,
            'event' => $event,
            'trigger' => $trigger,
            'host' => $host,
            'pattern_events' => $pattern_events,
            'items' => $items,
            'monthly_comparison' => $monthly_comparison,
            'operational_data' => $operational_data,
            'graph_timeline' => $graph_timeline,
            'graph_layout' => $this->getInput('graph_layout', 'items'),
            'user' => [
                'debug_mode' => $this->getDebugMode()
            ]
        ];

        $this->setResponse(new CControllerResponseData($data));
    }

    /**
     * Get system metrics at event time based on host interface type
     */
    /**
     * Get comprehensive host data for hostcard display
     */
    private function getHostCardData($hostid) {
        $options = [
            'output' => ['hostid', 'name', 'status', 'maintenanceid', 'maintenance_status', 'maintenance_type',
                'description', 'active_available', 'monitored_by', 'proxyid', 'proxy_groupid'
            ],
            'hostids' => $hostid,
            'selectHostGroups' => ['name'],
            'selectInterfaces' => ['interfaceid', 'ip', 'dns', 'port', 'main', 'type', 'useip', 'available',
                'error', 'details'
            ],
            'selectParentTemplates' => ['templateid'],
            'selectTags' => ['tag', 'value'],
            'selectInheritedTags' => ['tag', 'value']
        ];

        // Always get counts for monitoring section
        $options['selectGraphs'] = API_OUTPUT_COUNT;
        $options['selectHttpTests'] = API_OUTPUT_COUNT;

        // Get inventory fields
        $inventory_fields = getHostInventories();
        $options['selectInventory'] = array_column($inventory_fields, 'db_field');

        $db_hosts = API::Host()->get($options);

        if (!$db_hosts) {
            return null;
        }

        $host = $db_hosts[0];

        // Get maintenance details if in maintenance
        if ($host['status'] == HOST_STATUS_MONITORED && $host['maintenance_status'] == HOST_MAINTENANCE_STATUS_ON) {
            $db_maintenances = API::Maintenance()->get([
                'output' => ['name', 'description'],
                'maintenanceids' => [$host['maintenanceid']]
            ]);

            $host['maintenance'] = $db_maintenances
                ? $db_maintenances[0]
                : [
                    'name' => _('Inaccessible maintenance'),
                    'description' => ''
                ];
        }

        // Get problem count for header
        if ($host['status'] == HOST_STATUS_MONITORED) {
            $db_problems = API::Problem()->get([
                'output' => ['eventid', 'severity'],
                'source' => EVENT_SOURCE_TRIGGERS,
                'object' => EVENT_OBJECT_TRIGGER,
                'hostids' => [$host['hostid']],
                'suppressed' => false,
                'symptom' => false
            ]);

            $host_problems = [];
            foreach ($db_problems as $problem) {
                $host_problems[$problem['severity']][$problem['eventid']] = true;
            }

            for ($severity = TRIGGER_SEVERITY_COUNT - 1; $severity >= TRIGGER_SEVERITY_NOT_CLASSIFIED; $severity--) {
                $host['problem_count'][$severity] = array_key_exists($severity, $host_problems)
                    ? count($host_problems[$severity])
                    : 0;
            }
        }

        // Sort host groups
        CArrayHelper::sort($host['hostgroups'], ['name']);

        // Get items count
        $db_items_count = API::Item()->get([
            'countOutput' => true,
            'hostids' => [$host['hostid']],
            'webitems' => true,
            'monitored' => true
        ]);

        // Get dashboard count (HostDashboard API is not available in all Zabbix versions)
        $host['dashboard_count'] = 0;
        if (method_exists(API::class, 'HostDashboard')) {
            $host['dashboard_count'] = API::HostDashboard()->get([
                'countOutput' => true,
                'hostids' => $host['hostid']
            ]);
        }

        $host['item_count'] = $db_items_count;
        $host['graph_count'] = $host['graphs'];
        $host['web_scenario_count'] = $host['httpTests'];

        unset($host['graphs'], $host['httpTests']);

        // Prepare interfaces for availability section
        $interface_enabled_items_count = getEnabledItemsCountByInterfaceIds(
            array_column($host['interfaces'], 'interfaceid')
        );

        foreach ($host['interfaces'] as &$interface) {
            $interfaceid = $interface['interfaceid'];
            $interface['has_enabled_items'] = array_key_exists($interfaceid, $interface_enabled_items_count)
                && $interface_enabled_items_count[$interfaceid] > 0;
        }
        unset($interface);

        // Add active agent interface if there are enabled active items
        $enabled_active_items_count = getEnabledItemTypeCountByHostId(ITEM_TYPE_ZABBIX_ACTIVE, [$host['hostid']]);
        if ($enabled_active_items_count) {
            $host['interfaces'][] = [
                'type' => INTERFACE_TYPE_AGENT_ACTIVE,
                'available' => $host['active_available'],
                'has_enabled_items' => true,
                'error' => ''
            ];
        }

        unset($host['active_available']);

        // Get proxy/proxy group info
        if ($host['monitored_by'] == ZBX_MONITORED_BY_PROXY) {
            $db_proxies = API::Proxy()->get([
                'output' => ['name'],
                'proxyids' => [$host['proxyid']]
            ]);
            $host['proxy'] = $db_proxies[0];
        }
        elseif ($host['monitored_by'] == ZBX_MONITORED_BY_PROXY_GROUP) {
            $db_proxy_groups = API::ProxyGroup()->get([
                'output' => ['name'],
                'proxy_groupids' => [$host['proxy_groupid']]
            ]);
            $host['proxy_group'] = $db_proxy_groups[0];
        }

        // Get templates
        if ($host['parentTemplates']) {
            $db_templates = API::Template()->get([
                'output' => ['templateid', 'name'],
                'selectParentTemplates' => ['templateid', 'name'],
                'templateids' => array_column($host['parentTemplates'], 'templateid'),
                'preservekeys' => true
            ]);

            CArrayHelper::sort($db_templates, ['name']);

            foreach ($db_templates as &$template) {
                CArrayHelper::sort($template['parentTemplates'], ['name']);
            }
            unset($template);

            $host['templates'] = $db_templates;
        }
        else {
            $host['templates'] = [];
        }

        unset($host['parentTemplates']);

        // Merge host tags with inherited tags
        if (!$host['inheritedTags']) {
            $tags = $host['tags'];
        }
        elseif (!$host['tags']) {
            $tags = $host['inheritedTags'];
        }
        else {
            $tags = $host['tags'];

            foreach ($host['inheritedTags'] as $template_tag) {
                foreach ($tags as $host_tag) {
                    // Skip tags with same name and value
                    if ($host_tag['tag'] === $template_tag['tag']
                            && $host_tag['value'] === $template_tag['value']) {
                        continue 2;
                    }
                }

                $tags[] = $template_tag;
            }
        }

        CArrayHelper::sort($tags, ['tag', 'value']);
        $host['tags'] = $tags;

        return $host;
    }

    private function getGraphTimeline(array $event): array {
        $period = $this->getInput('graph_period', 'around');
        $event_clock = (int) $event['clock'];
        $now = time();
        switch ($period) {
            case 'custom':
                $from = $this->getInput('graph_from');
                $to = $this->getInput('graph_to');
                break;
            case 'before':
                $from = date(ZBX_FULL_DATE_TIME, max(0, $event_clock - 6 * SEC_PER_HOUR));
                $to = date(ZBX_FULL_DATE_TIME, $event_clock);
                break;
            case 'duration':
                $end = (int) ($event['recovery_clock'] ?? $now);
                $from = date(ZBX_FULL_DATE_TIME, max(0, min($event_clock, $end - ZBX_MIN_PERIOD)));
                $to = date(ZBX_FULL_DATE_TIME, $end);
                break;
            case 'day':
                $from = 'now-1d';
                $to = 'now';
                break;
            default:
                $from = date(ZBX_FULL_DATE_TIME, max(0, $event_clock - SEC_PER_HOUR));
                $to = date(ZBX_FULL_DATE_TIME, min($now, $event_clock + SEC_PER_HOUR));
        }
        $parser = new CRangeTimeParser();
        if ($parser->parse($from) !== CParser::PARSE_SUCCESS) throw new \RuntimeException(_('Invalid start time.'));
        $from_ts = $parser->getDateTime(true)->getTimestamp();
        if ($parser->parse($to) !== CParser::PARSE_SUCCESS) throw new \RuntimeException(_('Invalid end time.'));
        $to_ts = $parser->getDateTime(false)->getTimestamp();
        $duration = $to_ts - $from_ts + 1;
        if ($duration < CTimePeriodHelper::getMinPeriod()) {
            throw new \RuntimeException(_('The selected period is too short or the end time precedes the start time.'));
        }
        if ($duration > CTimePeriodHelper::getMaxPeriod()) {
            throw new \RuntimeException(_('The selected period exceeds the maximum time period configured in Zabbix.'));
        }
        return compact('period', 'from', 'to', 'from_ts', 'to_ts');
    }

    private function getMoreHistory(array $event): void {
        $itemid = (string) $this->getInput('itemid');
        $triggers = API::Trigger()->get([
            'output' => ['triggerid'], 'triggerids' => $event['objectid'], 'selectItems' => ['itemid']
        ]);
        $itemids = array_map('strval', array_column($triggers[0]['items'] ?? [], 'itemid'));
        $items = in_array($itemid, $itemids, true) ? API::Item()->get([
            'output' => ['itemid', 'name', 'key_', 'value_type', 'units'],
            'itemids' => [$itemid], 'monitored' => true, 'selectValueMap' => ['mappings']
        ]) : [];
        $item = $items ? $items[0] : null;
        if (!$item || (int) $item['value_type'] === ITEM_VALUE_TYPE_BINARY) {
            $this->setResponse((new CControllerResponseData(['main_block' => json_encode([
                'error' => ['messages' => [_('Item history not found or access denied.')]]
            ])]))->disableView());
            return;
        }

        $clock = (int) $this->getInput('before_clock');
        $ns = (int) $this->getInput('before_ns');
        $older = [];
        try {
            $limit = 4;
            // Read one extra value to decide whether another batch is available.
            // Expand only when values at the cursor's second fill the query, preserving nanosecond order.
            if ($clock > 0 || $ns > 0) {
                do {
                    $rows = API::History()->get([
                        'output' => ['itemid', 'clock', 'ns', 'value'],
                        'history' => (int) $item['value_type'], 'itemids' => [$itemid],
                        'time_till' => $ns === 0 ? $clock - 1 : $clock,
                        'sortfield' => ['clock', 'ns'], 'sortorder' => ZBX_SORT_DOWN, 'limit' => $limit
                    ]);
                    if (!is_array($rows)) throw new \RuntimeException('History lookup failed');
                    $older = array_values(array_filter($rows, static function (array $row) use ($clock, $ns): bool {
                        return (int) $row['clock'] < $clock
                            || ((int) $row['clock'] === $clock && (int) $row['ns'] < $ns);
                    }));
                    $full = count($rows) === $limit;
                    $limit *= 2;
                } while (count($older) < 4 && $full);
            }
        }
        catch (\Exception $exception) {
            $this->setResponse((new CControllerResponseData(['main_block' => json_encode([
                'error' => ['messages' => [_('Unable to load older values. Please retry.')]]
            ])]))->disableView());
            return;
        }

        $values = array_slice($older, 0, 3);
        $last = $values ? $values[count($values) - 1] : null;
        $this->setResponse(new CControllerResponseData([
            'tab' => 'history', 'event' => $event, 'history_item' => $item, 'history_values' => $values,
            'history_has_more' => count($older) > 3,
            'history_cursor' => $last ? ['clock' => (int) $last['clock'], 'ns' => (int) $last['ns']] : null
        ]));
    }

    private function getOperationalData(?array $trigger, array $event, array $trigger_items): array {
        $data = [
            'value' => '',
            'history' => []
        ];

        if (!$trigger) {
            return $data;
        }

        $opdata = trim((string) ($trigger['opdata'] ?? ''));

        if ($opdata !== '') {
            $event_opdata = trim((string) ($event['opdata'] ?? ''));
            $data['value'] = $event_opdata !== ''
                ? (string) $event['opdata']
                : CMacrosResolverHelper::resolveTriggerOpdata(
                    [
                        'triggerid' => $trigger['triggerid'],
                        'expression' => $trigger['expression'],
                        'opdata' => $trigger['opdata'],
                        'clock' => (int) ($event['clock'] ?? time()),
                        'ns' => (int) ($event['ns'] ?? 0)
                    ],
                    [
                        'events' => true,
                        'html' => false
                    ]
                );
        }

        if (!$trigger_items) {
            return $data;
        }

        $items_by_id = zbx_toHash($trigger_items, 'itemid');
        $history_values = Manager::History()->getLastValues($items_by_id, 3, timeUnitToSeconds(CSettingsHelper::get(
            CSettingsHelper::HISTORY_PERIOD
        )));

        foreach ($items_by_id as $itemid => $item) {
            if ((int) $item['value_type'] === ITEM_VALUE_TYPE_BINARY) {
                $data['history'][] = [
                    'item' => $item,
                    'values' => [[
                        'clock' => $item['lastclock'] ?? null,
                        'ns' => 0,
                        'value' => _('binary value')
                    ]]
                ];
                continue;
            }

            $values = $history_values[$itemid] ?? [];

            if (!$values && array_key_exists('lastvalue', $item) && $item['lastvalue'] !== '') {
                $values = [[
                    'itemid' => $item['itemid'],
                    'clock' => $item['lastclock'] ?? null,
                    'ns' => 0,
                    'value' => $item['lastvalue']
                ]];
            }

            $data['history'][] = [
                'item' => $item,
                'values' => $values
            ];
        }

        return $data;
    }

    private function getSystemMetricsAtEventTime($host, $event_timestamp) {
        $hostid = $host['hostid'];
        $interfaces = $host['interfaces'] ?? [];

        // Determine monitoring type based on main interface
        $monitoring_type = $this->getHostMonitoringType($interfaces);

        $metrics = [
            'type' => $monitoring_type,
            'available' => false,
            'categories' => []
        ];

        // Only proceed if we have Zabbix Agent
        if ($monitoring_type !== 'agent') {
            return $metrics;
        }

        try {
            // Get essential system metrics for Zabbix Agent (using lastvalue)
            $metrics_list = $this->getEssentialSystemMetrics($hostid);
            $metrics['categories'] = $metrics_list;

            $metrics['available'] = !empty($metrics_list);

        } catch (Exception $e) {
            error_log('Error getting system metrics: ' . $e->getMessage());
        }

        return $metrics;
    }

    /**
     * Determine monitoring type based on host interfaces
     */
    private function getHostMonitoringType($interfaces) {
        if (empty($interfaces)) {
            return 'unknown';
        }

        // Find main interface
        foreach ($interfaces as $interface) {
            if ($interface['main'] == 1) {
                switch ($interface['type']) {
                    case 1: return 'agent';    // Zabbix Agent
                    case 2: return 'snmp';     // SNMP
                    case 3: return 'ipmi';     // IPMI
                    case 4: return 'jmx';      // JMX
                    default: return 'unknown';
                }
            }
        }

        return 'unknown';
    }

    /**
     * Get essential system metrics for Zabbix Agent: CPU, Memory, Load, Disk /
     */
    private function getEssentialSystemMetrics($hostid) {
        $metrics = [];

        // Define flexible patterns for different Zabbix versions
        $metric_patterns = [
            //'CPU' => ['system.cpu.util', 'system.cpu.utilization'],
            //'Memory' => ['vm.memory.util', 'vm.memory.size[available]', 'vm.memory.size[total]'],
            //'Load' => ['system.cpu.load[percpu,avg1]', 'system.cpu.load[,avg5]', 'system.cpu.load'],
            'Uptime' => ['system.uptime', 'system.hw.uptime[hrSystemUptime.0]'],
            'Os' => ['vfs.file.contents[/etc/os-release]', 'system.sw.os'],
            '/data' => ['vfs.fs.dependent.size[/data,pused]'],
            '/' => ['vfs.fs.dependent.size[/,pused]']
        ];

        // Search for each category using multiple patterns
        foreach ($metric_patterns as $category => $patterns) {
            $found_item = null;

            foreach ($patterns as $pattern) {
                $items = API::Item()->get([
                    'output' => ['itemid', 'name', 'key_', 'units', 'lastvalue', 'lastclock'],
                    'hostids' => $hostid,
                    'search' => ['key_' => $pattern],
                    'monitored' => true,
                    'filter' => [
                        'value_type' => [ITEM_VALUE_TYPE_FLOAT, ITEM_VALUE_TYPE_UINT64]
                    ],
                    'limit' => 1
                ]);

                if (!empty($items)) {
                    $found_item = $items[0];
                    break; // Use first matching pattern
                }
            }

            if ($found_item) {
                // Simply get the last value from the item
                $metric_data = [
                    'name' => $found_item['name'],
                    'key' => $found_item['key_'],
                    'units' => $found_item['units'] ?? '',
                    'category' => $category,
                    'last_value' => $found_item['lastvalue'] ?? 'N/A'
                ];

                $metrics[] = $metric_data;
            }
        }

        return $metrics;
    }
}
