<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SerializedDevice;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ActivityLogService
{
    private ?bool $available = null;

    public function model(string $action, Model $model): void
    {
        if (! str_starts_with($model::class, 'App\\Models\\') || $model instanceof ActivityLog) {
            return;
        }
        $after = $action === 'deleted' ? [] : ($action === 'created' ? $model->getAttributes() : $model->getChanges());
        $after = $this->safe($after);
        $before = $action === 'created' ? [] : $this->safe($action === 'deleted' ? $model->getRawOriginal() : array_intersect_key($model->getRawOriginal(), $after));
        if ($before === [] && $after === []) {
            return;
        }
        $businessId = $model instanceof Business ? $model->getKey() : $model->getAttribute('business_id');
        $productId = $model instanceof Product ? $model->getKey() : $model->getAttribute('product_id');
        $variant = $model instanceof ProductVariant ? $model : (($model->getAttribute('product_variant_id') ?? $model->getAttribute('bundle_variant_id')) ? ProductVariant::query()->find((int) ($model->getAttribute('product_variant_id') ?? $model->getAttribute('bundle_variant_id'))) : null);
        if ($variant === null && $model->getAttribute('serialized_device_id')) {
            $device = SerializedDevice::query()->find((int) $model->getAttribute('serialized_device_id'));
            $variant = $device ? ProductVariant::query()->find($device->product_variant_id) : null;
        }
        $productId ??= $variant?->product_id;
        $product = $model instanceof Product ? $model : ($productId ? Product::query()->find((int) $productId) : null);
        $businessId ??= $product?->business_id;
        $name = $model->getAttribute('name') ?? $model->getAttribute('sale_number') ?? $model->getAttribute('reference') ?? $model->getAttribute('sku') ?? class_basename($model).' #'.$model->getKey();
        $this->record($action, class_basename($model), (string) $model->getKey(), (string) $name, ['before' => $before, 'after' => $after], $businessId ? (int) $businessId : null,
            $model->getAttribute('location_id') ? (int) $model->getAttribute('location_id') : null, $productId ? (int) $productId : null, $product?->name);
    }

    /** @param array<string, mixed> $changes */
    public function record(string $action, string $subjectType, ?string $subjectId, ?string $subjectName, array $changes = [], ?int $businessId = null, ?int $locationId = null, ?int $productId = null, ?string $productName = null, ?User $actor = null): void
    {
        if (! ($this->available ??= Schema::hasTable('activity_logs'))) {
            return;
        }
        $businessId ??= Business::query()->where('setup_key', 'initial')->value('id');
        if ($businessId === null) {
            return;
        }
        $request = app()->bound('request') ? request() : null;
        $user = $actor ?? $request?->user();
        $session = $request?->hasSession() ? $request->session() : null;
        $locationId ??= is_int($session?->get('active_location_id')) ? $session->get('active_location_id') : null;
        DB::table('activity_logs')->insert([
            'business_id' => $businessId, 'location_id' => $locationId,
            'actor_id' => $user?->id, 'actor_name' => $user->name ?? (app()->runningInConsole() ? 'System / command' : 'Guest'),
            'action' => $action, 'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'subject_name' => mb_substr($subjectName ?? '', 0, 255), 'product_id' => $productId, 'product_name' => $productName,
            'changes' => json_encode($this->safe($changes), JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE),
            'route' => $request?->route()?->getName(), 'method' => $request?->method(), 'ip_address' => $request?->ip(),
            'session_fingerprint' => $session ? hash_hmac('sha256', $session->getId(), (string) config('app.key')) : null,
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 500) : null,
            'created_at' => now('UTC'),
        ]);
    }

    /** @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function safe(array $values): array
    {
        $safe = [];
        foreach ($values as $key => $value) {
            if (preg_match('/password|secret|token|credential|recovery|remember|payload|operation_key|updated_at|created_at|two_factor/i', (string) $key)) {
                continue;
            }
            if (is_string($value) && in_array(substr($value, 0, 1), ['{', '['], true)) {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $value = $decoded;
                }
            }
            $safe[$key] = is_array($value) ? $this->safe($value) : $value;
        }

        return $safe;
    }
}
