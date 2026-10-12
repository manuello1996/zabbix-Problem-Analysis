<?php declare(strict_types = 0);


// Include required files for event functions
require_once dirname(__FILE__).'/../../../include/events.inc.php';
require_once dirname(__FILE__).'/../../../include/actions.inc.php';
require_once dirname(__FILE__).'/../../../include/users.inc.php';
$this->addJsFile('layout.mode.js');
$this->addJsFile('gtlc.js');
$this->addJsFile('class.calendar.js');

/**
 * @var CView $this
 */

$event = $data['event'] ?? [];
$trigger = $data['trigger'] ?? null;
$host = $data['host'] ?? null;
$pattern_events = $data['pattern_events'] ?? [];
$items = $data['items'] ?? [];
$monthly_comparison = $data['monthly_comparison'] ?? [];
$operational_data = $data['operational_data'] ?? ['value' => '', 'history' => []];

// Format timestamps
$event_time = isset($event['clock']) ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, $event['clock']) : '';
$event_details_url = null;

if ($event_time !== '' && isset($event['eventid'])) {
    $event_triggerid = $trigger['triggerid'] ?? $event['objectid'] ?? null;

    if ($event_triggerid !== null) {
        $event_details_url = 'tr_events.php?triggerid='.$event_triggerid.'&eventid='.$event['eventid'];
    }
}

$event_time_value = $event_details_url !== null
    ? new CLink($event_time, $event_details_url)
    : ($event_time ?: 'N/A');
$time_ago = isset($event['clock']) ? zbx_date2age($event['clock']) : '';
$is_resolved = !empty($event['r_eventid']);
$recovery_time = isset($event['recovery_clock'])
    ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, (int) $event['recovery_clock'])
    : '';
$problem_duration = isset($event['clock'])
    ? zbx_date2age((int) $event['clock'], isset($event['recovery_clock']) ? (int) $event['recovery_clock'] : 0)
    : '';

// Get severity info
$severity = isset($event['severity']) ? (int) $event['severity'] : 0;
$is_acknowledged = (int) ($event['acknowledged'] ?? EVENT_NOT_ACKNOWLEDGED) === (int) EVENT_ACKNOWLEDGED;
$acknowledgement_value = (new CDiv([
    (new CIcon(
        $is_acknowledged ? ZBX_ICON_CHECK : ZBX_ICON_UNCHECK,
        $is_acknowledged ? _('Acknowledged') : _('Unacknowledged')
    ))->addClass($is_acknowledged ? ZBX_STYLE_COLOR_POSITIVE : ZBX_STYLE_COLOR_NEGATIVE),
    new CSpan($is_acknowledged ? _('Acknowledged') : _('Unacknowledged'))
]))->addClass('analist-detail-inline');

/**
 * Create essential metrics table for Zabbix Agent hosts
 */
function createEssentialMetricsTable($metrics) {
    $table = new CTableInfo();
    $table->setHeader([_('Metric'), _('Last Value')]);

    if (empty($metrics)) {
        $no_data_row = new CRow([
            new CCol(_('No system metrics available'), null, 2)
        ]);
        $no_data_row->addClass('system-metrics-more');
        $table->addRow($no_data_row);
        return $table;
    }

    // Helper: format seconds -> "Xd HH:MM:SS"
    $formatUptime = static function (int $seconds): string {
        if ($seconds < 0) {
            $seconds = 0;
        }
        $days    = intdiv($seconds, 86400);
        $hours   = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs    = $seconds % 60;

        $parts = [];
        if ($days > 0) {
            $parts[] = $days.'d';
        }
        $parts[] = sprintf('%02d:%02d:%02d', $hours, $minutes, $secs);
        return implode(' ', $parts);
    };

    foreach ($metrics as $metric) {
        $name       = $metric['name'] ?? '';
        $category   = $metric['category'] ?? '';
        $key        = $metric['key'] ?? '';
        $last_value = $metric['last_value'] ?? '';
        $units      = trim((string)($metric['units'] ?? ''));
        $suffix     = ($units !== '') ? (' ' . $units) : '';

        $value_display = '';

        // Treat as uptime if categorized as Uptime OR key matches known uptime keys.
        $is_uptime = ($category === 'Uptime')
            || preg_match('/^system\.uptime\b|^system\.hw\.uptime\b/i', (string)$key);

        if ($is_uptime && is_numeric($last_value)) {
            $value_display = $formatUptime((int)$last_value);  // no units for uptime
        }
        elseif (is_numeric($last_value)) {
            $num = (float)$last_value;

            if ($num >= 1_000_000_000) {
                $value_display = number_format($num / 1_000_000_000, 1) . 'G' . $suffix;
            }
            elseif ($num >= 1_000_000) {
                $value_display = number_format($num / 1_000_000, 1) . 'M' . $suffix;
            }
            elseif ($num >= 1_000) {
                $value_display = number_format($num / 1_000, 1) . 'K' . $suffix;
            }
            else {
                // Keep small numbers with two decimals; for percentages/ratios this looks good.
                $value_display = number_format($num, 2) . $suffix;
            }
        }
        else {
            $value_display = (string)$last_value . $suffix;
        }

        $row = new CRow([
            $name,
            $value_display
        ]);

        $table->addRow($row);
    }

    return $table;
}

function createAnalistHistoryValue($value, array $item) {
    if ((int) ($item['value_type'] ?? -1) === ITEM_VALUE_TYPE_BINARY) {
        return italic(_('binary value'))->addClass(ZBX_STYLE_GREY);
    }

    if (!array_key_exists('valuemap', $item) || !is_array($item['valuemap'])) {
        $item['valuemap'] = [];
    }

    if ((int) ($item['value_type'] ?? -1) === ITEM_VALUE_TYPE_FLOAT && function_exists('formatFloat')) {
        $value = formatFloat($value, ['decimals' => ZBX_UNITS_ROUNDOFF_UNSUFFIXED]);
    }

    if (class_exists('CValueMapHelper')) {
        $value = CValueMapHelper::applyValueMap((int) ($item['value_type'] ?? -1), $value, $item['valuemap']);
    }

    return new CPre(zbx_nl2br((string) $value));
}

function createOperationalDataSection(array $operational_data): CDiv {
    $section = (new CDiv())->addClass('operational-data-section');

    $opdata = $operational_data['value'] ?? '';

    if (is_string($opdata) && trim($opdata) !== '') {
        $opdata_table = new CTableInfo();
        $opdata_table->setHeader([_('Operational Data')]);
        $opdata_table->addRow([(new CCol($opdata))->addClass(ZBX_STYLE_WORDBREAK)]);
        $opdata_table->addClass('operational-data-table');
        $section->addItem($opdata_table);
    }

    $history = $operational_data['history'] ?? [];
    $table = new CTableInfo();
    $table->addClass('operational-history-table');
    $table->setHeader([
        (new CColHeader(_('Item')))->addStyle('width: 30%;'),
        (new CColHeader(_('Latest 3 Values')))->addStyle('width: 70%;')
    ]);

    if (!$history) {
        $table->addRow([_('No recent item history available'), '']);
        $section->addItem($table);

        return $section;
    }

    foreach ($history as $entry) {
        $item = $entry['item'] ?? [];
        $values = $entry['values'] ?? [];
        $value_lines = [];

        foreach ($values as $value) {
            $clock = isset($value['clock']) && $value['clock'] !== null
                ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, (int) $value['clock'])
                : _('N/A');

            $value_lines[] = (new CDiv([
                (new CSpan($clock))->addClass('grey')->addClass('operational-history-clock'),
                createAnalistHistoryValue($value['value'] ?? '', $item)
                    ->addClass('operational-history-data')
            ]))->addClass('operational-history-value');
        }

        if (!$value_lines) {
            $value_lines[] = new CSpan(_('No history values found'));
        }

        $table->addRow([
            (new CCol($item['name'] ?? $item['key_'] ?? _('Unknown item')))
                ->addClass(ZBX_STYLE_WORDBREAK)
                ->addStyle('width: 30%;'),
            (new CCol($value_lines))
                ->addClass(ZBX_STYLE_WORDBREAK)
                ->addStyle('width: 70%;')
        ]);
    }

    $section->addItem($table);

    return $section;
}

function createMonthlyComparisonSection(array $monthly_comparison): ?CDiv {
    if (empty($monthly_comparison)) {
        return null;
    }

    $section = (new CDiv())->addClass('monthly-comparison-section');
    $section->addItem(new CTag('h4', false, _('Monthly Comparison')));

    $months = [];

    if (!empty($monthly_comparison['months'])) {
        $months = array_reverse($monthly_comparison['months']);
    }
    elseif (!empty($monthly_comparison['current_month'])) {
        $months = [
            $monthly_comparison['previous_month'],
            $monthly_comparison['current_month']
        ];
    }

    if (!$months) {
        return $section;
    }

    $tables = [];
    foreach (array_chunk($months, 3) as $month_group) {
        $table = new CTableInfo();
        $table->setHeader([_('Period'), _('Incidents'), _('Change')]);

        foreach ($month_group as $m) {
            $chg = $m['change_percentage'] ?? null;
            $change_color = '#666666';
            $change_icon = '->';
            $change_text = ($chg === null) ? '-' : (($chg > 0) ? ('+' . $chg . '%') : ($chg . '%'));

            if ($chg !== null) {
                if ($chg > 0) {
                    $change_color = '#e74c3c';
                    $change_icon = '^';
                }
                elseif ($chg < 0) {
                    $change_color = '#27ae60';
                    $change_icon = 'v';
                }
            }

            $table->addRow([
                $m['name'],
                $m['count'],
                ($chg === null)
                    ? '-'
                    : (new CSpan($change_icon . ' ' . $change_text))
                        ->addStyle("color: {$change_color}; font-weight: bold;")
            ]);
        }

        $tables[] = $table;
    }

    $section->addItem(
        (new CDiv($tables))
            ->addClass('monthly-comparison-tables')
            ->addStyle('display: grid; grid-template-columns: repeat(2, minmax(280px, 1fr)); gap: 15px;')
    );

    $latest_chg = $monthly_comparison['change_percentage'] ?? 0;
    if ($latest_chg != 0) {
        $change_color = ($latest_chg > 0) ? '#e74c3c' : '#27ae60';
        $trend_message = ($latest_chg > 0)
            ? (_('Incidents increased by') . ' ' . abs($latest_chg) . '% ' . _('compared to previous month'))
            : (_('Incidents decreased by') . ' ' . abs($latest_chg) . '% ' . _('compared to previous month'));

        $section->addItem(
            (new CDiv($trend_message))
                ->addStyle("color: {$change_color}; font-style: italic; margin-top: 10px; font-size: 12px;")
        );
    }

    return $section;
}

// Create tabs
CCookieHelper::unset('tab');
$tabs = (new CTabView())->setSelected(0);

// Problem details, aligned with the native Zabbix trigger/event details layout.
$details_label = static function (string $label): CCol {
    return (new CCol($label))->addClass('analist-detail-label');
};

$details_value = static function ($value): CCol {
    return (new CCol($value))->addClass('analist-detail-value');
};

$details_full_value = static function ($value): CCol {
    return (new CCol($value))
        ->setColSpan(3)
        ->addClass('analist-detail-value')
        ->addClass(ZBX_STYLE_WORDBREAK);
};

$problem_details_table = new CTableInfo();
$problem_details_table
    ->addClass('analist-detail-table')
    ->addClass('analist-problem-detail-table');

$problem_details_table->addRow([
    $details_label(_('Event')),
    $details_full_value($event['name'] ?? '')
]);

$problem_details_table->addRow([
    $details_label(_('Time')),
    $details_full_value($event_time_value)
]);

$problem_details_table->addRow([
    $details_label(_('Severity')),
    CSeverityHelper::makeSeverityCell($severity),
    $details_label(_('Acknowledged')),
    $details_value($acknowledgement_value)
]);

