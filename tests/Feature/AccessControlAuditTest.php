<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Business;
use App\Models\CashierShift;
use App\Models\Category;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SerializedDevice;
use App\Models\Supplier;
use App\Models\User;
use App\Services\CashierShiftService;
use App\Services\InventoryService;
use App\Services\PurchaseService;
use App\SystemRole;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

/** @return array{owner: User, business: Business, a: Location, b: Location, user: User} */
function auditAccessContext(): array
{
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner, 'owner')->create();
    $a = Location::factory()->for($business)->create(['name' => 'Authorized Shop', 'is_main' => true]);
    $b = Location::factory()->for($business)->create(['name' => 'Private Shop']);
    test()->seed(RolePermissionSeeder::class);
    $user = User::factory()->create();
    $a->users()->attach($user);
    $user->givePermissionTo([
        'dashboard.view', 'sales.pos', 'sales.view', 'sales.create',
        'inventory.view', 'inventory.transfer', 'serialized-devices.view', 'serialized-devices.manage',
        'stock-movements.view', 'purchases.view', 'suppliers.view', 'shifts.view',
        'sales-receipts.print', 'return-receipts.print', 'credit-sales.view', 'damaged-stock.view',
        'shifts.open', 'shifts.movements', 'shifts.close',
    ]);

    return compact('owner', 'business', 'a', 'b', 'user');
}

function auditAccessVariant(Business $business, string $sku = 'AUDIT-SKU'): ProductVariant
{
    $category = Category::factory()->for($business)->create();
    $product = Product::factory()->for($business)->for($category)->create();

    return ProductVariant::factory()->for($business)->for($product)->create(['sku' => $sku, 'selling_price' => '100.00']);
}

/** @return array<string, mixed> */
function auditCheckoutPayload(Location $location, ProductVariant $variant): array
{
    return [
        'location_id' => $location->id, 'operation_key' => (string) Str::uuid(),
        'source' => 'in_store_pos', 'order_discount' => '0.00',
        'lines' => [['product_variant_id' => $variant->id, 'quantity' => '1.000', 'discount_amount' => '0.00']],
        'payments' => [['method' => 'cash', 'amount' => '100.00', 'cash_tendered' => '100.00']],
    ];
}

