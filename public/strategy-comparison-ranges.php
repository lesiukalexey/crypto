<?php
declare(strict_types=1);

function strategyComparisonRanges(DateTimeImmutable $start, DateTimeImmutable $end, DateTimeImmutable $today, int $limit = 11): array
{
    if ($end < $start || $start > $today) throw new InvalidArgumentException('Invalid comparison dates.');
    $days = (int) $start->diff($end)->format('%a') + 1;
    $ranges = [];
    for ($offset = -5; $offset <= $limit - 6; $offset++) {
        $shift = $offset * $days;
        $rangeStart = $start->modify(sprintf('%+d days', $shift));
        $rangeEnd = $end->modify(sprintf('%+d days', $shift));
        if ($rangeStart > $today) break;
        $partial = $rangeEnd > $today;
        if ($partial) $rangeEnd = $today;
        $ranges[] = ['offset'=>$offset, 'start_day'=>$rangeStart->format('Y-m-d'),
            'end_day'=>$rangeEnd->format('Y-m-d'), 'days'=>(int) $rangeStart->diff($rangeEnd)->format('%a') + 1,
            'partial'=>$partial];
    }
    return $ranges;
}
