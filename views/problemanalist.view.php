<?php declare(strict_types = 0);


// Include required files for event functions
require_once dirname(__FILE__).'/../../../include/events.inc.php';
require_once dirname(__FILE__).'/../../../include/actions.inc.php';
require_once dirname(__FILE__).'/../../../include/users.inc.php';
require_once dirname(__FILE__).'/../../../include/items.inc.php';
require_once dirname(__FILE__).'/../../../include/triggers.inc.php';
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
$active_tab = $data['tab'] ?? 'overview';
$panels = [];
$pattern_data = null;

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
$is_resolved = !empty($event['r_eventid']);
$closing_pending = !$is_resolved && !empty($data['closing_pending']);
$recovery_time = isset($event['recovery_clock'])
    ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, (int) $event['recovery_clock'])
    : '';
$problem_duration = isset($event['clock']) && (!$is_resolved || isset($event['recovery_clock']))
    ? zbx_date2age((int) $event['clock'], isset($event['recovery_clock']) ? (int) $event['recovery_clock'] : 0)
    : '';

// Get severity info
$severity = isset($event['severity']) ? (int) $event['severity'] : 0;
$is_acknowledged = (int) ($event['acknowledged'] ?? EVENT_NOT_ACKNOWLEDGED) === (int) EVENT_ACKNOWLEDGED;
$problem_actions = [
    'canAcknowledge' => (bool) ($data['can_acknowledge'] ?? false),
    'canComment' => (bool) ($data['can_comment'] ?? false),
    'canClose' => (bool) ($data['can_close'] ?? false),
    'canChangeSeverity' => (bool) ($data['can_change_severity'] ?? false),
    'severity' => $severity,
    'minSeverity' => TRIGGER_SEVERITY_NOT_CLASSIFIED,
    'maxSeverity' => TRIGGER_SEVERITY_COUNT - 1,
    'severityAction' => ZBX_PROBLEM_UPDATE_SEVERITY,
    'acknowledged' => $is_acknowledged,
    'recoveryReady' => $is_resolved && isset($event['recovery_clock']),
    'closingPending' => $closing_pending,
    'pendingRecoveryRow' => (new CRow([
        (new CColHeader(_('Recovery time')))->setAttribute('scope', 'row')
            ->addClass('analist-detail-label')->addClass(ZBX_STYLE_GREY),
        (new CCol(_('Pending')))->addClass('analist-detail-value'),
        (new CColHeader(_('Duration')))->setAttribute('scope', 'row')
            ->addClass('analist-detail-label')->addClass(ZBX_STYLE_GREY),
        (new CCol(_('Pending')))->addClass('analist-detail-value')
    ]))->setAttribute('data-recovery-row', '')->toString(),
    'closingLabel' => _('Closing problem. Waiting for recovery time…'),
    'closingStatusLabel' => _('Closing'),
    'csrfTokenName' => CSRF_TOKEN_NAME,
    'csrfToken' => !empty($data['can_acknowledge']) || !empty($data['can_close']) || !empty($data['can_change_severity'])
            || !empty($data['can_comment'])
        ? CCsrfTokenHelper::get('acknowledge') : '',
    'acknowledgeAction' => ZBX_PROBLEM_UPDATE_ACKNOWLEDGE,
    'unacknowledgeAction' => ZBX_PROBLEM_UPDATE_UNACKNOWLEDGE,
    'closeAction' => ZBX_PROBLEM_UPDATE_CLOSE,
    'selectedScope' => ZBX_ACKNOWLEDGE_SELECTED,
    'updatedLabel' => _('Event updated'),
    'commentAddedLabel' => _('Comment added'),
    'emptyCommentLabel' => _('Enter a comment.'),
    'refreshErrorLabel' => _('Event updated, but the details could not be refreshed. Please retry.')
];
$severity_value = CSeverityHelper::makeSeverityCell($severity);
if ($problem_actions['canChangeSeverity']) {
    $severity_controls = (new CDiv([
        new CSpan(CSeverityHelper::getName($severity)),
        (new CDiv([
            (new CButtonIcon(ZBX_ICON_ARROW_UP_SMALL, _('Increase severity')))
                ->setAttribute('aria-label', _('Increase severity'))
                ->setAttribute('data-problem-action', 'severity-up')
                ->setEnabled($severity < TRIGGER_SEVERITY_COUNT - 1),
            (new CButtonIcon(ZBX_ICON_ARROW_DOWN_SMALL, _('Decrease severity')))
                ->setAttribute('aria-label', _('Decrease severity'))
                ->setAttribute('data-problem-action', 'severity-down')
                ->setEnabled($severity > TRIGGER_SEVERITY_NOT_CLASSIFIED)
        ]))->addClass('analist-severity-arrows')
    ]))->addClass('analist-severity-control');
    $severity_value = CSeverityHelper::makeSeverityCell($severity, $severity_controls);
}
$severity_value->addClass('analist-detail-value');
$acknowledgement_value = (new CDiv([
    (new CIcon(
        $is_acknowledged ? ZBX_ICON_CHECK : ZBX_ICON_UNCHECK,
        $is_acknowledged ? _('Acknowledged') : _('Unacknowledged')
    ))->addClass($is_acknowledged ? ZBX_STYLE_COLOR_POSITIVE : ZBX_STYLE_COLOR_NEGATIVE),
    new CSpan($is_acknowledged ? _('Acknowledged') : _('Unacknowledged'))
]))->addClass('analist-detail-inline')->addClass('analist-problem-acknowledgement');
if ($problem_actions['canAcknowledge']) {
    $acknowledgement_value->addItem((new CButton(null, $is_acknowledged ? _('Unacknowledge') : _('Acknowledge')))
        ->addClass('btn-alt')->setAttribute('data-problem-action', $is_acknowledged ? 'unacknowledge' : 'acknowledge'));
}

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
    $item += ['units' => '', 'value_type' => ITEM_VALUE_TYPE_STR];

    $raw_value = (string) $value;
    $formatted_value = formatHistoryValue($raw_value, $item, false);
    $raw_suffix = ' ('.$raw_value.')';
    // Native value maps may already end with the exact raw value in parentheses.
    if ($formatted_value !== $raw_value && substr($formatted_value, -strlen($raw_suffix)) !== $raw_suffix) {
        $formatted_value .= $raw_suffix;
    }

    return new CPre(zbx_nl2br($formatted_value));
}

