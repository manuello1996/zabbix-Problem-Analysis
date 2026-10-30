/* Each popup owns its requests, cached tabs and handlers. */
(() => {
    function initializePatterns(root, timePatternData) {
        const jQuery = selector => window.jQuery(selector, root);
        const hourlyData = timePatternData.hourlyData;
        const weeklyData = timePatternData.weeklyData;
        const weekdayLabels = timePatternData.weekLabels;
        const hourLabels = timePatternData.hourLabels;
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
                d3.select(root.querySelector(container)).selectAll("*").remove();

                var svg = d3.select(root.querySelector(container))
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
                var el = root.querySelector("#" + "mnz-investigation-heatmap");
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

                jQuery("#mnz-investigation-heatmap").off("click.analistPatterns", ".mnz-heatmap-cell-active").on("click.analistPatterns", ".mnz-heatmap-cell-active", function() {
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
                var el = root.querySelector("#" + elId);
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
                var c = root.querySelector("#" + containerId);
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
                var fb = root.querySelector("#" + "mnz-investigation-filter-bar");
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

                jQuery("#mnz-investigation-filter-bar .mnz-filter-clear").off("click.analistPatterns").on("click.analistPatterns", function() {
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

                jQuery("#mnz-investigation-monthly-chart").off("click.analistPatterns", ".mnz-bar-clickable").on("click.analistPatterns", ".mnz-bar-clickable", function() {
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
                        jQuery("#mnz-monthly-drilldown .mnz-drilldown-close").off("click.analistPatterns").on("click.analistPatterns", function() {
                            var c = jQuery("#mnz-monthly-drilldown");
                            c.removeClass("mnz-drilldown-visible").empty().append("<div class=\"mnz-drilldown-hint mnz-drilldown-hint-monthly\">" + (d.hintMonth || "") + "</div>");
                        });
                    }

                    jQuery("#mnz-monthly-drilldown").off("click.analistPatterns", ".mnz-drilldown-day-bar").on("click.analistPatterns", ".mnz-drilldown-day-bar", function() {
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
                        root.querySelector("#mnz-investigation-heatmap").scrollIntoView({block: "nearest"});
                    });
                });

                updateFilterBar();
            };

            currentAgg = computeAggregates(clocks);
            applyAndRender();
            return applyAndRender;
        };


        const renderAdvanced = initAdvancedTimePatterns();
        if (!renderAdvanced) createPatternCharts();
        return {redraw: renderAdvanced || createPatternCharts};
    }

    function createServiceUi(popup) {
        const root = popup.root;
        const serviceStatusLabels = popup.config.statusLabels;
        const slaLabels = popup.config.slaLabels;
        const labels = popup.config.serviceLabels;
        let currentServices = [];
        function escapeServiceHtml(value) {
            return String(value ?? "").replace(/&/g, "&amp;").replace(/</g, "&lt;")
                .replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/\x27/g, "&#39;");
        }

        function displayServicesTree(services) {
            const tree = root.querySelector('#services-tree-container');
            if (!tree) return;
            currentServices = services;
            const matchedIds = new Set(services.map(service => String(service.serviceid)));
            const relatedServices = new Map();
            services.forEach(service => (service.hierarchy_path || []).forEach(parent => {
                const id = String(parent.serviceid);
                if (!matchedIds.has(id) && !relatedServices.has(id)) relatedServices.set(id, parent);
            }));
            const relatedHtml = relatedServices.size
                ? '<details class="analist-related-services" data-services-related><summary>'
                    + escapeServiceHtml(labels.related) + ' (' + relatedServices.size + ')</summary>'
                    + '<div class="analist-services-list">'
                    + Array.from(relatedServices.values(), service => createServiceCard(service)).join('')
                    + '</div></details>' : '';
            tree.innerHTML = '<div class="services-summary-header"><h4>' + escapeServiceHtml(labels.matching)
                + ' (' + services.length + ')</h4><p>' + escapeServiceHtml(labels.current) + '</p></div>'
                + '<div class="analist-services-toolbar"><label>' + escapeServiceHtml(labels.search)
                + '<input type="search" data-services-search autocomplete="off"></label></div>'
                + '<div class="analist-services-list" data-services-matching>' + services.map(service => createServiceCard(service, true)).join('') + '</div>'
                + '<p data-services-search-empty role="status" hidden>' + escapeServiceHtml(labels.empty) + '</p>'
                + relatedHtml;
        }

        function filterServices(query) {
            const normalized = query.trim().toLocaleLowerCase();
            const ids = new Set(currentServices.filter(service =>
                [service.name, ...(service.hierarchy_path || []).map(parent => parent.name)]
                    .some(name => String(name || '').toLocaleLowerCase().includes(normalized)))
                .map(service => String(service.serviceid)));
            root.querySelectorAll('[data-services-matching] .service-card').forEach(card => {
                card.hidden = !ids.has(card.dataset.serviceid);
            });
            const empty = root.querySelector('[data-services-search-empty]');
            if (empty) empty.hidden = ids.size > 0;
        }

        function createHierarchyPath(path) {
            return '<div class="services-hierarchy-path"><span class="hierarchy-label">' + escapeServiceHtml(labels.hierarchy) + ':</span>'
                + path.map(service => '<button type="button" class="btn-link hierarchy-service" data-service-action="'
                    + escapeServiceHtml(service.serviceid) + '">' + escapeServiceHtml(service.name) + '</button>')
                    .join('<span class="hierarchy-arrow"> → </span>') + '</div>';
        }

        function createServiceSlaInfo(service) {
            const state = service.sla_state || (service.has_sla === false ? 'none'
                : service.has_sla === true ? 'configured' : 'unavailable');
            if (state === 'none') {
                return '<div class="service-no-sla">' + escapeServiceHtml(slaLabels.none) + '</div>';
            }
            if (state !== 'configured') {
                return '<div class="service-no-sla">' + escapeServiceHtml(slaLabels.unknown) + '</div>';
            }
            const slas = Array.isArray(service.slas) && service.slas.length ? service.slas : [{
                name: service.sla_name, slo: service.sla_info?.target?.replace(/%$/, ''),
                sli: service.sli, uptime: service.uptime, downtime: service.downtime, error_budget: service.error_budget
            }];
            return '<div class="service-sla-info">' + slas.map(sla => {
                const sli = Number(sla.sli);
                const available = sla.sli !== null && sla.sli !== undefined && sla.sli !== ''
                    && Number.isFinite(sli) && sli >= 0 && sli <= 100
                    && (!sla.sli_state || sla.sli_state === 'available');
                const rows = [];
                if (sla.name !== undefined && sla.name !== null) rows.push([slaLabels.sla, sla.name]);
                if (sla.slo !== undefined && sla.slo !== null) rows.push([slaLabels.target, String(sla.slo) + '%']);
                const period = sla.reporting_period;
                if (period && Number.isFinite(Number(period.period_from)) && Number.isFinite(Number(period.period_to))) {
                    const format = timestamp => new Date(Number(timestamp) * 1000).toISOString().slice(0, 16).replace('T', ' ');
                    rows.push([slaLabels.period, format(period.period_from) + ' – ' + format(period.period_to)]);
                }
                const target = Number(sla.slo);
                const comparison = available && sla.slo !== undefined && sla.slo !== null && Number.isFinite(target)
                    ? ' (' + (sli >= target ? slaLabels.met : slaLabels.below) + ')' : '';
                rows.push([slaLabels.sli, available ? sli.toFixed(2) + '%' + comparison
                    : sla.sli_state === 'disabled' ? slaLabels.disabled : slaLabels.unavailable]);
                if (available) rows.push([slaLabels.uptime, sla.uptime ?? 'N/A'],
                    [slaLabels.downtime, sla.downtime ?? 'N/A'], [slaLabels.budget, sla.error_budget ?? 'N/A']);
                return '<div class="service-sla-entry"><table class="list-table analist-sla-table"><tbody>' + rows.map(row =>
                    '<tr><th scope="row">' + escapeServiceHtml(row[0])
                    + '</th><td>' + escapeServiceHtml(row[1]) + '</td></tr>'
                ).join('') + '</tbody></table></div>';
            }).join('') + '</div>';
        }

        function serviceSlaSummary(service) {
            return service.slas?.length ? service.slas.length + ' ' + labels.slas
                : service.has_sla === false ? slaLabels.none : slaLabels.unknown;
        }

        function createServiceCard(service, isImpacted = false) {
            const id = escapeServiceHtml(service.serviceid);
            const path = service.hierarchy_path || [];
            const rules = isImpacted && service.problem_tags?.length
                ? '<div class="analist-service-rules"><table class="list-table analist-service-rules-table">'
                    + '<caption>' + escapeServiceHtml(labels.rules) + '</caption>'
                    + '<thead><tr><th scope="col">' + escapeServiceHtml(labels.tag) + '</th>'
                    + '<th scope="col">' + escapeServiceHtml(labels.operator) + '</th>'
                    + '<th scope="col">' + escapeServiceHtml(labels.value) + '</th></tr></thead><tbody>'
                    + service.problem_tags.map(rule => '<tr><td>' + escapeServiceHtml(rule.tag) + '</td><td>'
                        + escapeServiceHtml(Number(rule.operator) === labels.containsOperator ? labels.contains : labels.equals)
                        + '</td><td>' + escapeServiceHtml(rule.value) + '</td></tr>').join('') + '</tbody></table></div>' : '';
            return '<details class="service-card' + (isImpacted ? ' service-card-impacted' : '')
                + '" data-serviceid="' + id + '"><summary class="service-header"><span class="service-name">'
                + escapeServiceHtml(service.name) + '</span><span class="analist-service-summary">'
                + '<span class="analist-service-match">' + escapeServiceHtml(isImpacted ? labels.matched : labels.context) + '</span>'
                + '<span class="analist-service-match" data-service-sla-summary>' + escapeServiceHtml(serviceSlaSummary(service)) + '</span>'
                + '<span class="service-status ' + getServiceStatusClass(service.status) + '">'
                + escapeServiceHtml(getServiceStatusText(service.status)) + '</span></span></summary>'
                + '<div class="analist-service-details">' + (path.length > 1 ? createHierarchyPath(path) : '')
                + rules + '<h5 class="analist-service-details-heading">' + escapeServiceHtml(labels.details) + '</h5>'
                + createServiceSlaInfo(service) + '</div></details>';
        }

        function getServiceStatusClass(status) {
            return popup.config.statusStyles[status] || "status-grey";
        }

        function getServiceStatusText(status) {
            return serviceStatusLabels[status] || "Unknown";
        }

        function findServiceCard(serviceid) {
            const cards = root.querySelectorAll("#services-tree-container .service-card[data-serviceid]");
            return Array.from(cards).find(card => card.dataset.serviceid === String(serviceid));
        }

        function selectService(serviceid) {
            const tree = root.querySelector("#services-tree-container");
            if (!tree) return;
            tree.querySelectorAll(".service-card").forEach(card => {
                card.classList.remove("is-selected");
            });
            const selectedCard = findServiceCard(serviceid);
            if (selectedCard) {
                const related = selectedCard.closest("[data-services-related]");
                if (related) related.open = true;
                selectedCard.open = true;
                selectedCard.hidden = false;
                selectedCard.classList.add("is-selected");
                selectedCard.scrollIntoView({behavior: "smooth", block: "nearest"});
                popup.scheduleLayout();
            }
        }

        function displayNoServicesMessage() {
            const tree = root.querySelector("#services-tree-container");
            if (!tree) return;
            tree.innerHTML = '<div class="services-no-results"><p><strong>' + escapeServiceHtml(labels.none) + '</strong></p>'
                + '<p>' + escapeServiceHtml(labels.explanation) + '</p></div>';
        }

        return {displayServicesTree, displayNoServicesMessage, selectService, filterServices};
    }

    class AnalistProblemPopup {
        static instances = new Set();

        static mount(config) {
            const root = document.getElementById(config.rootId);
            if (!root) return;
            for (const instance of this.instances) {
                if (instance.root === root || !instance.root.isConnected) instance.destroy();
            }
            const instance = new this(root, config);
            this.instances.add(instance);
            return instance;
        }

        static cleanupDetached() {
            for (const instance of this.instances) {
                if (!instance.root.isConnected) instance.destroy();
            }
        }

        constructor(root, config) {
            this.root = root;
            this.config = config;
            this.closed = false;
            this.requests = new Set();
            this.lifecycle = new AbortController();
            this.tabs = new Map();
            this.panels = new Map(Array.from(root.querySelectorAll('[data-tab]'), panel => [panel.dataset.tab, panel]));
            this.tabs.set('overview', {loaded: true, promise: null});
            this.serviceUi = createServiceUi(this);
            this.patternRenderer = null;
            this.updatingProblem = false;
            this.layoutFrame = null;
            this.recoveryTimer = null;
            this.waitingForRecovery = false;
            this.recoveryDelay = 1000;
            this.dataVersion = 0;
            this.refreshPromise = null;
            this.historyRequests = new Map();
            this.graphOptions = {};
            this.graphRequest = null;
            root.addEventListener('click', event => this.onClick(event), {signal: this.lifecycle.signal});
            root.addEventListener('keydown', event => this.onKeyDown(event), {signal: this.lifecycle.signal});
            root.addEventListener('input', event => {
                if (event.target.matches('[data-services-search]')) this.serviceUi.filterServices(event.target.value);
            }, {signal: this.lifecycle.signal});
            root.addEventListener('change', event => {
                if (!event.target.matches('[data-graph-period]')) return;
                const range = event.target.closest('[data-graph-controls]')?.querySelector('[data-graph-custom-range]');
                if (range) range.hidden = event.target.value !== 'custom';
                this.scheduleLayout();
            }, {signal: this.lifecycle.signal});
            root.addEventListener('submit', event => {
                if (!event.target.matches('[data-graph-controls]')) return;
                event.preventDefault();
                event.stopPropagation();
                this.updateGraphs(event.target);
            }, {signal: this.lifecycle.signal});
            root.addEventListener('toggle', event => {
                if (event.target.matches('details.service-card, details.analist-related-services')) this.scheduleLayout();
            }, {capture: true, signal: this.lifecycle.signal});
            // Image sizing changes do not trigger Zabbix 7.0's content mutation observer.
            const imageSettled = event => {
                if (event.target.matches('img.chart-image')) this.scheduleLayout();
            };
            root.addEventListener('load', imageSettled, {capture: true, signal: this.lifecycle.signal});
            root.addEventListener('error', imageSettled, {capture: true, signal: this.lifecycle.signal});
            this.dialogue = root.closest('[data-dialogueid]');
            this.dialogue?.classList.add('analist-problem-dialogue');
            const closeButton = this.dialogue?.querySelector('.btn-overlay-close');
            if (closeButton) {
                this.refreshButton = document.createElement('button');
                this.refreshButton.type = 'button';
                this.refreshButton.className = 'btn-alt analist-popup-refresh';
                this.refreshButton.textContent = config.refreshLabel;
                this.refreshButton.addEventListener('click', event => {
                    event.preventDefault();
                    this.refreshTab();
                }, {signal: this.lifecycle.signal});
                closeButton.before(this.refreshButton);
            }
            const cancelButton = this.dialogue?.querySelector('.overlay-dialogue-footer .js-cancel');
            if (cancelButton) {
                this.commentButton = document.createElement('button');
                this.commentButton.type = 'button';
                this.commentButton.className = 'btn-alt analist-popup-comment';
                this.commentButton.textContent = config.addCommentLabel;
                this.commentButton.addEventListener('click', event => {
                    event.preventDefault();
                    this.openCommentForm();
                }, {signal: this.lifecycle.signal});
                cancelButton.before(this.commentButton);
            }
            this.dialogue?.addEventListener('dialogue.close', () => this.destroy(),
                {once: true, signal: this.lifecycle.signal});
            this.activateTab('overview');
            this.trackPendingRecovery();
        }

        syncCommentButton() {
            if (!this.commentButton || this.closed) return;
            const form = this.root.querySelector('[data-comment-form]');
            this.commentButton.hidden = !this.config.problemActions?.canComment;
            this.commentButton.disabled = this.updatingProblem || this.refreshPromise !== null;
            this.commentButton.setAttribute('aria-expanded', String(Boolean(form && !form.hidden && this.activeTab === 'update')));
            if (form) this.commentButton.setAttribute('aria-controls', form.id);
            else this.commentButton.removeAttribute('aria-controls');
        }

        async openCommentForm() {
            if (this.closed || this.updatingProblem || this.refreshPromise || !this.config.problemActions?.canComment) return;
            await this.activateTab('update');
            if (this.closed || this.activeTab !== 'update' || !this.config.problemActions.canComment
                    || !this.tabs.get('update')?.loaded) return;
            const form = this.root.querySelector('[data-comment-form]');
            if (!form) return;
            form.hidden = false;
            this.syncCommentButton();
            this.root.querySelector('[data-problem-comment]')?.focus();
            this.scheduleLayout();
        }

        trackPendingRecovery() {
            if (this.closed || !this.config.problemActions?.closingPending
                    || this.config.problemActions.recoveryReady) return;
            this.waitingForRecovery = true;
            const panel = this.panels.get('overview');
            const status = panel.querySelector('[data-problem-status]');
            if (status) {
                status.textContent = this.config.problemActions.closingStatusLabel;
                status.className = 'color-warning';
            }
            if (!panel.querySelector('[data-recovery-row]')) {
                panel.querySelector('[data-age-row]')?.remove();
                panel.querySelector('.analist-problem-detail-table tbody')
                    ?.insertAdjacentHTML('beforeend', this.config.problemActions.pendingRecoveryRow);
            }
            const message = this.root.querySelector('[data-problem-action-message]');
            if (message) message.textContent = this.config.problemActions.closingLabel;
            this.scheduleLayout();
            this.scheduleRecoveryRefresh();
        }

        onClick(event) {
            const button = event.target.closest('[data-tab-target], [data-retry-tab], [data-service-action], [data-problem-action], [data-history-more], .mnz-pattern-toggle');
            if (!button || !this.root.contains(button)) return;
            if (button.dataset.tabTarget) {
                event.preventDefault();
                this.activateTab(button.dataset.tabTarget);
            } else if (button.dataset.retryTab) {
                event.preventDefault();
                this.loadTab(button.dataset.retryTab);
            } else if (button.dataset.serviceAction) {
                event.preventDefault();
                this.serviceUi.selectService(button.dataset.serviceAction);
            } else if (button.hasAttribute('data-history-more')) {
                event.preventDefault();
                this.loadMoreHistory(button);
            } else if (button.dataset.problemAction) {
                event.preventDefault();
                this.updateProblem(button.dataset.problemAction);
            } else {
                event.preventDefault();
                const target = Array.from(this.root.querySelectorAll('.mnz-pattern-collapsible-body'))
                    .find(body => body.id === button.dataset.target);
                if (!target) return;
                const expanded = target.style.display === 'none';
                target.style.display = expanded ? 'block' : 'none';
                button.textContent = expanded ? this.config.collapseLabel : this.config.expandLabel;
                button.setAttribute('aria-expanded', String(expanded));
                if (expanded) this.patternRenderer?.redraw();
            }
        }

        onKeyDown(event) {
            if (!event.target.matches('[data-tab-target]')) return;
            const buttons = Array.from(this.root.querySelectorAll('[data-tab-target]'));
            const index = buttons.indexOf(event.target);
            let next;
            switch (event.key) {
                case 'ArrowRight': next = (index + 1) % buttons.length; break;
                case 'ArrowLeft': next = (index + buttons.length - 1) % buttons.length; break;
                case 'Home': next = 0; break;
                case 'End': next = buttons.length - 1; break;
                default: return;
            }
            event.preventDefault();
            buttons[next].focus();
            this.activateTab(buttons[next].dataset.tabTarget);
        }

        activateTab(key) {
            if (this.closed || !this.panels.has(key)) return;
            this.root.querySelectorAll('[data-tab-target]').forEach(button => {
                const active = button.dataset.tabTarget === key;
                button.setAttribute('aria-selected', String(active));
                button.tabIndex = active ? 0 : -1;
                button.parentElement.classList.toggle('ui-tabs-active', active);
                button.parentElement.classList.toggle('ui-state-active', active);
            });
            for (const [name, panel] of this.panels) {
                panel.style.display = name === key ? 'block' : 'none';
                panel.setAttribute('aria-hidden', String(name !== key));
            }
            this.activeTab = key;
            this.syncCommentButton();
            this.scheduleLayout();
            return this.loadTab(key);
        }

        scheduleLayout() {
            if (this.closed || this.layoutFrame !== null) return;
            this.layoutFrame = window.requestAnimationFrame(() => {
                this.layoutFrame = null;
                if (this.closed || !this.root.isConnected || !this.dialogue) return;
                const overlay = window.overlays_stack?.getById(this.dialogue.dataset.dialogueid);
                if (overlay?.$dialogue?.[0] === this.dialogue) overlay.centerDialog?.();
            });
        }

        invalidateTabs() {
            this.dataVersion++;
            if (this.activeTab !== 'graphs') this.graphOptions = {};
            this.graphRequest = null;
            if (this.recoveryTimer !== null) window.clearTimeout(this.recoveryTimer);
            this.recoveryTimer = null;
            for (const request of this.requests) request.abort();
            this.historyRequests.clear();
            this.tabs.clear();
            this.patternRenderer = null;
            if (window.jQuery) {
                window.jQuery(this.root).find('*').addBack().off('.analistPatterns').stop(true, true);
            }
            for (const panel of this.panels.values()) {
                panel.replaceChildren();
                panel.removeAttribute('aria-busy');
            }
            this.serviceUi = createServiceUi(this);
            this.syncCommentButton();
        }

        refreshTab() {
            if (this.closed || this.updatingProblem) return Promise.resolve();
            if (this.refreshPromise) return this.refreshPromise;
            const key = this.activeTab;
            this.invalidateTabs();
            if (this.refreshButton) this.refreshButton.disabled = true;
            this.refreshPromise = this.loadTab(key).finally(() => {
                this.refreshPromise = null;
                if (!this.closed && this.refreshButton) this.refreshButton.disabled = false;
                this.syncCommentButton();
            });
            this.syncCommentButton();
            return this.refreshPromise;
        }

        loadTab(key) {
            if (this.closed || !this.panels.has(key)) return Promise.resolve();
            let state = this.tabs.get(key);
            if (!state) {
                state = {loaded: false, promise: null};
                this.tabs.set(key, state);
            }
            if (state.loaded) return Promise.resolve();
            if (state.promise) return state.promise;
            const panel = this.panels.get(key);
            panel.textContent = this.config.loadingLabel;
            panel.setAttribute('aria-busy', 'true');
            const action = key === 'services' ? 'problemanalist.service.get' : 'problemanalist.tab.get';
            const params = {eventid: this.config.eventData.eventid};
            if (key !== 'services') {
                params.tab = key;
                if (this.config.eventData.hostid) params.hostid = this.config.eventData.hostid;
            }
            if (key === 'graphs') Object.assign(params, this.graphOptions);
            state.promise = this.fetchJson(action, params).then(result => {
                if (this.closed || this.tabs.get(key) !== state) return;
                if (key === 'services') {
                    if (!result.success || !Array.isArray(result.data)) throw new Error(result.error?.message || 'Unable to load services');
                    panel.innerHTML = '<div id="services-tree-container" class="services-container"></div>';
                    if (result.data.length) this.serviceUi.displayServicesTree(result.data);
                    else this.serviceUi.displayNoServicesMessage();
                } else {
                    if (typeof result.body !== 'string') throw new Error('Invalid tab response');
                    panel.innerHTML = result.body;
                    if (key === 'update' && result.comment_actions) {
                        Object.assign(this.config.problemActions, result.comment_actions);
                    }
                    if (key === 'overview' && result.problem_actions) {
                        if (this.waitingForRecovery && !result.problem_actions.recoveryReady) {
                            result.problem_actions.closingPending = true;
                        }
                        this.config.problemActions = result.problem_actions;
                        if (result.problem_actions.recoveryReady) {
                            this.waitingForRecovery = false;
                            if (this.recoveryTimer !== null) window.clearTimeout(this.recoveryTimer);
                            this.recoveryTimer = null;
                        } else {
                            this.trackPendingRecovery();
                        }
                    }
                    if (key === 'patterns') {
                        if (!result.pattern_data) throw new Error('Invalid pattern response');
                        this.patternRenderer = initializePatterns(this.root, result.pattern_data);
                    }
                }
                state.loaded = true;
                this.scheduleLayout();
            }).catch(error => {
                if (!this.closed && this.tabs.get(key) === state && error.name !== 'AbortError') {
                    this.showError(panel, key, error);
                }
            }).finally(() => {
                state.promise = null;
                if (!this.closed && this.tabs.get(key) === state) {
                    panel.removeAttribute('aria-busy');
                    this.syncCommentButton();
                    this.scheduleLayout();
                }
            });
            return state.promise;
        }

        async fetchJson(action, params) {
            if (this.closed) throw new DOMException('Popup closed', 'AbortError');
            const controller = new AbortController();
            this.requests.add(controller);
            try {
                const response = await fetch('zabbix.php?action=' + encodeURIComponent(action), {
                    method: 'POST', body: new URLSearchParams(params), signal: controller.signal, cache: 'no-store'
                });
                if (!response.ok) throw new Error('HTTP error! status: ' + response.status);
                const result = await response.json();
                if (this.closed || controller.signal.aborted) throw new DOMException('Request cancelled', 'AbortError');
                if (result.error) throw new Error(result.error.message || result.error.messages?.join(' ') || 'Unable to load tab');
                return result;
            } finally {
                this.requests.delete(controller);
            }
        }

        updateGraphs(form) {
            if (this.closed || !this.root.contains(form)) return Promise.resolve();
            if (this.graphRequest) return this.graphRequest;
            const options = {
                graph_period: form.querySelector('[name="graph_period"]').value,
                graph_layout: form.querySelector('[name="graph_layout"]')?.value ?? 'items'
            };
            if (options.graph_period === 'custom') {
                options.graph_from = form.querySelector('[data-graph-from] input').value;
                options.graph_to = form.querySelector('[data-graph-to] input').value;
            }
            const version = this.dataVersion;
            const state = this.tabs.get('graphs');
            const submit = form.querySelector('[type="submit"]');
            const message = form.querySelector('[data-graph-message]');
            message.textContent = '';
            submit.disabled = true;
            form.setAttribute('aria-busy', 'true');
            const request = this.fetchJson('problemanalist.tab.get', {
                eventid: this.config.eventData.eventid, tab: 'graphs', ...options,
                ...(this.config.eventData.hostid ? {hostid: this.config.eventData.hostid} : {})
            }).then(result => {
                if (this.closed || version !== this.dataVersion || this.tabs.get('graphs') !== state) return;
                if (typeof result.body !== 'string') throw new Error('Invalid graph response');
                this.panels.get('graphs').innerHTML = result.body;
                this.graphOptions = options;
                this.scheduleLayout();
            }).catch(error => {
                if (!this.closed && version === this.dataVersion && error.name !== 'AbortError'
                        && this.root.contains(form)) message.textContent = error.message;
            }).finally(() => {
                if (this.graphRequest === request) this.graphRequest = null;
                if (!this.closed && version === this.dataVersion && this.root.contains(form)) {
                    submit.disabled = false;
                    form.removeAttribute('aria-busy');
                    this.scheduleLayout();
                }
            });
            this.graphRequest = request;
            return request;
        }

        loadMoreHistory(button) {
            const row = button.closest('[data-history-item]');
            if (this.closed || !row || !this.root.contains(row)) return Promise.resolve();
            const itemid = row.dataset.historyItem;
            if (this.historyRequests.has(row)) return this.historyRequests.get(row);
            const values = row.querySelector('[data-history-values]');
            const message = row.querySelector('[data-history-message]');
            const version = this.dataVersion;
            button.disabled = true;
            row.setAttribute('aria-busy', 'true');
            if (message) message.textContent = '';
            const request = this.fetchJson('problemanalist.tab.get', {
                eventid: this.config.eventData.eventid, tab: 'history', itemid,
                before_clock: button.dataset.beforeClock, before_ns: button.dataset.beforeNs
            }).then(result => {
                if (this.closed || version !== this.dataVersion || !this.root.contains(row)) return;
                if (typeof result.body !== 'string' || typeof result.has_more !== 'boolean'
                        || (result.has_more && !result.cursor)) throw new Error('Invalid history response');
                values.insertAdjacentHTML('beforeend', result.body);
                if (result.cursor) {
                    button.dataset.beforeClock = result.cursor.clock;
                    button.dataset.beforeNs = result.cursor.ns;
                }
                button.hidden = !result.has_more;
                if (!result.body && message) message.textContent = this.config.noMoreHistoryLabel;
                this.scheduleLayout();
            }).catch(error => {
                if (!this.closed && version === this.dataVersion && this.root.contains(row)
                        && error.name !== 'AbortError' && message) {
                    message.textContent = error.message;
                }
            }).finally(() => {
                if (this.historyRequests.get(row) === request) this.historyRequests.delete(row);
                if (!this.closed && version === this.dataVersion && this.root.contains(row)) {
                    button.disabled = false;
                    row.removeAttribute('aria-busy');
                    this.scheduleLayout();
                }
            });
            this.historyRequests.set(row, request);
            return request;
        }

        showError(panel, key, error) {
            panel.textContent = '';
            const message = document.createElement('p');
            message.setAttribute('role', 'alert');
            message.textContent = error.message || String(error);
            const retry = document.createElement('button');
            retry.type = 'button';
            retry.className = 'btn-alt';
            retry.dataset.retryTab = key;
            retry.textContent = this.config.retryLabel;
            panel.append(message, retry);
        }

        async updateProblem(action) {
            const actions = this.config.problemActions;
            if (this.closed || this.updatingProblem || this.refreshPromise || !actions) return;
            const commenting = action === 'comment';
            const comment = commenting ? this.root.querySelector('[data-problem-comment]') : null;
            const messageSelector = commenting ? '[data-comment-message]' : '[data-problem-action-message]';
            const message = this.root.querySelector(messageSelector);
            const fields = {
                acknowledge: ['acknowledge_problem', actions.acknowledgeAction],
                unacknowledge: ['unacknowledge_problem', actions.unacknowledgeAction],
                close: ['close_problem', actions.closeAction],
                'severity-up': ['change_severity', actions.severityAction],
                'severity-down': ['change_severity', actions.severityAction],
                comment: ['message', comment?.value]
            };
            const changingSeverity = action === 'severity-up' || action === 'severity-down';
            if (!fields[action] || !(commenting ? actions.canComment : changingSeverity ? actions.canChangeSeverity
                    : action === 'close' ? actions.canClose : actions.canAcknowledge)) return;
            if (commenting && !comment?.value.trim()) {
                if (message) message.textContent = actions.emptyCommentLabel;
                comment?.focus();
                return;
            }
            const nextSeverity = Number(actions.severity) + (action === 'severity-up' ? 1 : -1);
            if (changingSeverity && (!Number.isInteger(nextSeverity)
                    || nextSeverity < actions.minSeverity || nextSeverity > actions.maxSeverity)) return;
            this.updatingProblem = true;
            this.syncCommentButton();
            if (this.refreshButton) this.refreshButton.disabled = true;
            if (comment) comment.disabled = true;
            const buttons = Array.from(this.root.querySelectorAll('[data-problem-action]'));
            const disabledStates = buttons.map(button => button.disabled);
            buttons.forEach(button => { button.disabled = true; });
            if (message) message.textContent = '';
            let updated = false;
            try {
                const [field, value] = fields[action];
                const result = await this.fetchJson('popup.acknowledge.create', {
                    'eventids[]': this.config.eventData.eventid,
                    scope: actions.selectedScope,
                    [actions.csrfTokenName]: actions.csrfToken,
                    [field]: value,
                    ...(changingSeverity ? {severity: nextSeverity} : {})
                });
                if (!result.success) throw new Error('Unable to update event');
                updated = true;
                if (action === 'close') {
                    this.config.problemActions.closingPending = true;
                    this.trackPendingRecovery();
                }
                // Discard stale panels and fetch only the tab needed after the update.
                this.invalidateTabs();
                const targetTab = commenting ? 'update' : 'overview';
                await this.activateTab(targetTab);
                const refreshedMessage = this.root.querySelector(messageSelector);
                if (refreshedMessage) refreshedMessage.textContent = commenting ? actions.commentAddedLabel : actions.updatedLabel;
                if (action === 'close' && !this.config.problemActions.recoveryReady) {
                    this.config.problemActions.closingPending = true;
                    this.trackPendingRecovery();
                }
                if (!this.tabs.get(targetTab)?.loaded && !this.closed) {
                    const panel = this.panels.get(targetTab);
                    panel.querySelector('[role="alert"]')?.replaceChildren(actions.refreshErrorLabel);
                }
                if (window.jQuery) window.jQuery.publish?.('acknowledge.create', [result]);
            } catch (error) {
                if (!this.closed && error.name !== 'AbortError' && message) message.textContent = error.message;
            } finally {
                this.updatingProblem = false;
                this.syncCommentButton();
                if (!this.closed && this.refreshButton) this.refreshButton.disabled = false;
                if (!this.closed && comment) comment.disabled = false;
                if (!updated && !this.closed) buttons.forEach((button, index) => { button.disabled = disabledStates[index]; });
            }
        }

        scheduleRecoveryRefresh() {
            if (this.closed || !this.waitingForRecovery || this.recoveryTimer !== null || !this.tabs.has('overview')) return;
            const version = this.dataVersion;
            this.recoveryTimer = window.setTimeout(async () => {
                this.recoveryTimer = null;
                if (this.closed) return;
                try {
                    const params = {eventid: this.config.eventData.eventid, tab: 'overview'};
                    if (this.config.eventData.hostid) params.hostid = this.config.eventData.hostid;
                    const result = await this.fetchJson('problemanalist.tab.get', params);
                    if (version !== this.dataVersion) return;
                    if (typeof result.body !== 'string' || !result.problem_actions) throw new Error('Invalid overview response');
                    // Refresh the existing panel without changing the tab the user is viewing.
                    this.panels.get('overview').innerHTML = result.body;
                    this.config.problemActions = result.problem_actions;
                    this.syncCommentButton();
                    this.tabs.set('overview', {loaded: true, promise: null});
                    this.waitingForRecovery = !result.problem_actions.recoveryReady;
                    if (this.waitingForRecovery) {
                        this.config.problemActions.closingPending = true;
                        this.trackPendingRecovery();
                    }
                    const message = this.root.querySelector('[data-problem-action-message]');
                    if (message) message.textContent = this.waitingForRecovery
                        ? result.problem_actions.closingLabel : result.problem_actions.updatedLabel;
                    this.scheduleLayout();
                } catch (error) {
                    if (this.closed || version !== this.dataVersion) return;
                    const message = this.root.querySelector('[data-problem-action-message]');
                    if (message) message.textContent = this.config.problemActions.closingLabel + ' ' + error.message;
                    // A transient read failure must not lose tracking of an accepted manual close.
                }
                this.recoveryDelay = Math.min(this.recoveryDelay * 2, 5000);
                this.scheduleRecoveryRefresh();
            }, this.recoveryDelay);
        }

        destroy() {
            if (this.closed) return;
            this.closed = true;
            if (this.recoveryTimer !== null) window.clearTimeout(this.recoveryTimer);
            this.recoveryTimer = null;
            this.waitingForRecovery = false;
            if (this.layoutFrame !== null) window.cancelAnimationFrame(this.layoutFrame);
            this.layoutFrame = null;
            this.lifecycle.abort();
            this.dialogue?.classList.remove('analist-problem-dialogue');
            this.refreshButton?.remove();
            this.commentButton?.remove();
            for (const controller of this.requests) controller.abort();
            this.requests.clear();
            if (window.jQuery) {
                window.jQuery(this.root).find('*').addBack().off('.analistPatterns').stop(true, true);
            }
            this.tabs.clear();
            this.historyRequests.clear();
            this.graphRequest = null;
            this.graphOptions = {};
            this.patternRenderer = null;
            AnalistProblemPopup.instances.delete(this);
        }
    }

    window.AnalistProblemPopup = AnalistProblemPopup;
})();
