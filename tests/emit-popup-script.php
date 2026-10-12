<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$ui_root = getenv('ZABBIX_UI_ROOT') ?: dirname(__DIR__, 3);
require $ui_root.'/include/defines.inc.php';
require $ui_root.'/include/gettextwrapper.inc.php';
require $ui_root.'/include/translateDefines.inc.php';
require $ui_root.'/include/func.inc.php';
require $ui_root.'/include/html.inc.php';
require $ui_root.'/include/classes/core/CAutoloader.php';
$autoloader = new CAutoloader();
$autoloader->addNamespace('', [$ui_root.'/include/classes/html', $ui_root.'/include/classes/core',
    $ui_root.'/include/classes/html/interfaces', $ui_root.'/include/classes/helpers', $ui_root.'/include/classes/parsers', $ui_root.'/include/classes/validators']);
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
    public static function makeSeverityCell($severity) { return new CCol(self::getName($severity)); }
}
class CWebUser {
    public static $data = ['lang' => 'en_US'];
    public static function checkAccess($permission) { return true; }
}
class CSettingsHelper {
    public const SEARCH_LIMIT = 'search_limit';
    public static function get($name) { return 100; }
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
class ViewFixture {
    public function addJsFile($file) {}
    public function render($data) {
        ob_start();
        include dirname(__DIR__).'/views/problemanalist.view.php';
        return json_decode(ob_get_clean(), true, 512, JSON_THROW_ON_ERROR);
    }
}
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
$response = (new ViewFixture())->render([
    'tab' => $argv[1] ?? 'overview', 'event' => $event,
    'host' => ($argv[1] ?? 'overview') === 'host' ? null : $host, 'trigger' => null,
    'is_fragment' => $scenario === 'fragment',
    'closing_pending' => $scenario === 'closing',
    'can_acknowledge' => in_array($scenario, ['permitted', 'acknowledged', 'ack-only']),
    'can_close' => in_array($scenario, ['permitted', 'acknowledged']),
    'pattern_events' => [['clock' => strtotime('2026-03-30 00:30:00')]],
    'items' => [['itemid' => '1', 'name' => 'Fixture metric']],
    'monthly_comparison' => ['months' => [['name' => 'March 2026', 'count' => 1, 'change_percentage' => 0]]]
]);
echo json_encode(['inline' => $response['script_inline'] ?? '', 'response' => $response, 'event' => $event, 'host' => $host]);