function createAnalistHistoryRows(array $values, array $item): array {
    $rows = [];
    foreach ($values as $value) {
        $clock = isset($value['clock']) && $value['clock'] !== null
            ? zbx_date2str(DATE_TIME_FORMAT_SECONDS, (int) $value['clock']) : _('N/A');
        $rows[] = (new CDiv([
            (new CSpan($clock))->addClass('grey')->addClass('operational-history-clock'),
            createAnalistHistoryValue($value['value'] ?? '', $item)->addClass('operational-history-data')
        ]))->addClass('operational-history-value');
    }
    return $rows;
}

/** Render paged events with the same native statuses, links and action helpers as make_small_eventlist(). */
function createAnalistTimelineRows(array $events): array {
    if (!$events) return [];
    $events = array_column($events, null, 'eventid');
    $recovery_ids = array_filter(array_unique(array_column($events, 'r_eventid')));
    $recoveries = $recovery_ids ? API::Event()->get([
        'output' => ['clock'], 'source' => EVENT_SOURCE_TRIGGERS, 'object' => EVENT_OBJECT_TRIGGER,
        'eventids' => array_values($recovery_ids), 'preservekeys' => true
    ]) : [];
    $triggers = API::Trigger()->get([
        'output' => ['priority', 'manual_close'], 'triggerids' => array_unique(array_column($events, 'objectid')),
        'preservekeys' => true
    ]);
    foreach ($events as &$event) $event['r_clock'] = $recoveries[$event['r_eventid']]['clock'] ?? 0;
    unset($event);
    $actions = getEventsActionsIconsData($events, $triggers);
    $users = API::User()->get([
        'output' => ['username', 'name', 'surname'], 'userids' => array_keys($actions['userids']), 'preservekeys' => true
    ]);
    $allowed = [
        'add_comments' => CWebUser::checkAccess(CRoleHelper::ACTIONS_ADD_PROBLEM_COMMENTS),
        'change_severity' => CWebUser::checkAccess(CRoleHelper::ACTIONS_CHANGE_SEVERITY),
        'acknowledge' => CWebUser::checkAccess(CRoleHelper::ACTIONS_ACKNOWLEDGE_PROBLEMS),
        'suppress_problems' => CWebUser::checkAccess(CRoleHelper::ACTIONS_SUPPRESS_PROBLEMS),
        'close' => CWebUser::checkAccess(CRoleHelper::ACTIONS_CLOSE_PROBLEMS),
        'rank_change' => CWebUser::checkAccess(CRoleHelper::ACTIONS_CHANGE_PROBLEM_RANKING)
    ];
    $rows = [];
    foreach ($events as $event) {
        $resolved = (string) $event['r_eventid'] !== '0';
        $closing = !$resolved && hasEventCloseAction($event['acknowledges']);
        $acknowledged = (int) $event['acknowledged'] === EVENT_ACKNOWLEDGED;
        $status = new CSpan(getEventStatusString($closing, $event));
        if (isEventUpdating($closing, $event)) $status->addClass('js-blink');
        addTriggerValueStyle($status, $resolved || $closing ? TRIGGER_VALUE_FALSE : TRIGGER_VALUE_TRUE,
            $resolved ? $event['r_clock'] : ($closing ? time() : $event['clock']), $acknowledged);
        $can_close = $allowed['close'] && !$resolved && !$closing
            && (int) ($triggers[$event['objectid']]['manual_close'] ?? 0) === ZBX_TRIGGER_MANUAL_CLOSE_ALLOWED;
        $can_update = $allowed['add_comments'] || $allowed['change_severity'] || $allowed['acknowledge']
            || $allowed['suppress_problems'] || $allowed['rank_change'] || $can_close;
        $update = $can_update ? (new CLink(_('Update')))->addClass(ZBX_STYLE_LINK_ALT)
            ->setAttribute('data-eventid', $event['eventid'])
            ->onClick('acknowledgePopUp({eventids: [this.dataset.eventid]}, this);') : new CSpan(_('Update'));
        $event_url = 'tr_events.php?triggerid='.$event['objectid'].'&eventid='.$event['eventid'];
        $rows[] = (new CRow([
            (new CLink(zbx_date2str(DATE_TIME_FORMAT_SECONDS, $event['clock']), $event_url))->addClass('action'),
            $resolved ? (new CLink(zbx_date2str(DATE_TIME_FORMAT_SECONDS, $event['r_clock']), $event_url))
                ->addClass('action') : '',
            $status, zbx_date2age($event['clock']),
            $resolved ? zbx_date2age($event['clock'], $event['r_clock']) : zbx_date2age($event['clock']),
            $update, makeEventActionsIcons($event['eventid'], $actions['data'], $users, $acknowledged)
        ]))->setAttribute('data-timeline-event', $event['eventid']);
    }
    return $rows;
}

