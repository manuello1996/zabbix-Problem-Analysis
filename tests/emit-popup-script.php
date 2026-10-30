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
    public static function makeSeverityCell($severity, $text = null) { return new CCol($text ?? self::getName($severity)); }
}
class CWebUser {
    public static $data = ['lang' => 'en_US'];
    public static function checkAccess($permission) { return true; }
}
class CSettingsHelper {
    public const SEARCH_LIMIT = 'search_limit';
    public const PERIOD_DEFAULT = 'period_default';
    public static function get($name) { return $name === self::PERIOD_DEFAULT ? '1d' : 100; }
}
class CCsrfTokenHelper {
    public static function get($action) { return 'fixture-csrf-'.$action; }
}
class ViewApiFixture {
    public function get($options) { return []; }
}
class API {
    public static function Event() { return new ViewApiFixture(); }
    public static function Alert() { return new ViewApiFixture(); }
    public static function Trigger() { return new ViewApiFixture(); }
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
$view = new CView('problemanalist.view', [
    'tab' => $argv[1] ?? 'overview', 'event' => $event,
    'host' => ($argv[1] ?? 'overview') === 'host' ? null : $host, 'trigger' => null,
    'is_fragment' => $scenario === 'fragment',
    'closing_pending' => $scenario === 'closing',
    'can_acknowledge' => in_array($scenario, ['permitted', 'acknowledged', 'ack-only']),
    'can_comment' => in_array($scenario, ['permitted', 'acknowledged', 'comment-only']),
    'can_close' => in_array($scenario, ['permitted', 'acknowledged']),
    'can_change_severity' => in_array($scenario, ['permitted', 'acknowledged']),
    'pattern_events' => [['clock' => strtotime('2026-03-30 00:30:00')]],
    'operational_data' => ['value' => '', 'history' => $scenario === 'history-preview'
        ? [['item' => $history_item, 'values' => $history_values]] : []],
    'history_item' => $history_item, 'history_values' => $history_values, 'history_has_more' => true,
    'history_cursor' => ['clock' => 80, 'ns' => 0],
    'items' => $graph_items,
    'graph_timeline' => $graph_timeline,
    'graph_layout' => $scenario === 'graphs-combined' ? 'combined' : 'items',
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
