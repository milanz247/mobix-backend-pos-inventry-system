<?php

use App\Models\Business;
use App\Models\WebOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

require_once __DIR__.'/../Support/UsedDeviceContinuityFixture.php';

pest()->extend(TestCase::class);

test('independent MySQL acceptances serialize one physical device and preserve replay', function () {
    if (app()->configurationIsCached() || ! app()->environment('testing') || config('database.default') !== 'mysql'
        || config('database.connections.mysql.database') !== 'nalaka_mobile_device_continuity_test'
        || DB::selectOne('SELECT DATABASE() AS db')->db !== 'nalaka_mobile_device_continuity_test') {
        throw new RuntimeException('Use only the dedicated isolated used-device database.');
    }
    $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
    if (Business::query()->exists()) {
        throw new RuntimeException('Use an empty disposable database; this test does not delete existing records.');
    }
    $f = usedContinuityFixture($this);
    $first = usedContinuityOffer($this, $f)->assertCreated();
    $selection = selectFixtureDevice($this, $f['product'], $f['variant'], $f['device']);
    $added = $this->postJson('/api/storefront/v1/cart/items', [
        'product_id' => $f['product']->id, 'variant_id' => $f['variant']->id,
        'quantity' => 1, 'device_selection_token' => $selection,
    ])->assertCreated();
    $other = [...$f, 'headers' => ['X-Storefront-Cart-Token' => $added->headers->get('X-Storefront-Cart-Token')]];
    $second = usedContinuityOffer($this, $other)->assertCreated();
    $worker = base_path('tests/Support/used-device-acceptance-worker.php');
    $inputs = [];
    foreach ([[$first, $f['token']], [$second, $other['headers']['X-Storefront-Cart-Token']]] as [$offer, $token]) {
        $inputs[] = ['business' => $f['business']->id, 'token' => $token, 'key' => (string) Str::uuid(),
            'offer' => $offer->json('data.reference'), 'digest' => $offer->json('data.terms_digest')];
    }
    $workers = array_map(fn (array $input) => new Process([PHP_BINARY, $worker], env: [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'nalaka_mobile_device_continuity_test', 'DB_URL' => '',
    ], input: json_encode($input, JSON_THROW_ON_ERROR), timeout: 20), $inputs);
    DB::beginTransaction();
    Business::query()->whereKey($f['business']->id)->lockForUpdate()->firstOrFail();
    try {
        foreach ($workers as $process) {
            $process->start();
        }
        $deadline = microtime(true) + 10;
        do {
            $blocked = collect($workers)->every(fn (Process $process): bool => str_starts_with($process->getOutput(), "LOCK_BLOCKED\n"));
            if ($blocked) {
                break;
            }
            usleep(50000);
        } while (microtime(true) < $deadline && collect($workers)->every(fn (Process $process): bool => $process->isRunning()));
        expect($blocked)->toBeTrue();
    } finally {
        DB::commit();
    }
    $results = [];
    foreach ($workers as $process) {
        $process->wait();
        expect($process->isSuccessful())->toBeTrue()->and($process->getErrorOutput())->toBe('');
        $results[] = json_decode(substr($process->getOutput(), strlen("LOCK_BLOCKED\n")), true, 512, JSON_THROW_ON_ERROR);
    }
    expect(collect($results)->where('ok', true)->count())->toBe(1)
        ->and(WebOrder::query()->count())->toBe(1)->and(DB::table('web_order_reservations')->count())->toBe(1)
        ->and(DB::table('web_order_reservations')->sole()->active_device_id)->toBe($f['device']->id);
    $winner = $results[0]['ok'] ? 0 : 1;
    $winning = $winner === 0 ? $f : $other;
    $winningOffer = $winner === 0 ? $first : $second;
    usedContinuityAcceptance($this, $winning, $winningOffer, $inputs[$winner]['key'])->assertOk();
    expect(DB::table('web_order_reservations')->count())->toBe(1);
});
