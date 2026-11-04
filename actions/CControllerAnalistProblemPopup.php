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
            'tab'           => 'string|in overview,host,timeline,timeline_events,monthly,patterns,graphs,update,history,recurrences',
            'initial_tab'   => 'in overview,timeline',
            'recurrence_eventids' => 'array_id',
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
            'before_eventid' => 'id',
            'before_group' => 'in problem,other',
            'graph_period'  => 'in around,before,duration,day,custom',
            'graph_layout'  => 'in items,combined',
            'graph_from'    => 'range_time',
            'graph_to'      => 'range_time',
            'pattern_period' => 'in 1,3,6,12,custom',
            'pattern_from' => 'range_time',
            'pattern_to' => 'range_time',
            'comparison_period' => 'in 3,6,12,custom',
            'comparison_from' => 'range_time',
            'comparison_to' => 'range_time'
        ];

        $ret = $this->validateInput($fields);
        if ($ret && $this->getInput('tab', 'overview') === 'recurrences') {
            $eventids = $this->getInput('recurrence_eventids', [$this->getInput('eventid')]);
            if (!$eventids || count($eventids) > 40) {
                error(_('Select between 1 and 40 problem events.'));
                $ret = false;
            }
        }
        if ($ret && $this->getInput('tab', 'overview') === 'history') {
            $ret = $this->validateInput(array_replace($fields, [
                'itemid' => 'required|id',
                'before_clock' => 'required|int32|ge 0',
                'before_ns' => 'required|int32|ge 0|le 999999999'
            ]));
        }
        if ($ret && $this->getInput('tab', 'overview') === 'timeline_events') {
            $ret = $this->validateInput(array_replace($fields, [
                'before_eventid' => 'required|id', 'before_clock' => 'required|int32|ge 0'
            ]));
        }
        if ($ret && $this->getInput('tab', 'overview') === 'graphs'
                && $this->getInput('graph_period', 'around') === 'custom') {
            $ret = $this->validateInput(array_replace($fields, [
                'graph_from' => 'required|range_time', 'graph_to' => 'required|range_time'
            ]));
        }
        if ($ret && $this->getInput('tab', 'overview') === 'patterns'
                && $this->getInput('pattern_period', '12') === 'custom') {
            $ret = $this->validateInput(array_replace($fields, [
                'pattern_from' => 'required|range_time', 'pattern_to' => 'required|range_time'
            ]));
        }
        if ($ret && in_array($this->getInput('tab', 'overview'), ['timeline', 'monthly'], true)
                && $this->getInput('comparison_period', '6') === 'custom') {
            $ret = $this->validateInput(array_replace($fields, [
                'comparison_from' => 'required|range_time', 'comparison_to' => 'required|range_time'
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

        if ($tab === 'recurrences') {
            $this->getRecurrenceCounts();
            return;
        }


        // Get event details
        $events = API::Event()->get([
            'output' => ['eventid', 'source', 'object', 'objectid', 'clock', 'ns', 'value', 'acknowledged', 'name',
                'severity', 'opdata', 'r_eventid'
            ],
            'eventids' => $eventid,
            'selectTags' => $tab === 'overview' ? ['tag', 'value'] : null,
            'selectSuppressionData' => $tab === 'update' ? ['maintenanceid', 'userid', 'suppress_until'] : null,
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
        if (in_array($tab, ['timeline_events', 'monthly'], true)) {
            try {
                $this->setResponse(new CControllerResponseData([
                    'tab' => $tab, 'event' => $event
                ] + ($tab === 'monthly'
                    ? ['monthly_comparison' => $this->getMonthlyComparison($event)]
                    : ['timeline_page' => $this->getTimelinePage($event)])));
            }
            catch (\Exception $exception) {
                $this->setResponse((new CControllerResponseData(['main_block' => json_encode([
                    'error' => ['messages' => [$exception->getMessage()]]
                ])]))->disableView());
            }
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

        if ($recovery_eventid > 0 && in_array($tab, ['overview', 'graphs', 'update'], true)) {
            $recovery_events = API::Event()->get([
                'output' => $tab === 'update' ? ['eventid', 'clock', 'correlationid', 'userid'] : ['eventid', 'clock'],
                'eventids' => $recovery_eventid
            ]);

            if ($recovery_events) {
                $event['recovery_clock'] = $recovery_events[0]['clock'];
                if ($tab === 'update') {
                    $event['correlationid'] = $recovery_events[0]['correlationid'];
                    $event['userid'] = $recovery_events[0]['userid'];
                }
            }
        }

        // The event determines the trigger; request hints must not override it.
        $trigger = null;
        $actual_triggerid = $event['objectid'];

        if ($actual_triggerid > 0 && in_array($tab, ['overview', 'host', 'graphs'], true)) {
            $triggers = API::Trigger()->get([
                'output' => ['triggerid', 'description', 'expression', 'comments', 'priority', 'opdata', 'manual_close',
                    'recovery_mode', 'recovery_expression', 'type', 'status'
                ],
                'triggerids' => $actual_triggerid,
                'selectHosts' => ['hostid', 'host', 'name'],
                'selectItems' => in_array($tab, ['overview', 'graphs'], true)
                    ? ['itemid'] : null,
                'expandExpression' => $tab === 'overview'
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
        $pattern_timeline = null;
        if ($tab === 'patterns' && $actual_triggerid > 0) {
            try {
                $pattern_timeline = $this->getCalendarTimeline($event);
            }
            catch (\Exception $exception) {
                $this->setResponse((new CControllerResponseData(['main_block' => json_encode([
                    'error' => ['messages' => [$exception->getMessage()]]
                ])]))->disableView());
                return;
            }

            $pattern_events = API::Event()->get($this->getRelatedEventOptions($event) + [
                'output'    => ['clock'],
                'time_from' => $pattern_timeline['from_ts'],
                'time_till' => $pattern_timeline['to_ts']
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
                    'output' => $tab === 'overview'
                        ? ['itemid', 'name', 'key_', 'hostid', 'value_type', 'units', 'lastvalue', 'lastclock', 'valuemapid']
                        : ['itemid', 'name', 'value_type'],
                    'itemids' => $unique_itemids,
                    'monitored' => true,
                    'selectValueMap' => $tab === 'overview' ? ['mappings'] : null
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

        // Aggregate monthly counts in the API; never transfer all matching event timestamps.
        $monthly_comparison = [];
        if ($tab === 'timeline') {
            try {
                $monthly_comparison = $this->getMonthlyComparison($event);
            }
            catch (\Exception $exception) {
                $this->setResponse((new CControllerResponseData(['main_block' => json_encode([
                    'error' => ['messages' => [$exception->getMessage()]]
                ])]))->disableView());
                return;
            }
        }

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

        $timeline_page = null;
        if ($tab === 'timeline') {
            try {
                $timeline_page = $this->getTimelinePage($event);
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
            'initial_tab' => $this->getInput('initial_tab', 'overview'),
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
            'pattern_timeline' => $pattern_timeline,
            'items' => $items,
            'monthly_comparison' => $monthly_comparison,
            'timeline_page' => $timeline_page,
            'operational_data' => $operational_data,
            'graph_timeline' => $graph_timeline,
            'graph_layout' => $this->getInput('graph_layout', 'items'),
            'user' => [
                'debug_mode' => $this->getDebugMode()
            ]
        ];

        $this->setResponse(new CControllerResponseData($data));
    }

    /** Related occurrences share the trigger and the exact name stored when the event was raised. */
    private function getRelatedEventOptions(array $event): array {
        return ['source' => EVENT_SOURCE_TRIGGERS, 'object' => EVENT_OBJECT_TRIGGER,
            'value' => TRIGGER_VALUE_TRUE, 'objectids' => $event['objectid'],
            'filter' => ['name' => $event['name']]];
    }

    private function getMonthlyComparison(array $event): array {
        $timeline = $this->getCalendarTimeline($event, 'comparison', '6');
        $months = [];
        $previous = null;
        $start = strtotime(date('Y-m-01 00:00:00', $timeline['from_ts']));
        while ($start <= $timeline['to_ts']) {
            $next = strtotime('+1 month', $start);
            $count = API::Event()->get($this->getRelatedEventOptions($event) + [
                'countOutput' => true,
                'time_from' => max($start, $timeline['from_ts']),
                'time_till' => min($next - 1, $timeline['to_ts'])
            ]);
            if ($count === false) throw new \RuntimeException(_('Unable to load monthly comparison.'));
            $count = (int) $count;
            $months[] = ['name' => zbx_date2str('F Y', $start), 'count' => $count,
                'change_percentage' => $previous === null ? null : ($previous > 0
                    ? round(($count - $previous) / $previous * 100, 1) : ($count > 0 ? 100.0 : 0.0))];
            $previous = $count;
            $start = $next;
        }
        $months = array_reverse($months);
        return ['months' => $months, 'timeline' => $timeline,
            'change_percentage' => $months[0]['change_percentage'] ?? null];
    }

    private function getTimelinePage(array $event): array {
        $options = $this->getRelatedEventOptions($event) + [
            'output' => ['eventid', 'clock'],
            'sortfield' => ['clock', 'eventid'], 'sortorder' => ZBX_SORT_DOWN
        ];
        $read = static function (array $query): array {
            $events = API::Event()->get($query);
            if (!is_array($events)) throw new \RuntimeException(_('Unable to load recent events.'));
            return $events;
        };
        $cursor = null;
        if ($this->getInput('tab', 'overview') === 'timeline_events') {
            $before_id = (string) $this->getInput('before_eventid');
            $before_clock = (int) $this->getInput('before_clock');
            $cursor_events = API::Event()->get([
                'output' => ['eventid', 'source', 'object', 'objectid', 'clock', 'value', 'name'], 'eventids' => [$before_id]
            ]);
            $cursor = $cursor_events ? $cursor_events[0] : null;
            if (!$cursor || (string) $cursor['objectid'] !== (string) $event['objectid']
                    || $cursor['name'] !== $event['name']
                    || (int) $cursor['source'] !== EVENT_SOURCE_TRIGGERS
                    || (int) $cursor['object'] !== EVENT_OBJECT_TRIGGER || (int) $cursor['value'] !== TRIGGER_VALUE_TRUE
                    || (int) $cursor['clock'] !== $before_clock) {
                throw new \RuntimeException(_('Recent event not found or access denied.'));
            }
        }

        // Fetch only unresolved metadata. Native problem.get does not support clock sorting;
        // sort this group locally, then fetch full event/action data for the displayed page only.
        $problem_options = $this->getRelatedEventOptions($event);
        unset($problem_options['value']);
        $problems = API::Problem()->get($problem_options + [
            'output' => ['eventid', 'clock'],
            'selectAcknowledges' => ['action'], 'recent' => false
        ]);
        if (!is_array($problems)) throw new \RuntimeException(_('Unable to load recent events.'));
        $problems = array_values(array_filter($problems, static function (array $problem): bool {
            foreach ($problem['acknowledges'] as $ack) {
                if (((int) $ack['action'] & ZBX_PROBLEM_UPDATE_CLOSE) !== 0) return false;
            }
            return true;
        }));
        usort($problems, static function (array $left, array $right): int {
            return ((int) $right['clock'] <=> (int) $left['clock']) ?: bccomp($right['eventid'], $left['eventid']);
        });
        $problem_ids = array_fill_keys(array_column($problems, 'eventid'), true);
        $group = $cursor ? $this->getInput('before_group', isset($problem_ids[$cursor['eventid']]) ? 'problem' : 'other') : 'problem';
        $page = [];
        if ($group === 'problem') {
            foreach ($problems as $problem) {
                if ($cursor && ((int) $problem['clock'] > (int) $cursor['clock']
                        || ((int) $problem['clock'] === (int) $cursor['clock']
                            && bccomp($problem['eventid'], $cursor['eventid']) >= 0))) continue;
                $page[] = $problem + ['group' => 'problem'];
                if (count($page) === 21) break;
            }
        }

        // Read the other group from its newest event after the Problem group is exhausted.
        // Advancing a timestamp/ID cursor avoids repeatedly scanning the same open events.
        $read_older = static function (?array $before, int $limit) use ($read, $options): array {
            if (!$before) return $read($options + ['limit' => $limit]);
            $clock = (int) $before['clock'];
            $rows = bccomp($before['eventid'], '1') > 0 ? $read(array_replace($options, [
                'time_from' => $clock, 'time_till' => $clock,
                'eventid_till' => bcsub($before['eventid'], '1', 0), 'limit' => $limit
            ])) : [];
            if (count($rows) < $limit && $clock > 0) {
                $rows = array_merge($rows, $read($options + ['time_till' => $clock - 1, 'limit' => $limit - count($rows)]));
            }
            return $rows;
        };
        $scan_cursor = $group === 'other' ? $cursor : null;
        while (count($page) < 21) {
            $rows = $read_older($scan_cursor, 100);
            foreach ($rows as $row) {
                if (isset($problem_ids[$row['eventid']])) continue;
                $page[] = $row + ['group' => 'other'];
                if (count($page) === 21) break;
            }
            if (count($rows) < 100) break;
            $scan_cursor = $rows[count($rows) - 1];
        }

        $has_more = count($page) > 20;
        $page = array_slice($page, 0, 20);
        $details = $page ? $read($this->getRelatedEventOptions($event) + [
            'eventids' => array_column($page, 'eventid'), 'preservekeys' => true,
            'output' => ['eventid', 'source', 'object', 'objectid', 'acknowledged', 'clock', 'ns', 'severity',
                'r_eventid', 'cause_eventid'],
            'selectAcknowledges' => ['userid', 'clock', 'message', 'action', 'old_severity', 'new_severity',
                'suppress_until', 'taskid']
        ]) : [];
        $events = [];
        foreach ($page as $row) {
            if (isset($details[$row['eventid']])) $events[] = $details[$row['eventid']];
        }
        $last = $page ? $page[count($page) - 1] : null;
        return ['events' => $events, 'has_more' => $has_more,
            'cursor' => $last ? ['eventid' => (string) $last['eventid'], 'clock' => (int) $last['clock'],
                'group' => $last['group']] : null];
    }

    private function getRecurrenceCounts(): void {
        try {
            $eventids = array_values(array_unique($this->getInput('recurrence_eventids', [$this->getInput('eventid')])));
            $events = API::Event()->get([
                'output' => ['eventid', 'source', 'object', 'objectid', 'value', 'name'],
                'eventids' => $eventids,
                'source' => EVENT_SOURCE_TRIGGERS, 'object' => EVENT_OBJECT_TRIGGER, 'value' => TRIGGER_VALUE_TRUE
            ]);
            $events = array_values(array_filter($events ?: [], static function (array $event): bool {
                return (int) $event['source'] === EVENT_SOURCE_TRIGGERS
                    && (int) $event['object'] === EVENT_OBJECT_TRIGGER && (int) $event['value'] === TRIGGER_VALUE_TRUE;
            }));
            if (!$events) throw new \RuntimeException(_('Problem event not found or access denied.'));

            $parser = new CRangeTimeParser();
            $parser->parse('now-6M');
            $time_from = $parser->getDateTime(true)->getTimestamp();
            $time_till = time();
            // Native event.get groups counts by objectid only. Batch triggers sharing an exact name
            // instead of fetching every historical event or mixing different macro expansions.
            $events_by_name = [];
            foreach ($events as $event) $events_by_name[$event['name']][] = $event;
            $result = [];
            foreach ($events_by_name as $same_name_events) {
                $options = $this->getRelatedEventOptions($same_name_events[0]);
                $options['objectids'] = array_values(array_unique(array_column($same_name_events, 'objectid')));
                $counts = API::Event()->get($options + ['countOutput' => true, 'groupBy' => ['objectid'],
                    'time_from' => $time_from, 'time_till' => $time_till]);
                if (!is_array($counts)) throw new \RuntimeException(_('Unable to load recurrence counts.'));
                $counts_by_trigger = [];
                foreach ($counts as $count) $counts_by_trigger[$count['objectid']] = (string) $count['rowscount'];
                foreach ($same_name_events as $event) {
                    $result[$event['eventid']] = $counts_by_trigger[$event['objectid']] ?? '0';
                }
            }
            $response = ['counts' => (object) $result, 'time_from' => $time_from, 'time_till' => $time_till];
        }
        catch (\Exception $exception) {
            $response = ['error' => ['messages' => [$exception->getMessage()]]];
        }
        $this->setResponse((new CControllerResponseData(['main_block' => json_encode($response)]))->disableView());
    }

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

    private function getCalendarTimeline(array $event, string $prefix = 'pattern', string $default = '12'): array {
        $period = $this->getInput($prefix.'_period', $default);
        if ($period === 'custom') {
            $from = $this->getInput($prefix.'_from');
            $to = $this->getInput($prefix.'_to');
            $parser = new CRangeTimeParser();
            if ($parser->parse($from) !== CParser::PARSE_SUCCESS) throw new \RuntimeException(_('Invalid start time.'));
            $from_ts = $parser->getDateTime(true)->getTimestamp();
            if ($parser->parse($to) !== CParser::PARSE_SUCCESS) throw new \RuntimeException(_('Invalid end time.'));
            $to_ts = $parser->getDateTime(false)->getTimestamp();
            if ($from_ts < 0 || $to_ts > ZBX_MAX_INT32 || $from_ts > $to_ts) {
                throw new \RuntimeException(_('The end time must not precede the start time.'));
            }
            if ($to_ts - $from_ts + 1 > CTimePeriodHelper::getMaxPeriod()) {
                throw new \RuntimeException(_('The selected period exceeds the maximum time period configured in Zabbix.'));
            }
        }
        else {
            // Include the event's month: comparisons use the full month; patterns end at problem start.
            $to_ts = (int) $event['clock'];
            $month_start = strtotime(date('Y-m-01 00:00:00', $to_ts));
            $from_ts = max(0, strtotime('-'.((int) $period - 1).' months', $month_start));
            if ($prefix === 'comparison') $to_ts = strtotime('+1 month', $month_start) - 1;
            $from = date(ZBX_FULL_DATE_TIME, $from_ts);
            $to = date(ZBX_FULL_DATE_TIME, $to_ts);
        }
        return compact('period', 'from', 'to', 'from_ts', 'to_ts');
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
            $query = ['history' => (int) $item['value_type'], 'itemids' => [$itemid],
                'sortfield' => ['clock', 'ns'], 'sortorder' => ZBX_SORT_DOWN];
            if ($ns > 0) {
                // The native API has no nanosecond range filter. Seek within just this second,
                // fetching timestamps only so dense text/log history does not repeat large values.
                $limit = 4;
                do {
                    $rows = API::History()->get($query + ['output' => ['ns'],
                        'time_from' => $clock, 'time_till' => $clock, 'limit' => $limit]);
                    if (!is_array($rows)) throw new \RuntimeException('History lookup failed');
                    $timestamps = array_values(array_filter($rows, static function (array $row) use ($ns): bool {
                        return (int) $row['ns'] < $ns;
                    }));
                    $full = count($rows) === $limit;
                    $limit *= 2;
                } while (count($timestamps) < 4 && $full);
                if ($timestamps) {
                    $older = API::History()->get($query + ['output' => ['itemid', 'clock', 'ns', 'value'],
                        'time_from' => $clock, 'time_till' => $clock,
                        'filter' => ['ns' => array_column(array_slice($timestamps, 0, 4), 'ns')], 'limit' => 4]);
                    if (!is_array($older)) throw new \RuntimeException('History lookup failed');
                }
            }
            // One lookahead value determines whether another page is available.
            if ($clock > 0 && count($older) < 4) {
                $rows = API::History()->get($query + ['output' => ['itemid', 'clock', 'ns', 'value'],
                    'time_till' => $clock - 1, 'limit' => 4 - count($older)]);
                if (!is_array($rows)) throw new \RuntimeException('History lookup failed');
                $older = array_merge($older, $rows);
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

}
