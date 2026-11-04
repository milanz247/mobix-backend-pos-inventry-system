<?php

use App\Exceptions\StorefrontCartException;
use App\Models\Business;
use App\Models\WebCart;
use App\Services\StorefrontOrderSubmissionService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if ($app->configurationIsCached() || ! $app->environment('testing') || config('database.default') !== 'mysql'
    || config('database.connections.mysql.database') !== 'nalaka_mobile_device_continuity_test'
    || DB::selectOne('SELECT DATABASE() AS db')->db !== 'nalaka_mobile_device_continuity_test') {
    throw new RuntimeException('Worker requires the isolated used-device MySQL database.');
}
config(['storefront.reservation_ttl_minutes' => 30, 'storefront.cod_confirmation_ttl_minutes' => 20,
    'storefront.order_access_key' => str_repeat('k', 32)]);
$input = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$business = Business::query()->findOrFail($input['business']);
try {
    DB::transaction(fn () => DB::select('SELECT id FROM businesses WHERE id = ? FOR UPDATE NOWAIT', [$business->id]));
    throw new RuntimeException('Parent lock was not held.');
} catch (QueryException $e) {
    if (! in_array((int) ($e->errorInfo[1] ?? 0), [1205, 3572], true)) {
        throw $e;
    }
    echo "LOCK_BLOCKED\n";
    fflush(STDOUT);
}
try {
    $cart = WebCart::query()->where('business_id', $business->id)
        ->where('token_hash', hash('sha256', $input['token']))->sole();
    $result = app(StorefrontOrderSubmissionService::class)->submit($business, $cart, $input['token'],
        $input['key'], $input['offer'], $input['digest']);
    echo json_encode(['ok' => true, 'reference' => $result['data']['reference'], 'replayed' => $result['replayed']]);
} catch (StorefrontCartException $e) {
    echo json_encode(['ok' => false, 'code' => $e->errorCode]);
}
