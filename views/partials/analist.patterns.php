<?php

require_once __DIR__.'/../../includes/PatternData.php';
$timeline = $data['pattern_timeline'];
$pattern_data = \Modules\AnalistProblem\PatternData::build($pattern_events,
    $timeline['from_ts'], $timeline['to_ts'], (int) $event['clock']);
$weekdays = [_('Sunday'), _('Monday'), _('Tuesday'), _('Wednesday'), _('Thursday'), _('Friday'), _('Saturday')];
$hour_labels = [];
$use_12h = strpos(TIME_FORMAT, 'A') !== false || strpos(TIME_FORMAT, 'a') !== false;
for ($hour = 0; $hour < 24; $hour++) {
    $sample = gmmktime($hour, 0, 0, 1, 1, 2024);
    $hour_labels[] = $use_12h ? zbx_date2str('g a', $sample, 'UTC')
        : zbx_date2str('H', $sample, 'UTC')._x('h', 'hour short');
}
$month_labels = array_map(static function ($month) {
    return zbx_date2str('M/y', strtotime($month.'-01'));
}, $pattern_data['monthKeys']);
$pattern_data += ['weekLabels' => $weekdays, 'hourLabels' => $hour_labels, 'monthLabels' => $month_labels,
    'filteredBy' => _('Filtered by'), 'incidents' => _('occurrences'),
    'sameSlotLastWeek' => _('Same slot last week'), 'noEvents' => _('No occurrences in the selected period.'),
    'noMatches' => _('No occurrences match the selected filters.'), 'allOccurrences' => _('All occurrences'),
    'selectedOccurrences' => _('Matching occurrences'),
    'expandLabel' => _('Expand'), 'collapseLabel' => _('Collapse')];
$pattern_id = uniqid('analist-patterns-');
$time_patterns_div->addClass('analist-patterns patterns-d3-container')->setAttribute('data-pattern-view', '');
$time_patterns_div->addItem((new CDiv(new CTag('h6', true,
    _('Overview based on the time of day and weekday of the same problem. Occurrences are listed in Timeline.'))))
    ->addClass('pattern-chart-container')->addStyle('margin-bottom:5px'));
$period = (new CSelect('pattern_period'))->setId($pattern_id.'-period')
    ->setFocusableElementId($pattern_id.'-period-focus')->setValue($timeline['period'])
    ->setAttribute('data-pattern-period', '')->addOptions(CSelect::createOptionsFromArray([
        '1' => _('1 month'), '3' => _('3 months'), '6' => _('6 months'), '12' => _('12 months'),
        'custom' => _('Custom period')
    ]));
$apply = (new CSubmit('apply_patterns', _('Apply')))->setId($pattern_id.'-apply')
    ->setAttribute('data-pattern-apply', '');
$custom_range = (new CDiv([
    new CLabel(_('From'), $pattern_id.'_from'),
    (new CDateSelector($pattern_id.'_from', $timeline['from']))->setDateFormat(ZBX_FULL_DATE_TIME)
        ->setAriaRequired(true)->setAttribute('data-pattern-from', ''),
    new CLabel(_('To'), $pattern_id.'_to'),
    (new CDateSelector($pattern_id.'_to', $timeline['to']))->setDateFormat(ZBX_FULL_DATE_TIME)
        ->setAriaRequired(true)->setAttribute('data-pattern-to', '')
]))->addClass('analist-pattern-custom-range')->setAttribute('data-pattern-custom-range', '');
if ($timeline['period'] !== 'custom') {
    $apply->setAttribute('hidden', 'hidden');
    $custom_range->setAttribute('hidden', 'hidden');
}
$time_patterns_div->addItem((new CForm('get', 'zabbix.php'))->setName($pattern_id)
    ->setAttribute('data-pattern-controls', '')->addItem([
        (new CDiv([new CLabel(_('Period'), $period->getFocusableElementId()), $period, $apply]))
            ->addClass('analist-pattern-toolbar'),
        $custom_range,
        (new CTag('p', true))->setAttribute('data-pattern-message', '')->setAttribute('role', 'alert')
    ]));
$time_patterns_div->addItem((new CTag('p', true, [
    zbx_date2str(DATE_TIME_FORMAT_SECONDS, $timeline['from_ts']).' – '
        .zbx_date2str(DATE_TIME_FORMAT_SECONDS, $timeline['to_ts']).' · '.date_default_timezone_get(),
    $timeline['period'] === 'custom' ? '' : ' · '._('Period ends at problem start')
]))->addClass('mnz-drilldown-hint'));
$time_patterns_div->addItem((new CDiv([
    (new CSpan())->addClass('mnz-filter-label')->setAttribute('data-pattern-filter-label', ''),
    (new CButton(null, _('Clear filter')))->addClass(ZBX_STYLE_BTN_ALT)->setAttribute('data-pattern-clear', '')
]))->addClass('mnz-investigation-filter-bar')->setAttribute('data-pattern-filter', '')->setAttribute('hidden', 'hidden'));
$time_patterns_div->addItem((new CTag('p', true))->setAttribute('data-pattern-status', '')
    ->addClass('mnz-drilldown-hint')->setAttribute('role', 'status')->setAttribute('aria-live', 'polite'));