if ($active_tab === 'timeline_events') {
    $page = $data['timeline_page'];
    echo json_encode([
        'body' => implode('', array_map(static function ($row) { return $row->toString(); },
            createAnalistTimelineRows($page['events']))),
        'has_more' => $page['has_more'], 'cursor' => $page['cursor']
    ]);
    return;
}

if ($active_tab === 'history') {
    $rows = createAnalistHistoryRows($data['history_values'], $data['history_item']);
    echo json_encode([
        'body' => implode('', array_map(static function ($row) { return $row->toString(); }, $rows)),
        'has_more' => $data['history_has_more'], 'cursor' => $data['history_cursor']
    ]);
    return;
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
        (new CColHeader(_('Latest values')))->addStyle('width: 70%;')
    ]);

    if (!$history) {
        $table->addRow([_('No recent item history available'), '']);
        $section->addItem($table);

        return $section;
    }

    foreach ($history as $entry) {
        $item = $entry['item'] ?? [];
        $values = $entry['values'] ?? [];
        $value_lines = createAnalistHistoryRows($values, $item);

        if (!$value_lines) {
            $value_lines[] = new CSpan(_('No history values found'));
        }

        $value_cell = (new CCol((new CDiv($value_lines))->setAttribute('data-history-values', '')))
            ->addClass(ZBX_STYLE_WORDBREAK)->addStyle('width: 70%;');
        $last = $values ? $values[count($values) - 1] : null;
        if (!empty($item['itemid']) && isset($last['clock'])
                && (int) ($item['value_type'] ?? -1) !== ITEM_VALUE_TYPE_BINARY) {
            $value_cell->addItem((new CDiv([
                (new CSpan())->setAttribute('data-history-message', '')->setAttribute('role', 'status')
                    ->setAttribute('aria-live', 'polite'),
                (new CButton(null, _('More')))->addClass('btn-alt')->setAttribute('data-history-more', '')
                    ->setAttribute('data-before-clock', (int) $last['clock'])
                    ->setAttribute('data-before-ns', (int) ($last['ns'] ?? 0))
                    ->setAttribute('aria-label', _s('Load 3 more values for %1$s', $item['name'] ?? $item['itemid']))
            ]))->addClass('operational-history-more'));
        }
        $table->addRow((new CRow([
            (new CCol($item['name'] ?? $item['key_'] ?? _('Unknown item')))
                ->addClass(ZBX_STYLE_WORDBREAK)
                ->addStyle('width: 30%;'),
            $value_cell
        ]))->setAttribute('data-history-item', $item['itemid'] ?? ''));
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


if ($active_tab === 'overview') {
// Event context and actions stay visible; expressions are available on demand.
$details_label = static function (string $label): CCol {
    return (new CColHeader($label))->setAttribute('scope', 'row')
        ->addClass('analist-detail-label')->addClass(ZBX_STYLE_GREY);
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

$status_label = $is_resolved ? _('Resolved') : ($closing_pending ? _('Closing') : _('Problem'));
$status_class = $is_resolved ? ZBX_STYLE_COLOR_POSITIVE
    : ($closing_pending ? ZBX_STYLE_COLOR_WARNING : ZBX_STYLE_COLOR_NEGATIVE);
$status_value = (new CDiv([
    (new CSpan($status_label))->addClass($status_class)->setAttribute('data-problem-status', '')
]))->addClass('analist-detail-inline')->addClass('analist-problem-status');
if ($problem_actions['canClose']) {
    $status_value->addItem((new CButton(null, _('Close problem')))->addClass('btn-alt')
        ->setAttribute('data-problem-action', 'close'));
}
$problem_details_table->addRow([
    $details_label(_('Status')), $details_value($status_value),
    $details_label(_('Severity')), $severity_value
]);

$detail_hosts = $trigger['hosts'] ?? ($host ? [$host] : []);
$host_summaries = [];
foreach ($detail_hosts as $detail_host) {
    $visible_name = ($detail_host['name'] ?? '') !== ''
        ? $detail_host['name'] : ($detail_host['host'] ?? _('Unknown'));
    $host_summary = (new CDiv(new CSpan($visible_name)))->addClass('analist-host-summary');
    $inventory_notes = (string) ($detail_host['inventory']['notes'] ?? '');
    if (trim($inventory_notes) !== '') {
        $host_summary->addItem((new CDiv($inventory_notes))
            ->addClass('analist-host-notes')->addClass(ZBX_STYLE_GREY));
    }
    $host_summaries[] = $host_summary;
}
$problem_details_table->addRow([
    $details_label(count($detail_hosts) > 1 ? _('Hosts') : _('Host')),
    $details_value($host_summaries ?: _('Unknown')),
    $details_label(_('Acknowledgement')), $details_value($acknowledgement_value)
]);
$problem_details_table->addRow([
    $details_label(_('Started')), $details_value($event_time_value),
    $details_label(_('Event ID')),
    $details_value($event_details_url !== null
        ? new CLink('#'.$event['eventid'], $event_details_url) : '#'.$event['eventid'])
]);

if ($is_resolved || $closing_pending) {
    $problem_details_table->addRow((new CRow([
        $details_label(_('Recovery time')),
        $details_value($recovery_time ?: ($closing_pending ? _('Pending') : _('Unknown'))),
        $details_label(_('Duration')),
        $details_value($closing_pending ? _('Pending') : ($problem_duration ?: _('Unknown')))
    ]))->setAttribute('data-recovery-row', ''));
}
else {
    $problem_details_table->addRow((new CRow([
        $details_label(_('Open for')),
        $details_full_value($problem_duration ?: _('Unknown'))
    ]))->setAttribute('data-age-row', ''));
}

$event_tags = makeTags([$event], true, 'eventid', count($event['tags'] ?? []));
if (!empty($event_tags[$event['eventid']])) {
    $problem_details_table->addRow([
        $details_label(_('Tags')),
        $details_full_value((new CDiv($event_tags[$event['eventid']]))->addClass(ZBX_STYLE_TAGS_WRAPPER))
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
$problem_detail_section->addItem(new CTag('h4', true, _('Problem details')));
$problem_detail_section->addItem($problem_details_table);
$problem_detail_section->addItem((new CDiv())->setAttribute('data-problem-action-message', '')
    ->setAttribute('role', 'status')->setAttribute('aria-live', 'polite'));
if ($trigger && ($trigger['comments'] ?? '') !== '') {
    $problem_detail_section->addItem((new CDiv([
        new CTag('h5', true, _('Description')),
        (new CDiv($trigger['comments']))->addClass('analist-problem-description')
    ]))->addClass('analist-problem-notes'));
}
if ($trigger) {
    $technical_table = (new CTableInfo())->addClass('analist-technical-table');
    if (isset($trigger['triggerid'])) {
        $technical_table->addRow([$details_label(_('Trigger ID')), $details_value($trigger['triggerid'])]);
    }
    foreach (['expression' => _('Problem expression'), 'recovery_expression' => _('Recovery expression')] as $key => $label) {
        if (($trigger[$key] ?? '') !== '') {
            $technical_table->addRow([
                $details_label($label),
                $details_value((new CPre($trigger[$key]))->addClass('analist-trigger-expression'))
            ]);
        }
    }
    $problem_detail_section->addItem((new CSectionCollapsible($technical_table))
        ->setId(uniqid('analist-technical-'.$event['eventid'].'-'))
        ->setHeader(new CTag('h4', true, _('Technical details')))->setExpanded(false)
        ->addClass('analist-problem-technical'));
}
$overview_container->addItem($problem_detail_section);

}
$overview_container = $overview_container ?? new CDiv();
$panels['overview'] = $overview_container;

// TAB 1: Host Information - Primeiro tab
$host_div = new CDiv();
if ($active_tab === 'host') {

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

}
$panels['host'] = $host_div;

// TAB 3: Event Timeline
$timeline_div = new CDiv();
$timeline_div->addClass('timeline-tab');
if ($active_tab === 'timeline') {

$timeline_comparison_section = createMonthlyComparisonSection($monthly_comparison);
if ($timeline_comparison_section !== null) {
    $timeline_div->addItem($timeline_comparison_section);
}

$page = $data['timeline_page'] ?? ['events' => [], 'has_more' => false, 'cursor' => null];
$timeline_table = (new CTableInfo())->setHeader([
    _('Time'), _('Recovery time'), _('Status'), _('Age'), _('Duration'), _('Update'), _('Actions')
])->setAttribute('data-timeline-table', '');
foreach (createAnalistTimelineRows($page['events']) as $row) $timeline_table->addRow($row);
$more = (new CButton(null, _('More')))->addClass('btn-alt')->setAttribute('data-timeline-more', '')
    ->setAttribute('data-before-eventid', $page['cursor']['eventid'] ?? '')
    ->setAttribute('data-before-clock', $page['cursor']['clock'] ?? '')
    ->setAttribute('aria-label', _('Load 20 more events'));
if (!$page['has_more']) $more->setAttribute('hidden', 'hidden');

// Add header
$timeline_div->addItem(new CTag('h4', false, _('Recent Events')));
$timeline_div->addItem((new CDiv([
    $timeline_table,
    (new CDiv([
        (new CSpan())->setAttribute('data-timeline-message', '')->setAttribute('role', 'status')
            ->setAttribute('aria-live', 'polite'),
        $more
    ]))->addClass('analist-timeline-more')
]))->setAttribute('data-timeline-events', ''));
}
$panels['timeline'] = $timeline_div;

// TAB 4: Time Patterns
$time_patterns_div = new CDiv();
if ($active_tab === 'patterns') {

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

$pattern_data = [
    'hourlyData' => array_values($hourly_data),
    'weeklyData' => array_values($weekly_data),
    'eventClocks' => $event_clocks,
    'eventSlots' => $event_slots,
    'lastWeekStart' => $pattern_week_start,
    'lastWeekEnd' => $pattern_week_end,
    'monthKeys' => $month_keys,
    'monthLabels' => $monthly_labels,
    'dayLabels' => range(1, 31),
    'weekLabels' => $weekdays,
    'hourLabels' => $hour_labels,
    'hintMonth' => _('Click a month to see which days had the most incidents'),
    'hintDay' => _('Click a day to see hourly distribution in heatmap'),
    'filteredBy' => _('Filtered by'),
    'clearFilter' => _('Clear filter'),
    'sameSlotLastWeek' => _('Same slot last week'),
    'sameDayLastWeek' => _('Same day last week'),
    'incidents' => _('incidents')
];

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

}
$panels['patterns'] = $time_patterns_div;


// TAB 5: Native chart rendering, selectors, calendars and collapsible graph sections.
$graphs_div = (new CDiv())->addClass('analist-graphs');
if ($active_tab === 'graphs') {
    $timeline = $data['graph_timeline'];
    $has_multiple_items = count($items) >= 2;
    $graph_layout = $has_multiple_items ? ($data['graph_layout'] ?? 'items') : 'items';
    $graph_id = uniqid('analist-graphs-');
    $period = (new CSelect('graph_period'))->setId($graph_id.'-period')
        ->setFocusableElementId($graph_id.'-period-focus')->setValue($timeline['period'])
        ->setAttribute('data-graph-period', '')->addOptions(CSelect::createOptionsFromArray([
            'around' => _('Around problem start'), 'before' => _('6 hours before problem'),
            'duration' => _('Problem duration'), 'day' => _('Last 24 hours'), 'custom' => _('Custom period')
        ]));
    $graph_toolbar = (new CDiv([
        new CLabel(_('Period'), $period->getFocusableElementId()), $period
    ]))->addClass('analist-graph-toolbar');
    if ($has_multiple_items) {
        $layout = (new CSelect('graph_layout'))->setId($graph_id.'-layout')
            ->setFocusableElementId($graph_id.'-layout-focus')->setValue($graph_layout)
            ->setAttribute('data-graph-layout', '')
            ->addOptions(CSelect::createOptionsFromArray([
                'items' => _('Individual graphs'), 'combined' => _('Combined graph')
            ]));
        $graph_toolbar->addItem([new CLabel(_('Display'), $layout->getFocusableElementId()), $layout]);
    }
    $apply_graphs = (new CSubmit('apply_graphs', _('Apply')))->setId($graph_id.'-apply')
        ->setAttribute('data-graph-apply', '');
    if ($timeline['period'] !== 'custom') $apply_graphs->setAttribute('hidden', 'hidden');
    $graph_toolbar->addItem($apply_graphs);
    $custom_range = (new CDiv([
        new CLabel(_('From'), $graph_id.'_from'),
        (new CDateSelector($graph_id.'_from', $timeline['from']))->setDateFormat(ZBX_FULL_DATE_TIME)
            ->setAriaRequired(true)->setAttribute('data-graph-from', ''),
        new CLabel(_('To'), $graph_id.'_to'),
        (new CDateSelector($graph_id.'_to', $timeline['to']))->setDateFormat(ZBX_FULL_DATE_TIME)
            ->setAriaRequired(true)->setAttribute('data-graph-to', '')
    ]))->addClass('analist-graph-custom-range')->setAttribute('data-graph-custom-range', '');
    if ($timeline['period'] !== 'custom') $custom_range->setAttribute('hidden', 'hidden');
    $graphs_div->addItem((new CForm('get', 'zabbix.php'))->setName($graph_id)
        ->setAttribute('data-graph-controls', '')->addItem([
            $graph_toolbar,
            $custom_range,
            (new CTag('p', true))->setAttribute('data-graph-message', '')->setAttribute('role', 'alert')
        ]));
    $graphs_div->addItem((new CTableInfo())->setHeader([_('Problem start'), _('Recovery time'), _('Displayed period')])
        ->addRow([
            $event_time,
            $recovery_time ?: ($is_resolved ? _('Unknown') : _('Unresolved')),
            zbx_date2str(DATE_TIME_FORMAT_SECONDS, $timeline['from_ts']).' – '
                .zbx_date2str(DATE_TIME_FORMAT_SECONDS, $timeline['to_ts'])
        ]));

    $graph_groups = [];
    if ($graph_layout === 'combined' && $items) {
        $graph_groups[] = ['title' => _('Combined metrics'), 'itemids' => array_column($items, 'itemid')];
    }
    else {
        foreach ($items as $item) {
            $graph_groups[] = ['title' => $item['name'], 'itemids' => [$item['itemid']]];
        }
    }
    foreach ($graph_groups as $index => $group) {
        $history_params = ['itemids' => $group['itemids'], 'from' => $timeline['from'], 'to' => $timeline['to']];
        $graph_url = 'history.php?'.http_build_query($history_params + [
            'action' => count($group['itemids']) > 1 ? HISTORY_BATCH_GRAPH : HISTORY_GRAPH
        ]);
        $values_url = 'history.php?'.http_build_query($history_params + ['action' => HISTORY_VALUES]);
        $chart_url = 'chart.php?'.http_build_query($history_params + [
            // Without profileIdx, Zabbix replaces explicit from/to with its default period.
            'profileIdx' => 1,
            'type' => GRAPH_TYPE_NORMAL, 'resolve_macros' => 1, 'width' => 800, 'height' => 300, '_' => time()
        ]);
        $image = (new CTag('img', false))->setAttribute('src', $chart_url)->setAttribute('alt', $group['title'])
            ->setAttribute('width', 800)->setAttribute('height', 300)->setAttribute('loading', 'lazy')
            ->addClass('chart-image');
        $graphs_div->addItem((new CSectionCollapsible($image))->setId($graph_id.'-chart-'.$index)
            ->setHeader([
                new CTag('h4', true, $group['title']),
                (new CDiv([
                    (new CLink(_('Open interactive graph'), $graph_url))->setTarget('_blank')->addClass(ZBX_STYLE_BTN_ALT),
                    (new CLink(_('Values'), $values_url))->setTarget('_blank')->addClass(ZBX_STYLE_BTN_ALT)
                ]))->addClass('analist-graph-links')
            ])->setExpanded(true));
    }
    if (!$graph_groups) {
        $graphs_div->addItem((new CTableInfo())->setNoDataMessage(_('No numeric items are available for this event.')));
    }
}
$panels['graphs'] = $graphs_div;


// TAB 6: Update (Action history)
$update_div = new CDiv();
$update_div->addClass('update-tab');

// The native collector includes all manual updates, recovery events and recovery alerts.
if ($active_tab === 'update' && !empty($event['eventid'])) {
    if ($problem_actions['canComment']) {
        $comment_id = uniqid('analist-comment-');
        $update_div->addItem((new CDiv([
            new CLabel(_('Comment'), $comment_id),
            (new CTextArea('message'))->setId($comment_id)->setRows(4)
                ->setMaxlength(DB::getFieldLength('acknowledges', 'message'))
                ->setAdaptiveWidth(ZBX_TEXTAREA_BIG_WIDTH)
                ->setAttribute('aria-required', 'true')->setAttribute('data-problem-comment', ''),
            (new CButton(null, _('Post comment')))->addClass('btn-alt')
                ->setAttribute('data-problem-action', 'comment')
        ]))->setId($comment_id.'-form')->addClass('analist-comment-form')
            ->setAttribute('data-comment-form', '')->setAttribute('hidden', 'hidden'));
        $update_div->addItem((new CTag('p', true))->setAttribute('data-comment-message', '')
            ->setAttribute('role', 'status')->setAttribute('aria-live', 'polite'));
    }
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
$panels['update'] = $update_div;


// TAB 7: Services
$services_div = (new CDiv())->addClass('services-container');

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

$panels['services'] = $services_div;

if ($active_tab !== 'overview' || !empty($data['is_fragment'])) {
    echo json_encode([
        'body' => $panels[$active_tab]->toString(),
        'pattern_data' => $pattern_data,
        'problem_actions' => $active_tab === 'overview' ? $problem_actions : null,
        'comment_actions' => $active_tab === 'update' ? array_intersect_key($problem_actions,
            array_flip(['canComment', 'csrfTokenName', 'csrfToken'])) : null
    ]);
    return;
}

$popup_id = uniqid('analist-popup-'.$event['eventid'].'-');
$tab_labels = [
    'overview' => _('Overview'), 'host' => _('Host Info'), 'timeline' => _('Timeline'),
    'patterns' => _('Time Patterns'), 'graphs' => _('Graphs'), 'update' => _('Update'), 'services' => _('Services')
];
$nav = (new CList())->addClass('ui-tabs-nav tabs-nav')->setAttribute('role', 'tablist');
$tabs = (new CDiv())->addClass('ui-tabs')->addClass(ZBX_STYLE_TABLE_FORMS_CONTAINER)->setId($popup_id.'-tabs');
foreach ($tab_labels as $key => $label) {
    $panel_id = $popup_id.'-'.$key;
    $button_id = $panel_id.'-tab';
    $nav->addItem((new CButton($button_id, $label))
        ->setAttribute('type', 'button')->setAttribute('id', $button_id)
        ->setAttribute('role', 'tab')->setAttribute('data-tab-target', $key)
        ->setAttribute('aria-controls', $panel_id)->setAttribute('aria-selected', $key === 'overview' ? 'true' : 'false')
        ->setAttribute('tabindex', $key === 'overview' ? '0' : '-1')->addClass('btn-link ui-tabs-anchor'));
}
$tabs->addItem($nav);
foreach ($tab_labels as $key => $label) {
    $body = $key === 'overview' ? $panels[$key] : (new CDiv(_('Loading...')))->addClass('analist-tab-loading');
    $panel = (new CDiv($body))->setId($popup_id.'-'.$key)->addClass('ui-tabs-panel')
        ->setAttribute('data-tab', $key)->setAttribute('role', 'tabpanel')
        ->setAttribute('aria-labelledby', $popup_id.'-'.$key.'-tab')
        ->setAttribute('aria-hidden', $key === 'overview' ? 'false' : 'true');
    if ($key !== 'overview') {
        $panel->addStyle('display: none;');
    }
    $tabs->addItem($panel);
}
$popup_config = [
    'rootId' => $popup_id,
    'initialTab' => $data['initial_tab'] ?? 'overview',
    'problemActions' => $problem_actions,
    'slaLabels' => [
        'none' => _('No accessible SLA configured'), 'unknown' => _('SLA information unavailable'),
        'unavailable' => _('SLI unavailable'), 'disabled' => _('SLI unavailable (SLA disabled)'),
        'sla' => _('SLA'), 'target' => _('Target'), 'sli' => _('SLI'),
        'uptime' => _('Uptime'), 'downtime' => _('Downtime'), 'budget' => _('Error budget'),
        'met' => _('Target met'), 'below' => _('Below target'),
        'period' => _('Reporting period (UTC)')
    ],
    'serviceLabels' => [
        'matching' => _('Matching services'), 'matched' => _('Matches event tags'),
        'slas' => _('SLAs'),
        'current' => _('Service status and SLA values show current data, not the state at the event time.'),
        'search' => _('Search services'), 'hierarchy' => _('Hierarchy path'),
        'details' => _('Service details'), 'rules' => _('Matching tag rules'), 'equals' => _('equals'), 'contains' => _('contains'),
        'tag' => _('Tag'), 'operator' => _('Operator'), 'value' => _('Value'),
        'related' => _('Related services'), 'context' => _('Hierarchy context'),
        'empty' => _('No services match this search.'), 'none' => _('No matching services found for this event.'),
        'explanation' => _('No accessible service has problem-tag rules matching all required event tags.'),
        'containsOperator' => ZBX_SERVICE_PROBLEM_TAG_OPERATOR_LIKE
    ],
    'eventData' => [
        'eventid' => $event['eventid'],
        'hostname' => $host['name'] ?? $host['host'] ?? '',
        'hostid' => $host['hostid'] ?? '',
        'triggerid' => $trigger['triggerid'] ?? '',
        'problem_name' => $event['name'],
        'severity' => $event['severity']
    ],
    'statusStyles' => [
        ZBX_SEVERITY_OK => ZBX_STYLE_STATUS_GREEN,
        TRIGGER_SEVERITY_NOT_CLASSIFIED => CSeverityHelper::getStatusStyle(TRIGGER_SEVERITY_NOT_CLASSIFIED),
        TRIGGER_SEVERITY_INFORMATION => CSeverityHelper::getStatusStyle(TRIGGER_SEVERITY_INFORMATION),
        TRIGGER_SEVERITY_WARNING => CSeverityHelper::getStatusStyle(TRIGGER_SEVERITY_WARNING),
        TRIGGER_SEVERITY_AVERAGE => CSeverityHelper::getStatusStyle(TRIGGER_SEVERITY_AVERAGE),
        TRIGGER_SEVERITY_HIGH => CSeverityHelper::getStatusStyle(TRIGGER_SEVERITY_HIGH),
        TRIGGER_SEVERITY_DISASTER => CSeverityHelper::getStatusStyle(TRIGGER_SEVERITY_DISASTER)
    ],
    'statusLabels' => [
        ZBX_SEVERITY_OK => _('OK'),
        TRIGGER_SEVERITY_NOT_CLASSIFIED => CSeverityHelper::getName(TRIGGER_SEVERITY_NOT_CLASSIFIED),
        TRIGGER_SEVERITY_INFORMATION => CSeverityHelper::getName(TRIGGER_SEVERITY_INFORMATION),
        TRIGGER_SEVERITY_WARNING => CSeverityHelper::getName(TRIGGER_SEVERITY_WARNING),
        TRIGGER_SEVERITY_AVERAGE => CSeverityHelper::getName(TRIGGER_SEVERITY_AVERAGE),
        TRIGGER_SEVERITY_HIGH => CSeverityHelper::getName(TRIGGER_SEVERITY_HIGH),
        TRIGGER_SEVERITY_DISASTER => CSeverityHelper::getName(TRIGGER_SEVERITY_DISASTER)
    ],
    'loadingLabel' => _('Loading...'), 'retryLabel' => _('Retry'),
    'refreshLabel' => _('Refresh'),
    'addCommentLabel' => _('Add comment'),
    'noMoreHistoryLabel' => _('No more values'),
    'noMoreEventsLabel' => _('No more events'),
    'expandLabel' => _('Expand'), 'collapseLabel' => _('Collapse')
];
$output = [
    'header' => _('Event Details').': '.$event['name'],
    'body' => (new CDiv($tabs))->setId($popup_id)->addClass('event-details-popup')->toString(),
    'buttons' => [[
        'title' => _('Cancel'),
        'class' => 'btn-alt js-cancel',
        'cancel' => true,
        'action' => 'return true;'
    ]],
    // Native popup responses execute script_inline before mounting their controls.
    // Pages opened before a module update may not have loaded the popup runtime.
    'script_inline' => 'if (!window.AnalistProblemPopup) {'.
        $this->readJsFile('analist-popup.js', null, '/../assets/js').
        "\n}\nwindow.AnalistProblemPopup.mount(".json_encode($popup_config,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).');'
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
