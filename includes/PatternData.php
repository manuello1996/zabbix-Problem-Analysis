<?php

namespace Modules\AnalistProblem;

/** Calendar buckets use the frontend/user timezone, including its DST boundaries. */
final class PatternData {
    public static function build(array $events, int $from, int $to, int $reference): array {
        $month_keys = $month_days = $month_indexes = [];
        for ($month = strtotime(date('Y-m-01 00:00:00', $from)); $month <= $to;
                $month = strtotime('+1 month', $month)) {
            $key = date('Y-m', $month);
            $month_indexes[$key] = count($month_keys);
            $month_keys[] = $key;
            $month_days[] = (int) date('t', $month);
        }
        $heatmap = array_fill(0, 7, array_fill(0, 24, 0));
        $last_week = $heatmap;
        $week_end = strtotime('monday this week 00:00:00', $reference);
        $week_start = strtotime('-1 week', $week_end);
        $buckets = [];
        $total = $scale_max = 0;
        foreach ($events as $event) {
            $clock = (int) $event['clock'];
            if ($clock < $from || $clock > $to) continue;
            $date = date('Y-m-d', $clock);
            $hour = (int) date('G', $clock);
            $key = $date.'-'.$hour;
            if (!isset($buckets[$key])) {
                // month index, day of month, weekday, hour, occurrence count.
                $buckets[$key] = [$month_indexes[substr($date, 0, 7)], (int) substr($date, 8),
                    (int) date('w', $clock), $hour, 0];
            }
            $weekday = $buckets[$key][2];
            $buckets[$key][4]++;
            $scale_max = max($scale_max, ++$heatmap[$weekday][$hour]);
            if ($clock >= $week_start && $clock < $week_end) $last_week[$weekday][$hour]++;
            $total++;
        }
        return ['buckets' => array_values($buckets), 'total' => $total, 'scaleMax' => $scale_max,
            'monthKeys' => $month_keys, 'monthDays' => $month_days,
            'fromTs' => $from, 'toTs' => $to,
            'lastWeekCounts' => $last_week, 'lastWeekStart' => $week_start, 'lastWeekEnd' => $week_end];
    }
}
