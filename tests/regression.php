<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
/** Run with: ZABBIX_UI_ROOT=/usr/share/zabbix php tests/regression.php */
$ui_root = getenv('ZABBIX_UI_ROOT') ?: dirname(__DIR__, 3);
require $ui_root.'/include/defines.inc.php';
require $ui_root.'/include/classes/helpers/CArrayHelper.php';
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
    protected $input;
    public function __construct(array $input = []) { $this->input = $input; }
    protected function getInput($name, $default = null) { return $this->input[$name] ?? $default; }
    protected function setResponse($response) { $this->response = $response; }
    protected function getDebugMode() { return false; }
    protected function checkAccess($permission) { return true; }
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
                if (!isset($options['eventids'])) return [];
                $event = API::$event;
                if (!$event) return [];
                if (isset($options['selectAcknowledges'])) {
                    $event['acknowledges'] = array_map(static function ($ack) use ($options) {
                        return array_intersect_key($ack, array_flip($options['selectAcknowledges']));
                    }, $event['acknowledges']);
                }
                return [$event];
            case 'trigger': return API::$trigger ? [API::$trigger] : [];
            case 'host': throw new HostSelectionProbe((string) $options['hostids']);
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
}
class API {
    public static $event = [];
    public static $trigger = null;
    public static $services = [];
    public static $calls = [];
    public static function Event() { return new ApiFixture('event'); }
    public static function Trigger() { return new ApiFixture('trigger'); }
    public static function Host() { return new ApiFixture('host'); }
    public static function Service() { return new ApiFixture('service'); }
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
check($data['event']['acknowledges'][0] === $ack, 'Controller must request complete acknowledgement records');
$trigger_calls = array_values(array_filter(API::$calls, static function ($call) { return $call[0] === 'trigger'; }));
check($trigger_calls[0][1]['triggerids'] === '7', 'Event trigger must override a conflicting request hint');
check($data['host'] === null, 'An unrelated requested host must not be loaded');
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

API::$trigger = ['triggerid' => '7', 'hosts' => [['hostid' => '11'], ['hostid' => '12']]];
foreach (['88' => '11', '12' => '12'] as $requested => $expected) {
    try {
        (new ProblemControllerFixture(['eventid' => '1', 'hostid' => (string) $requested]))->run();
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
