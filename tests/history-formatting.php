<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Load the actual view helper with native Zabbix HTML, unit conversion and value-map classes.
$argv[1] = 'history';
ob_start();
require __DIR__.'/emit-popup-script.php';
ob_end_clean();

$format = static function (string $value, string $units = '', int $type = ITEM_VALUE_TYPE_UINT64,
        array $valuemap = []): array {
    $html = createAnalistHistoryValue($value, ['value_type' => $type, 'units' => $units,
        'valuemap' => $valuemap])->toString();
    return [$html, html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')];
};
$expect = static function (string $actual, string $expected, string $label): void {
    if ($actual !== $expected) {
        throw new RuntimeException($label.': expected '.json_encode($expected).', got '.json_encode($actual));
    }
};

$expect($format('2162000000', 'bps')[1], '2.16 Gbps (2162000000)', 'Bits per second');
$expect($format('1048576', 'B')[1], '1 MB (1048576)', 'Bytes use native binary scaling');
$expect($format('1048576', 'Bps')[1], '1 MBps (1048576)', 'Bytes per second');
$expect($format('90061', 's')[1], '1d 1h 1m (90061)', 'Seconds use the native three-unit duration');
$expect($format('90061', 'uptime')[1], '1 day, 01:01:01 (90061)', 'Uptime');
$timestamp = '1774951200';
$expect($format($timestamp, 'unixtime')[1], zbx_date2str(DATE_TIME_FORMAT_SECONDS, (int) $timestamp).' ('.$timestamp.')',
    'Unix timestamps use the frontend timezone and date format');
$expect($format('0', 'B')[1], '0 B (0)', 'Zero with units');
$expect($format('1048576', '!B')[1], '1048576 B (1048576)', 'Native unit prefix disables scaling');
$expect($format('1.23000000', '', ITEM_VALUE_TYPE_FLOAT)[1], '1.23 (1.23000000)', 'Raw floating-point representation is preserved');
$expect($format('42')[1], '42', 'Unchanged plain numbers are not duplicated');
$text = str_repeat('Long text ', 8);
$expect($format($text, '', ITEM_VALUE_TYPE_TEXT)[1], $text, 'Text is not shortened or duplicated');

$mapped = ['mappings' => [['type' => VALUEMAP_MAPPING_TYPE_EQUAL, 'value' => '2', 'newvalue' => 'Connected']]];
$expect($format('2', '', ITEM_VALUE_TYPE_UINT64, $mapped)[1], 'Connected (2)', 'Mapped raw values appear once');
$mapped['mappings'][0]['value'] = '1048576';
$mapped['mappings'][0]['newvalue'] = 'Ready';
$expect($format('1048576', 'B', ITEM_VALUE_TYPE_UINT64, $mapped)[1], 'Ready (1 MB) (1048576)',
    'Mapped units retain native formatting and the exact raw value');
$large_raw = '18446744073709551615';
$large = $format($large_raw, 'B')[1];
if (substr($large, -strlen(' ('.$large_raw.')')) !== ' ('.$large_raw.')') {
    throw new RuntimeException('The exact uint64 raw value must survive native numeric rounding');
}
$mapped['mappings'][0]['value'] = '2';
$mapped['mappings'][0]['newvalue'] = '<img src=x onerror=alert(1)>';
[$html, $plain] = $format('2', '', ITEM_VALUE_TYPE_UINT64, $mapped);
$expect($plain, '<img src=x onerror=alert(1)> (2)', 'Mapped labels keep their text');
if (strpos($html, '<img') !== false) throw new RuntimeException('Mapped labels must remain escaped');
$expect($format('raw binary', '', ITEM_VALUE_TYPE_BINARY)[1], 'binary value', 'Binary payloads remain hidden');

echo "Native history formatting checks passed.\n";
