<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$ui_root = getenv('ZABBIX_UI_ROOT') ?: dirname(__DIR__, 3);
require $ui_root.'/include/defines.inc.php';
class CSeverityHelper {
    public static function getName($severity) {
        return ['Not classified', 'Information', 'Warning', 'Average', 'High', 'Disaster'][$severity];
    }
}
if (!function_exists('_')) { function _($message) { return $message; } }
$hourly_data = array_fill(0, 24, 0);
$weekly_data = array_fill(0, 7, 0);
$weekdays = $hour_labels = $event_clocks = $event_slots = $month_keys = $monthly_labels = [];
$pattern_week_start = 1774220400;
$pattern_week_end = 1774821600;
$event = ['eventid' => '1', 'name' => "Quoted \"event\" \n </script>", 'severity' => 2];
$host = ['name' => "Quoted \"host\" \n </script>", 'hostid' => '1'];
$trigger = ['triggerid' => '1'];
$view = file_get_contents(dirname(__DIR__).'/views/problemanalist.view.php');
$start = strpos($view, "'script_inline' => ") + strlen("'script_inline' => ");
$end = strpos($view, "\n];", $start);
$expression = substr($view, $start, $end - $start);
$inline = eval('return '.$expression.';');
echo json_encode(['inline' => $inline, 'event' => $event, 'host' => $host]);
