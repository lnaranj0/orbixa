<?php

namespace Modules\Work\Infrastructure;

use Illuminate\Database\DatabaseManager;

/**
 * 05 P7: flow metrics from work.item_status_changes over the items matched
 * by a caller-built predicate (alias i, values as bindings).
 * - throughput: items whose current category is done, by their last
 *   arrival in done within [from, to);
 * - lead time: creation -> last done; cycle time: first active -> last done;
 * - aging: open items (not done/canceled) since their first active entry.
 * Canceled items are never delivered.
 */
final class FlowMetricsCalculator
{
    public function __construct(private readonly DatabaseManager $db) {}

    /**
     * @param  list<mixed>  $bindings
     * @return array{throughput: int, lead_time_days: array{count: int, median: float|null, p85: float|null}, cycle_time_days: array{count: int, median: float|null, p85: float|null}, aging_days: array{count: int, median: float|null, p85: float|null}}
     */
    public function compute(string $predicate, array $bindings, string $from, string $to): array
    {
        $row = (array) $this->db->connection()->selectOne(
            "with scope as (
                 select i.id, i.created_at, i.status from work.items i where {$predicate}
             ), last_done as (
                 select c.item_id, max(c.occurred_at) as at from work.item_status_changes c join scope s on s.id = c.item_id
                  where c.to_category = 'done' group by c.item_id
             ), first_active as (
                 select c.item_id, min(c.occurred_at) as at from work.item_status_changes c join scope s on s.id = c.item_id
                  where c.to_category = 'active' group by c.item_id
             ), delivered as (
                 select extract(epoch from (d.at - s.created_at)) / 86400 as lead_days,
                        case when a.at is not null and a.at <= d.at then extract(epoch from (d.at - a.at)) / 86400 end as cycle_days
                   from scope s join last_done d on d.item_id = s.id left join first_active a on a.item_id = s.id
                  where s.status = 'done' and d.at >= ?::date and d.at < ?::date
             ), aging as (
                 select extract(epoch from (now() - a.at)) / 86400 as age_days
                   from scope s join first_active a on a.item_id = s.id
                  where s.status not in ('done', 'canceled')
             )
             select (select count(*) from delivered) as throughput,
                    (select count(*) from delivered) as lead_count,
                    (select percentile_cont(0.5) within group (order by lead_days) from delivered) as lead_median,
                    (select percentile_cont(0.85) within group (order by lead_days) from delivered) as lead_p85,
                    (select count(cycle_days) from delivered) as cycle_count,
                    (select percentile_cont(0.5) within group (order by cycle_days) from delivered where cycle_days is not null) as cycle_median,
                    (select percentile_cont(0.85) within group (order by cycle_days) from delivered where cycle_days is not null) as cycle_p85,
                    (select count(*) from aging) as aging_count,
                    (select percentile_cont(0.5) within group (order by age_days) from aging) as aging_median,
                    (select percentile_cont(0.85) within group (order by age_days) from aging) as aging_p85",
            [...$bindings, $from, $to]
        );
        $round = fn (mixed $value) => $value === null ? null : round((float) $value, 2);

        return [
            'throughput' => (int) $row['throughput'],
            'lead_time_days' => ['count' => (int) $row['lead_count'], 'median' => $round($row['lead_median']), 'p85' => $round($row['lead_p85'])],
            'cycle_time_days' => ['count' => (int) $row['cycle_count'], 'median' => $round($row['cycle_median']), 'p85' => $round($row['cycle_p85'])],
            'aging_days' => ['count' => (int) $row['aging_count'], 'median' => $round($row['aging_median']), 'p85' => $round($row['aging_p85'])],
        ];
    }

    /**
     * @return array{throughput: int, lead_time_days: array{count: int, median: float|null, p85: float|null}, cycle_time_days: array{count: int, median: float|null, p85: float|null}, aging_days: array{count: int, median: float|null, p85: float|null}}
     */
    public static function empty(): array
    {
        $none = ['count' => 0, 'median' => null, 'p85' => null];

        return ['throughput' => 0, 'lead_time_days' => $none, 'cycle_time_days' => $none, 'aging_days' => $none];
    }
}