if ($is_resolved) {
    $problem_details_table->addRow([
        $details_label(_('Recovery time')),
        $details_value($recovery_time ?: _('Unknown')),
        $details_label(_('Duration')),
        $details_value($problem_duration ?: _('Unknown'))
    ]);
}
else {
    $problem_details_table->addRow([
        $details_label(_('Age')),
        $details_value($time_ago ?: 'N/A')
    ]);
}

$event_tags = makeTags([$event], true, 'eventid', count($event['tags'] ?? []));
if (!empty($event_tags[$event['eventid']])) {
    $problem_details_table->addRow([
        $details_label(_('Tags')),
        $details_full_value((new CDiv($event_tags[$event['eventid']]))->addClass(ZBX_STYLE_TAGS_WRAPPER))
    ]);
}

if ($trigger && isset($trigger['comments']) && $trigger['comments'] !== '') {
    $problem_details_table->addRow([
        $details_label(_('Description')),
        $details_full_value($trigger['comments'])
    ]);
}

if ($trigger && isset($trigger['expression']) && $trigger['expression'] !== '') {
    $problem_details_table->addRow([
        $details_label(_('Problem expression')),
        $details_full_value((new CDiv($trigger['expression']))->addClass(ZBX_STYLE_WORDBREAK))
    ]);
}

if ($trigger && isset($trigger['recovery_expression']) && $trigger['recovery_expression'] !== '') {
    $problem_details_table->addRow([
        $details_label(_('Recovery expression')),
        $details_full_value((new CDiv($trigger['recovery_expression']))->addClass(ZBX_STYLE_WORDBREAK))
    ]);
}

// Create overview container with full-width rows.
$overview_container = new CDiv();
$overview_container
    ->addClass('overview-container')
    ->addStyle('display: flex; flex-direction: column; gap: 15px;');

$overview_container->addItem(createOperationalDataSection($operational_data));

$problem_detail_section = new CDiv();
$problem_detail_section->addClass('problem-detail-section');
$problem_detail_section->addItem(new CTag('h4', false, _('Problem details')));
$problem_detail_section->addItem($problem_details_table);
$overview_container->addItem($problem_detail_section);

// TAB 1: Host Information - Primeiro tab
$host_div = new CDiv();

// Check if host data is available for debugging
if (!$host) {
    $host_div->addItem(new CDiv(_('Host information not available')));
}

if ($host && is_array($host)) {
    // Reorganized sections for better layout flow
    $primary_sections = [];      // Full-width sections at top
    $info_row_1 = [];           // Basic info row: Monitoring + Availability
    $info_row_2 = [];           // Extended info row: Host groups + Monitored by
    $tags_row = [];             // Tags in separate row for better visibility
    $secondary_sections = [];    // Templates and Inventory in grid

    // Description first - full width if exists
    if (!empty($host['description'])) {
        $primary_sections[] = makeAnalistHostSectionDescription($host['description']);
    }

    $host_problem_total = array_sum($host['problem_count'] ?? []);

    // Info row 1: Core monitoring information side by side
    $info_row_1[] = makeAnalistHostSectionMonitoring($host['hostid'], $host['dashboard_count'] ?? 0,
        $host['item_count'] ?? 0, $host['graph_count'] ?? 0, $host['web_scenario_count'] ?? 0, $host_problem_total
    );
    $info_row_1[] = makeAnalistHostSectionAvailability($host['interfaces'] ?? []);

    // Info row 2: Configuration information side by side
    if (!empty($host['hostgroups'])) {
        $info_row_2[] = makeAnalistHostSectionHostGroups($host['hostgroups']);
    }
    $info_row_2[] = makeAnalistHostSectionMonitoredBy($host);

    // Tags in separate row for better readability
    if (!empty($host['tags'])) {
        $tags_row[] = makeAnalistHostSectionTags($host['tags']);
    }

    // Secondary sections: Templates and Inventory in grid layout
    if (!empty($host['templates'])) {
        $secondary_sections[] = makeAnalistHostSectionTemplates($host['templates']);
    }

    if (!empty($host['inventory']) && hasAnalistHostInventoryData($host['inventory'], [])) {
        $secondary_sections[] = makeAnalistHostSectionInventoryCompact($host['hostid'], $host['inventory'], []);
    }

    // Create organized layout containers with improved structure
    $sections_container = new CDiv();
    $sections_container->addClass('analisthost-sections-reorganized');

    // Add primary sections (full width at top)
    foreach ($primary_sections as $section) {
        $sections_container->addItem(
            (new CDiv($section))->addClass('analisthost-row analisthost-row-primary')
        );
    }

    // Add info row 1: Core monitoring info (side by side)
    if (!empty($info_row_1)) {
        $info_container_1 = new CDiv($info_row_1);
        $info_container_1->addClass('analisthost-row analisthost-row-info-primary');
        $sections_container->addItem($info_container_1);
    }

    // Add info row 2: Configuration info (side by side)
    if (!empty($info_row_2)) {
        $info_container_2 = new CDiv($info_row_2);
        $info_container_2->addClass('analisthost-row analisthost-row-info-secondary');
        $sections_container->addItem($info_container_2);
    }

    // Add tags row (separate for better visibility)
    if (!empty($tags_row)) {
        $tags_container = new CDiv($tags_row);
        $tags_container->addClass('analisthost-row analisthost-row-tags');
        $sections_container->addItem($tags_container);
    }

    // Add secondary sections in grid layout
    if (!empty($secondary_sections)) {
        $secondary_container = new CDiv($secondary_sections);
        $secondary_container->addClass('analisthost-row analisthost-row-secondary-grid');
        $sections_container->addItem($secondary_container);
    }

    $body = (new CDiv([
        makeAnalistHostSectionsHeader($host),
        $sections_container
    ]))->addClass('analisthost-container');

    $host_div->addItem($body);
} else {
    $host_div->addItem(new CDiv(_('Host information not available')));
}

// TAB 1: Overview
$tabs->addTab('overview', _('Overview'), $overview_container);

// TAB 2: Host Information
$tabs->addTab('host', _('Host Info'), $host_div);

// TAB 3: Event Timeline
$timeline_div = new CDiv();
$timeline_div->addClass('timeline-tab');

$timeline_comparison_section = createMonthlyComparisonSection($monthly_comparison);
if ($timeline_comparison_section !== null) {
    $timeline_div->addItem($timeline_comparison_section);
}

// Use Zabbix's built-in function to create the event list
$allowed = [
    'add_comments' => CWebUser::checkAccess(CRoleHelper::ACTIONS_ADD_PROBLEM_COMMENTS),
    'change_severity' => CWebUser::checkAccess(CRoleHelper::ACTIONS_CHANGE_SEVERITY),
    'acknowledge' => CWebUser::checkAccess(CRoleHelper::ACTIONS_ACKNOWLEDGE_PROBLEMS),
    'suppress_problems' => CWebUser::checkAccess(CRoleHelper::ACTIONS_SUPPRESS_PROBLEMS),
    'close' => CWebUser::checkAccess(CRoleHelper::ACTIONS_CLOSE_PROBLEMS) &&
                     isset($trigger['manual_close']) && $trigger['manual_close'] == ZBX_TRIGGER_MANUAL_CLOSE_ALLOWED,
    'rank_change' => CWebUser::checkAccess(CRoleHelper::ACTIONS_CHANGE_PROBLEM_RANKING)
];

// Create the event list table using the native function
$timeline_table = make_small_eventlist($event, $allowed);

// Add header
$timeline_div->addItem(new CTag('h4', false, _('Recent Events')));
$timeline_div->addItem($timeline_table);
$tabs->addTab('timeline', _('Timeline'), $timeline_div);

// TAB 4: Time Patterns
$time_patterns_div = new CDiv();

// Calculate hourly distribution
$hourly_data = [];
for ($h = 0; $h < 24; $h++) {
    $hourly_data[$h] = 0;
}

foreach ($pattern_events as $rel_event) {
    $hour = date('G', $rel_event['clock']);
    $hourly_data[(int)$hour]++;
}

// Calculate weekly distribution
$weekdays = [_('Sunday'), _('Monday'), _('Tuesday'), _('Wednesday'), _('Thursday'), _('Friday'), _('Saturday')];
$weekly_data = [0, 0, 0, 0, 0, 0, 0];
$weekly_hourly_details = array_fill(0, 7, array_fill(0, 24, 0));
$monthly_data = [];
$month_keys = [];
$monthly_daily_details = [];

$current_time = isset($event['clock']) ? (int) $event['clock'] : time();
for ($i = 11; $i >= 0; $i--) {
    $ts = strtotime("-$i months", strtotime(date('Y-m-01 00:00:00', $current_time)));
    $mk = date('Y-m', $ts);
    $month_keys[] = $mk;
    $monthly_data[$mk] = 0;
    $monthly_daily_details[$mk] = array_fill(0, 31, 0);
}

// Generate hour labels following TimePattern module behavior.
$user_lang = (CWebUser::$data && isset(CWebUser::$data['lang'])) ? CWebUser::$data['lang'] : null;
$force_24h = in_array($user_lang, ['pt_BR', 'pt_PT']);
$use_12h = !$force_24h && (strpos(TIME_FORMAT, 'A') !== false || strpos(TIME_FORMAT, 'a') !== false);
$hour_labels = [];
for ($h = 0; $h < 24; $h++) {
    $ts_utc = gmmktime($h, 0, 0, 1, 1, 2024);
    $hour_labels[] = $use_12h
        ? zbx_date2str('g a', $ts_utc, 'UTC')
        : (zbx_date2str('H', $ts_utc, 'UTC') . _x('h', 'hour short'));
}

foreach ($pattern_events as $rel_event) {
    $weekday = date('w', $rel_event['clock']);
    $weekly_data[(int)$weekday]++;

    $clock = (int) $rel_event['clock'];
    $hour = (int) date('G', $clock);
    $mk = date('Y-m', $clock);
    $day = (int) date('j', $clock);
    $weekly_hourly_details[(int)$weekday][$hour]++;

    if (isset($monthly_data[$mk])) {
        $monthly_data[$mk]++;
    }

    if (isset($monthly_daily_details[$mk][$day - 1])) {
        $monthly_daily_details[$mk][$day - 1]++;
    }
}

$monthly_labels = [];
$monthly_values = [];
$monthly_drilldown = [];
foreach ($month_keys as $mk) {
    $ts = strtotime($mk . '-01');
    $monthly_labels[] = zbx_date2str('M/y', $ts);
    $monthly_values[] = $monthly_data[$mk] ?? 0;
    $monthly_drilldown[] = array_values($monthly_daily_details[$mk] ?? array_fill(0, 31, 0));
}

$event_clocks = array_column($pattern_events, 'clock');
// Use the same user/frontend timezone for PHP charts and interactive browser filters.
$event_slots = [];
foreach ($event_clocks as $clock) {
    $event_slots[(string) $clock] = [
        'hour' => (int) date('G', $clock),
        'weekday' => (int) date('w', $clock),
        'month' => date('Y-m', $clock),
        'day' => (int) date('j', $clock),
        'date' => date('Y-m-d', $clock)
    ];
}
$pattern_week_end = strtotime('monday this week 00:00:00', $current_time);
$pattern_week_start = strtotime('-1 week', $pattern_week_end);

// Create containers for D3.js charts
$patterns_container = new CDiv();
$patterns_container->addClass('patterns-d3-container');

// pattern Description
$description_container = new CDiv();
$description_container->addClass('pattern-chart-container');
$description_container->addItem(new CTag('h6', false, _('Overview  based on daily time and week day of the same problem. The time and day reference to the problem start date. Last 20 occurences are listed under tab Timeline')))->addStyle('margin-bottom:5px');

