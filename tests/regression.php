<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/** Run with: ZABBIX_UI_ROOT=/usr/share/zabbix php tests/regression.php */
$ui_root = getenv('ZABBIX_UI_ROOT') ?: dirname(__DIR__, 3);
require $ui_root.'/include/defines.inc.php';
require $ui_root.'/include/gettextwrapper.inc.php';
require $ui_root.'/include/classes/validators/CApiInputValidator.php';
require $ui_root.'/include/classes/helpers/CArrayHelper.php';
require $ui_root.'/include/classes/helpers/CRoleHelper.php';
require $ui_root.'/include/actions.inc.php';
require $ui_root.'/include/hosts.inc.php';
require $ui_root.'/include/classes/core/CAutoloader.php';
$autoload = new CAutoloader();
$autoload->addNamespace('', [$ui_root.'/include/classes/parsers', $ui_root.'/include/classes/helpers']);
$autoload->register();
$settings = new ReflectionProperty(CSettingsHelper::class, 'params');
$settings->setAccessible(true);
$settings->setValue(null, [CSettingsHelper::MAX_PERIOD => '2y']);

set_error_handler(static function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
if (!function_exists('_')) {
    function _($message) { return $message; }
}
function check($condition, $message) {
    if (!$condition) throw new RuntimeException($message);
}
class CController {
    public $response;
    public static $denied = [];
    protected $input;
    public function __construct(array $input = []) { $this->input = $input; }
    protected function getInput($name, $default = null) { return $this->input[$name] ?? $default; }
    protected function setResponse($response) { $this->response = $response; }
    protected function getDebugMode() { return false; }
    protected function checkAccess($permission) { return !in_array($permission, self::$denied, true); }
    public function getAction() { return isset($this->input['tab']) ? 'problemanalist.tab.get' : 'problemanalist.view'; }
}
class CControllerResponseData {
    private $data;
    public function __construct($data) { $this->data = $data; }
    public function getData() { return $this->data; }
    public function disableView() { return $this; }
}
class HostSelectionProbe extends Exception {}
class ApiFixture {
    private $kind;
    public function __construct($kind) { $this->kind = $kind; }
    public function get(array $options) {
        API::$calls[] = [$this->kind, $options];
        switch ($this->kind) {
            case 'event':
                if (API::$timeline_events !== null && isset($options['limit'], $options['objectids'])) {
                    $eventid_till = $options['eventid_till'];
                    $error = '';
                    check(CApiInputValidator::validate(['type' => API_ID], $eventid_till, '/eventid_till', $error),
                        'Timeline paging must send a valid native API event ID: '.$error);
                    if (API::$timeline_events === false) return false;
                    $events = array_values(array_filter(API::$timeline_events, static function ($event) use ($options) {
                        return (string) $event['objectid'] === (string) $options['objectids']
                            && (int) $event['source'] === $options['source'] && (int) $event['object'] === $options['object']
                            && (int) $event['value'] === $options['value']
                            && bccomp((string) $event['eventid'], (string) $options['eventid_till']) <= 0
                            && (!isset($options['time_from']) || $event['clock'] >= $options['time_from'])
                            && (!isset($options['time_till']) || $event['clock'] <= $options['time_till']);
                    }));
                    usort($events, static function ($left, $right) {
                        return ($right['clock'] <=> $left['clock']) ?: bccomp($right['eventid'], $left['eventid']);
                    });
                    return array_slice($events, 0, $options['limit']);
                }
                if (is_array(API::$timeline_events) && isset($options['eventids'])) {
                    return array_values(array_filter(API::$timeline_events, static function ($event) use ($options) {
                        return in_array((string) $event['eventid'], array_map('strval', (array) $options['eventids']), true);
                    }));
                }
                if (!empty($options['countOutput'])) return API::$recurrence_counts;
                if (isset($options['eventids']) && API::$recurrence_events !== null) {
                    return array_values(array_filter(API::$recurrence_events, static function ($event) use ($options) {
                        return in_array($event['eventid'], (array) $options['eventids']);
                    }));
                }
                if (!isset($options['eventids'])) return API::$history;
                if (in_array('2', array_map('strval', (array) $options['eventids']), true)) {
                    return [['eventid' => '2', 'clock' => 220]];
                }
                $event = API::$event;
                if (!$event) return [];
                if (isset($options['selectAcknowledges'])) {
                    $event['acknowledges'] = array_map(static function ($ack) use ($options) {
                        return array_intersect_key($ack, array_flip($options['selectAcknowledges']));
                    }, $event['acknowledges']);
                }
                if (!isset($options['selectAcknowledges'])) unset($event['acknowledges']);
                return [$event];
            case 'trigger': return API::$trigger && (empty($options['editable']) || API::$editable) ? [API::$trigger] : [];
            case 'item': return array_values(array_filter(API::$items, static function ($item) use ($options) {
                return in_array((string) $item['itemid'], array_map('strval', $options['itemids']), true);
            }));
            case 'item_history':
                if (API::$item_history === false) return false;
                $rows = array_values(array_filter(API::$item_history, static function ($row) use ($options) {
                    return in_array((string) $row['itemid'], array_map('strval', $options['itemids']), true)
                        && (int) $row['clock'] <= $options['time_till']
                        && (!isset($options['time_from']) || (int) $row['clock'] >= $options['time_from'])
                        && (!isset($options['filter']['ns']) || in_array($row['ns'], $options['filter']['ns']));
                }));
                usort($rows, static function ($a, $b) {
                    return ($b['clock'] <=> $a['clock']) ?: ($b['ns'] <=> $a['ns']);
                });
                return array_map(static function ($row) use ($options) {
                    return array_intersect_key($row, array_flip($options['output']));
                }, array_slice($rows, 0, $options['limit']));
            case 'host':
                if (($options['selectInventory'] ?? []) === ['notes']) {
                    $hosts = array_filter(API::$hosts, static function ($host) use ($options) {
                        return in_array((string) $host['hostid'], array_map('strval', (array) $options['hostids']), true);
                    });
                    return array_column($hosts, null, 'hostid');
                }
                throw new HostSelectionProbe((string) $options['hostids']);
            case 'sla':
                if (API::$slas instanceof Exception) throw API::$slas;
                return API::$slas;
            case 'service':
                if (API::$services === false) return false;
                if (isset($options['serviceids'])) {
                    return array_values(array_filter(API::$services, static function ($service) use ($options) {
                        return in_array($service['serviceid'], $options['serviceids']);
                    }));
                }
                return API::$services;
        }
        return [];
    }
    public function getSli(array $options) {
        API::$calls[] = ['sli', $options];
        $result = API::$sli[$options['slaid']] ?? [];
        if ($result instanceof Exception) throw $result;
        return $result;
    }
}
class API {
    public static $event = [];
    public static $timeline_events = null;
    public static $recurrence_events = null;
    public static $recurrence_counts = [];
    public static $trigger = null;
    public static $hosts = [];
    public static $editable = true;
    public static $services = [];
    public static $calls = [];
    public static $history = [];
    public static $items = [];
    public static $item_history = [];
    public static $slas = [];
    public static $sli = [];
    public static function Event() { return new ApiFixture('event'); }
    public static function Trigger() { return new ApiFixture('trigger'); }
    public static function Host() { return new ApiFixture('host'); }
    public static function Item() { return new ApiFixture('item'); }
    public static function History() { return new ApiFixture('item_history'); }
    public static function Service() { return new ApiFixture('service'); }
    public static function Sla() { return new ApiFixture('sla'); }
}
require dirname(__DIR__).'/actions/CControllerAnalistProblemPopup.php';
require dirname(__DIR__).'/actions/CControllerAnalistServicePopup.php';
class ProblemControllerFixture extends Modules\AnalistProblem\Actions\CControllerAnalistProblemPopup {
    public function run() { $this->doAction(); }
}
class ServiceControllerFixture extends Modules\AnalistProblem\Actions\CControllerAnalistServicePopup {
    public function run() { $this->doAction(); }
}

$service_controller = new ServiceControllerFixture();
$match = new ReflectionMethod($service_controller, 'matchesEventTags');
$equal = static function ($tag, $value) {
    return ['tag' => $tag, 'value' => $value, 'operator' => ZBX_SERVICE_PROBLEM_TAG_OPERATOR_EQUAL];
};
$like = static function ($tag, $value) {
    return ['tag' => $tag, 'value' => $value, 'operator' => ZBX_SERVICE_PROBLEM_TAG_OPERATOR_LIKE];
};
$tags = [['tag' => 'env', 'value' => 'production'], ['tag' => 'app', 'value' => 'web']];
check($match->invoke($service_controller, [$equal('env', 'production')], $tags), 'Exact tag must match');
check(!$match->invoke($service_controller, [$equal('env', 'prod')], $tags), 'Equality must not match substrings');
check($match->invoke($service_controller, [$like('env', 'prod')], $tags), 'Contains must search the event value');
check(!$match->invoke($service_controller, [$like('env', 'production-long')], $tags), 'Contains must not be reversed');
check(!$match->invoke($service_controller, [$like('env', 'PROD')], $tags), 'Matching must be case-sensitive');
check($match->invoke($service_controller, [$like('env', '')], $tags), 'Empty contains matches an existing tag');
check(!$match->invoke($service_controller, [$equal('missing', '')], $tags), 'A missing tag must not match');
check(!$match->invoke($service_controller, [], $tags), 'Services without rules are not direct matches');
check(!$match->invoke($service_controller, [$equal('env', 'production'), $equal('app', 'database')], $tags), 'Every service rule is required');
check(!$match->invoke($service_controller, [$equal('env', 'production'), $equal('env', 'staging')], $tags), 'Rules sharing a tag are also ANDed');
check($match->invoke($service_controller, [$equal('env', 'production'), $equal('env', 'staging')], array_merge($tags, [['tag' => 'env', 'value' => 'staging']])), 'Different event values can satisfy different rules');

$list = new ReflectionMethod($service_controller, 'getServices');
API::$calls = [];
check($list->invoke($service_controller, ['eventid' => '1'], [])['data'] === [], 'Untagged events have no impacted services');
check(API::$calls === [], 'Untagged events must not issue an unfiltered service query');
check(!$list->invoke($service_controller, [], $tags)['success'], 'Lists require an event');
API::$services = false;
check(!$list->invoke($service_controller, ['eventid' => '1'], $tags)['success'], 'API failures must remain errors');
$service_base = ['status' => -1, 'algorithm' => 0, 'tags' => [], 'parents' => []];
API::$services = [
    $service_base + ['serviceid' => '101', 'name' => 'Match', 'problem_tags' => [$like('env', 'prod')]],
    $service_base + ['serviceid' => '102', 'name' => 'Wrong', 'problem_tags' => [$equal('env', 'prod')]]
];
$result = $list->invoke($service_controller, ['eventid' => '1'], $tags);
check($result['success'] && array_column($result['data'], 'serviceid') === ['101'], 'Only matching services may be enriched and returned');

// Shared visible ancestors are preloaded once, with an explicit SLA state.
API::$services[0]['parents'] = [['serviceid' => '99']];
API::$services[] = array_merge($service_base, ['serviceid' => '103', 'name' => 'Second match',
    'parents' => [['serviceid' => '99']], 'problem_tags' => [$like('env', 'prod')]]);
API::$services[] = $service_base + ['serviceid' => '99', 'name' => 'Parent', 'problem_tags' => []];
API::$calls = [];
$result = $list->invoke(new ServiceControllerFixture(), ['eventid' => '1'], $tags);
check(count($result['data']) === 2, 'Related services do not become direct matches');
foreach ($result['data'] as $service) {
    check($service['hierarchy_path'][0]['serviceid'] === '99'
        && $service['hierarchy_path'][0]['sla_state'] === 'none', 'Related services include preloaded SLA state');
}
$parent_sla_calls = array_filter(API::$calls, static function ($call) {
    return $call[0] === 'sla' && $call[1]['serviceids'] === ['99'];
});
check(count($parent_sla_calls) === 1, 'Shared parent SLA is loaded once per request');

$sla_method = new ReflectionMethod($service_controller, 'getSlaData');
$read_sla = static function () use ($sla_method) {
    return $sla_method->invoke(new ServiceControllerFixture(), '101');
};
check($read_sla()['sla_state'] === 'none', 'Empty successful lookup establishes no accessible SLA');
$sla_base = ['period' => ZBX_SLA_PERIOD_MONTHLY, 'timezone' => 'Europe/Zurich',
    'status' => ZBX_SLA_STATUS_ENABLED, 'effective_date' => 100];
API::$slas = [
    $sla_base + ['slaid' => '5', 'name' => 'Production SLA', 'slo' => '97.25'],
    $sla_base + ['slaid' => '6', 'name' => 'Critical SLA', 'slo' => '99.95']
];
$cell = ['sli' => '93.75', 'uptime' => 3600, 'downtime' => 240, 'error_budget' => -120];
API::$sli = ['5' => ['periods' => [['period_from' => 100, 'period_to' => 200], ['period_from' => 200, 'period_to' => 300]],
    'serviceids' => ['999', '101'], 'sli' => [[['sli' => 10], ['sli' => 20]], [['sli' => 30], $cell]]],
    '6' => new Exception('SLI denied')];
API::$calls = [];
$result = $read_sla();
check($result['has_sla'] && count($result['slas']) === 2, 'All associated SLA definitions must be retained');
check($result['slas'][0]['sli'] === 93.75, 'SLI is indexed by reporting period then service, matching IDs as strings');
check($result['sla_info'] === ['name' => 'Production SLA', 'target' => '97.25%'], 'Summary uses configured name and target');
check($result['slas'][0]['error_budget'] === '-2m', 'Negative error budgets must stay negative');
check($result['slas'][0]['reporting_period']['period_from'] === 200, 'Use the latest returned reporting period');
check($result['slas'][1]['sli_state'] === 'unavailable' && $result['slas'][1]['name'] === 'Critical SLA',
    'SLI failure must preserve SLA configuration and not claim no SLA');
$sli_calls = array_values(array_filter(API::$calls, static function ($call) { return $call[0] === 'sli'; }));
check($sli_calls[0][1] === ['slaid' => '5', 'serviceids' => ['101'], 'periods' => 1],
    'Native API chooses current reporting boundaries and timezone without an arbitrary period_from');
API::$slas = [$sla_base + ['slaid' => '5', 'name' => 'Production SLA', 'slo' => '97.25']];
foreach ([-1, null, 0, 100, 101] as $value) {
    API::$sli['5'] = ['periods' => [['period_from' => 100, 'period_to' => 200]], 'serviceids' => [101],
        'sli' => [[array_merge($cell, ['sli' => $value, 'uptime' => 0])]]];
    $result = $read_sla()['slas'][0];
    $available = $value === 0 || $value === 100;
    check(($result['sli_state'] === 'available') === $available, 'Zero is a valid SLI; undefined or invalid values are unavailable');
    check($result['uptime'] === ($available ? '0s' : null), 'Unavailable SLI must not fabricate duration values');
}
API::$slas[0]['status'] = ZBX_SLA_STATUS_DISABLED;
API::$calls = [];
check($read_sla()['slas'][0]['sli_state'] === 'disabled', 'Disabled SLAs retain their configuration');
check(count(API::$calls) === 1, 'Disabled SLAs must not calculate SLI');
foreach ([false, new Exception('Denied')] as $failure) {
    API::$slas = $failure;
    $result = $read_sla();
    check($result['sla_state'] === 'unavailable' && $result['has_sla'] === null, 'Lookup errors cannot prove no SLA');
}
API::$slas = [];
$cached_controller = new ServiceControllerFixture();
API::$calls = [];
$sla_method->invoke($cached_controller, '101'); $sla_method->invoke($cached_controller, '101');
check(count(API::$calls) === 1, 'Duplicate lookups within a request use its local cache');
$read_sla();
check(count(API::$calls) === 2, 'New controller instances must not reuse another request or user cache');

API::$event = [];
$controller = new ServiceControllerFixture(['eventid' => '999']);
$controller->run();
check(!json_decode($controller->response->getData()['main_block'], true)['success'], 'Inaccessible service events must return an error');
$controller = new ProblemControllerFixture(['eventid' => '999', 'triggerid' => '99', 'hostid' => '88']);
$controller->run();
check(isset(json_decode($controller->response->getData()['main_block'], true)['error']), 'Missing events must not be fabricated');

// Recurrences count only readable problem events and group the count by the server-resolved trigger.
$recurrence_base = ['source' => EVENT_SOURCE_TRIGGERS, 'object' => EVENT_OBJECT_TRIGGER, 'value' => TRIGGER_VALUE_TRUE];
API::$recurrence_events = [
    $recurrence_base + ['eventid' => '1', 'objectid' => '7'],
    $recurrence_base + ['eventid' => '3', 'objectid' => '7'],
    $recurrence_base + ['eventid' => '4', 'objectid' => '8'],
    ['eventid' => '2', 'objectid' => '7', 'source' => 0, 'object' => 0, 'value' => 0]
];
API::$recurrence_counts = [['objectid' => '7', 'rowscount' => '12']];
API::$calls = [];
$recurrence_request = ['eventid' => '1', 'tab' => 'recurrences', 'triggerid' => '999',
    'recurrence_eventids' => ['1', '3', '4', '2', '999']];
$recurrences = new ProblemControllerFixture($recurrence_request);
$recurrences->run();
$counts = json_decode($recurrences->response->getData()['main_block'], true);
check($counts['counts'] === ['1' => '12', '3' => '12', '4' => '0'],
    'Rows for the same trigger share a count; inaccessible or recovery events do not get fabricated counts');
check(count(API::$calls) === 2 && API::$calls[1][1]['objectids'] === ['7', '8'],
    'Recurrences use one grouped count query without trigger, host or history enrichment');
$count_query = API::$calls[1][1];
check($count_query['countOutput'] && $count_query['groupBy'] === ['objectid']
    && $count_query['value'] === TRIGGER_VALUE_TRUE && !isset($count_query['limit']),
    'Count all matching problem occurrences rather than recovery events or a limited page');
check($counts['time_till'] - $counts['time_from'] > 150 * SEC_PER_DAY
    && $counts['time_till'] - $counts['time_from'] < 187 * SEC_PER_DAY,
    'Recurrences use a rolling six-calendar-month window ending now');
API::$recurrence_counts = false;
$recurrences->run();
check(isset(json_decode($recurrences->response->getData()['main_block'], true)['error']),
    'A count-query failure is an error rather than a zero');
API::$recurrence_events = [];
API::$calls = [];
$recurrences->run();
check(isset(json_decode($recurrences->response->getData()['main_block'], true)['error']) && count(API::$calls) === 1,
    'Inaccessible events never trigger an unscoped count query');
API::$recurrence_events = null;
API::$recurrence_counts = [];

$ack = ['acknowledgeid' => '3', 'clock' => 110, 'userid' => '4', 'message' => 'Investigating',
    'action' => ZBX_PROBLEM_UPDATE_MESSAGE | ZBX_PROBLEM_UPDATE_ACKNOWLEDGE | ZBX_PROBLEM_UPDATE_SEVERITY,
    'old_severity' => 2, 'new_severity' => 3, 'suppress_until' => 0];
API::$event = ['eventid' => '1', 'source' => EVENT_SOURCE_TRIGGERS, 'object' => EVENT_OBJECT_TRIGGER,
    'objectid' => '7', 'clock' => 100, 'ns' => 0, 'value' => TRIGGER_VALUE_TRUE, 'acknowledged' => 1,
    'name' => 'Example', 'severity' => 3, 'opdata' => '', 'r_eventid' => '0', 'tags' => [], 'acknowledges' => [$ack]];
API::$calls = [];
$controller = new ProblemControllerFixture(['eventid' => '1', 'triggerid' => '99', 'hostid' => '88']);
$controller->run();
$data = $controller->response->getData();
check(!isset($data['event']['acknowledges']), 'Overview must skip acknowledgement history');
check(count(API::$calls) === 2, 'Overview skips writable permission queries when the trigger is unavailable');
$trigger_calls = array_values(array_filter(API::$calls, static function ($call) { return $call[0] === 'trigger'; }));
check($trigger_calls[0][1]['triggerids'] === '7', 'Event trigger must override a conflicting request hint');
check($data['host'] === null, 'An unrelated requested host must not be loaded');
$update = new ProblemControllerFixture(['eventid' => '1', 'tab' => 'update']);
API::$calls = [];
$update->run();
$data = $update->response->getData();
check($data['event']['acknowledges'][0] === $ack, 'Update must request complete acknowledgements');
check(count(API::$calls) === 1, 'Update must skip trigger, host and range queries');
check($data['can_comment'], 'Update exposes the permitted comment action without loading a trigger');
CController::$denied = [CRoleHelper::ACTIONS_ADD_PROBLEM_COMMENTS];
$update->run();
check(!$update->response->getData()['can_comment'], 'Comment controls obey their own role permission');
CController::$denied = [CRoleHelper::ACTIONS_ACKNOWLEDGE_PROBLEMS];
$update->run();
check($update->response->getData()['can_comment'], 'Adding comments does not require acknowledgement permission');
CController::$denied = [];
foreach (['patterns', 'timeline'] as $tab) {
    API::$calls = [];
    (new ProblemControllerFixture(['eventid' => '1', 'tab' => $tab]))->run();
    $ranges = array_filter(API::$calls, static function ($call) {
        return $call[0] === 'event' && isset($call[1]['time_from']);
    });
    check(count($ranges) === 1, 'Each historical tab must request only its own range');
    check(!array_filter(API::$calls, static function ($call) { return $call[0] === 'trigger'; }),
        'Historical tabs skip unused parent trigger enrichment');
    check($tab !== 'patterns' || !isset(array_values($ranges)[0][1]['sortfield']),
        'Time pattern counts do not sort raw events');
    API::$history = false;
    $failed = new ProblemControllerFixture(['eventid' => '1', 'tab' => $tab]);
    $failed->run();
    check(isset(json_decode($failed->response->getData()['main_block'], true)['error']), 'Range failures must remain retryable errors');
    API::$history = [];
}
// Graph rendering must not request item last values, value maps or expanded expressions.
API::$trigger = ['triggerid' => '7', 'hosts' => [], 'items' => [['itemid' => '10']]];
API::$calls = [];
(new ProblemControllerFixture(['eventid' => '1', 'tab' => 'graphs']))->run();
$graph_queries = array_column(API::$calls, 1, 0);
check($graph_queries['item']['output'] === ['itemid', 'name', 'value_type']
    && $graph_queries['item']['selectValueMap'] === null && !$graph_queries['trigger']['expandExpression'],
    'Graphs fetch item metadata without reading history or expanding expressions');
API::$trigger = null;
API::$event['r_eventid'] = '2';
API::$calls = [];
(new ProblemControllerFixture(['eventid' => '1', 'tab' => 'patterns']))->run();
check(count(API::$calls) === 2 && API::$calls[0][1]['selectTags'] === null,
    'Time patterns skip unused recovery and tag lookups for resolved events');
API::$event['r_eventid'] = '0';
$history_event = $data['event'];
$history_event['r_eventid'] = '2';
$recovery_alert = ['eventid' => '2', 'clock' => 121, 'alerttype' => ALERT_TYPE_MESSAGE,
    'mediatypeid' => '1', 'userid' => '4', 'status' => ALERT_STATUS_SENT];
$actions = getSingleEventActions($history_event, ['2' => ['clock' => 120]], [$recovery_alert]);
$manual = array_values(array_filter($actions['actions'], static function ($action) {
    return $action['action_type'] === ZBX_EVENT_HISTORY_MANUAL_UPDATE;
}));
check(count($manual) === 1 && $manual[0]['message'] === 'Investigating' && $manual[0]['new_severity'] === 3,
    'Native history must retain comment and severity details exactly once');
$recoveries = array_filter($actions['actions'], static function ($action) {
    return $action['action_type'] === ZBX_EVENT_HISTORY_RECOVERY_EVENT;
});
check(count($recoveries) === 1, 'Native collector emits one recovery entry');
$alerts = array_filter($actions['actions'], static function ($action) {
    return $action['action_type'] === ZBX_EVENT_HISTORY_ALERT;
});
check(count($alerts) === 1, 'Native collector emits one recovery notification');

API::$trigger = ['triggerid' => '7', 'manual_close' => ZBX_TRIGGER_MANUAL_CLOSE_ALLOWED, 'hosts' => []];
$overview = static function () {
    $controller = new ProblemControllerFixture(['eventid' => '1']);
    $controller->run();
    return $controller->response->getData();
};
$data = $overview();
check($data['can_acknowledge'] && $data['can_close'] && $data['can_change_severity'], 'Permitted writable problems expose acknowledgement, close and severity actions');
API::$editable = false;
$readonly = $overview();
check(!$readonly['can_close'] && !$readonly['can_change_severity'], 'Read-only triggers cannot close or change severity');
API::$editable = true;
CController::$denied = [CRoleHelper::ACTIONS_ACKNOWLEDGE_PROBLEMS, CRoleHelper::ACTIONS_CLOSE_PROBLEMS, CRoleHelper::ACTIONS_CHANGE_SEVERITY];
$data = $overview();
check(!$data['can_acknowledge'] && !$data['can_close'] && !$data['can_change_severity'], 'Role restrictions hide both controls');
CController::$denied = [];
API::$event['acknowledges'][] = ['action' => ZBX_PROBLEM_UPDATE_CLOSE];
$closing = $overview();
check(!$closing['can_close'] && $closing['closing_pending'], 'Pending closure hides the close control and enables recovery tracking');
API::$event['acknowledges'] = [$ack];
API::$event['r_eventid'] = '2';
$recovered = $overview();
check(!$recovered['can_close'], 'Resolved problems cannot be manually closed');
check($recovered['event']['recovery_clock'] === 220, 'Overview uses the actual recovery event timestamp');
API::$event['r_eventid'] = '0';
API::$trigger['manual_close'] = ZBX_TRIGGER_MANUAL_CLOSE_NOT_ALLOWED;
check(!$overview()['can_close'], 'Trigger manual-close setting must be respected');

API::$trigger = ['triggerid' => '7', 'hosts' => [['hostid' => '11'], ['hostid' => '12']]];
API::$hosts = [
    ['hostid' => '11', 'name' => 'Visible primary', 'inventory' => ['notes' => "Rack 1\nOwner: Operations"]],
    ['hostid' => '12', 'name' => 'Visible secondary', 'inventory' => []],
    ['hostid' => '88', 'name' => 'Unrelated', 'inventory' => ['notes' => 'Must not be selected']]
];
API::$calls = [];
$controller = new ProblemControllerFixture(['eventid' => '1', 'hostid' => '88']);
$controller->run();
$host_data = $controller->response->getData();
$host_queries = array_values(array_filter(API::$calls, static function ($call) { return $call[0] === 'host'; }));
check(count($host_queries) === 1 && $host_queries[0][1]['hostids'] === ['11', '12'],
    'Overview batches inventory lookup for event trigger hosts only');
check($host_queries[0][1]['output'] === ['hostid', 'name'] && $host_queries[0][1]['selectInventory'] === ['notes'],
    'Overview requests only visible names and inventory notes');
check($host_data['trigger']['hosts'][0]['inventory']['notes'] === "Rack 1\nOwner: Operations"
    && $host_data['host']['name'] === 'Visible primary', 'Notes and visible names stay attached to the matching host');
API::$hosts = [];
check(!isset($overview()['trigger']['hosts'][0]['inventory']), 'Unavailable inventory must not prevent Overview rendering');
foreach (['88' => '11', '12' => '12'] as $requested => $expected) {
    try {
        (new ProblemControllerFixture(['eventid' => '1', 'tab' => 'host', 'hostid' => (string) $requested]))->run();
        throw new RuntimeException('Expected host selection probe');
    } catch (HostSelectionProbe $probe) {
        check($probe->getMessage() === $expected, 'Host selection must stay within event trigger hosts');
    }
}
API::$trigger = null;
API::$event['value'] = 0;
$controller = new ProblemControllerFixture(['eventid' => '1']);
$controller->run();
check(isset(json_decode($controller->response->getData()['main_block'], true)['error']), 'Recovery events must not be analyzed as problems');

API::$event['value'] = TRIGGER_VALUE_TRUE;
API::$trigger = ['triggerid' => '7', 'items' => [['itemid' => '10']]];
API::$items = [['itemid' => '10', 'name' => 'Example metric', 'value_type' => ITEM_VALUE_TYPE_UINT64]];
API::$item_history = array_map(static function ($pair) {
    return ['itemid' => '10', 'clock' => $pair[0], 'ns' => $pair[1], 'value' => '42'];
}, [[100, 900], [100, 500], [100, 400], [100, 300], [100, 200], [100, 100], [99, 800], [99, 0], [98, 0]]);
$more_history = static function ($clock, $ns, $itemid = '10') {
    $controller = new ProblemControllerFixture(['eventid' => '1', 'tab' => 'history', 'itemid' => $itemid,
        'before_clock' => $clock, 'before_ns' => $ns]);
    $controller->run();
    return $controller->response->getData();
};
API::$calls = [];
$page = $more_history(100, 500);
check(array_column($page['history_values'], 'ns') === [400, 300, 200], 'More loads exactly three older values, including within the same second');
check($page['history_has_more'] && $page['history_cursor'] === ['clock' => 100, 'ns' => 200], 'Cursor points to the last returned value');
$queries = array_values(array_filter(API::$calls, static function ($call) { return $call[0] === 'item_history'; }));
check(count($queries) === 3 && $queries[0][1]['limit'] === 4
    && $queries[0][1]['output'] === ['ns'] && $queries[1][1]['output'] === ['ns']
    && $queries[2][1]['filter']['ns'] === [400, 300, 200, 100],
    'Same-second seeks expand metadata only, then fetch three values and one lookahead');
check($queries[0][1]['history'] === ITEM_VALUE_TYPE_UINT64 && $queries[0][1]['sortfield'] === ['clock', 'ns'], 'Use the server item type and stable timestamp ordering');
$page = $more_history(100, 200);
check(array_column($page['history_values'], 'clock') === [100, 99, 99], 'Following batches neither skip nor repeat values');
$page = $more_history(99, 0);
check(count($page['history_values']) === 1 && !$page['history_has_more'], 'The final partial batch hides More');
$page = $more_history(98, 0);
check($page['history_values'] === [] && $page['history_cursor'] === null && !$page['history_has_more'], 'Exhausted history returns an empty final batch');
// Dense log/text history must not refetch large values while seeking a nanosecond cursor.
$saved_history = API::$item_history;
API::$items[0]['value_type'] = ITEM_VALUE_TYPE_TEXT;
API::$item_history = [];
for ($ns = 2000; $ns >= 1; $ns--) {
    API::$item_history[] = ['itemid' => '10', 'clock' => 100, 'ns' => $ns, 'value' => str_repeat('x', 65536)];
}
API::$calls = [];
$page = $more_history(100, 10);
check(array_column($page['history_values'], 'ns') === [9, 8, 7] && $page['history_has_more'],
    'Dense text history preserves the cursor and lookahead');
$value_limit = 0;
foreach (API::$calls as [$kind, $options]) {
    if ($kind !== 'item_history') continue;
    check($options['history'] === ITEM_VALUE_TYPE_TEXT, 'Dense history uses the native text backend');
    if (in_array('value', $options['output'], true)) $value_limit += $options['limit'];
    else check($options['time_from'] === 100 && $options['time_till'] === 100,
        'Metadata expansion stays within the cursor second');
}
check($value_limit === 4, 'History transfers at most four value payloads per page, regardless of cursor depth');
API::$item_history = $saved_history;
API::$items[0]['value_type'] = ITEM_VALUE_TYPE_UINT64;
API::$calls = [];
$page = $more_history(100, 500, '999');
check(isset(json_decode($page['main_block'], true)['error']), 'An unrelated item cannot be loaded through an event');
check(count(API::$calls) === 2, 'Reject unrelated items before querying item history');
API::$items = [];
check(isset(json_decode($more_history(100, 500)['main_block'], true)['error']), 'Inaccessible items cannot expose history');
API::$items = [['itemid' => '10', 'name' => 'Binary metric', 'value_type' => ITEM_VALUE_TYPE_BINARY]];
check(isset(json_decode($more_history(100, 500)['main_block'], true)['error']), 'Binary payloads are not paged as text');
API::$items[0]['value_type'] = ITEM_VALUE_TYPE_UINT64;
API::$item_history = false;
check(isset(json_decode($more_history(100, 500)['main_block'], true)['error']), 'History lookup failures remain retryable errors');

// Event paging is stable across same-second rows, out-of-order timestamps and large IDs.
$timeline_parent = ['eventid' => '9007199254741999', 'objectid' => '7', 'source' => EVENT_SOURCE_TRIGGERS,
    'object' => EVENT_OBJECT_TRIGGER, 'value' => TRIGGER_VALUE_TRUE, 'clock' => 100,
    'acknowledged' => 0, 'severity' => 2, 'r_eventid' => '0', 'cause_eventid' => '0', 'acknowledges' => []];
API::$timeline_events = [$timeline_parent];
for ($number = 0; $number < 39; $number++) {
    API::$timeline_events[] = array_replace($timeline_parent, ['eventid' => bcsub($timeline_parent['eventid'], (string) (100 + $number), 0)]);
}
foreach ([1, 300, 301, 302] as $offset) {
    API::$timeline_events[] = array_replace($timeline_parent,
        ['eventid' => bcsub($timeline_parent['eventid'], (string) $offset, 0), 'clock' => 90]);
}
$foreign_id = bcsub($timeline_parent['eventid'], '600', 0);
$future_id = bcadd($timeline_parent['eventid'], '1', 0);
API::$timeline_events[] = array_replace($timeline_parent, ['eventid' => $foreign_id, 'objectid' => '8']);
API::$timeline_events[] = array_replace($timeline_parent, ['eventid' => $future_id]);
$event_page_method = new ReflectionMethod(Modules\AnalistProblem\Actions\CControllerAnalistProblemPopup::class, 'getTimelinePage');
$event_page_method->setAccessible(true);
$event_page = static function (?array $cursor = null) use ($timeline_parent, $event_page_method) {
    $input = ['eventid' => $timeline_parent['eventid'], 'tab' => $cursor ? 'timeline_events' : 'timeline'];
    if ($cursor) $input += ['before_eventid' => $cursor['eventid'], 'before_clock' => $cursor['clock']];
    return $event_page_method->invoke(new ProblemControllerFixture($input), $timeline_parent);
};
API::$calls = [];
$first_page = $event_page();
check(count($first_page['events']) === 20 && $first_page['has_more'], 'Timeline starts with twenty events and a lookahead');
check(API::$calls[0][1]['limit'] === 21 && in_array('message', API::$calls[0][1]['selectAcknowledges'], true),
    'Paging retains complete native update data and requests one lookahead event');
$second_page = $event_page($first_page['cursor']);
$final_page = $event_page($second_page['cursor']);
check(count($second_page['events']) === 20 && $second_page['has_more'], 'More appends the next twenty events');
check(count($final_page['events']) === 4 && !$final_page['has_more'], 'The final partial page exhausts More');
check($final_page['events'][0]['eventid'] === bcsub($timeline_parent['eventid'], '1', 0),
    'An older timestamp with a larger event ID must not be skipped');
$loaded_ids = array_column(array_merge($first_page['events'], $second_page['events'], $final_page['events']), 'eventid');
check(count(array_unique($loaded_ids)) === 44, 'All events load exactly once across a shared timestamp');
check($event_page($final_page['cursor'])['events'] === [], 'An exhausted cursor returns an empty page');
foreach ([['eventid' => $foreign_id, 'clock' => 100], ['eventid' => $future_id, 'clock' => 100],
        ['eventid' => $first_page['cursor']['eventid'], 'clock' => 99]] as $bad_cursor) {
    try { $event_page($bad_cursor); }
    catch (RuntimeException $exception) { continue; }
    throw new RuntimeException('Invalid or unrelated event cursors must be rejected');
}
API::$calls = [];
$more_controller = new ProblemControllerFixture(['eventid' => $timeline_parent['eventid'], 'tab' => 'timeline_events',
    'before_eventid' => $first_page['cursor']['eventid'], 'before_clock' => $first_page['cursor']['clock']]);
$more_controller->run();
check(count($more_controller->response->getData()['timeline_page']['events']) === 20
    && !array_filter(API::$calls, static function ($call) { return $call[0] !== 'event'; }),
    'More skips monthly comparisons and unrelated tab data');
API::$timeline_events = false;
try { $event_page(); throw new Exception('A failed history query must not look like exhaustion'); }
catch (RuntimeException $exception) {}
API::$timeline_events = null;

// Calendar data follows the selected range and the frontend timezone.
require dirname(__DIR__).'/includes/PatternData.php';
date_default_timezone_set('Europe/Zurich');
foreach (['2026-03-31', '2024-02-29', '2026-12-31'] as $date) {
    $end = strtotime($date.' 12:00:00');
    $start = strtotime('-11 months', strtotime(date('Y-m-01 00:00:00', $end)));
    $calendar = \Modules\AnalistProblem\PatternData::build([], $start, $end, $end);
    check(count($calendar['monthKeys']) === 12 && count(array_unique($calendar['monthKeys'])) === 12,
        'Month-end patterns must contain twelve distinct months');
}
$end = strtotime('2026-03-31 12:00:00');
$clock = strtotime('2026-03-30 00:30:00');
$calendar = \Modules\AnalistProblem\PatternData::build([['clock' => $clock], ['clock' => $clock]],
    strtotime('2026-02-01'), $end, $end);
check($calendar['buckets'] === [[1, 30, 1, 0, 2]], 'Buckets use the frontend timezone and preserve same-second occurrences');
check($calendar['total'] === 2 && $calendar['scaleMax'] === 2, 'Counts and heatmap scale include every occurrence');
check($calendar['monthDays'] === [28, 31], 'Day drilldowns use the actual calendar month length');
check($calendar['lastWeekEnd'] - $calendar['lastWeekStart'] === 601200, 'Previous calendar week respects the DST transition');
check($calendar['lastWeekEnd'] <= $end, 'Historical comparisons must end before the selected event');
$leap = \Modules\AnalistProblem\PatternData::build([], strtotime('2024-02-01'), strtotime('2024-02-29 23:59:59'), $end);
check($leap['monthDays'] === [29], 'Leap-year February includes exactly twenty-nine days');
$filtered = \Modules\AnalistProblem\PatternData::build([['clock' => $clock - 1], ['clock' => $clock], ['clock' => $clock + 1]],
    $clock, $clock, $clock);
check($filtered['total'] === 1 && $filtered['buckets'] === [[0, 30, 1, 0, 1]],
    'Custom boundaries are inclusive and exclude out-of-range events');
$repeated_hour = \Modules\AnalistProblem\PatternData::build([
    ['clock' => strtotime('2026-10-25T02:30:00+02:00')], ['clock' => strtotime('2026-10-25T02:30:00+01:00')]
], strtotime('2026-10-01'), strtotime('2026-10-31'), strtotime('2026-10-31'));
check($repeated_hour['buckets'] === [[0, 25, 0, 2, 2]] && $repeated_hour['lastWeekCounts'][0][2] === 2,
    'Both occurrences of the repeated DST hour contribute to the same local slot and previous week');
$dense_events = [];
$start = strtotime('2026-03-01');
for ($i = 0; $i < 43200; $i++) $dense_events[] = ['clock' => $start + $i * 60];
$dense = \Modules\AnalistProblem\PatternData::build($dense_events, $start, $end, $end);
check($dense['total'] === 43200 && count($dense['buckets']) <= 720 && strlen(json_encode($dense)) < 20000,
    'Pattern payload grows by occupied calendar hours instead of raw event count');

echo "PHP regression checks passed.\n";
