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
foreach (['itemid', 'before_clock', 'before_ns'] as $required) {
    $request = $history;
    unset($request[$required]);
    $validate($request, false);
}
foreach (['itemid' => 'not-an-id', 'before_clock' => '-1', 'before_ns' => '1000000000'] as $field => $value) {
    $validate(array_replace($history, [$field => $value]), false);
}
echo "Native input validation checks passed.\n";
