<?php

use App\Models\Business;
use App\Models\InventoryStock;
use App\Models\Sale;
use App\Models\SalePayment;
use App\Models\WebOrderFulfillment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

require_once __DIR__.'/../Support/CodPickupFixture.php';

pest()->extend(TestCase::class);

beforeEach(function () {
    if (config('database.default') !== 'mysql' || config('database.connections.mysql.database') !== 'nalaka_cod_pickup_test_20261102') {
        throw new RuntimeException('Use only the dedicated isolated COD pickup test database.');
    }
    $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
});

test('independent MySQL connections serialize duplicate handover and POS contention', function (string $contender) {
    $withPos = $contender === 'pos';
    $f = codPickupFixture($this);
    readyCodPickup($f);
    $key = (string) Str::uuid();
    $worker = base_path('tests/Support/cod-pickup-worker.php');
    $first = new Process([PHP_BINARY, $worker, 'pickup', (string) $f['actor']->id, $f['order']->reference, $key]);
    $second = new Process($withPos
        ? [PHP_BINARY, $worker, 'pos', (string) $f['actor']->id, (string) $f['location']->id, (string) Str::uuid(), (string) $f['variant']->id]
        : [PHP_BINARY, $worker, 'pickup', (string) $f['actor']->id, $f['order']->reference, $key]);
    if ($contender === 'payment') {
        $posSale = Sale::factory()->create(['business_id' => $f['business']->id, 'location_id' => $f['location']->id,
            'cashier_id' => $f['actor']->id, 'paid_total' => '0.00', 'payment_status' => 'unpaid']);
        $second = new Process([PHP_BINARY, $worker, 'payment', (string) $f['actor']->id, (string) $posSale->id, (string) Str::uuid()]);
    }
    DB::beginTransaction();
    Business::query()->whereKey($f['business']->id)->lockForUpdate()->firstOrFail();
    try {
        $first->start();
        $second->start();
        // Both independent connections must prove NOWAIT refuses the parent's lock.
        $deadline = microtime(true) + 10;
        do {
            $blocked = str_starts_with($first->getOutput(), "LOCK_BLOCKED\n")
                && str_starts_with($second->getOutput(), "LOCK_BLOCKED\n");
            if ($blocked) {
                break;
            }
            usleep(50000);
        } while (microtime(true) < $deadline && $first->isRunning() && $second->isRunning());
        expect($blocked)->toBeTrue()->and($first->isRunning())->toBeTrue()->and($second->isRunning())->toBeTrue();
    } finally {
        DB::commit();
    }
    $first->wait();
    $second->wait();
    expect($first->getErrorOutput())->toBe('')->and($second->getErrorOutput())->toBe('')
        ->and($first->isSuccessful())->toBeTrue()->and($second->isSuccessful())->toBeTrue();
    $a = json_decode(substr($first->getOutput(), strlen("LOCK_BLOCKED\n")), true, 512, JSON_THROW_ON_ERROR);
    $b = json_decode(substr($second->getOutput(), strlen("LOCK_BLOCKED\n")), true, 512, JSON_THROW_ON_ERROR);
    expect(WebOrderFulfillment::query()->count())->toBe(1)
        ->and(Sale::query()->whereNotNull('web_order_id')->count())->toBe(1)
        ->and(SalePayment::query()->whereNotNull('web_cod_order_id')->count())->toBe(1)
        ->and((string) InventoryStock::query()->where('product_variant_id', $f['variant']->id)->value('quantity_on_hand'))
        ->toBe($withPos ? '0.000' : '4.000');
    if ($contender === 'pickup') {
        expect($a['sale_id'])->toBe($b['sale_id'])->and($a['replayed'] !== $b['replayed'])->toBeTrue();
    }
    if ($contender === 'payment') {
        expect((string) $posSale->fresh()->paid_total)->toBe('1.00');
    }
})->with(['pickup', 'pos', 'payment']);