// Hourly pattern container
$hourly_container = new CDiv();
$hourly_container->addClass('pattern-chart-container mnz-pattern-collapsible');
$hourly_header = new CDiv([
    (new CTag('h4', false, _('Hourly Pattern')))->addClass('mnz-incident-chart-title'),
    (new CButton('mnz-hourly-toggle', _('Expand')))
        ->addClass('btn btn-alt mnz-pattern-toggle')
        ->setAttribute('data-target', 'mnz-hourly-pattern-body')
]);
$hourly_header->addClass('mnz-pattern-collapsible-header');
$hourly_chart = new CDiv();
$hourly_chart->setId('hourly-pattern-chart');
$hourly_chart->addClass('pattern-chart');
$hourly_body = (new CDiv($hourly_chart))
    ->setId('mnz-hourly-pattern-body')
    ->addClass('mnz-pattern-collapsible-body')
    ->addStyle('display:none;');
$hourly_container->addItem([$hourly_header, $hourly_body]);

// Weekly pattern container
$weekly_container = new CDiv();
$weekly_container->addClass('pattern-chart-container mnz-pattern-collapsible');
$weekly_header = new CDiv([
    (new CTag('h4', false, _('Weekly Pattern')))->addClass('mnz-incident-chart-title'),
    (new CButton('mnz-weekly-toggle', _('Expand')))
        ->addClass('btn btn-alt mnz-pattern-toggle')
        ->setAttribute('data-target', 'mnz-weekly-pattern-body')
]);
$weekly_header->addClass('mnz-pattern-collapsible-header');
$weekly_chart = new CDiv();
$weekly_chart->setId('weekly-pattern-chart');
$weekly_chart->addClass('pattern-chart');
$weekly_body = (new CDiv($weekly_chart))
    ->setId('mnz-weekly-pattern-body')
    ->addClass('mnz-pattern-collapsible-body')
    ->addStyle('display:none;');
$weekly_container->addItem([$weekly_header, $weekly_body]);

$filter_bar = (new CDiv())
    ->setId('mnz-investigation-filter-bar')
    ->addClass('mnz-investigation-filter-bar');

$heatmap_container = (new CDiv())
    ->setId('mnz-investigation-heatmap')
    ->addClass('mnz-heatmap-container');

$heatmap_section = (new CDiv([
    (new CTag('h4', false, _('Incident heatmap (day x hour)')))->addClass('mnz-incident-chart-title'),
    (new CDiv(_('Click a cell to filter all charts')))->addClass('mnz-drilldown-hint'),
    $heatmap_container
]))->addClass('mnz-incident-chart-container');

$monthly_chart = (new CDiv())
    ->setId('mnz-investigation-monthly-chart')
    ->addClass('mnz-incident-chart mnz-incident-chart-monthly');

$monthly_drilldown_container = (new CDiv())
    ->setId('mnz-monthly-drilldown')
    ->addClass('mnz-investigation-drilldown')
    ->addItem((new CDiv(_('Click a month to see which days had the most incidents')))
        ->addClass('mnz-drilldown-hint')
        ->addClass('mnz-drilldown-hint-monthly'));

$monthly_container = (new CDiv([
    (new CTag('h4', false, _('Last 12 months')))->addClass('mnz-incident-chart-title'),
    $monthly_chart,
    $monthly_drilldown_container
]))->addClass('mnz-incident-chart-container');

$patterns_container->addItem([
    $description_container,
    $filter_bar,
    $heatmap_section,
    $monthly_container,
    $hourly_container,
    $weekly_container
]);
$time_patterns_div->addItem($patterns_container);

$tabs->addTab('patterns', _('Time Patterns'), $time_patterns_div);


// TAB 5: Graphs - Fixed time period (1 hour before incident, and 12h before incident to now)
$graphs_div = new CDiv();

if ($items && isset($event['clock'])) {
    // --- Fixed time windows relative to the incident
    $event_ts    = (int)$event['clock'];
    $from_6h_ts  = $event_ts - 21600;         // 6 hours before incident

    // UI strings (respect user TZ + 24h settings)
    $from_6h_ui  = zbx_date2str(DATE_TIME_FORMAT_SECONDS, $from_6h_ts);
    $event_ui    = zbx_date2str(DATE_TIME_FORMAT_SECONDS, $event_ts);

    // chart.php expects absolute datetime strings (server-side parsing)
    $from_6h_param = date('Y-m-d H:i:s', $from_6h_ts);
    $event_param   = date('Y-m-d H:i:s', $event_ts);
    $to_now_param  = 'now';

    // Create charts container
    $charts_container = new CDiv();
    $charts_container->addClass('charts-container');

    // Period info: (1) 6h before → now, (2) incident → now
    $period_info = new CDiv(sprintf(
        '%s: %s → %s | %s: %s → %s',
        _('6h window'), $from_6h_ui, $event_ui,
        _('From incident'), $event_ui, _('now')
    ));
    $period_info->addClass('period-info');
    $charts_container->addItem($period_info);

    // Collect unique itemids and names
    $processed_items = [];
    $unique_itemids  = [];
    $item_names      = [];

    foreach ($items as $item) {
        if (!isset($processed_items[$item['itemid']])) {
            $processed_items[$item['itemid']] = true;
            $unique_itemids[] = $item['itemid'];
            $item_names[] = $item['name'];
        }
    }

    if (!empty($unique_itemids)) {
        // Helper: consolidated chart for a time range
        $build_consolidated_chart = static function (
            array $itemids,
            string $from_str,
            string $to_str,
            string $title_text,
            int $width = 800,
            int $height = 300
        ) {
            $chart_div = new CDiv();
            $chart_div->addClass('chart-item');

            $title = (new CTag('h5', false, $title_text))->addClass('chart-title');
            $chart_div->addItem($title);

            $base_params = [
                'from' => $from_str,   // e.g. '2025-09-11 16:38:57'
                'to'   => $to_str,     // e.g. '2025-09-11 17:38:57' or 'now'
                'type' => 0,
                'resolve_macros' => 1,
                'width' => $width,
                'height' => $height,
                'profileIdx' => 1,
                '_' => time() // cache buster
            ];

            $chart_url = 'chart.php?' . http_build_query($base_params);
            foreach ($itemids as $itemid) {
                $chart_url .= '&itemids[]=' . urlencode($itemid);
            }

            $img = new CTag('img', true);
            $img->setAttribute('src', $chart_url);
            $img->setAttribute('alt', $title_text);
            $img->setAttribute('title', $title_text);
            $img->addClass('chart-image');

            $chart_div->addItem($img);
            return $chart_div;
        };

        // Show item list if multiple items (bullet list)
        if (count($item_names) > 1) {
            $items_list = new CDiv();
            $items_list->addClass('chart-items-list');

            $ul = new CTag('ul', false);
            foreach ($item_names as $name) {
                $ul->addItem(new CTag('li', false, $name));
            }

            $items_list->addItem(new CTag('strong', false, _('Items') . ':'));
            $items_list->addItem($ul);

            $charts_container->addItem($items_list);
        }

        // 1) 6h before incident → now
        $title_6h = (count($item_names) === 1)
            ? $item_names[0] . ' (' . _('6h around incident') . ')'
            : _('Combined metrics') . ' (' . _('6h around incident') . ', ' . count($unique_itemids) . ' ' . _('items') . ')';

        $charts_container->addItem(
            $build_consolidated_chart($unique_itemids, $from_6h_param, $event_param, $title_6h, 800, 300)
        );

        // 2) From incident → now
        $title_inc_now = (count($item_names) === 1)
            ? $item_names[0] . ' (' . _('From incident to now') . ')'
            : _('Combined metrics') . ' (' . _('From incident to now') . ', ' . count($unique_itemids) . ' ' . _('items') . ')';

        $charts_container->addItem(
            $build_consolidated_chart($unique_itemids, $event_param, $to_now_param, $title_inc_now, 800, 300)
        );
    }

    $graphs_div->addItem($charts_container);

} elseif ($items && !isset($event['clock'])) {
    $graphs_div->addItem(new CDiv(_('Event timestamp not available for chart generation')));
} else {
    $graphs_div->addItem(new CDiv(_('No graph data available')));
}


$tabs->addTab('graphs', _('Graphs'), $graphs_div);


// TAB 6: Update (Action history)
$update_div = new CDiv();
$update_div->addClass('update-tab');

// The native collector includes all manual updates, recovery events and recovery alerts.
if (!empty($event['eventid'])) {
    $actions = getEventDetailsActions($event);
    $users = !empty($actions['userids']) ? API::User()->get([
        'output' => ['username', 'name', 'surname'],
        'userids' => array_keys($actions['userids']),
        'preservekeys' => true
    ]) : [];
    $mediatypes = !empty($actions['mediatypeids']) ? API::Mediatype()->get([
        'output' => ['name', 'maxattempts', 'type'],
        'mediatypeids' => array_keys($actions['mediatypeids']),
        'preservekeys' => true
    ]) : [];
    $update_div->addItem((new CSectionCollapsible(
        makeEventDetailsActionsTable($actions, $users, $mediatypes)
    ))->setHeader(new CTag('h4', true, _('Actions')))->setExpanded(true));
}

// Add the tab.
$tabs->addTab('update', _('Update'), $update_div);


// TAB 7: Services
$services_div = new CDiv();

// Loading indicator
$loading_div = new CDiv(_('Loading services...'));
$loading_div->addClass('services-loading');
$loading_div->addStyle('text-align: center; padding: 20px; font-style: italic;');
$services_div->addItem($loading_div);

// Container for services tree
$services_tree_container = new CDiv();
$services_tree_container->setAttribute('id', 'services-tree-container');
$services_tree_container->addStyle('min-height: 100px;');
$services_div->addItem($services_tree_container);

$tabs->addTab('services', _('Services'), $services_div);

$event_name = $event['name'] ?? 'Unknown Event';

// Add a unique ID to tabs for easier selection
$tabs->setAttribute('id', 'event-details-tabs');

