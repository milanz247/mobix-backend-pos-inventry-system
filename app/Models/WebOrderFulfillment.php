<?php

namespace App\Models;

use App\WebFulfillmentMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Historical handover identity; no handover writer is registered in this phase. */
class WebOrderFulfillment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['handover_mode' => WebFulfillmentMode::class, 'handover_at' => 'datetime'];
    }

    /** @return BelongsTo<WebOrder, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(WebOrder::class, 'web_order_id');
    }

    /** @return BelongsTo<Sale, $this> */
    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    /** @return BelongsTo<InventoryOperation, $this> */
    public function inventoryOperation(): BelongsTo
    {
        return $this->belongsTo(InventoryOperation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handover_actor_id');
    }
}
