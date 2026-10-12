<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/** Run with: ZABBIX_UI_ROOT=/usr/share/zabbix php tests/regression.php */
$ui_root = getenv('ZABBIX_UI_ROOT') ?: dirname(__DIR__, 3);
require $ui_root.'/include/defines.inc.php';
require $ui_root.'/include/classes/helpers/CArrayHelper.php';
require $ui_root.'/include/classes/helpers/CRoleHelper.php';
require $ui_root.'/include/actions.inc.php';
require $ui_root.'/include/hosts.inc.php';

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
            case 'host': throw new HostSelectionProbe((string) $options['hostids']);
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
    public static $trigger = null;
    public static $editable = true;
    public static $services = [];
    public static $calls = [];
    public static $history = [];
    public static $slas = [];
    public static $sli = [];
    public static function Event() { return new ApiFixture('event'); }
    public static function Trigger() { return new ApiFixture('trigger'); }
    public static function Host() { return new ApiFixture('host'); }
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
foreach (['patterns', 'timeline'] as $tab) {
    API::$calls = [];
    (new ProblemControllerFixture(['eventid' => '1', 'tab' => $tab]))->run();
    $ranges = array_filter(API::$calls, static function ($call) {
        return $call[0] === 'event' && isset($call[1]['time_from']);
    });
    check(count($ranges) === 1, 'Each historical tab must request only its own range');
    API::$history = false;
    $failed = new ProblemControllerFixture(['eventid' => '1', 'tab' => $tab]);
    $failed->run();
    check(isset(json_decode($failed->response->getData()['main_block'], true)['error']), 'Range failures must remain retryable errors');
    API::$history = [];
}
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

// Evaluate the view’s actual calendar preparation without rendering a Zabbix page.
$view = file_get_contents(dirname(__DIR__).'/views/problemanalist.view.php');
$start = strpos($view, '$current_time =');
$end = strpos($view, '// Generate hour labels', $start);
$calendar = substr($view, $start, $end - $start);
$start = strpos($view, '$event_clocks =');
$end = strpos($view, '// Create patterns container', $start);
if ($end === false) $end = strpos($view, '$patterns_container =', $start);
// Stop after the reference-week calculation.
$end = strpos($view, '$pattern_week_start =', $start);
$end = strpos($view, ';', $end) + 1;
$slots = substr($view, $start, $end - $start);
date_default_timezone_set('Europe/Zurich');
foreach (['2026-03-31', '2024-02-29', '2026-12-31'] as $date) {
    $event = ['clock' => strtotime($date.' 12:00:00')];
    $monthly_data = $month_keys = $monthly_daily_details = [];
    eval($calendar);
    check(count($month_keys) === 12 && count(array_unique($month_keys)) === 12, 'Month-end charts must contain twelve distinct months');
}
$event = ['clock' => strtotime('2026-03-31 12:00:00')];
$monthly_data = $month_keys = $monthly_daily_details = [];
eval($calendar);
$pattern_events = [['clock' => strtotime('2026-03-30 00:30:00')]];
eval($slots);
check($event_slots[(string) $pattern_events[0]['clock']]['date'] === '2026-03-30', 'Buckets use frontend timezone near midnight');
check($pattern_week_end - $pattern_week_start === 601200, 'Previous calendar week respects the DST transition');
check($pattern_week_end <= $event['clock'], 'Historical comparisons must end before the selected event');
echo "PHP regression checks passed.\n";