$output = [
    'header' => _('Event Details') . ': ' . $event_name,
    'body' => (new CDiv())
        ->addClass('event-details-popup')
        ->addItem($tabs)
        ->toString(),
    'buttons' => null,
    'script_inline' => '
        (function() {
            // Data for D3.js charts
            var hourlyData = ' . json_encode(array_values($hourly_data)) . ';
            var weeklyData = ' . json_encode(array_values($weekly_data)) . ';
            var weekdayLabels = ' . json_encode($weekdays) . ';
            var hourLabels = ' . json_encode($hour_labels) . ';
            var timePatternData = {
                eventClocks: ' . json_encode($event_clocks) . ',
                eventSlots: ' . json_encode($event_slots) . ',
                lastWeekStart: ' . $pattern_week_start . ',
                lastWeekEnd: ' . $pattern_week_end . ',
                monthKeys: ' . json_encode($month_keys) . ',
                monthLabels: ' . json_encode($monthly_labels) . ',
                dayLabels: ' . json_encode(range(1, 31)) . ',
                weekLabels: ' . json_encode($weekdays) . ',
                hourLabels: ' . json_encode($hour_labels) . ',
                hintMonth: "' . addslashes(_('Click a month to see which days had the most incidents')) . '",
                hintDay: "' . addslashes(_('Click a day to see hourly distribution in heatmap')) . '",
                filteredBy: "' . addslashes(_('Filtered by')) . '",
                clearFilter: "' . addslashes(_('Clear filter')) . '",
                sameSlotLastWeek: "' . addslashes(_('Same slot last week')) . '",
                sameDayLastWeek: "' . addslashes(_('Same day last week')) . '",
                incidents: "' . addslashes(_('incidents')) . '"
            };

            // Event data for services loading
            window.currentEventData = ' . json_encode([
                'eventid' => $event['eventid'] ?? '',
                'hostname' => $host['name'] ?? $host['host'] ?? '',
                'hostid' => $host['hostid'] ?? '',
                'triggerid' => $trigger['triggerid'] ?? '',
                'problem_name' => $event['name'] ?? '',
                'severity' => $event['severity'] ?? 0
            ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';
            var serviceStatusLabels = ' . json_encode([
                ZBX_SEVERITY_OK => _('OK'),
                TRIGGER_SEVERITY_NOT_CLASSIFIED => CSeverityHelper::getName(TRIGGER_SEVERITY_NOT_CLASSIFIED),
                TRIGGER_SEVERITY_INFORMATION => CSeverityHelper::getName(TRIGGER_SEVERITY_INFORMATION),
                TRIGGER_SEVERITY_WARNING => CSeverityHelper::getName(TRIGGER_SEVERITY_WARNING),
                TRIGGER_SEVERITY_AVERAGE => CSeverityHelper::getName(TRIGGER_SEVERITY_AVERAGE),
                TRIGGER_SEVERITY_HIGH => CSeverityHelper::getName(TRIGGER_SEVERITY_HIGH),
                TRIGGER_SEVERITY_DISASTER => CSeverityHelper::getName(TRIGGER_SEVERITY_DISASTER)
            ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';


            // Function to create D3.js bar charts or fallback
            var createPatternCharts = function() {

                if (typeof d3 === "undefined") {
                    createCSSFallbackCharts();
                    return;
                }

                // Hourly pattern chart
                if (jQuery("#hourly-pattern-chart").length) {
                    createBarChart("#hourly-pattern-chart", hourlyData, hourLabels, "#0275b8");
                }

                // Weekly pattern chart
                if (jQuery("#weekly-pattern-chart").length) {

                    createBarChart("#weekly-pattern-chart", weeklyData, weekdayLabels, "#28a745");
                } else {

                }
            };

            // CSS Fallback for when D3.js is not available
            var createCSSFallbackCharts = function() {

                // Hourly chart fallback
                createCSSBarChart("#hourly-pattern-chart", hourlyData, hourLabels, "#0275b8");

                // Weekly chart fallback
                createCSSBarChart("#weekly-pattern-chart", weeklyData, weekdayLabels, "#28a745");
            };

            var createCSSBarChart = function(container, data, labels, color) {
                var $container = jQuery(container);
                if (!$container.length) return;

                $container.empty();

                // Define missing color variables
                var emptyBarColor = "#e0e0e0";
                var textColor = "#333333";
                var labelColor = "#666666";

                // Dark theme support
                if (document.body.getAttribute("theme") === "dark-theme") {
                    emptyBarColor = "#3a3a3a";
                    textColor = "#cccccc";
                    labelColor = "#999999";
                }

                var maxValue = Math.max.apply(Math, data);
                if (maxValue === 0) maxValue = 1;

                var chartHtml = "<div style=\"display: flex; align-items: end; height: 140px; gap: 2px; padding: 10px;\">";

                for (var i = 0; i < data.length; i++) {
                    var height = (data[i] / maxValue) * 110;
                    var barColor = data[i] > 0 ? color : emptyBarColor;

                    chartHtml += "<div style=\"display: flex; flex-direction: column; align-items: center; flex: 1;\">";
                    chartHtml += "<div style=\"font-size: 10px; margin-bottom: 2px; color: " + textColor + ";\">" + (data[i] > 0 ? data[i] : "") + "</div>";
                    chartHtml += "<div style=\"background: " + barColor + "; width: 100%; height: " + height + "px; min-height: 2px;\"></div>";
                    chartHtml += "<div style=\"font-size: 8px; margin-top: 5px; color: " + labelColor + "; white-space: nowrap; text-align: center;\">" + labels[i] + "</div>";
                    chartHtml += "</div>";
                }

                chartHtml += "</div>";
                $container.html(chartHtml);
            };

            var createBarChart = function(container, data, labels, color) {


                var $container = jQuery(container);
                if (!$container.length) {

                    return;
                }

                var containerEl = $container[0];
                if (!containerEl.offsetWidth) {

                    createCSSBarChart(container, data, labels, color);
                    return;
                }

                var margin = {top: 15, right: 25, bottom: 35, left: 35};
                var width = containerEl.offsetWidth - margin.left - margin.right;
                var height = 140 - margin.top - margin.bottom;

                if (width <= 0 || height <= 0) {

                    createCSSBarChart(container, data, labels, color);
                    return;
                }

                try {
                    // Clear previous chart
                    d3.select(container).selectAll("*").remove();

                    var svg = d3.select(container)
                        .append("svg")
                        .attr("width", width + margin.left + margin.right)
                        .attr("height", height + margin.top + margin.bottom)
                        .append("g")
                        .attr("transform", "translate(" + margin.left + "," + margin.top + ")");

                    var x = d3.scaleBand()
                        .range([0, width])
                        .domain(labels)
                        .padding(0.1);

                    var y = d3.scaleLinear()
                        .domain([0, d3.max(data) || 1])
                        .range([height, 0]);

                    // Add bars
                    svg.selectAll(".bar")
                        .data(data)
                        .enter().append("rect")
                        .attr("class", "bar")
                        .attr("x", function(d, i) { return x(labels[i]); })
                        .attr("width", x.bandwidth())
                        .attr("y", function(d) { return y(d); })
                        .attr("height", function(d) { return height - y(d); })
                        .attr("fill", color)
                        .attr("opacity", 0.8)
                        .on("mouseover", function(event, d) {
                            d3.select(this).attr("opacity", 1);
                        })
                        .on("mouseout", function(event, d) {
                            d3.select(this).attr("opacity", 0.8);
                        });

                    // Add labels on bars
                    svg.selectAll(".text")
                        .data(data)
                        .enter().append("text")
                        .attr("x", function(d, i) { return x(labels[i]) + x.bandwidth()/2; })
                        .attr("y", function(d) { return y(d) - 5; })
                        .attr("text-anchor", "middle")
                        .style("font-size", "10px")
                        .style("fill", "#333")
                        .text(function(d) { return d > 0 ? d : ""; });

                                    // Add X axis
                svg.append("g")
                    .attr("transform", "translate(0," + height + ")")
                    .call(d3.axisBottom(x))
                    .selectAll("text")
                    .style("font-size", "9px")
                    .style("text-anchor", "middle");

                    // Add Y axis
                    svg.append("g")
                        .call(d3.axisLeft(y).ticks(5))
                        .selectAll("text")
                        .style("font-size", "10px");



                } catch (error) {
                    console.error("Error creating D3 chart:", error);

                    createCSSBarChart(container, data, labels, color);
                }
            };

            var initAdvancedTimePatterns = function() {
                var d = timePatternData;
                if (!d || !jQuery("#mnz-investigation-heatmap").length || !jQuery("#mnz-investigation-monthly-chart").length) {
                    return;
                }

                var clocks = (d.eventClocks || []).map(function(t) { return parseInt(t, 10); }).filter(function(t) { return !isNaN(t); });
                var monthKeys = d.monthKeys || [];
                var isDark = document.body.getAttribute("theme") === "dark-theme" || document.documentElement.getAttribute("theme") === "dark-theme";
                var barBg = isDark ? "#3a3a3a" : "#e9ecef";
                var barFill = "#0275b8";
                var textColor = isDark ? "#e0e0e0" : "#333333";
                var labelColor = isDark ? "#999999" : "#666666";

                var currentFilter = null;
                var currentAgg = null;

                var computeAggregates = function(clockList) {
                    var hourly = [];
                    var weekly = [];
                    var weeklyHourly = [];
                    var monthly = {};
                    var monthlyDaily = {};

                    for (var h = 0; h < 24; h++) hourly[h] = 0;
                    for (var w = 0; w < 7; w++) {
                        weekly[w] = 0;
                        weeklyHourly[w] = [];
                        for (h = 0; h < 24; h++) weeklyHourly[w][h] = 0;
                    }

                    for (var m = 0; m < monthKeys.length; m++) {
                        monthly[monthKeys[m]] = 0;
                        monthlyDaily[monthKeys[m]] = [];
                        for (var day = 0; day < 31; day++) monthlyDaily[monthKeys[m]][day] = 0;
                    }

                    for (var i = 0; i < clockList.length; i++) {
                        var t = clockList[i];
                        var dt = d.eventSlots[t];
                        var hour = dt.hour;
                        var weekday = dt.weekday;
                        var ym = dt.month;
                        var dIdx = dt.day - 1;

                        hourly[hour]++;
                        weekly[weekday]++;
                        if (weeklyHourly[weekday]) weeklyHourly[weekday][hour]++;
                        if (monthly[ym] !== undefined) {
                            monthly[ym]++;
                            if (monthlyDaily[ym] && dIdx >= 0 && dIdx < 31) monthlyDaily[ym][dIdx]++;
                        }
                    }

                    var monthVals = [];
                    var monthDrill = [];
                    for (m = 0; m < monthKeys.length; m++) {
                        var mk = monthKeys[m];
                        monthVals.push(monthly[mk] || 0);
                        monthDrill.push(monthlyDaily[mk] ? monthlyDaily[mk].slice(0, 31) : []);
                    }

                    return {
                        hourly: hourly,
                        weekly: weekly,
                        monthly: monthVals,
                        weeklyHourlyDetails: weeklyHourly,
                        monthlyDailyDetails: monthDrill
                    };
                };

                var renderHeatmap = function(agg) {
                    var el = document.getElementById("mnz-investigation-heatmap");
                    if (!el) return;

                    var wh = agg.weeklyHourlyDetails || [];
                    var weekDisplayOrder = [1, 2, 3, 4, 5, 6, 0]; // Monday first, Sunday last
                    var maxVal = 0;
                    for (var w = 0; w < 7; w++) {
                        for (var h = 0; h < 24; h++) {
                            if (wh[w] && wh[w][h] > maxVal) maxVal = wh[w][h];
                        }
                    }
                    if (maxVal === 0) maxVal = 1;

                    var html = "<div class=\"mnz-heatmap-grid\"><div class=\"mnz-heatmap-labels-col\"><div class=\"mnz-heatmap-corner\"></div>";
                    for (var wi = 0; wi < 7; wi++) {
                        var weekdayIdx = weekDisplayOrder[wi];
                        html += "<div class=\"mnz-heatmap-row-label\">" + (d.weekLabels[weekdayIdx] || "") + "</div>";
                    }
                    html += "</div><div class=\"mnz-heatmap-body\"><div class=\"mnz-heatmap-hours-row\">";
                    for (h = 0; h < 24; h++) html += "<div class=\"mnz-heatmap-hour-label\">" + (d.hourLabels[h] || h) + "</div>";
                    html += "</div>";

                    for (wi = 0; wi < 7; wi++) {
                        var rowWeekday = weekDisplayOrder[wi];
                        html += "<div class=\"mnz-heatmap-row\">";
                        for (h = 0; h < 24; h++) {
                            var v = (wh[rowWeekday] && wh[rowWeekday][h]) || 0;
                            var intensity = v / maxVal;
                            var color = intensity > 0 ? (intensity > 0.5 ? (intensity > 0.8 ? "#c0392b" : "#e67e22") : "#27ae60") : barBg;
                            var cls = v > 0 ? " mnz-heatmap-cell-active" : "";
                            html += "<div class=\"mnz-heatmap-cell" + cls + "\" data-w=\"" + rowWeekday + "\" data-h=\"" + h + "\" style=\"background:" + color + "\" title=\"" + (d.weekLabels[rowWeekday] || "") + " " + (d.hourLabels[h] || h) + ": " + v + "\">" + v + "</div>";
                        }
                        html += "</div>";
                    }

                    html += "</div></div>";
                    el.innerHTML = html;

                    jQuery("#mnz-investigation-heatmap").off("click", ".mnz-heatmap-cell-active").on("click", ".mnz-heatmap-cell-active", function() {
                        var center = jQuery(this);
                        var weekday = parseInt(center.data("w"), 10);
                        var hour = parseInt(center.data("h"), 10);
                        var filtered = clocks.filter(function(t) {
                            var dt = d.eventSlots[t];
                            return dt.weekday === weekday && dt.hour === hour;
                        });
                        currentFilter = {type: "heatmap", weekday: weekday, hour: hour};
                        currentAgg = computeAggregates(filtered);
                        applyAndRender();
                    });
                };

                var renderMonthly = function(elId, data, labels) {
                    var el = document.getElementById(elId);
                    if (!el) return;
                    var max = Math.max.apply(Math, data);
                    if (max === 0) max = 1;

                    var html = "<div class=\"mnz-investigation-monthly-bars\">";
                    for (var i = 0; i < data.length; i++) {
                        var h = (data[i] / max) * 100;
                        var col = data[i] > 0 ? (i === data.length - 1 ? "#ff9800" : barFill) : barBg;
                        var cls = data[i] > 0 ? " mnz-bar-clickable" : "";

                        html += "<div class=\"mnz-investigation-monthly-item" + cls + "\" data-index=\"" + i + "\" data-type=\"monthly\" title=\"" + labels[i] + ": " + data[i] + "\">";
                        html += "<div style=\"font-size:11px;font-weight:bold;color:" + textColor + "\">" + data[i] + "</div>";
                        html += "<div class=\"mnz-investigation-monthly-bar\" style=\"height:" + Math.max(h, 4) + "px;background:" + col + "\"></div>";
                        html += "<span class=\"mnz-investigation-monthly-label\" style=\"color:" + labelColor + "\">" + labels[i] + "</span>";
                        html += "</div>";
                    }
                    html += "</div>";
                    el.innerHTML = html;
                };

                var renderDrilldown = function(containerId, data, labels, title, barColor) {
                    var c = document.getElementById(containerId);
                    if (!c) return;
                    var max = Math.max.apply(Math, data);
                    if (max === 0) max = 1;

                    var html = "<div class=\"mnz-drilldown-content\"><div class=\"mnz-drilldown-title\">" + title + "</div><div class=\"mnz-drilldown-subhint\">" + (d.hintDay || "") + "</div><div class=\"mnz-drilldown-chart\"><div class=\"mnz-investigation-bars\">";
                    for (var i = 0; i < data.length; i++) {
                        var barH = (data[i] / max) * 80;
                        var day = i + 1;
                        var cls = data[i] > 0 ? " mnz-drilldown-day-bar mnz-bar-clickable" : "";
                        var dayData = data[i] > 0 ? " data-day=\"" + day + "\"" : "";
                        html += "<div class=\"mnz-investigation-bar-item mnz-drilldown-bar-item" + cls + "\"" + dayData + " title=\"" + labels[i] + ": " + data[i] + "\">";
                        html += "<span class=\"mnz-drilldown-bar-value\">" + data[i] + "</span>";
                        html += "<div class=\"mnz-investigation-bar\" style=\"height:" + Math.max(barH, 4) + "px;background:" + (data[i] > 0 ? barColor : barBg) + "\"></div>";
                        html += "<span class=\"mnz-investigation-bar-label\">" + labels[i] + "</span>";
                        html += "</div>";
                    }
                    html += "</div></div><button type=\"button\" class=\"btn btn-alt mnz-drilldown-close\">Close</button></div>";
                    c.innerHTML = html;
                    c.classList.add("mnz-drilldown-visible");
                };

                var countSameSlotLastWeek = function(weekday, hour) {
                    var lastWeekEnd = d.lastWeekEnd;
                    var lastWeekStart = d.lastWeekStart;
                    var n = 0;

                    for (var i = 0; i < clocks.length; i++) {
                        var t = clocks[i];
                        if (t >= lastWeekStart && t < lastWeekEnd) {
                            var dt = d.eventSlots[t];
                            if (dt.weekday === weekday && dt.hour === hour) n++;
                        }
                    }

                    return n;
                };

                var countSameDayLastWeek = function(weekday) {
                    var lastWeekEnd = d.lastWeekEnd;
                    var lastWeekStart = d.lastWeekStart;
                    var n = 0;

                    for (var i = 0; i < clocks.length; i++) {
                        var t = clocks[i];
                        if (t >= lastWeekStart && t < lastWeekEnd) {
                            var dt = d.eventSlots[t];
                            if (dt.weekday === weekday) n++;
                        }
                    }

                    return n;
                };

                var updateFilterBar = function() {
                    var fb = document.getElementById("mnz-investigation-filter-bar");
                    if (!fb) return;

                    if (!currentFilter) {
                        fb.innerHTML = "";
                        fb.classList.remove("mnz-filter-active");
                        return;
                    }

                    var label = "";
                    var compHtml = "";
                    if (currentFilter.type === "month") {
                        label = (d.monthLabels || [])[currentFilter.value] || "";
                    }
                    else if (currentFilter.type === "day") {
                        label = (currentFilter.dateStr || "") + " (" + ((d.weekLabels || [])[currentFilter.weekday] || "") + ")";
                        var lastWeek = countSameDayLastWeek(currentFilter.weekday);
                        compHtml = " <span class=\"mnz-filter-comparison\">" + (d.sameDayLastWeek || "") + ": " + lastWeek + " " + (d.incidents || "incidents") + "</span>";
                    }
                    else if (currentFilter.type === "heatmap") {
                        label = (d.weekLabels || [])[currentFilter.weekday] + " " + ((d.hourLabels || [])[currentFilter.hour] || (currentFilter.hour + "h"));
                        var slotWeek = countSameSlotLastWeek(currentFilter.weekday, currentFilter.hour);
                        compHtml = " <span class=\"mnz-filter-comparison\">" + (d.sameSlotLastWeek || "") + ": " + slotWeek + " " + (d.incidents || "incidents") + "</span>";
                    }

                    fb.classList.add("mnz-filter-active");
                    fb.innerHTML = "<span class=\"mnz-filter-label\">" + (d.filteredBy || "Filtered by") + ": " + label + "</span>" + compHtml + " <button type=\"button\" class=\"btn btn-alt mnz-filter-clear\">" + (d.clearFilter || "Clear filter") + "</button>";

                    jQuery("#mnz-investigation-filter-bar .mnz-filter-clear").off("click").on("click", function() {
                        currentFilter = null;
                        currentAgg = computeAggregates(clocks);
                        var m = jQuery("#mnz-monthly-drilldown");
                        m.removeClass("mnz-drilldown-visible").empty().append("<div class=\"mnz-drilldown-hint mnz-drilldown-hint-monthly\">" + (d.hintMonth || "") + "</div>");
                        applyAndRender();
                    });
                };

                var applyAndRender = function() {
                    currentAgg = currentAgg || computeAggregates(clocks);

                    createCSSBarChart("#hourly-pattern-chart", currentAgg.hourly, d.hourLabels || hourLabels, "#0275b8");
                    createCSSBarChart("#weekly-pattern-chart", currentAgg.weekly, d.weekLabels || weekdayLabels, "#28a745");
                    renderHeatmap(currentAgg);
                    renderMonthly("mnz-investigation-monthly-chart", currentAgg.monthly, d.monthLabels || []);

                    var dayLabels = (d.dayLabels && d.dayLabels.length) ? d.dayLabels.slice(0) : [];
                    while (dayLabels.length < 31) dayLabels.push(dayLabels.length + 1);

                    jQuery("#mnz-investigation-monthly-chart").off("click", ".mnz-bar-clickable").on("click", ".mnz-bar-clickable", function() {
                        var idx = parseInt(jQuery(this).data("index"), 10);
                        var mk = monthKeys[idx];
                        if (!mk) return;

                        var filtered = clocks.filter(function(t) {
                            var dt = d.eventSlots[t];
                            return dt.month === mk;
                        });
                        currentFilter = {type: "month", value: idx};
                        currentAgg = computeAggregates(filtered);
                        applyAndRender();

                        var dd = currentAgg.monthlyDailyDetails[idx];
                        if (dd) {
                            renderDrilldown("mnz-monthly-drilldown", dd, dayLabels, "Daily distribution - " + (d.monthLabels[idx] || ""), barFill);
                            jQuery("#mnz-monthly-drilldown .mnz-drilldown-close").off("click").on("click", function() {
                                var c = jQuery("#mnz-monthly-drilldown");
                                c.removeClass("mnz-drilldown-visible").empty().append("<div class=\"mnz-drilldown-hint mnz-drilldown-hint-monthly\">" + (d.hintMonth || "") + "</div>");
                            });
                        }

                        jQuery("#mnz-monthly-drilldown").off("click", ".mnz-drilldown-day-bar").on("click", ".mnz-drilldown-day-bar", function() {
                            var day = parseInt(jQuery(this).data("day"), 10);
                            if (!day) return;

                            var dateStr = mk + "-" + String(day).padStart(2, "0");
                            var dayFiltered = clocks.filter(function(t) {
                                var dt = d.eventSlots[t];
                                var ds = dt.date;
                                return ds === dateStr;
                            });

                            var parts = dateStr.split("-");
                            var sampleDate = new Date(Date.UTC(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10)));
                            var weekday = sampleDate.getUTCDay();
                            var hourly = [];
                            var wh = [];
                            for (var h = 0; h < 24; h++) hourly[h] = 0;
                            for (var w = 0; w < 7; w++) {
                                wh[w] = [];
                                for (h = 0; h < 24; h++) wh[w][h] = 0;
                            }
                            for (var cIdx = 0; cIdx < dayFiltered.length; cIdx++) {
                                var dtf = d.eventSlots[dayFiltered[cIdx]];
                                hourly[dtf.hour]++;
                            }
                            for (h = 0; h < 24; h++) wh[weekday][h] = hourly[h];

                            currentFilter = {type: "day", monthIdx: idx, day: day, weekday: weekday, dateStr: dateStr};
                            currentAgg = {
                                hourly: hourly,
                                weekly: [0, 0, 0, 0, 0, 0, 0],
                                monthly: currentAgg.monthly,
                                weeklyHourlyDetails: wh,
                                monthlyDailyDetails: currentAgg.monthlyDailyDetails
                            };

                            createCSSBarChart("#hourly-pattern-chart", currentAgg.hourly, d.hourLabels || hourLabels, "#0275b8");
                            renderHeatmap(currentAgg);
                            updateFilterBar();
                            jQuery("html, body").animate({scrollTop: jQuery("#mnz-investigation-heatmap").offset().top - 80}, 300);
                        });
                    });

                    updateFilterBar();
                };

                currentAgg = computeAggregates(clocks);
                applyAndRender();
            };

            var initPatternCollapsibles = function() {
                var expandText = "' . addslashes(_('Expand')) . '";
                var collapseText = "' . addslashes(_('Collapse')) . '";

                jQuery(".mnz-pattern-toggle").off("click").on("click", function(e) {
                    e.preventDefault();
                    var $btn = jQuery(this);
                    var targetId = $btn.data("target");
                    var $body = jQuery("#" + targetId);
                    if (!$body.length) {
                        return;
                    }

                    var isVisible = $body.is(":visible");
                    if (isVisible) {
                        $body.slideUp(120);
                        $btn.text(expandText);
                    } else {
                        $body.slideDown(120, function() {
                            createPatternCharts();
                            initAdvancedTimePatterns();
                        });
                        $btn.text(collapseText);
                    }
                });
            };

            // Wait for DOM and jQuery UI to be ready
            var initTabs = function() {
                var $tabs = jQuery("#event-details-tabs");
                if ($tabs.length > 0) {
                    // Check if jQuery UI tabs is available
                    if (typeof jQuery.fn.tabs === "function") {
                        try {
                            $tabs.tabs({
                                activate: function(event, ui) {
                                    // Recreate D3 charts when patterns tab is activated
                                    if (ui.newPanel.attr("id") === "ui-id-2") {
                                        setTimeout(createPatternCharts, 100);
                                        setTimeout(initAdvancedTimePatterns, 120);
                                    }

                                }
                            });
                        } catch(e) {
                            console.error("Error initializing tabs with jQuery UI:", e);
                            // Fallback to manual implementation
                            setupManualTabs();
                        }
                    } else {
                        // jQuery UI tabs not available, use manual implementation
                        setupManualTabs();
                    }
                }

                // Create D3 charts initially and when patterns tab becomes visible
                setTimeout(function() {
                    createPatternCharts();
                    initAdvancedTimePatterns();
                    initPatternCollapsibles();
                    // Also try to create when patterns tab becomes visible
                    jQuery("#event-details-tabs").on("tabsactivate", function(event, ui) {
                        if (ui.newPanel.find("#hourly-pattern-chart").length > 0) {
                            setTimeout(createPatternCharts, 50);
                            setTimeout(initAdvancedTimePatterns, 80);
                            setTimeout(initPatternCollapsibles, 90);
                        }
                        // Load services when services tab is activated
                        if (ui.newPanel.find("#services-tree-container").length > 0) {
                            setTimeout(function() {
                                if (window.loadImpactedServices) {
                                    window.loadImpactedServices();
                                }
                            }, 100);
                        }
                    });
                }, 300);
            };

            var setupManualTabs = function() {

                var $container = jQuery("#event-details-tabs");
                var $navLinks = $container.find(".ui-tabs-nav a");
                var $panels = $container.find(".ui-tabs-panel");



                // Remove any existing active classes first
                $panels.removeClass("active-panel ui-tabs-panel-active");
                $navLinks.parent().removeClass("ui-tabs-active ui-state-active");

                // Force hide all panels first using multiple methods
                $panels.each(function() {
                    jQuery(this).hide();
                    jQuery(this).css("display", "none");
                    jQuery(this).removeClass("active-panel ui-tabs-panel-active");
                });

                // Show and mark first panel as active
                var $firstPanel = $panels.first();
                $firstPanel.addClass("active-panel");
                $firstPanel.css("display", "block");
                $firstPanel.show();
                $navLinks.first().parent().addClass("ui-tabs-active ui-state-active");

                // Handle tab clicks
                $navLinks.off("click.tabs").on("click.tabs", function(e) {
                    e.preventDefault();
                    e.stopPropagation();

                    var $link = jQuery(this);
                    var target = $link.attr("href");


                    // Update active states on nav
                    $navLinks.parent().removeClass("ui-tabs-active ui-state-active");
                    $link.parent().addClass("ui-tabs-active ui-state-active");

                    // Hide all panels forcefully
                    $panels.each(function() {
                        var $panel = jQuery(this);
                        $panel.removeClass("active-panel ui-tabs-panel-active");
                        $panel.hide();
                        $panel.css("display", "none");
                    });

                    // Show target panel and add active class
                    if (target && target.startsWith("#")) {
                        var $targetPanel = jQuery(target);
                        if ($targetPanel.length) {
                            $targetPanel.addClass("active-panel");
                            $targetPanel.css("display", "block");
                            $targetPanel.show();


                            // Recreate D3 charts when patterns tab is shown
                            if (target.includes("patterns") || $link.text().includes("Pattern") ||
                                $targetPanel.find("#hourly-pattern-chart").length > 0) {

                                setTimeout(createPatternCharts, 50);
                                setTimeout(createPatternCharts, 200);
                                setTimeout(createPatternCharts, 500);
                                setTimeout(initAdvancedTimePatterns, 100);
                                setTimeout(initAdvancedTimePatterns, 300);
                                setTimeout(initPatternCollapsibles, 110);
                            }

                            // Load services when services tab is shown
                            if (target.includes("services") || $link.text().includes("Services") ||
                                $targetPanel.find("#services-tree-container").length > 0) {

                                setTimeout(function() {
                                    if (window.loadImpactedServices) {
                                        window.loadImpactedServices();
                                    }
                                }, 100);
                            }


                            // Double-check other panels are hidden
                            $panels.not($targetPanel).each(function() {
                                jQuery(this).hide();
                                jQuery(this).css("display", "none");
                            });
                        }
                    }

                    return false;
                });

                // Also bind to parent li for better clickability
                $container.find(".ui-tabs-nav li").off("click.tabs").on("click.tabs", function(e) {
                    if (!jQuery(e.target).is("a")) {
                        e.preventDefault();
                        jQuery(this).find("a").trigger("click.tabs");
                    }
                });

                // Debug info



            };

            // Try multiple initialization approaches
            if (typeof jQuery !== "undefined") {
                // Try immediate execution
                initTabs();

                // Also try on document ready
                jQuery(document).ready(function() {
                    setTimeout(initTabs, 100);
                    // Additional attempts to create charts
                    setTimeout(createPatternCharts, 500);
                    setTimeout(createPatternCharts, 1000);
                    setTimeout(createPatternCharts, 2000);
                    setTimeout(initAdvancedTimePatterns, 700);
                    setTimeout(initAdvancedTimePatterns, 1200);
                    setTimeout(initPatternCollapsibles, 400);
                });

                // And on window load as last resort
                jQuery(window).on("load", function() {
                    setTimeout(initTabs, 300);
                    setTimeout(createPatternCharts, 800);
                    setTimeout(createPatternCharts, 1500);
                    setTimeout(initAdvancedTimePatterns, 900);
                    setTimeout(initPatternCollapsibles, 500);
                });

                // Watch for tab visibility changes
                jQuery(document).on("click", "a[href*=patterns]", function() {

                    setTimeout(createPatternCharts, 100);
                    setTimeout(createPatternCharts, 300);
                    setTimeout(initAdvancedTimePatterns, 140);
                    setTimeout(initPatternCollapsibles, 160);
                });

            } else {
                // Fallback if jQuery not ready
                setTimeout(initTabs, 500);
                setTimeout(createPatternCharts, 1000);
                setTimeout(initAdvancedTimePatterns, 1200);
                setTimeout(initPatternCollapsibles, 1300);
                setTimeout(function() {
                    if (window.loadImpactedServices) {
                        window.loadImpactedServices();
                    }
                }, 1500);
            }

            // Load services automatically when popup opens
            setTimeout(function() {
                if (window.loadImpactedServices && window.currentEventData) {

                    window.loadImpactedServices();
                }
            }, 2000);

            // ==================== SERVICES MANAGEMENT ====================
            // Services management functions for AnalistProblem module

            /**
             * Load impacted services for the current event
             */
            async function loadImpactedServices() {
                try {

                    const loadingElement = document.querySelector(".services-loading");
                    const treeContainer = document.querySelector("#services-tree-container");

                    if (loadingElement) loadingElement.style.display = "block";
                    if (treeContainer) treeContainer.innerHTML = "";

                    // Get current event data from global variable
                    if (!window.currentEventData) {
                        console.error("No event data available");
                        displayServicesError("Event data not available");
                        return;
                    }

                    const eventData = window.currentEventData;


                    // Fetch services related to this host/trigger
                    const services = await fetchRelatedServices(eventData);

                    if (services && services.length > 0) {

                        displayServicesTree(services);
                    } else {

                        displayNoServicesMessage();
                    }

                } catch (error) {
                    console.error("Error loading impacted services:", error);
                    displayServicesError(error.message || String(error));
                } finally {
                    const loadingElement = document.querySelector(".services-loading");
                    if (loadingElement) loadingElement.style.display = "none";
                }
            }

            /**
             * Fetch services related to this event using AnalistProblem API
             */
            function escapeServiceHtml(value) {
                return String(value ?? "").replace(/&/g, "&amp;").replace(/</g, "&lt;")
                    .replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/\\x27/g, "&#39;");
            }

            async function requestServices(parameters) {
                const response = await fetch("zabbix.php?action=problemanalist.service.get", {
                    method: "POST", body: new URLSearchParams(parameters)
                });
                if (!response.ok) throw new Error("HTTP error! status: " + response.status);
                const result = await response.json();
                if (!result || !result.success || result.data === undefined) {
                    throw new Error(result?.error?.message || "Unable to load services");
                }
                return result.data;
            }

            async function fetchRelatedServices(eventData) {
                if (!eventData.eventid) throw new Error("A problem event is required");
                const services = await requestServices({eventid: eventData.eventid});
                if (!Array.isArray(services)) throw new Error("Invalid services response");
                return services;
            }

            function displayServicesTree(services) {
                const treeContainer = document.querySelector("#services-tree-container");
                if (!treeContainer) return;
                const impactedService = services[0];
                const path = impactedService.hierarchy_path?.length
                    ? impactedService.hierarchy_path : [impactedService];
                let html = \'<div class="services-container"><div class="services-summary-header"><h4>Impacted Services (\'
                    + services.length + \')</h4><p>Services matching this event’s tags</p></div>\'
                    + createHierarchyPath(path) + createServiceCard(impactedService, true);
                if (services.length > 1) {
                    html += \'<div><h5>Other Impacted Services</h5>\'
                        + services.slice(1).map(service => createServiceCard(service, true)).join("") + \'</div>\';
                }
                treeContainer.innerHTML = html + \'</div>\';
                treeContainer.onclick = event => {
                    const action = event.target.closest("[data-service-action]");
                    if (action && treeContainer.contains(action)) selectService(action.dataset.serviceAction);
                };
                window.currentServices = services;
                window.currentServiceId = impactedService.serviceid;
            }

            function createHierarchyPath(path) {
                return \'<div class="services-hierarchy-path"><span class="hierarchy-label">Hierarchy:</span>\'
                    + path.map(service => \'<button type="button" class="btn-link hierarchy-service" data-service-action="\'
                        + escapeServiceHtml(service.serviceid) + \'">\' + escapeServiceHtml(service.name) + \'</button>\')
                        .join(\'<span class="hierarchy-arrow"> → </span>\') + \'</div>\';
            }

            function createServiceSlaInfo(service) {
                if (!service.has_sla || service.sli === null || service.sli === undefined) {
                    return \'<div class="service-no-sla">No SLA configured</div>\';
                }
                const sli = Number(service.sli);
                const rows = [
                    ["SLI", Number.isFinite(sli) && sli >= 0 ? sli.toFixed(2) + "%" : "N/A"],
                    ["Uptime", service.uptime || "N/A"],
                    ["Downtime", service.downtime || "N/A"],
                    ["Error Budget", service.error_budget || "N/A"]
                ];
                return \'<div class="service-sla-info">\' + rows.map(row =>
                    \'<div class="sla-item"><span class="sla-label">\' + row[0]
                    + \':</span><span class="sla-value">\' + escapeServiceHtml(row[1]) + \'</span></div>\'
                ).join("") + \'</div>\';
            }

            function createServiceCard(service, isImpacted = false) {
                const id = escapeServiceHtml(service.serviceid);
                return \'<div class="service-card\' + (isImpacted ? \' service-card-impacted\' : \'\')
                    + \'" data-serviceid="\' + id + \'"><div class="service-header"><div class="service-name">\'
                    + \'<button type="button" class="btn-link service-name-link" data-service-action="\' + id + \'">\'
                    + escapeServiceHtml(service.name) + \'</button>\'
                    + (isImpacted ? \'<span class="impacted-badge">Impacted</span>\' : \'\')
                    + \'</div><div class="service-status \' + getServiceStatusClass(service.status) + \'">\'
                    + escapeServiceHtml(getServiceStatusText(service.status)) + \'</div></div>\'
                    + createServiceSlaInfo(service) + \'</div>\';
            }

            function getServiceStatusClass(status) {
                const classes = {"-1": "status-ok", 0: "status-not-classified", 1: "status-information",
                    2: "status-warning", 3: "status-average", 4: "status-high", 5: "status-disaster"};
                return classes[status] || "status-unknown";
            }

            function getServiceStatusText(status) {
                return serviceStatusLabels[status] || "Unknown";
            }

            function findServiceCard(serviceid) {
                const cards = document.querySelectorAll("#services-tree-container .service-card[data-serviceid]");
                return Array.from(cards).find(card => card.dataset.serviceid === String(serviceid));
            }

            async function loadServiceSLI(serviceid) {
                try {
                    const service = await requestServices({serviceid});
                    updateServiceSLIDisplay(serviceid, service);
                } catch (error) {
                    console.error("Error loading SLI data:", error);
                }
            }

            function updateServiceSLIDisplay(serviceid, service) {
                const card = findServiceCard(serviceid);
                if (!card) return;
                const status = card.querySelector(".service-status");
                status.className = "service-status " + getServiceStatusClass(service.status);
                status.textContent = getServiceStatusText(service.status);
                const sla = card.querySelector(".service-sla-info, .service-no-sla");
                if (sla) sla.outerHTML = createServiceSlaInfo(service);
            }

            function selectService(serviceid) {
                const tree = document.querySelector("#services-tree-container");
                if (!tree) return;
                tree.querySelectorAll(".service-card").forEach(card => {
                    card.style.borderColor = "";
                    card.style.boxShadow = "";
                });
                let selectedCard = findServiceCard(serviceid);
                if (!selectedCard) {
                    const parent = (window.currentServices || []).flatMap(service => service.hierarchy_path || [])
                        .find(service => String(service.serviceid) === String(serviceid));
                    if (parent) {
                        tree.insertAdjacentHTML("beforeend", createServiceCard(parent));
                        selectedCard = findServiceCard(serviceid);
                    }
                }
                if (selectedCard) {
                    selectedCard.style.borderColor = "#007cba";
                    selectedCard.style.boxShadow = "0 0 10px rgba(0,124,186,0.3)";
                    selectedCard.scrollIntoView({behavior: "smooth", block: "nearest"});
                    loadServiceSLI(serviceid);
                }
                window.currentServiceId = serviceid;
            }

            function displayNoServicesMessage() {
                const tree = document.querySelector("#services-tree-container");
                if (!tree) return;
                tree.innerHTML = \'<div class="services-no-results"><p><strong>No impacted services found for this event.</strong></p>\'
                    + \'<p>No accessible service has problem-tag rules matching all required event tags.</p></div>\';
            }

            function displayServicesError(message) {
                const tree = document.querySelector("#services-tree-container");
                if (!tree) return;
                tree.innerHTML = \'<div class="services-no-results"><p>Error loading services: \'
                    + escapeServiceHtml(message) + \'</p></div>\';
            }

            window.loadImpactedServices = loadImpactedServices;
            window.loadServiceSLI = loadServiceSLI;
            window.selectService = selectService;


        })();
    '
];

