<?php

namespace Modules\AnalistProblem;

/** Calendar buckets use the frontend/user timezone, including its DST boundaries. */
final class PatternData {
    public static function build(array $events, int $from, int $to, int $reference): array {
        $month_keys = $month_days = [];
        $monthly = $daily = [];
        for ($month = strtotime(date('Y-m-01 00:00:00', $from)); $month <= $to;
                $month = strtotime('+1 month', $month)) {
            $key = date('Y-m', $month);
            $month_keys[] = $key;
            $month_days[] = (int) date('t', $month);
            $monthly[$key] = 0;
            $daily[$key] = array_fill(0, (int) date('t', $month), 0);
        }
        $hourly = array_fill(0, 24, 0);
        $weekly = array_fill(0, 7, 0);
        $heatmap = array_fill(0, 7, array_fill(0, 24, 0));
        $clocks = $slots = [];
        foreach ($events as $event) {
            $clock = (int) $event['clock'];
            if ($clock < $from || $clock > $to) continue;
            $slot = ['hour' => (int) date('G', $clock), 'weekday' => (int) date('w', $clock),
                'month' => date('Y-m', $clock), 'day' => (int) date('j', $clock), 'date' => date('Y-m-d', $clock)];
            $clocks[] = $clock;
            $slots[(string) $clock] = $slot;
            $hourly[$slot['hour']]++;
            $weekly[$slot['weekday']]++;
            $heatmap[$slot['weekday']][$slot['hour']]++;
            $monthly[$slot['month']]++;
            $daily[$slot['month']][$slot['day'] - 1]++;
        }
        $week_end = strtotime('monday this week 00:00:00', $reference);
        return ['hourlyData' => $hourly, 'weeklyData' => $weekly, 'weeklyHourlyDetails' => $heatmap,
            'eventClocks' => $clocks, 'eventSlots' => (object) $slots,
            'monthKeys' => $month_keys, 'monthDays' => $month_days,
            'monthlyData' => array_values($monthly), 'monthlyDailyDetails' => array_values($daily),
            'fromTs' => $from, 'toTs' => $to,
            'lastWeekStart' => strtotime('-1 week', $week_end), 'lastWeekEnd' => $week_end];
    }
}