// Keep the original compact grid, with keyboard-accessible cells.
$labels = (new CDiv((new CDiv())->addClass('mnz-heatmap-corner')))->addClass('mnz-heatmap-labels-col');
$hours = (new CDiv())->addClass('mnz-heatmap-hours-row');
foreach ($hour_labels as $label) $hours->addItem((new CDiv($label))->addClass('mnz-heatmap-hour-label'));
$heatmap_body = (new CDiv($hours))->addClass('mnz-heatmap-body');
foreach ([1, 2, 3, 4, 5, 6, 0] as $weekday) {
    $labels->addItem((new CDiv($weekdays[$weekday]))->addClass('mnz-heatmap-row-label'));
    $row = (new CDiv())->addClass('mnz-heatmap-row');
    for ($hour = 0; $hour < 24; $hour++) {
        $row->addItem((new CButton(null, '0'))->addClass('mnz-heatmap-cell mnz-heatmap-cell-active')
            ->setAttribute('data-pattern-slot', '')->setAttribute('data-weekday', $weekday)->setAttribute('data-hour', $hour)
            ->setAttribute('data-level', 0)->setAttribute('aria-pressed', 'false')
            ->setAttribute('tabindex', $weekday === 1 && $hour === 0 ? 0 : -1));
    }
    $heatmap_body->addItem($row);
}
$time_patterns_div->addItem((new CDiv([
    (new CTag('h4', true, _('Incident heatmap (day x hour)')))->addClass('mnz-incident-chart-title'),
    (new CDiv(_('Click a cell to filter the charts. Click it again to clear the selection.')))->addClass('mnz-drilldown-hint'),
    (new CDiv((new CDiv([$labels, $heatmap_body]))->addClass('mnz-heatmap-grid')))->addClass('mnz-heatmap-container')
]))->addClass('mnz-incident-chart-container'));

// Retain the original bar charts without native table/button presentation.
$make_bars = static function (array $labels, string $kind, bool $interactive): CDiv {
    $bars = (new CDiv())->addClass($kind === 'month' ? 'mnz-investigation-monthly-bars' : 'mnz-investigation-bars')
        ->setAttribute('data-pattern-bars', $kind);
    foreach ($labels as $index => $label) {
        $items = [(new CSpan('0'))->addClass('mnz-drilldown-bar-value')->setAttribute('data-pattern-count', ''),
            (new CDiv())->addClass($kind === 'month' ? 'mnz-investigation-monthly-bar' : 'mnz-investigation-bar')
                ->setAttribute('data-pattern-bar', '')->setAttribute('aria-hidden', 'true'),
            (new CSpan($label))->addClass($kind === 'month' ? 'mnz-investigation-monthly-label' : 'mnz-investigation-bar-label')];
        $bar = $interactive ? (new CButton(null, $items))->addClass('mnz-bar-clickable')
            ->setAttribute('data-pattern-'.$kind, $index)->setAttribute('aria-pressed', 'false') : new CDiv($items);
        $bars->addItem($bar->addClass($kind === 'month' ? 'mnz-investigation-monthly-item' : 'mnz-investigation-bar-item')
            ->setAttribute('data-pattern-index', $index));
    }
    return $bars;
};
$drilldown = (new CDiv([
    (new CDiv())->addClass('mnz-drilldown-title')->setAttribute('data-pattern-day-title', ''),
    (new CDiv(_('Click a day to see its hourly distribution in the heatmap.')))->addClass('mnz-drilldown-subhint'),
    (new CDiv($make_bars(range(1, 31), 'day', true)))->addClass('mnz-drilldown-chart'),
    (new CButton(null, _('Close')))->addClass('btn-alt')->setAttribute('data-pattern-hide-days', '')
]))->addClass('mnz-investigation-drilldown')->setAttribute('data-pattern-days', '')->setAttribute('hidden', 'hidden');
$monthly_titles = ['1' => _('Last month'), '3' => _('Last 3 months'), '6' => _('Last 6 months'),
    '12' => _('Last 12 months'), 'custom' => _('Monthly distribution')];
$time_patterns_div->addItem((new CDiv([
    (new CTag('h4', true, $monthly_titles[$timeline['period']]))->addClass('mnz-incident-chart-title'),
    $make_bars($month_labels, 'month', true), $drilldown
]))->addClass('mnz-incident-chart-container'));
foreach (['hour' => [_('Hourly Pattern'), $hour_labels], 'weekday' => [_('Weekly Pattern'), $weekdays]] as $kind => $chart) {
    $body_id = $pattern_id.'-'.$kind;
    $time_patterns_div->addItem((new CDiv([
        (new CDiv([
            (new CTag('h4', true, $chart[0]))->addClass('mnz-incident-chart-title'),
            (new CButton(null, _('Expand')))->addClass('btn-alt mnz-pattern-toggle')
                ->setAttribute('data-pattern-toggle', $kind)->setAttribute('aria-controls', $body_id)
                ->setAttribute('aria-expanded', 'false')
        ]))->addClass('mnz-pattern-collapsible-header'),
        (new CDiv($make_bars($chart[1], $kind, false)))->setId($body_id)->addClass('mnz-pattern-collapsible-body')
            ->setAttribute('data-pattern-body', $kind)->setAttribute('hidden', 'hidden')
    ]))->addClass('pattern-chart-container mnz-pattern-collapsible'));
}