/**
 * AnalistHost helper functions for Host Info tab - adapted from ProblemKanban
 */

function makeAnalistHostSectionsHeader(array $host): CDiv {
    $host_status = '';
    $maintenance_status = '';
    $problems_indicator = '';

    if ($host['status'] == HOST_STATUS_MONITORED) {
        if ($host['maintenance_status'] == HOST_MAINTENANCE_STATUS_ON) {
            $maintenance_status = makeMaintenanceIcon($host['maintenance_type'], $host['maintenance']['name'],
                $host['maintenance']['description']
            );
        }

        $problems = [];

        if (isset($host['problem_count'])) {
            foreach ($host['problem_count'] as $severity => $count) {
                if ($count > 0) {
                    $problems[] = (new CSpan($count))
                        ->addClass(ZBX_STYLE_PROBLEM_ICON_LIST_ITEM)
                        ->addClass(CSeverityHelper::getStatusStyle($severity))
                        ->setTitle(CSeverityHelper::getName($severity));
                }
            }
        }

        if ($problems) {
            $problems_indicator = CWebUser::checkAccess(CRoleHelper::UI_MONITORING_PROBLEMS)
                ? new CLink(null,
                    (new CUrl('zabbix.php'))
                        ->setArgument('action', 'problem.view')
                        ->setArgument('hostids', [$host['hostid']])
                        ->setArgument('filter_set', '1')
                )
                : new CSpan();

            $problems_indicator
                ->addClass(ZBX_STYLE_PROBLEM_ICON_LINK)
                ->addItem($problems);
        }
    }
    else {
        $host_status = (new CDiv(_('Disabled')))->addClass(ZBX_STYLE_COLOR_NEGATIVE);
    }

    return (new CDiv([
        (new CDiv([
            (new CDiv([
                (new CLinkAction($host['name']))
                    ->setTitle($host['name'])
                    ->setMenuPopup(CMenuPopupHelper::getHost($host['hostid'])),
                $host_status,
                $maintenance_status
            ]))->addClass('host-name-container'),
            $problems_indicator ? (new CDiv($problems_indicator))->addClass('problems-container') : null
        ]))->addClass('host-header-main')
    ]))->addClass('analisthost-sections-header');
}

