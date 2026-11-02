<?php

use App\Models\Sale;
use App\Models\User;
use App\Services\SaleService;
use App\Services\WebOrderFulfillmentService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// Independent Laravel process and MySQL connection. Never run against a working database.
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (app()->environment() !== 'testing' || config('database.connections.mysql.database') !== 'nalaka_cod_pickup_test_20261102') {
    throw new RuntimeException('Concurrency worker requires the dedicated isolated test database.');
}
try {
    $actor = User::query()->findOrFail((int) $argv[2]);
    // Prove the parent holds this exact business lock on a different connection.
    try {
        DB::transaction(fn () => DB::select('SELECT id FROM businesses WHERE owner_id = ? FOR UPDATE NOWAIT', [$actor->id]));
        throw new RuntimeException('The parent business lock was not held.');
    } catch (QueryException $e) {
        if (! in_array((int) ($e->errorInfo[1] ?? 0), [1205, 3572], true)) {
            throw $e;
        }
        echo "LOCK_BLOCKED\n";
        fflush(STDOUT);
    }
    if ($argv[1] === 'pickup') {
        $result = app(WebOrderFulfillmentService::class)->collectPickup($actor, $argv[3], $argv[4], '1000.00');
        echo json_encode(['ok' => true, 'sale_id' => $result['sale']->id, 'replayed' => $result['replayed']]);
    } elseif ($argv[1] === 'payment') {
        $sale = Sale::query()->findOrFail((int) $argv[3]);
        app(SaleService::class)->recordPayment($actor, $sale, [
            'operation_key' => $argv[4], 'method' => 'cash', 'amount' => '1.00', 'cash_tendered' => '1.00',
        ]);
        echo json_encode(['ok' => true, 'sale_id' => $sale->id]);
    } else {
        $sale = app(SaleService::class)->checkout($actor, [
            'operation_key' => $argv[4], 'location_id' => (int) $argv[3], 'source' => 'in_store_pos',
            'lines' => [['product_variant_id' => (int) $argv[5], 'quantity' => '4.000']],
            'payments' => [['method' => 'cash', 'amount' => '4000.00', 'cash_tendered' => '4000.00']],
        ], requireActiveShift: true);
        echo json_encode(['ok' => true, 'sale_id' => $sale->id]);
    }
} catch (Throwable $e) {
    fwrite(STDERR, $e::class.': '.$e->getMessage());
    exit(1);
}