test('owner sees all locations while admin retains assigned location boundaries', function () {
    $c = auditAccessContext();
    $admin = User::factory()->create();
    $admin->assignRole(SystemRole::Admin->value);
    $c['a']->users()->attach($admin);

    $this->actingAs($c['owner'])->get(route('sales.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('locations', 2));
    $this->actingAs($admin)->get(route('pos.index', ['location_id' => $c['b']->id]))->assertNotFound();
    $this->actingAs($admin)->get(route('pos.index', ['location_id' => $c['a']->id]))->assertOk();
});

test('location filters reject unassigned locations rather than returning unrestricted data', function (string $route) {
    $c = auditAccessContext();

    $this->actingAs($c['user'])->get(route($route, ['location_id' => $c['b']->id]))->assertNotFound();
})->with(['pos.index', 'inventory.locations.index', 'inventory.devices.index', 'inventory.movements.index', 'sales.index', 'purchases.index', 'shifts.index']);

test('location permission and assignment are both required', function () {
    $c = auditAccessContext();
    $assignedWithoutPermission = User::factory()->create();
    $c['a']->users()->attach($assignedWithoutPermission);
    $unassignedWithPermission = User::factory()->create();
    $unassignedWithPermission->givePermissionTo('sales.pos');

    $this->actingAs($assignedWithoutPermission)->get(route('pos.index', ['location_id' => $c['a']->id]))->assertForbidden();
    $this->actingAs($unassignedWithPermission)->get(route('pos.index'))->assertForbidden();
});

test('selected authorized location persists and revoked assignments immediately stop access', function () {
    $c = auditAccessContext();
    $c['b']->users()->attach($c['user']);

    $this->actingAs($c['user'])->get(route('pos.index', ['location_id' => $c['b']->id]))
        ->assertOk()->assertSessionHas('active_location_id', $c['b']->id);
    $this->get(route('pos.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('selectedLocation.id', $c['b']->id));

    $c['b']->users()->detach($c['user']);
    $this->get(route('pos.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('selectedLocation.id', $c['a']->id)->has('locationAccess.locations', 1));
    $this->get(route('pos.index', ['location_id' => $c['b']->id]))->assertNotFound();
});

test('dashboard aggregates include only authorized locations and hide unauthorized costs', function () {
    $c = auditAccessContext();
    Sale::factory()->for($c['business'])->for($c['a'])->for($c['user'], 'cashier')->create(['total' => '120.00']);
    Sale::factory()->for($c['business'])->for($c['b'])->create(['total' => '990.00']);

    $this->actingAs($c['user'])->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('overview.sales_total', '120.00')->where('overview.completed_sales', 1)
        ->where('canViewCosts', false)->where('performance.gross_profit_30_days', null)->where('trend.29.gross_profit', null));
    $this->actingAs($c['owner'])->get(route('dashboard'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('overview.sales_total', '1110.00')->where('canViewCosts', true));
    $this->get(route('dashboard', ['location_id' => $c['b']->id]))->assertOk()->assertInertia(fn (Assert $page) => $page->where('overview.sales_total', '990.00'));
});

test('direct sale receipt purchase and shift URLs cannot expose another location', function () {
    $c = auditAccessContext();
    $sale = Sale::factory()->for($c['business'])->for($c['b'])->create();
    $purchase = Purchase::factory()->for($c['business'])->for($c['b'])->create();
    $shift = CashierShift::factory()->for($c['business'])->for($c['b'])->create();

    $this->actingAs($c['user'])->get(route('sales.show', $sale))->assertNotFound();
    $this->get(route('sales.receipt', $sale))->assertNotFound();
    $this->get(route('purchases.show', $purchase))->assertNotFound();
    $this->get(route('shifts.show', $shift))->assertNotFound();
});

test('POS search and stock totals do not reveal another locations devices or balances', function () {
    $c = auditAccessContext();
    $variant = auditAccessVariant($c['business']);
    InventoryStock::factory()->for($c['business'])->for($c['b'])->for($variant, 'variant')->create(['quantity_on_hand' => '77.000']);
    $device = SerializedDevice::factory()->for($c['b'])->for($variant, 'variant')->create(['business_id' => $c['business']->id]);
    $device->identifiers()->create(['business_id' => $c['business']->id, 'identifier' => $device->imei, 'slot' => 'imei']);

    $this->actingAs($c['user'])->get(route('pos.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('variants.data', 1)->where('variants.data.0.quantity_on_hand', '0.000')->where('variants.data.0.devices_in_stock', 0));
    $this->get(route('pos.index', ['search' => $device->imei]))->assertOk()->assertInertia(fn (Assert $page) => $page->where('matchingDevice', null));
    $this->get(route('inventory.devices.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('devices.data', 0));
    $this->get(route('inventory.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('metrics.serialized_in_stock', 0));
});

test('forged checkout location and cross business variant requests do not create sales', function () {
    $c = auditAccessContext();
    $variant = auditAccessVariant($c['business']);
    CashierShift::factory()->for($c['business'])->for($c['a'])->for($c['user'], 'cashier')->create();
    $otherBusiness = Business::factory()->create(['setup_key' => 'foreign']);
    $foreignVariant = auditAccessVariant($otherBusiness, 'FOREIGN-SKU');

    $this->actingAs($c['user'])->post(route('pos.checkout'), auditCheckoutPayload($c['b'], $variant))->assertSessionHasErrors('location_id');
    $this->post(route('pos.checkout'), auditCheckoutPayload($c['a'], $foreignVariant))->assertNotFound();
    $this->assertDatabaseCount('sales', 0);
});

test('transfers require destination authorization as well as source authorization', function () {
    $c = auditAccessContext();
    $variant = auditAccessVariant($c['business']);
    $stock = InventoryStock::factory()->for($c['business'])->for($c['a'])->for($variant, 'variant')->create(['quantity_on_hand' => '5.000']);

    $this->actingAs($c['user'])->post(route('inventory.transfers.store'), [
        'operation_key' => (string) Str::uuid(), 'source_location_id' => $c['a']->id,
        'destination_location_id' => $c['b']->id,
        'reference' => 'AUDIT-TRANSFER',
        'items' => [['product_variant_id' => $variant->id, 'quantity' => '1.000']], 'device_ids' => [],
    ])->assertSessionHasErrors('location_id');
    expect($stock->refresh()->quantity_on_hand)->toBe('5.000');
    $this->assertDatabaseCount('stock_movements', 0);
});

test('supplier purchase counts and links obey both permission and location rules', function () {
    $c = auditAccessContext();
    $supplier = Supplier::factory()->for($c['business'])->create();
    Purchase::factory()->for($c['business'])->for($supplier)->for($c['a'])->create();
    Purchase::factory()->for($c['business'])->for($supplier)->for($c['b'])->create();

    $this->actingAs($c['user'])->get(route('suppliers.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('suppliers.data.0.purchases_count', 1));
    $this->get(route('suppliers.show', $supplier))->assertOk()->assertInertia(fn (Assert $page) => $page->has('purchases', 1));
    $c['user']->revokePermissionTo('purchases.view');
    $this->get(route('suppliers.show', $supplier))->assertOk()->assertInertia(fn (Assert $page) => $page->has('purchases', 0));
});

test('limited administrators cannot assign roles granting permissions they lack', function () {
    $c = auditAccessContext();
    $c['user']->givePermissionTo(['users.create', 'users.assign-roles']);
    $adminRole = Role::findByName(SystemRole::Admin->value);

    $this->actingAs($c['user'])->post(route('users.store'), [
        'name' => 'Escalated', 'email' => 'audit-escalated@example.com', 'status' => 'active',
        'password' => 'password', 'password_confirmation' => 'password', 'role_ids' => [$adminRole->id],
    ])->assertSessionHasErrors('role_ids');
    $this->assertDatabaseMissing('users', ['email' => 'audit-escalated@example.com']);
});

test('limited administrators cannot reset a higher privilege account or an outside location account', function () {
    $c = auditAccessContext();
    $c['user']->givePermissionTo(['users.update', 'users.assign-roles']);
    $privileged = User::factory()->create();
    $privileged->assignRole(SystemRole::Admin->value);
    $c['a']->users()->attach($privileged);
    $outsider = User::factory()->create();
    $outsider->assignRole(SystemRole::Cashier->value);
    $c['b']->users()->attach($outsider);
    $password = $privileged->password;

    $this->actingAs($c['user'])->put(route('users.update', $privileged), [
        'name' => $privileged->name, 'email' => $privileged->email, 'status' => 'active',
        'password' => 'new-password', 'password_confirmation' => 'new-password', 'role_ids' => $privileged->roles->modelKeys(),
    ])->assertSessionHasErrors('user');
    $this->get(route('users.edit', $outsider))->assertNotFound();
    expect($privileged->refresh()->password)->toBe($password);
});

test('user editing supports multiple authorized assignments and removes assignments immediately', function () {
    $c = auditAccessContext();
    $cashierRole = Role::findByName(SystemRole::Cashier->value);
    $payload = ['name' => $c['user']->name, 'email' => $c['user']->email, 'status' => 'active', 'role_ids' => [$cashierRole->id], 'location_ids' => [$c['a']->id, $c['b']->id]];

    $this->actingAs($c['owner'])->put(route('users.update', $c['user']), $payload)->assertSessionHasNoErrors();
    expect($c['user']->locations()->count())->toBe(2);
    $payload['location_ids'] = [];
    $this->put(route('users.update', $c['user']), $payload)->assertSessionHasNoErrors();
    expect($c['user']->locations()->count())->toBe(0);
    $this->actingAs($c['user'])->get(route('pos.index'))->assertForbidden();
});

test('administrators cannot grant themselves a new role or remove their own assignment', function () {
    $c = auditAccessContext();
    $admin = User::factory()->create();
    $admin->assignRole(SystemRole::Admin->value);
    $c['a']->users()->attach($admin);
    $cashierRole = Role::findByName(SystemRole::Cashier->value);

    $this->actingAs($admin)->put(route('users.update', $admin), [
        'name' => $admin->name, 'email' => $admin->email, 'status' => 'active', 'role_ids' => [$cashierRole->id],
    ])->assertSessionHasErrors('role_ids');
    $this->put(route('locations.users.update', $c['a']), ['user_ids' => [$c['user']->id]])->assertSessionHasErrors('user_ids');
    expect($c['a']->users()->whereKey($admin->id)->exists())->toBeTrue();
});

test('location assignment payload cannot add unauthorized locations', function () {
    $c = auditAccessContext();
    $admin = User::factory()->create();
    $admin->assignRole(SystemRole::Admin->value);
    $c['a']->users()->attach($admin);
    $target = User::factory()->create();
    $cashierRole = Role::findByName(SystemRole::Cashier->value);

    $this->actingAs($admin)->put(route('users.update', $target), [
        'name' => $target->name, 'email' => $target->email, 'status' => 'active',
        'role_ids' => [$cashierRole->id], 'location_ids' => [$c['b']->id],
    ])->assertSessionHasErrors('location_ids');
    expect($target->locations()->count())->toBe(0);
});

test('role edits cannot strip and take over an existing higher privilege role', function () {
    $c = auditAccessContext();
    $c['user']->givePermissionTo('roles.update');
    $role = Role::findByName(SystemRole::Admin->value);
    $dashboard = Permission::findByName('dashboard.view');
    $originalIds = $role->permissions->modelKeys();

    $this->actingAs($c['user'])->put(route('roles.update', $role), ['name' => 'Admin', 'permission_ids' => [$dashboard->id]])->assertSessionHasErrors('role');
    expect($role->fresh()->permissions->modelKeys())->toBe($originalIds);
});

test('own role cannot acquire additional directly held permissions through role editing', function () {
    $c = auditAccessContext();
    $role = Role::query()->create(['name' => 'Limited Supervisor', 'guard_name' => 'web']);
    $role->givePermissionTo('dashboard.view');
    $c['user']->assignRole($role);
    $c['user']->givePermissionTo('roles.update');

    $this->actingAs($c['user'])->put(route('roles.update', $role), ['name' => $role->name, 'permission_ids' => [Permission::findByName('dashboard.view')->id, Permission::findByName('roles.update')->id]])->assertSessionHasErrors('role');
    expect($role->fresh()->hasPermissionTo('roles.update'))->toBeFalse();
});

test('explicit business scope survives foreign records and refuses foreign catalogue URLs', function () {
    $c = auditAccessContext();
    $other = Business::factory()->create(['setup_key' => 'foreign']);
    $variant = auditAccessVariant($other, 'FOREIGN');
    $supplier = Supplier::factory()->for($other)->create();

    $this->actingAs($c['owner'])->get(route('products.index'))->assertOk()->assertInertia(fn (Assert $page) => $page->has('products.data', 0));
    $this->get(route('products.show', $variant->product_id))->assertNotFound();
    $this->get(route('suppliers.show', $supplier))->assertNotFound();
    $this->get(route('business-settings.edit'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('business.name', $c['business']->name));
});

test('transferred device history redacts unassigned locations and hides unrelated earlier events', function () {
    $c = auditAccessContext();
    $category = Category::factory()->for($c['business'])->create();
    $product = Product::factory()->serialized()->for($c['business'])->for($category)->create();
    $variant = ProductVariant::factory()->serialized()->for($c['business'])->for($product)->create();
    $inventory = app(InventoryService::class);
    $inventory->receive($c['owner'], [
        'operation_key' => (string) Str::uuid(), 'location_id' => $c['b']->id,
        'product_variant_id' => $variant->id, 'mode' => 'serialized',
        'devices' => [['imei' => '356789012345671', 'condition' => 'new', 'unit_cost' => '50.00']],
    ]);
    $device = SerializedDevice::query()->sole();
    $inventory->transfer($c['owner'], [
        'operation_key' => (string) Str::uuid(), 'source_location_id' => $c['b']->id,
        'destination_location_id' => $c['a']->id, 'items' => [], 'device_ids' => [$device->id],
    ]);

    $this->actingAs($c['user'])->get(route('inventory.devices.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('devices.data', 1)->has('devices.data.0.history', 1)
        ->where('devices.data.0.history.0.from', null)->where('devices.data.0.history.0.to', $c['a']->name));
    $this->actingAs($c['owner'])->get(route('inventory.devices.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('devices.data.0.history', 2));
});

test('purchase history hides current status and location of a transferred phone', function () {
    $c = auditAccessContext();
    $category = Category::factory()->for($c['business'])->create();
    $product = Product::factory()->serialized()->for($c['business'])->for($category)->create();
    $variant = ProductVariant::factory()->serialized()->for($c['business'])->for($product)->create();
    $supplier = Supplier::factory()->for($c['business'])->create();
    $purchases = app(PurchaseService::class);
    $purchase = $purchases->createDraft($c['owner'], [
        'supplier_id' => $supplier->id, 'location_id' => $c['a']->id,
        'invoice_number' => 'AUDIT-PHONE-PURCHASE', 'invoice_date' => now()->toDateString(),
        'lines' => [['product_variant_id' => $variant->id, 'quantity' => '1.000', 'unit_cost' => '50.00', 'discount_amount' => '0.00',
            'devices' => [['imei' => '356789012345671', 'condition' => 'new', 'unit_cost' => '50.00']]]],
    ]);
    $purchases->post($c['owner'], $purchase);
    $device = SerializedDevice::query()->sole();
    app(InventoryService::class)->transfer($c['owner'], [
        'operation_key' => (string) Str::uuid(), 'source_location_id' => $c['a']->id,
        'destination_location_id' => $c['b']->id, 'items' => [], 'device_ids' => [$device->id],
    ]);

    $this->actingAs($c['user'])->get(route('purchases.show', $purchase))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('purchase.lines.0.devices.0.location_id', null)->where('purchase.lines.0.devices.0.location_name', null)
        ->where('purchase.lines.0.devices.0.status', null)->where('purchase.lines.0.devices.0.imei', '356789012345671'));
});

test('serialized checkout rejects a valid identifier stored at another location', function () {
    $c = auditAccessContext();
    $category = Category::factory()->for($c['business'])->create();
    $product = Product::factory()->serialized()->for($c['business'])->for($category)->create();
    $variant = ProductVariant::factory()->serialized()->for($c['business'])->for($product)->create(['selling_price' => '100.00']);
    app(InventoryService::class)->receive($c['owner'], [
        'operation_key' => (string) Str::uuid(), 'location_id' => $c['b']->id,
        'product_variant_id' => $variant->id, 'mode' => 'serialized',
        'devices' => [['imei' => '356789012345671', 'condition' => 'new', 'unit_cost' => '50.00']],
    ]);
    CashierShift::factory()->for($c['business'])->for($c['a'])->for($c['user'], 'cashier')->create();
    $payload = auditCheckoutPayload($c['a'], $variant);
    $payload['lines'][0]['device_identifier'] = '356789012345671';

    $this->actingAs($c['user'])->post(route('pos.checkout'), $payload)->assertSessionHasErrors('lines.0.device_identifier');
    $this->assertDatabaseCount('sales', 0);
    expect(SerializedDevice::query()->sole()->status->value)->toBe('in_stock');
});

test('a guessed device status URL cannot change stock in another location', function () {
    $c = auditAccessContext();
    $variant = auditAccessVariant($c['business']);
    $device = SerializedDevice::factory()->for($c['b'])->for($variant, 'variant')->create(['business_id' => $c['business']->id]);

    $this->actingAs($c['user'])->patch(route('inventory.devices.status.update', $device), [
        'operation_key' => (string) Str::uuid(), 'status' => 'in_repair', 'reason' => 'Unauthorized edit attempt',
    ])->assertSessionHasErrors('location_id');
    expect($device->refresh()->status->value)->toBe('in_stock');
});

test('shift management requires ownership permission and location access', function () {
    $c = auditAccessContext();
    $other = User::factory()->create();
    $other->givePermissionTo(['shifts.view', 'shifts.open', 'shifts.movements', 'shifts.close']);
    $c['b']->users()->attach($other);
    $shift = app(CashierShiftService::class)->open($other, ['operation_key' => (string) Str::uuid(), 'location_id' => $c['b']->id, 'opening_cash' => '500.00']);
    $movement = app(CashierShiftService::class)->movement($other, $shift, ['operation_key' => (string) Str::uuid(), 'type' => 'expense', 'amount' => '75.00', 'reason' => 'Private shop expense']);

    $this->get(route('shifts.movement', [$shift, $movement]))->assertNotFound();
    $this->post(route('pos.shifts.movements', $shift), ['operation_key' => (string) Str::uuid(), 'location_id' => $c['b']->id, 'type' => 'cash_out', 'amount' => '1.00', 'reason' => 'Forged cash movement'])->assertNotFound();
    $this->post(route('pos.shifts.close', $shift), ['location_id' => $c['b']->id, 'actual_cash' => '425.00'])->assertNotFound();
    expect($shift->refresh()->status)->toBe('open');
    $this->assertDatabaseCount('cash_movements', 1);
});

test('location scoped user lists omit users with wider location assignments', function () {
    $c = auditAccessContext();
    $c['user']->givePermissionTo('users.view');
    $outside = User::factory()->create();
    $outside->locations()->attach([$c['a']->id, $c['b']->id]);

    $this->actingAs($c['user'])->get(route('users.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('users.data', fn ($rows): bool => collect($rows)->pluck('id')->contains($c['user']->id) && ! collect($rows)->pluck('id')->contains($outside->id)));
});

test('partial reloads refresh permission and location navigation props', function () {
    $c = auditAccessContext();
    $this->actingAs($c['user'])->get(route('sales.index'), [
        'X-Inertia-Version' => app(HandleInertiaRequests::class)->version(request()),
        'X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'sales/Index', 'X-Inertia-Partial-Data' => 'sales',
    ])->assertOk()->assertJsonPath('props.locationAccess.locations.0.id', $c['a']->id);
});

test('personal profile payload cannot change roles or location access', function () {
    $c = auditAccessContext();
    $adminRole = Role::findByName(SystemRole::Admin->value);

    $this->actingAs($c['user'])->put(route('profile-settings.update'), [
        'name' => 'Updated personal name', 'phone' => '0771234567',
        'role_ids' => [$adminRole->id], 'location_ids' => [$c['b']->id],
    ])->assertSessionHasNoErrors();
    expect($c['user']->fresh()->name)->toBe('Updated personal name');
    expect($c['user']->fresh()->hasRole(SystemRole::Admin->value))->toBeFalse();
    expect($c['user']->locations()->pluck('locations.id')->all())->toBe([$c['a']->id]);
    $this->put(route('business-settings.update'), ['name' => 'Unauthorized business edit'])->assertForbidden();
    expect($c['business']->fresh()->name)->toBe($c['business']->name);
});