function makeAnalistHostSectionHostGroups(array $host_groups): CDiv {
    $groups = [];

    $i = 0;
    $group_count = count($host_groups);

    foreach ($host_groups as $group) {
        $groups[] = (new CSpan([
            (new CSpan($group['name']))
                ->addClass('host-group-name')
                ->setTitle($group['name']),
            ++$i < $group_count ? (new CSpan(''))->addClass('delimiter') : null
        ]))->addClass('host-group');
    }

   # if ($groups) {
   #     $groups[] = (new CLink(new CIcon('zi-more')))
   #         ->addClass(ZBX_STYLE_LINK_ALT)
   #         ->setHint(implode(', ', array_column($host_groups, 'name')), ZBX_STYLE_HINTBOX_WRAP);
   # }

    return (new CDiv([
        (new CDiv(_('Host groups')))->addClass('analisthost-section-name'),
        (new CDiv($groups))
            ->addClass('analisthost-section-body')
            ->addClass('host-groups')
    ]))
        ->addClass('analisthost-section')
        ->addClass('section-host-groups');
}

function makeAnalistHostSectionDescription(string $description): CDiv {
    return (new CDiv([
        (new CDiv(_('Description')))->addClass('analisthost-section-name'),
        (new CTag('pre', true))
        ->addClass(ZBX_STYLE_LINE_CLAMP)
        ->addClass('analisthost-section-body')
        ->addStyle('font-family: inherit;')
        ->addItem($description)
    ]))
        ->addClass('analisthost-section')
        ->addClass('section-description')
        ->addStyle('max-width: 100%; overflow: hidden;');
}

