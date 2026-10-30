<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$ui_root = getenv('ZABBIX_UI_ROOT') ?: dirname(__DIR__, 3);
require $ui_root.'/include/defines.inc.php';
require $ui_root.'/include/gettextwrapper.inc.php';
require $ui_root.'/include/translateDefines.inc.php';
require $ui_root.'/include/func.inc.php';
require $ui_root.'/include/html.inc.php';
require $ui_root.'/include/classes/db/DB.php';
$DB = ['TYPE' => ZBX_DB_POSTGRESQL];
require $ui_root.'/include/classes/core/CAutoloader.php';
$autoloader = new CAutoloader();
$autoloader->addNamespace('', [$ui_root.'/include/classes/html', $ui_root.'/include/classes/core',
    $ui_root.'/include/classes/html/interfaces', $ui_root.'/include/classes/helpers', $ui_root.'/include/classes/parsers',
    $ui_root.'/include/classes/validators', $ui_root.'/include/classes/mvc']);
$autoloader->register();
set_error_handler(static function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
class CSeverityHelper {
    public static function getName($severity) {
        return ['Not classified', 'Information', 'Warning', 'Average', 'High', 'Disaster'][$severity];
    }
    public static function getStatusStyle($severity) {
        return [ZBX_STYLE_STATUS_NA_BG, ZBX_STYLE_STATUS_INFO_BG, ZBX_STYLE_STATUS_WARNING_BG,
            ZBX_STYLE_STATUS_AVERAGE_BG, ZBX_STYLE_STATUS_HIGH_BG, ZBX_STYLE_STATUS_DISASTER_BG][$severity] ?? null;
    }
    public static function makeSeverityCell($severity, $text = null, $force_normal = false, $return_as_div = false) {
        return $return_as_div ? new CDiv($text ?? self::getName($severity)) : new CCol($text ?? self::getName($severity));
    }
}
class CWebUser {
    public static $data = ['lang' => 'en_US'];
    public static function checkAccess($permission) { return true; }
}
class CSettingsHelper {
    public const SEARCH_LIMIT = 'search_limit';
    public const PERIOD_DEFAULT = 'period_default';
    public const PROBLEM_UNACK_STYLE = 'problem_unack_style';
    public const PROBLEM_ACK_STYLE = 'problem_ack_style';
    public const OK_UNACK_STYLE = 'ok_unack_style';
    public const OK_ACK_STYLE = 'ok_ack_style';
    public const BLINK_PERIOD = 'blink_period';
    public static function get($name) {
        if ($name === self::PERIOD_DEFAULT) return '1d';
        if ($name === self::SEARCH_LIMIT) return 100;
        return '0';
    }
}
class CCsrfTokenHelper {
    public static function get($action) { return 'fixture-csrf-'.$action; }
}
class ViewApiFixture {
    public function __construct(private string $kind = '') {}
    public function get($options) {
        if ($this->kind === 'trigger') return ['7' => ['triggerid' => '7', 'priority' => 2,
            'manual_close' => ZBX_TRIGGER_MANUAL_CLOSE_ALLOWED]];
        return [];
    }
}
class API {
    public static function Event() { return new ViewApiFixture(); }
    public static function Alert() { return new ViewApiFixture(); }
    public static function Trigger() { return new ViewApiFixture('trigger'); }
    public static function User() { return new ViewApiFixture(); }
    public static function Mediatype() { return new ViewApiFixture(); }
}
CView::registerDirectory(dirname(__DIR__).'/views');
date_default_timezone_set('Europe/Zurich');
$event = ['eventid' => '1', 'name' => "Quoted \"event\" \n </script>", 'severity' => 2,
    'clock' => strtotime('2026-03-31 12:00:00'), 'objectid' => '7', 'source' => 0, 'object' => 0,
    'value' => 1, 'acknowledged' => 0, 'r_eventid' => '0', 'tags' => [], 'acknowledges' => []];
$host = ['name' => "Quoted \"host\" \n </script>", 'hostid' => '1'];
$scenario = $argv[2] ?? 'readonly';
if ($scenario === 'acknowledged') $event['acknowledged'] = 1;
if ($scenario === 'recovered') {
    $event['r_eventid'] = '2';
    $event['recovery_clock'] = $event['clock'] + 120;
}
if ($scenario === 'recovery-unavailable') $event['r_eventid'] = '2';
$trigger = null;
if ($scenario === 'details') {
    $trigger = [
        'triggerid' => '7',
        'hosts' => [
            ['hostid' => '1', 'host' => 'technical-primary', 'name' => 'API <primary>',
                'inventory' => ['notes' => "Rack <one>\n<script>alert(1)</script>"]],
            ['hostid' => '2', 'host' => 'technical-secondary', 'name' => 'API secondary', 'inventory' => ['notes' => '']]
        ],
        'comments' => "Investigate <service>\nEscalate if needed.",
        'expression' => 'last(/API/custom.metric)<5',
        'recovery_expression' => 'last(/API/custom.metric)>10'
    ];
    $event['tags'] = [['tag' => 'service', 'value' => '<API>']];
}
$history_item = ['itemid' => '10', 'name' => 'Example <item>', 'value_type' => ITEM_VALUE_TYPE_TEXT];
$history_values = array_map(static function ($clock) {
    return ['clock' => $clock, 'ns' => 0, 'value' => '<script> & "quoted"'];
}, [100, 90, 80]);
$graph_items = [['itemid' => '1', 'name' => 'Fixture <metric>', 'units' => 'bps', 'value_type' => ITEM_VALUE_TYPE_UINT64]];
if (in_array($scenario, ['graphs-many', 'graphs-combined'])) {
    $graph_items[] = ['itemid' => '2', 'name' => 'Second metric', 'units' => 'B', 'value_type' => ITEM_VALUE_TYPE_UINT64];
}
if ($scenario === 'graphs-empty') $graph_items = [];
$graph_timeline = [
    'period' => $scenario === 'graphs-custom' ? 'custom' : 'around',
    'from' => date(ZBX_FULL_DATE_TIME, $event['clock'] - SEC_PER_HOUR),
    'to' => date(ZBX_FULL_DATE_TIME, $event['clock'] + SEC_PER_HOUR),
    'from_ts' => $event['clock'] - SEC_PER_HOUR, 'to_ts' => $event['clock'] + SEC_PER_HOUR
];
if ($scenario === 'graphs-before') {
    $graph_timeline = [
        'period' => 'before',
        'from' => date(ZBX_FULL_DATE_TIME, $event['clock'] - 6 * SEC_PER_HOUR),
        'to' => date(ZBX_FULL_DATE_TIME, $event['clock']),
        'from_ts' => $event['clock'] - 6 * SEC_PER_HOUR, 'to_ts' => $event['clock']
    ];
}
$timeline_events = [];
if (in_array($scenario, ['timeline-page', 'timeline-more-page', 'timeline-final-page'])) {
    $start = $scenario === 'timeline-page' ? 100 : ($scenario === 'timeline-more-page' ? 80 : 60);
    for ($index = 0; $index < ($scenario === 'timeline-final-page' ? 3 : 20); $index++) {
        $timeline_events[] = array_replace($event, ['eventid' => (string) ($start - $index),
            'clock' => $event['clock'] - (100 - $start + $index), 'cause_eventid' => '0']);
    }
}
$last_timeline_event = $timeline_events ? $timeline_events[count($timeline_events) - 1] : null;
$pattern_timeline = ['period' => '12', 'from' => '2025-04-01 00:00:00', 'to' => '2026-03-31 12:00:00',
    'from_ts' => strtotime('2025-04-01 00:00:00'), 'to_ts' => $event['clock']];
if (in_array($scenario, ['patterns-custom', 'patterns-rich'])) {
    $pattern_timeline = ['period' => 'custom', 'from' => '2026-02-01 00:00:00', 'to' => '2026-03-31 12:00:00',
        'from_ts' => strtotime('2026-02-01 00:00:00'), 'to_ts' => $event['clock']];
}
$pattern_events = [['clock' => strtotime('2026-03-30 00:30:00')]];
if ($scenario === 'patterns-rich') {
    foreach (['2026-02-02 08:10:00', '2026-02-02 08:10:00', '2026-02-02 09:15:00',
            '2026-03-02 08:20:00', '2026-03-03 08:00:00'] as $date) {
        $pattern_events[] = ['clock' => strtotime($date)];
    }
}
if ($scenario === 'patterns-short') {
    $pattern_timeline['period'] = '1';
    $pattern_timeline['from'] = '2026-03-01 00:00:00';
    $pattern_timeline['from_ts'] = strtotime($pattern_timeline['from']);
}
$view = new CView('problemanalist.view', [
    'initial_tab' => $scenario === 'timeline-start' ? 'timeline' : 'overview',
    'tab' => $argv[1] ?? 'overview', 'event' => $event,
    'host' => ($argv[1] ?? 'overview') === 'host' ? null : $host, 'trigger' => $trigger,
    'is_fragment' => $scenario === 'fragment',
    'closing_pending' => $scenario === 'closing',
    'can_acknowledge' => in_array($scenario, ['permitted', 'acknowledged', 'ack-only']),
    'can_comment' => in_array($scenario, ['permitted', 'acknowledged', 'comment-only']),
    'can_close' => in_array($scenario, ['permitted', 'acknowledged']),
    'can_change_severity' => in_array($scenario, ['permitted', 'acknowledged']),
    'pattern_events' => $scenario === 'patterns-empty' ? [] : $pattern_events,
    'pattern_timeline' => $pattern_timeline,
    'operational_data' => ['value' => '', 'history' => $scenario === 'history-preview'
        ? [['item' => $history_item, 'values' => $history_values]] : []],
    'history_item' => $history_item, 'history_values' => $history_values, 'history_has_more' => true,
    'history_cursor' => ['clock' => 80, 'ns' => 0],
    'items' => $graph_items,
    'graph_timeline' => $graph_timeline,
    'graph_layout' => $scenario === 'graphs-combined' ? 'combined' : 'items',
    'timeline_page' => ['events' => $timeline_events,
        'has_more' => $timeline_events && $scenario !== 'timeline-final-page',
        'cursor' => $last_timeline_event
            ? ['eventid' => $last_timeline_event['eventid'], 'clock' => $last_timeline_event['clock']] : null],
    'monthly_comparison' => ['months' => [['name' => 'March 2026', 'count' => 1, 'change_percentage' => 0]]]
]);
$response = json_decode($view->getOutput(), true, 512, JSON_THROW_ON_ERROR);
// Resolve the actual emitted image URLs using the same native helper as chart.php.
$chart_periods = [];
if (($argv[1] ?? 'overview') === 'graphs') {
    preg_match_all('/<img[^>]+src="([^"]+)"/', $response['body'], $matches);
    foreach ($matches[1] as $src) {
        parse_str(parse_url(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'), PHP_URL_QUERY), $params);
        $chart_periods[] = getTimeSelectorPeriod([
            'profileIdx' => $params['profileIdx'] ?? null, 'profileIdx2' => $params['profileIdx2'] ?? null,
            'from' => $params['from'] ?? null, 'to' => $params['to'] ?? null
        ]);
    }
}
echo json_encode(['inline' => $response['script_inline'] ?? '', 'response' => $response, 'event' => $event, 'host' => $host,
    'graph_timeline' => $graph_timeline, 'chart_periods' => $chart_periods]);
