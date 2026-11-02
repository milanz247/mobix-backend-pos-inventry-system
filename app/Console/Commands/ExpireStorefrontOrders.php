<?php

namespace App\Console\Commands;

use App\Models\WebOrder;
use App\Services\WebOrderExpiryProcessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExpireStorefrontOrders extends Command
{
    protected $signature = 'storefront:expire-orders {--batch=100 : Rows to fetch per page} {--max=1000 : Maximum orders examined per run} {--resume : Continue a bounded scheduled scan from its prior row}';

    protected $description = 'Expire eligible pending storefront orders and release reservations safely';

    public function handle(WebOrderExpiryProcessor $processor): int
    {
        $batch = filter_var($this->option('batch'), FILTER_VALIDATE_INT);
        $max = filter_var($this->option('max'), FILTER_VALIDATE_INT);
        if ($batch === false || $batch < 1 || $batch > 500 || $max === false || $max < 1 || $max > 5000) {
            $this->error('Use --batch between 1 and 500 and --max between 1 and 5000.');

            return self::FAILURE;
        }
        $counts = ['examined' => 0, 'expired' => 0, 'reservations_released' => 0,
            'skipped' => 0, 'manual_review' => 0, 'failures' => 0];
        $lastId = $this->option('resume') ? (int) Cache::get('storefront:expiry-scan:last-id', 0) : 0;
        $exhausted = false;
        while ($counts['examined'] < $max) {
            $orders = WebOrder::query()->where('id', '>', $lastId)
                ->where('status', 'pending_confirmation')
                ->where(function ($query): void {
                    $query->where('reservation_expires_at', '<=', DB::raw('CURRENT_TIMESTAMP'))
                        ->orWhere('cod_confirmation_deadline_at', '<=', DB::raw('CURRENT_TIMESTAMP'))
                        ->orWhere('bank_proof_deadline_at', '<=', DB::raw('CURRENT_TIMESTAMP'));
                })->orderBy('id')->limit(min($batch, $max - $counts['examined']))
                ->get(['id', 'business_id']);
            if ($orders->isEmpty()) {
                $exhausted = true;
                break;
            }
            foreach ($orders as $order) {
                $lastId = $order->id;
                $counts['examined']++;
                try {
                    $result = $processor->process($order->business_id, $order->id);
                    $counts[$result['outcome']]++;
                    $counts['reservations_released'] += $result['reservations_released'];
                } catch (\Throwable) {
                    $counts['failures']++;
                }
            }
        }
        if ($this->option('resume')) {
            Cache::put('storefront:expiry-scan:last-id', $exhausted ? 0 : $lastId);
        }
        $this->table(['Examined', 'Expired', 'Reservations released', 'Skipped', 'Manual review', 'Failures'],
            [array_values($counts)]);
        Log::info('Storefront order expiry completed', $counts);

        return $counts['failures'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