function makeAnalistHostSectionMonitoring(string $hostid, int $dashboard_count, int $item_count, int $graph_count,
        int $web_scenario_count, int $problem_total = 0): CDiv {
    $can_view_monitoring_hosts = CWebUser::checkAccess(CRoleHelper::UI_MONITORING_HOSTS);
    $can_view_problems = CWebUser::checkAccess(CRoleHelper::UI_MONITORING_PROBLEMS);
    $can_configure_hosts = CWebUser::checkAccess(CRoleHelper::UI_CONFIGURATION_HOSTS);
    $actions = [];

    $make_action = static function ($label, $url, ?int $count = null, string $title = '') {
        $content = [$label];

        if ($count !== null) {
            $content[] = (new CSpan($count))
                ->addClass(ZBX_STYLE_ENTITY_COUNT)
                ->addClass('monitoring-action-count')
                ->setTitle($count);
        }

        $action = $url !== null
            ? new CLink($content, $url)
            : new CSpan($content);

        $action->addClass('monitoring-action');

        if ($title !== '') {
            $action->setTitle($title);
        }

        return $action;
    };

    if ($can_view_problems && $problem_total > 0) {
        $actions[] = $make_action(_('Problems'),
            (new CUrl('zabbix.php'))
                ->setArgument('action', 'problem.view')
                ->setArgument('hostids', [$hostid])
                ->setArgument('filter_set', '1'),
            $problem_total,
            _('Problems')
        );
    }

    if ($can_view_monitoring_hosts && $dashboard_count > 0) {
        $actions[] = $make_action(_('Dashboards'),
            (new CUrl('zabbix.php'))
                ->setArgument('action', 'host.dashboard.view')
                ->setArgument('hostid', $hostid),
            $dashboard_count,
            _('Dashboards')
        );
    }

    if ($can_view_monitoring_hosts && $graph_count > 0) {
        $actions[] = $make_action(_('Graphs'),
            (new CUrl('zabbix.php'))
                ->setArgument('action', 'charts.view')
                ->setArgument('filter_hostids', [$hostid])
                ->setArgument('filter_show', GRAPH_FILTER_HOST)
                ->setArgument('filter_set', '1'),
            $graph_count,
            _('Graphs')
        );
    }

    if (CWebUser::checkAccess(CRoleHelper::UI_MONITORING_LATEST_DATA) && $item_count > 0) {
        $actions[] = $make_action(_('Latest Data'),
            (new CUrl('zabbix.php'))
                ->setArgument('action', 'latest.view')
                ->setArgument('hostids', [$hostid])
                ->setArgument('filter_set', '1'),
            $item_count,
            _('Latest Data')
        );
    }

    if ($can_view_monitoring_hosts && $web_scenario_count > 0) {
        $actions[] = $make_action(_('Web'),
            (new CUrl('zabbix.php'))
                ->setArgument('action', 'web.view')
                ->setArgument('filter_hostids', [$hostid])
                ->setArgument('filter_set', '1'),
            $web_scenario_count,
            _('Web scenarios')
        );
    }

    if ($can_configure_hosts) {
        $actions[] = $make_action(_('Configuration'),
            (new CUrl('zabbix.php'))
                ->setArgument('action', 'host.edit')
                ->setArgument('hostid', $hostid),
            null,
            _('Host Configuration')
        );
    }

    if (!$actions) {
        $actions[] = (new CSpan(_('No direct actions available')))->addClass(ZBX_STYLE_DISABLED);
    }

    return (new CDiv([
        (new CDiv(_('Monitoring')))->addClass('analisthost-section-name'),
        (new CDiv($actions))
            ->addClass('analisthost-section-body')
            ->addClass('monitoring-actions')
    ]))
        ->addClass('analisthost-section')
        ->addClass('section-monitoring');
}

