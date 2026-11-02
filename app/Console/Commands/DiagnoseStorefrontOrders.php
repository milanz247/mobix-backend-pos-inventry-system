<?php

namespace App\Console\Commands;

use App\Models\WebOrder;
use App\Services\WebOrderRecoveryService;
use Illuminate\Console\Command;

class DiagnoseStorefrontOrders extends Command
{
    protected $signature = 'storefront:diagnose-orders {--max=500 : Maximum orders to inspect} {--after=0 : Continue after this order row ID}';

    protected $description = 'Read-only storefront order and reservation consistency report';

    public function handle(WebOrderRecoveryService $recovery): int
    {
        $max = filter_var($this->option('max'), FILTER_VALIDATE_INT);
        $after = filter_var($this->option('after'), FILTER_VALIDATE_INT);
        if ($max === false || $max < 1 || $max > 5000 || $after === false || $after < 0) {
            $this->error('Use --max between 1 and 5000 and a nonnegative --after row ID.');

            return self::FAILURE;
        }
        $counts = [];
        $examined = 0;
        $lastId = $after;
        $orders = WebOrder::query()->where('id', '>', $after)->orderBy('id')->limit($max)->get();
        foreach ($orders as $order) {
            $lastId = $order->id;
            $examined++;
            foreach ($recovery->issues($order) as $issue) {
                $counts[$issue] = ($counts[$issue] ?? 0) + 1;
            }
        }
        $counts['orphan_reservation'] = $recovery->orphanReservationCount();
        ksort($counts);
        $this->line('Orders examined: '.$examined.'; last row ID: '.$lastId.'.');
        $this->table(['Issue code', 'Count'], array_map(fn ($code, $count): array => [$code, $count], array_keys($counts), array_values($counts)));

        return self::SUCCESS;
    }
}
