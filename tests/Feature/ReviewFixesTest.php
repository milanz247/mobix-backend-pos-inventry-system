<?php

use App\Actions\Catalog\SaveProduct;
use App\Models\Business;
use App\Models\Category;
use App\Models\HeldCart;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Sale;
use App\Models\User;
use App\SalePaymentStatus;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

function reviewFixture(): array
{
    $user = User::factory()->create();
    $business = Business::factory()->for(User::factory()->create(), 'owner')->create();
    $location = Location::factory()->for($business)->create();
    $location->users()->attach($user);
    $category = Category::factory()->for($business)->create();
    $product = Product::factory()->for($business)->for($category)->create();
    $variant = ProductVariant::factory()->for($business)->for($product)->create(['sku' => '00012345678901234567', 'cost_price' => '123.45']);
    foreach (['inventory.view', 'sales.pos'] as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    InventoryStock::factory()->for($business)->for($location)->for($variant, 'variant')->create(['quantity_on_hand' => 2]);

    return compact('user', 'business', 'location', 'variant');
}

test('stock reports conceal costs in page and exported content', function () {
    extract(reviewFixture());
    $this->actingAs($user)->get(route('inventory.reports.stock-overview'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('canViewCosts', false)->where('rows.0.unit_cost', null)->where('rows.0.cost_value', null)->where('totals.cost', null));
    $response = $this->get(route('inventory.reports.stock-overview.excel'))->assertOk();
    $file = tempnam(sys_get_temp_dir(), 'review-export-');
    file_put_contents($file, $response->streamedContent());
    $zip = new ZipArchive;
    $zip->open($file);
    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    unlink($file);
    expect($sheet)->toContain('00012345678901234567')->not->toContain('Unit cost estimate');
    $this->get(route('inventory.reports.stock-overview.pdf'))->assertOk()->assertDontSee('Cost estimate');
});

test('held cart remains recoverable until acknowledged by claiming cashier', function () {
    extract(reviewFixture());
    $cart = HeldCart::query()->create(['id' => (string) Str::uuid(), 'business_id' => $business->id, 'location_id' => $location->id, 'created_by' => $user->id, 'payload' => ['lines' => []]]);
    $this->actingAs($user)->postJson(route('pos.carts.claim', $cart))->assertOk();
    $this->assertDatabaseHas('held_carts', ['id' => $cart->id, 'claimed_by' => $user->id]);
    $this->postJson(route('pos.carts.claim', $cart))->assertOk();
    $this->postJson(route('pos.carts.acknowledge', $cart->id))->assertOk();
    $this->assertDatabaseMissing('held_carts', ['id' => $cart->id]);
    $this->postJson(route('pos.carts.acknowledge', $cart->id))->assertOk();
});

test('catalogue transaction preserves restricted costs and blocks historical type changes', function () {
    extract(reviewFixture());
    $product = $variant->product;
    $payload = ['name' => $product->name, 'category_id' => $product->category_id, 'brand_id' => $product->brand_id, 'product_type' => $product->product_type->value, 'status' => $product->status->value, 'variants' => [['id' => $variant->id, 'name' => $variant->name, 'tracking_mode' => $variant->tracking_mode->value, 'unit' => $variant->unit, 'cost_price' => '0.00', 'selling_price' => $variant->selling_price, 'status' => 'active']]];
    app(SaveProduct::class)->handle($business, $payload, $product, mayEditCosts: false);
    expect($variant->fresh()->cost_price)->toBe('123.45');
    $payload['product_type'] = 'service';
    expect(fn () => app(SaveProduct::class)->handle($business, $payload, $product))->toThrow(ValidationException::class);
});

test('credit report renders calculated balances only for an authorized location', function () {
    extract(reviewFixture());
    foreach (['sales.view', 'credit-sales.view'] as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $sale = Sale::factory()->create([
        'business_id' => $business->id,
        'location_id' => $location->id,
        'cashier_id' => $user->id,
        'is_credit' => true,
        'total' => '100.00',
        'paid_total' => '25.00',
        'payment_status' => SalePaymentStatus::PartiallyPaid,
    ]);

    $this->actingAs($user)->get(route('sales.credit.report'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('summary.invoices', 1)
            ->where('summary.outstanding_total', '75.00')
            ->where('sales.data.0.id', $sale->id)
            ->where('sales.data.0.total', '100.00')
            ->where('sales.data.0.paid', '25.00')
            ->where('sales.data.0.outstanding', '75.00'));
});
