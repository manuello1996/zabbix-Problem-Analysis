<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Exercise the installed controller lifecycle: getInput() contains only validated parameters.
$ui_root = getenv('ZABBIX_UI_ROOT') ?: dirname(__DIR__, 3);
require $ui_root.'/include/defines.inc.php';
require $ui_root.'/include/gettextwrapper.inc.php';
require $ui_root.'/include/translateDefines.inc.php';
require $ui_root.'/include/func.inc.php';
require $ui_root.'/include/classes/core/CAutoloader.php';
$autoloader = new CAutoloader();
$autoloader->addNamespace('', array_map(static function ($path) use ($ui_root) {
    return $ui_root.'/include/classes/'.$path;
}, ['core', 'mvc', 'validators', 'parsers', 'db', 'helpers']));
$autoloader->register();
require dirname(__DIR__).'/actions/CControllerAnalistProblemPopup.php';
// Keep native message filtering independent of a live settings/database connection.
$public_settings = new ReflectionProperty(CSettingsHelper::class, 'params_public');
$public_settings->setAccessible(true);
$public_settings->setValue(null, [CSettingsHelper::SHOW_TECHNICAL_ERRORS => '1']);
set_error_handler(static function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

$controller_class = new ReflectionClass(Modules\AnalistProblem\Actions\CControllerAnalistProblemPopup::class);
$raw_input = new ReflectionProperty(CController::class, 'raw_input');
$validated_input = new ReflectionProperty(CController::class, 'input');
$check_input = $controller_class->getMethod('checkInput');
$check_input->setAccessible(true);
$raw_input->setAccessible(true);
$validated_input->setAccessible(true);
$validate = static function (array $request, bool $expected) use ($controller_class, $raw_input, $validated_input, $check_input) {
    $controller = $controller_class->newInstanceWithoutConstructor();
    $raw_input->setValue($controller, $request);
    if ($check_input->invoke($controller) !== $expected) {
        throw new RuntimeException('Unexpected input validation result: '.json_encode($request));
    }
    return $validated_input->getValue($controller);
};

$history = ['eventid' => '1', 'tab' => 'history', 'itemid' => '10', 'before_clock' => '100', 'before_ns' => '500'];
$input = $validate($history, true);
foreach ($history as $name => $value) {
    if (!array_key_exists($name, $input) || (string) $input[$name] !== $value) {
        throw new RuntimeException('Validated history input was lost: '.$name);
    }
}
$validate(['eventid' => '1'], true);
$validate(['eventid' => '1', 'tab' => 'update'], true);
$timeline_request = ['eventid' => '100', 'tab' => 'timeline_events', 'before_eventid' => '81', 'before_clock' => '100'];
if ($validate($timeline_request, true)['before_eventid'] !== '81') {
    throw new RuntimeException('Timeline paging must retain its validated event cursor');
}
foreach (['before_eventid', 'before_clock'] as $field) {
    $invalid_timeline = $timeline_request;
    unset($invalid_timeline[$field]);
    $validate($invalid_timeline, false);
}
$validate(array_replace($timeline_request, ['before_eventid' => 'invalid']), false);
$validate(array_replace($timeline_request, ['before_clock' => '-1']), false);
$validate(['eventid' => '1', 'initial_tab' => 'timeline'], true);
$validate(['eventid' => '1', 'initial_tab' => 'invalid'], false);
$validate(['eventid' => '1', 'tab' => 'recurrences'], true);
$batch_input = ['eventid' => '1', 'tab' => 'recurrences', 'recurrence_eventids' => ['1', '3']];
if ($validate($batch_input, true)['recurrence_eventids'] !== ['1', '3']) {
    throw new RuntimeException('Validated recurrence event IDs must survive the native controller lifecycle');
}
$validate(array_replace($batch_input, ['recurrence_eventids' => ['invalid']]), false);
$validate(array_replace($batch_input, ['recurrence_eventids' => []]), false);
$validate(array_replace($batch_input, ['recurrence_eventids' => range(1, 41)]), false);
foreach (['itemid', 'before_clock', 'before_ns'] as $required) {
    $request = $history;
    unset($request[$required]);
    $validate($request, false);
}
foreach (['itemid' => 'not-an-id', 'before_clock' => '-1', 'before_ns' => '1000000000'] as $field => $value) {
    $validate(array_replace($history, [$field => $value]), false);
}
$graph_request = ['eventid' => '1', 'tab' => 'graphs', 'graph_period' => 'custom', 'graph_layout' => 'items',
    'graph_from' => 'now-6h', 'graph_to' => 'now'];
$input = $validate($graph_request, true);
foreach ($graph_request as $name => $value) {
    if (!isset($input[$name]) || $input[$name] !== $value) throw new RuntimeException('Lost graph parameter: '.$name);
}
foreach (['graph_from', 'graph_to'] as $required) {
    $request = $graph_request;
    unset($request[$required]);
    $validate($request, false);
}
$validate(array_replace($graph_request, ['graph_from' => 'not a date']), false);
$validate(array_replace($graph_request, ['graph_period' => 'invalid']), false);
$validate(array_replace($graph_request, ['graph_layout' => 'invalid']), false);
$validate(['eventid' => '1', 'tab' => 'graphs'], true);
$pattern_request = ['eventid' => '1', 'tab' => 'patterns', 'pattern_period' => 'custom',
    'pattern_from' => '2026-02-01 00:00:00', 'pattern_to' => '2026-03-31 12:00:00'];
foreach ($pattern_request as $name => $value) {
    if ($validate($pattern_request, true)[$name] !== $value) throw new RuntimeException('Lost pattern parameter: '.$name);
}
foreach (['pattern_from', 'pattern_to'] as $required) {
    $request = $pattern_request;
    unset($request[$required]);
    $validate($request, false);
}
$validate(array_replace($pattern_request, ['pattern_period' => '2']), false);
$validate(array_replace($pattern_request, ['pattern_from' => 'invalid']), false);
foreach (['1', '3', '6', '12'] as $preset) {
    $validate(['eventid' => '1', 'tab' => 'patterns', 'pattern_period' => $preset], true);
}

$settings = new ReflectionProperty(CSettingsHelper::class, 'params');
$settings->setAccessible(true);
$settings->setValue(null, [CSettingsHelper::MAX_PERIOD => '2y']);
$timeline_method = $controller_class->getMethod('getGraphTimeline');
$timeline_method->setAccessible(true);
$timeline = static function (array $options, array $event) use ($controller_class, $validated_input, $timeline_method): array {
    $controller = $controller_class->newInstanceWithoutConstructor();
    $validated_input->setValue($controller, $options);
    return $timeline_method->invoke($controller, $event);
};
date_default_timezone_set('Europe/Zurich');
$graph_event = ['clock' => time() - 7200];
$range = $timeline([], $graph_event);
if ($range['from_ts'] !== $graph_event['clock'] - 3600 || $range['to_ts'] !== $graph_event['clock'] + 3600) {
    throw new RuntimeException('The default graph period must span one hour on each side of the problem start');
}
$range = $timeline(['graph_period' => 'before'], $graph_event);
if ($range['to_ts'] !== $graph_event['clock'] || $range['to_ts'] - $range['from_ts'] !== 21600) {
    throw new RuntimeException('Before problem must end at the event timestamp');
}
$range = $timeline(['graph_period' => 'duration'], $graph_event + ['recovery_clock' => $graph_event['clock'] + 120]);
if ($range['to_ts'] !== $graph_event['clock'] + 120) throw new RuntimeException('Resolved graphs must end at recovery');
$range = $timeline(['graph_period' => 'duration'], $graph_event + ['recovery_clock' => $graph_event['clock'] + 10]);
if ($range['to_ts'] - $range['from_ts'] + 1 < ZBX_MIN_PERIOD) throw new RuntimeException('Short problems need a valid native graph period');
$range = $timeline(['graph_period' => 'day'], $graph_event);
if ($range['from'] !== 'now-1d' || $range['to'] !== 'now') throw new RuntimeException('Live periods must remain relative');
$range = $timeline(['graph_period' => 'custom', 'graph_from' => '2026-03-29 01:30:00',
    'graph_to' => '2026-03-29 03:30:00'], $graph_event);
if ($range['to_ts'] - $range['from_ts'] !== 3600) throw new RuntimeException('Custom ranges must use the native frontend timezone across DST');
foreach ([['now', 'now-1h'], ['now-10s', 'now'], ['now-3y', 'now']] as [$from, $to]) {
    try {
        $timeline(['graph_period' => 'custom', 'graph_from' => $from, 'graph_to' => $to], $graph_event);
    }
    catch (RuntimeException $exception) {
        continue;
    }
    throw new RuntimeException('Invalid graph periods must respect native minimum and maximum limits');
}

$pattern_method = $controller_class->getMethod('getPatternTimeline');
$pattern_method->setAccessible(true);
$pattern_range = static function (array $options, string $date) use ($controller_class, $validated_input, $pattern_method): array {
    $controller = $controller_class->newInstanceWithoutConstructor();
    $validated_input->setValue($controller, $options);
    return $pattern_method->invoke($controller, ['clock' => strtotime($date)]);
};
foreach (['1' => '2026-03-01', '3' => '2026-01-01', '6' => '2025-10-01', '12' => '2025-04-01'] as $preset => $start) {
    $range = $pattern_range(['pattern_period' => (string) $preset], '2026-03-31 12:00:00');
    if ($range['from_ts'] !== strtotime($start) || $range['to_ts'] !== strtotime('2026-03-31 12:00:00')) {
        throw new RuntimeException('Pattern presets must anchor calendar months at problem start');
    }
}
$range = $pattern_range(['pattern_period' => 'custom', 'pattern_from' => '2026-03-29 01:30:00',
    'pattern_to' => '2026-03-29 03:30:00'], '2026-03-31 12:00:00');
if ($range['to_ts'] - $range['from_ts'] !== 3600) throw new RuntimeException('Pattern ranges must honor frontend DST');
$range = $pattern_range(['pattern_period' => 'custom', 'pattern_from' => 'now-1d', 'pattern_to' => 'now'], '2026-03-31');
if (abs(($range['to_ts'] - $range['from_ts']) - 86400) > 3600) throw new RuntimeException('Relative custom pattern times must use the native parser');
foreach ([['2026-03-31', '2026-03-01'], ['2020-01-01', '2026-01-01']] as [$from, $to]) {
    try { $pattern_range(['pattern_period' => 'custom', 'pattern_from' => $from, 'pattern_to' => $to], '2026-03-31'); }
    catch (RuntimeException $exception) { continue; }
    throw new RuntimeException('Reversed or excessive custom pattern ranges must be rejected');
}

echo "Native input validation checks passed.\n";