function makeAnalistHostSectionAvailability(array $interfaces): CDiv {
    // Criar container para os indicadores de interface
    $indicators = new CDiv();
    $indicators->addClass('interface-indicators');

    // Definir os tipos de interface e seus labels
    $interface_types = [
        INTERFACE_TYPE_AGENT => 'ZBX',
        INTERFACE_TYPE_SNMP => 'SNMP',
        INTERFACE_TYPE_IPMI => 'IPMI',
        INTERFACE_TYPE_JMX => 'JMX'
    ];

    // Definir cores baseadas no status
    $status_colors = [
        INTERFACE_AVAILABLE_UNKNOWN => 'status-grey',
        INTERFACE_AVAILABLE_TRUE => 'status-green',
        INTERFACE_AVAILABLE_FALSE => 'status-red',
        INTERFACE_AVAILABLE_MIXED => 'status-yellow'
    ];

    // Agrupar interfaces por tipo
    $type_interfaces = [];
    foreach ($interfaces as $interface) {
        if (isset($interface['type']) && isset($interface['available'])) {
            $type_interfaces[$interface['type']][] = $interface;
        }
    }

    // Processar cada tipo de interface
    foreach ($interface_types as $type => $label) {
        if (isset($type_interfaces[$type]) && $type_interfaces[$type]) {
            // Determinar o status geral para este tipo
            $statuses = array_column($type_interfaces[$type], 'available');
            $overall_status = INTERFACE_AVAILABLE_TRUE;

            if (in_array(INTERFACE_AVAILABLE_FALSE, $statuses)) {
                $overall_status = INTERFACE_AVAILABLE_FALSE;
            } elseif (in_array(INTERFACE_AVAILABLE_UNKNOWN, $statuses)) {
                $overall_status = INTERFACE_AVAILABLE_UNKNOWN;
            }

            // Criar o badge/indicador
            $indicator = (new CSpan($label))
                ->addClass('interface-indicator')
                ->addClass($status_colors[$overall_status]);

            // Adicionar hint com detalhes das interfaces
            $hint_table = new CTableInfo();
            $hint_table->setHeader([_('Interface'), _('Status'), _('Error')]);

            foreach ($type_interfaces[$type] as $interface) {
                $interface_text = '';
                if (isset($interface['ip']) && $interface['ip']) {
                    $interface_text = $interface['ip'];
                    if (isset($interface['port'])) {
                        $interface_text .= ':' . $interface['port'];
                    }
                } elseif (isset($interface['dns']) && $interface['dns']) {
                    $interface_text = $interface['dns'];
                    if (isset($interface['port'])) {
                        $interface_text .= ':' . $interface['port'];
                    }
                }

                $status_text = [
                    INTERFACE_AVAILABLE_UNKNOWN => _('Unknown'),
                    INTERFACE_AVAILABLE_TRUE => _('Available'),
                    INTERFACE_AVAILABLE_FALSE => _('Not available')
                ];

                $hint_table->addRow([
                    $interface_text,
                    (new CSpan($status_text[$interface['available']]))
                        ->addClass($status_colors[$interface['available']]),
                    isset($interface['error']) ? $interface['error'] : ''
                ]);
            }

            $indicator->setHint($hint_table);
            $indicators->addItem($indicator);
        }
    }

    // Se não houver interfaces, mostrar um indicador padrão
    if ($indicators->items === null || count($indicators->items) === 0) {
        $indicators->addItem(
            (new CSpan('N/A'))
                ->addClass('interface-indicator')
                ->addClass('status-grey')
        );
    }

    return (new CDiv([
        (new CDiv(_('Availability')))->addClass('analisthost-section-name'),
        (new CDiv($indicators))->addClass('analisthost-section-body')
    ]))
        ->addClass('analisthost-section')
        ->addClass('section-availability');
}

function makeAnalistHostSectionMonitoredBy(array $host): CDiv {
    switch ($host['monitored_by']) {
        case ZBX_MONITORED_BY_SERVER:

            $monitored_by = [
                (new CSpan(_('Zabbix Server')))->addClass('monitored-label'),
            ];
            break;

        case ZBX_MONITORED_BY_PROXY:
            $proxy = new CSpan($host['proxy']['name']);
            $proxy->setTitle($host['proxy']['name']);

            $monitored_by = [
                (new CSpan(_('Proxy: ')))->addClass('monitored-label'),
                $proxy
            ];
            break;

        case ZBX_MONITORED_BY_PROXY_GROUP:
            $proxy_group = new CSpan($host['proxy_group']['name']);
            $proxy_group->setTitle($host['proxy_group']['name']);

            $monitored_by = [
                (new CSpan(_('Proxy group: ')))->addClass('monitored-label'),
                $proxy_group,
            ];

    }

    return (new CDiv([
        (new CDiv(_('Monitored by')))->addClass('analisthost-section-name'),
        (new CDiv($monitored_by))->addClass('analisthost-section-body')
    ]))
        ->addClass('analisthost-section')
        ->addClass('section-monitored-by');
}

function makeAnalistHostSectionTemplates(array $host_templates): CDiv {
    $templates = [];
    $hint_templates = [];

    foreach ($host_templates as $template) {
        $template_fullname = $template['parentTemplates']
            ? $template['name'].' ('.implode(', ', array_column($template['parentTemplates'], 'name')).')'
            : $template['name'];

        $templates[] = (new CSpan($template['name']))
            ->addClass('template')
            ->addClass('template-name')
            ->setTitle($template_fullname);

        $hint_templates[] = $template_fullname;
    }

   # if ($templates) {
   #     $templates[] = (new CLink(new CIcon('zi-more')))
   #         ->addClass(ZBX_STYLE_LINK_ALT)
   #         ->setHint(implode(', ', $hint_templates), ZBX_STYLE_HINTBOX_WRAP);
   # }

    return (new CDiv([
        (new CDiv(_('Templates')))->addClass('analisthost-section-name'),
        (new CDiv($templates))
            ->addClass('analisthost-section-body')
            ->addClass('templates')
            ->addStyle('
                max-width: 100%;
                overflow: hidden;
            ')
    ]))
        ->addClass('analisthost-section')
        ->addClass('section-templates')
        ->addStyle('max-width: 100%; overflow: hidden;');
}

function hasAnalistHostInventoryData(array $host_inventory, array $inventory_fields): bool {
    foreach (getHostInventories() as $inventory) {
        $db_field = $inventory['db_field'];

        if ($inventory_fields && !array_key_exists($db_field, $host_inventory)) {
            continue;
        }

        if (array_key_exists($db_field, $host_inventory) && $host_inventory[$db_field] !== '') {
            return true;
        }
    }

    return false;
}

function makeAnalistHostSectionInventoryCompact(string $hostid, array $host_inventory, array $inventory_fields): CDiv {
    $inventory_table = new CTableInfo();
    $inventory_table->setHeader([_('Field'), _('Value')]);

    $all_inventory_fields = [];
    $max_visible = 10;
    $priority_fields = [
        'os', 'os_full', 'type', 'type_full', 'name', 'alias', 'location', 'site_address_a', 'site_city',
        'site_country', 'contact', 'poc_1_name', 'poc_1_email', 'serialno_a', 'serialno_b', 'asset_tag',
        'hardware', 'hardware_full', 'software', 'software_full'
    ];

    if ($host_inventory) {
        foreach (getHostInventories() as $inventory) {
            $db_field = $inventory['db_field'];

            if ((!$inventory_fields && (!array_key_exists($db_field, $host_inventory) || $host_inventory[$db_field] === ''))
                    || ($inventory_fields && !array_key_exists($db_field, $host_inventory))) {
                continue;
            }

            $all_inventory_fields[] = [
                'db_field' => $db_field,
                'title' => $inventory['title'],
                'value' => $host_inventory[$db_field]
            ];
        }
    }

    usort($all_inventory_fields, static function (array $a, array $b) use ($priority_fields): int {
        $a_priority = array_search($a['db_field'], $priority_fields, true);
        $b_priority = array_search($b['db_field'], $priority_fields, true);

        $a_rank = ($a_priority === false) ? PHP_INT_MAX : $a_priority;
        $b_rank = ($b_priority === false) ? PHP_INT_MAX : $b_priority;

        return $a_rank === $b_rank
            ? strcasecmp($a['title'], $b['title'])
            : ($a_rank <=> $b_rank);
    });

    foreach (array_slice($all_inventory_fields, 0, $max_visible) as $field) {
        $inventory_table->addRow([
            $field['title'],
            (new CCol($field['value']))
                ->addClass(ZBX_STYLE_WORDBREAK)
                ->setTitle($field['value'])
        ]);
    }

    if (!$all_inventory_fields) {
        $inventory_table->addRow([_('No inventory data available'), '']);
    }
    elseif (count($all_inventory_fields) > $max_visible) {
        $hint_table = new CTableInfo();
        $hint_table->setHeader([_('Field'), _('Value')]);

        foreach (array_slice($all_inventory_fields, $max_visible) as $field) {
            $hint_table->addRow([
                $field['title'],
                (new CCol($field['value']))->addClass(ZBX_STYLE_WORDBREAK)
            ]);
        }

        $inventory_table->addRow([
            '',
            (new CLink(new CIcon('zi-more')))
                ->addClass(ZBX_STYLE_LINK_ALT)
                ->setHint($hint_table, ZBX_STYLE_HINTBOX_WRAP)
        ]);
    }

    return (new CDiv([
        (new CDiv(
            CWebUser::checkAccess(CRoleHelper::UI_INVENTORY_HOSTS)
                ? new CLink(_('Inventory'), (new CUrl('hostinventories.php'))->setArgument('hostid', $hostid))
                : _('Inventory')
        ))->addClass('analisthost-section-name'),
        (new CDiv($inventory_table))->addClass('analisthost-section-body')
    ]))
        ->addClass('analisthost-section')
        ->addClass('section-inventory');
}

function makeAnalistHostSectionInventory(string $hostid, array $host_inventory, array $inventory_fields): CDiv {
    $inventory_list = [];
    $all_inventory_fields = [];
    $visible_count = 0;
    $max_visible = 10;

    if ($host_inventory) {
        // Coletamos todos os campos de inventário primeiro
        foreach (getHostInventories() as $inventory) {
            if ((!$inventory_fields && $host_inventory[$inventory['db_field']] === '') ||
                ($inventory_fields && !array_key_exists($inventory['db_field'], $host_inventory))) {
                continue;
            }

            $all_inventory_fields[] = [
                'title' => $inventory['title'],
                'value' => $host_inventory[$inventory['db_field']]
            ];
        }

        // Mostrar apenas os primeiros 3 campos
        foreach ($all_inventory_fields as $index => $field) {
            if ($visible_count >= $max_visible) {
                break;
            }

            // Campo do inventário (nome)
            $inventory_list[] = (new CDiv($field['title']))
                ->addClass('inventory-field-name')
                ->setTitle($field['title'])
                ->addStyle('font-weight: bold; color: #666; margin-bottom: 2px;');

            // Valor do inventário com quebra de linha para textos longos
            $inventory_list[] = (new CDiv($field['value']))
                ->addClass('inventory-field-value')
                ->setTitle($field['value']);

            $visible_count++;
        }

        // Se há mais campos, adicionar botão "more"
        if (count($all_inventory_fields) > $max_visible) {
            $remaining_fields = array_slice($all_inventory_fields, $max_visible);
            $hint_content = [];

            foreach ($remaining_fields as $field) {
                $hint_content[] = $field['title'] . ': ' . $field['value'];
            }

            $inventory_list[] = (new CLink(new CIcon('zi-more')))
                ->addClass(ZBX_STYLE_LINK_ALT)
                ->setHint(implode("\n\n", $hint_content), ZBX_STYLE_HINTBOX_WRAP)
                ->addStyle('margin-top: 8px; display: inline-block;');
        }
    }

    return (new CDiv([
        (new CDiv(
            CWebuser::checkAccess(CRoleHelper::UI_INVENTORY_HOSTS)
                ? new CLink(_('Inventory'), (new CUrl('hostinventories.php'))->setArgument('hostid', $hostid))
                : _('Inventory')
        ))->addClass('analisthost-section-name'),
        (new CDiv($inventory_list))
            ->addClass('analisthost-section-body')
            ->addStyle('max-width: 100%; overflow: hidden;') // Previne overflow horizontal
    ]))
        ->addClass('analisthost-section')
        ->addClass('section-inventory')
        ->addStyle('max-width: 100%; overflow: hidden;'); // Previne overflow no container principal
}

function makeAnalistHostSectionTags(array $host_tags): CDiv {
    $tags = [];

    foreach ($host_tags as $tag) {
        $tag_text = $tag['tag'].($tag['value'] === '' ? '' : ': '.$tag['value']);
        $tags[] = (new CSpan($tag_text))->addClass('tag')->addClass('analisthost-tag');
    }

    return (new CDiv([
        (new CDiv(_('Tags')))->addClass('analisthost-section-name'),
        (new CDiv($tags))
            ->addClass('analisthost-section-body')
            ->addClass('analisthost-tags')
    ]))
        ->addClass('analisthost-section')
        ->addClass('section-tags');
}

if (isset($data['user']['debug_mode']) && $data['user']['debug_mode'] == 1) {
    if (class_exists('CProfiler')) {
        CProfiler::getInstance()->stop();
        $output['debug'] = CProfiler::getInstance()->make()->toString();
    }
}

echo json_encode($output);
