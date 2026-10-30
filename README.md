# **Zabbix Problem Analysis Module**

## This module was developed from version 7.0x.
## However, with some adjustments, it may be functional in other versions that support module deployment.
## To test in versions 6.0x, you need to change the manifest_version to 1.0.

This module provides a historical analysis of specific problems in Zabbix, offering a comparative report between the current and previous months. It helps users monitor issue trends and resolution efficiency.

![image 1](docs/img1.png)

## **Features**
- **Problem Summary**: Displays the total number of incidents recorded in the current and previous months.
- **Resolution Time**: Shows the average resolution time and highlights percentage changes.
- **Acknowledgment (ACK) Analysis**: Tracks the number of acknowledged events and their corresponding percentage.
- **Trend Indicators**: Uses color-coded arrows (green for improvement, red for deterioration) to indicate changes in key metrics.

## **Popup Tabs**
- **Host Info**: Host description plus monitoring/availability stats, host groups, monitored-by info, tags, templates, and inventory (when available).
- **Overview**: Event summary table and optional agent metrics. Item previews start with the latest three values; More appends up to three older values for that item. Both use Zabbix's native unit and value-map formatter, including bps, B, Bps, s, uptime, and unixtime. When formatting changes a value, its exact raw value is retained in parentheses, for example `2.16 Gbps (2162000000)`. Users with acknowledgement permission can acknowledge/unacknowledge the selected event. A Close problem row appears only for permitted, writable, manually closable problems that are unresolved and have no pending close action. Updates use Zabbix's native CSRF-protected endpoint, refresh Overview, and invalidate cached history.
- **Timeline**: Monthly incident comparison (last 6 months when available) and Zabbix event list for the previous 20 occurrences with native actions (acknowledge, severity change, close, etc.).
- **Time Patterns**: Extended section with interactive filter bar, incident heatmap (day x hour), and monthly drilldown (last 12 months). Hourly and weekly charts are shown below this section as collapsible panels (collapsed by default).
- **Graphs**: Zabbix-rendered charts with native period/layout selectors, calendar inputs, and collapsible sections. The default shows each numeric item on its own scale for one hour before and after the problem start (up to now). Choose six hours before the problem, the problem duration (ending at recovery for resolved events), the last 24 hours, or a custom absolute/relative period. Combined graphs are optional. Each section links to Zabbix's interactive graph and value views for the same items and period.
- **Update**: Full action history including acknowledgements, comments, suppress/unsuppress, severity changes, and recovery/manual-close entries. Users with the add-comments permission can select Add comment beside Cancel in the bottom-right footer to open Update and reveal the comment editor. Post comment reloads Update and invalidates the other tabs on success; failed submissions retain the draft.
- **Services**: Searchable, expandable cards for every service matching the event's tags. Each card shows current status, SLA configuration, target comparisons, its hierarchy path, and the tag rules that matched. Use the shared popup Refresh button to reload Services. Ancestors are labelled as hierarchy context.

## **Module Functionality**
- **Problem analysis popup**: Adds a dedicated event details popup with tabs for host context, timelines, patterns, graphs, updates, and impacted services. Overview's Problem details groups status, host names, severity, acknowledgement, start time, and a linked event ID. Close problem sits beside the status, severity arrows retain native severity colors, and acknowledgement controls stay beside their state. Open problems show elapsed time; resolved problems show the actual recovery time and duration, or Unknown when unavailable. Descriptions and native tags remain visible, while trigger IDs and expressions are in a collapsed native Technical details section.
- **On-demand tabs**: Overview renders immediately. Host Info, Timeline, Time Patterns, Graphs, Update, and Services load on their first visit and stay cached for that popup. Failed loads show a Retry button. Monthly Comparison appears only in Timeline.
- **Refresh**: The button beside the popup's top-right Close control reloads the selected tab, cancels pending loads, and discards all other tab data, including comment drafts and pattern filters. Discarded tabs fetch fresh data only when opened again.
- **Cancel**: The native Cancel button stays at the bottom right on every tab, including while requests and comment submissions are pending. The footer remains at the popup's lower edge, with Add comment immediately to the left; the tab content scrolls above it.
- **Popup lifecycle**: Each popup owns its tab state and requests. Closing or removing it cancels pending requests and removes its handlers. Problem-table refreshes use one debounced observer.
- **Historical comparison**: Builds monthly incident comparisons for the same problem (last 6 months when available).
- **Pattern detection**: Aggregates related events to show hourly/weekday distributions, interactive heatmap filtering, and monthly/day drilldowns.
- **Time format behavior**: All pattern charts and filters use the Zabbix frontend/user timezone. The 12-month window includes the event month and the preceding 11 calendar months; last-week comparisons use the calendar week preceding the selected event's week.
- **Graph behavior**: Native `chart.php` renders the previews; `history.php` provides full interactive graphs and values. Selecting a preset period or changing Display immediately reloads the graphs. Apply appears only for Custom period, alongside the date inputs. Changing Display with Custom period also applies the current dates. Custom ranges use Zabbix's time parser and configured minimum/maximum period limits. Invalid periods keep the current graphs and inputs visible. Refresh preserves the active graph settings; refreshing another tab discards them with the inactive tab data.
- **Service impact matching**: Every service `problem_tags` rule must match an event tag, using Zabbix's case-sensitive equals/contains operators. Events without tags return no impacted services; inaccessible events and failed requests display an error.
- **SLA accuracy**: Service cards show every accessible configured SLA name and SLO target, with SLI for its current reporting period. Zabbix computes period boundaries using the SLA's reporting frequency and timezone; displayed timestamps are UTC. Disabled SLAs and unavailable SLI retain their configuration. Empty SLA results show "No accessible SLA configured"; failed lookups show "SLA information unavailable". No default SLA or target is invented.
- **JavaScript structure**: Popup behavior lives in `assets/js/analist-popup.js`. The PHP view includes it through Zabbix's native `CView::readJsFile()` popup response before mounting the controls, so an already-open page does not depend on a separately loaded popup asset. Reopening a popup reuses the runtime and preserves other popup instances.

Regression check instructions are in [tests/README.md](tests/README.md).

## **Example Report**
The image shows an analysis for the problem **"Event Details: AIX: Zabbix agent is not available (for 3m)"**, comparing last 6 months

This module enhances incident management by providing insights into recurring issues, resolution effectiveness, and acknowledgment rates.



![image 2](docs/img2.png)
![image 3](docs/img3.png)
![image 4](docs/img4.png)
![image 5](docs/img5.png)
![image 6](docs/img6.png)
![image 7](docs/img7.png)
![image 8](docs/img8.png)
