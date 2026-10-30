<?php

use App\Models\Business;
use App\Models\Category;
use App\Models\DamagedStockEntry;
use App\Models\InventoryOperation;
use App\Models\InventoryStock;
use App\Models\Location;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Role;
use App\Models\User;
use App\PermissionName;
use App\Support\PermissionCatalog;
use App\SystemRole;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;

function functionPermissionOwner(): User
{
    $owner = User::factory()->create();
    Business::factory()->for($owner, 'owner')->create();
    test()->seed(RolePermissionSeeder::class);

    return $owner;
}

test('every implemented permission has a usable function action and valid prerequisite graph', function () {
    foreach (PermissionName::cases() as $permission) {
        expect($permission->module())->not->toBeEmpty()->and($permission->feature())->not->toBeEmpty()
            ->and($permission->action())->toBeIn(['view', 'add', 'edit', 'delete', 'actions']);
        $visit = function (PermissionName $item, array $path = []) use (&$visit): void {
            expect($path)->not->toContain($item->value);
            foreach ($item->dependencies() as $required) {
                expect(PermissionName::tryFrom($required))->not->toBeNull();
                $visit(PermissionName::from($required), [...$path, $item->value]);
            }
        };
        $visit($permission);
    }
});

test('role permission matrix can save clear and reload exact checkbox assignments', function () {
    $owner = functionPermissionOwner();
    $role = Role::create(['name' => 'Inventory observer', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);
    $view = Permission::findByName('inventory.view');
    $this->actingAs($owner)->put(route('roles.update', $role), ['name' => $role->name, 'description' => 'View stock only', 'permission_ids' => [$view->id]])->assertSessionHasNoErrors();
    expect($role->fresh()->permissions->pluck('name')->all())->toBe(['inventory.view']);
    $this->get(route('roles.edit', $role))->assertInertia(fn (Assert $page) => $page->component('management/roles/Edit')
        ->where('role.permissions.0.id', $view->id)->has('permissionGroups.Inventory'));
    $this->put(route('roles.update', $role), ['name' => $role->name, 'permission_ids' => []])->assertSessionHasNoErrors();
    expect($role->fresh()->permissions)->toHaveCount(0)->and($user->fresh()->can('inventory.view'))->toBeFalse();
    $this->post(route('roles.store'), ['name' => 'No access', 'permission_ids' => []])->assertSessionHasNoErrors();
    expect(Role::findByName('No access')->permissions)->toHaveCount(0);
});

test('selecting a function includes its prerequisites without granting unrelated destructive actions', function () {
    $owner = functionPermissionOwner();
    $edit = Permission::findByName('products.update');
    $this->actingAs($owner)->post(route('roles.store'), ['name' => 'Product editor', 'permission_ids' => [$edit->id]])->assertSessionHasNoErrors();
    $role = Role::findByName('Product editor');
    expect($role->permissions->pluck('name')->all())->toContain('products.update', 'products.view', 'variants.manage')
        ->not->toContain('products.delete', 'products.deactivate', 'roles.update');
});

test('limited role administrators cannot gain missing prerequisites through a forged assignment', function () {
    functionPermissionOwner();
    $actor = User::factory()->create();
    $actor->givePermissionTo(['roles.create', 'products.update']);
    $this->actingAs($actor)->post(route('roles.store'), ['name' => 'Forged editor', 'permission_ids' => [Permission::findByName('products.update')->id]])->assertSessionHasErrors('role');
    expect(Role::query()->where('name', 'Forged editor')->exists())->toBeFalse();
});

test('owner role remains protected and custom permission rows do not crash the matrix', function () {
    $owner = functionPermissionOwner();
    $custom = Permission::findOrCreate('custom.audit-view', 'web');
    $owner->givePermissionTo($custom);
    $this->actingAs($owner)->get(route('roles.create'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('permissionGroups.Custom permissions')->where('permissionGroups.Custom permissions.0.name', 'custom.audit-view'));
    $role = Role::findByName(SystemRole::Owner->value);
    $this->put(route('roles.update', $role), ['name' => $role->name, 'permission_ids' => []])->assertSessionHasErrors('role');
    expect($role->fresh()->permissions)->not->toHaveCount(0);
});

test('permission upgrade preserves previous role and direct-user capabilities and independent toggles remain editable', function () {
    functionPermissionOwner();
    $role = Role::create(['name' => 'Legacy stock manager', 'guard_name' => 'web']);
    $role->givePermissionTo(['inventory.view', 'inventory.adjust']);
    $user = User::factory()->create();
    $user->givePermissionTo('products.deactivate');
    PermissionCatalog::install();
    expect($role->fresh()->hasPermissionTo('damaged-stock.view'))->toBeTrue()
        ->and($role->fresh()->hasPermissionTo('damaged-stock.write-off'))->toBeTrue()
        ->and($user->fresh()->hasPermissionTo('products.delete'))->toBeTrue();
    $role->revokePermissionTo('damaged-stock.write-off');
    $this->seed(RolePermissionSeeder::class);
    expect($role->fresh()->hasPermissionTo('damaged-stock.write-off'))->toBeFalse();
});

test('broad legacy view permissions do not bypass newly separated function endpoints', function (string $permission, string $route) {
    functionPermissionOwner();
    $user = User::factory()->create();
    $user->givePermissionTo($permission);
    $this->actingAs($user)->get(route($route))->assertForbidden();
})->with([
    ['inventory.view', 'inventory.damaged.index'], ['inventory.view', 'reports.damaged'], ['inventory.view', 'reports.out-of-stock'],
    ['business-settings.view', 'invoice-settings.edit'], ['business-settings.view', 'pos-settings.edit'],
    ['shifts.view', 'reports.expenses'], ['shifts.view', 'reports.current-shift'], ['sales.view', 'sales.credit'],
]);

test('deactivate access cannot delete a product and editor access cannot change its status', function () {
    $owner = functionPermissionOwner();
    $business = Business::query()->sole();
    $user = User::factory()->create();
    $user->givePermissionTo(['products.view', 'products.deactivate']);
    $product = Product::factory()->for($business)->create(['status' => 'inactive']);
    $this->actingAs($user)->delete(route('products.destroy', $product))->assertForbidden();
    expect($product->fresh())->not->toBeNull();
    $user->givePermissionTo('products.delete');
    $this->delete(route('products.destroy', $product))->assertSessionHasNoErrors();
    expect(Product::find($product->id))->toBeNull();

    $category = Category::factory()->for($business)->create(['status' => 'active']);
    $editor = User::factory()->create();
    $editor->givePermissionTo(['categories.view', 'categories.update']);
    $this->actingAs($editor)->put(route('categories.update', $category), ['name' => 'Renamed category', 'status' => 'inactive'])->assertForbidden();
    expect($category->fresh()->status->value)->toBe('active');
    $this->put(route('categories.update', $category), ['name' => 'Renamed category', 'status' => 'active'])->assertSessionHasNoErrors();
    expect($category->fresh()->name)->toBe('Renamed category');
});

test('damaged stock repair and write-off permissions are independent even on a forged action payload', function () {
    functionPermissionOwner();
    $business = Business::query()->sole();
    $location = Location::factory()->for($business)->create();
    $product = Product::factory()->for($business)->create();
    $variant = ProductVariant::factory()->for($business)->for($product)->create();
    $operation = InventoryOperation::factory()->create(['business_id' => $business->id]);
    $entry = DamagedStockEntry::query()->create([
        'business_id' => $business->id, 'location_id' => $location->id, 'product_variant_id' => $variant->id,
        'inventory_operation_id' => $operation->id, 'quantity' => '2.000', 'remaining_quantity' => '2.000', 'unit_cost' => '20.00', 'reason' => 'Faulty', 'recorded_by' => $business->owner_id,
    ]);
    $user = User::factory()->create();
    $location->users()->attach($user);
    $user->givePermissionTo(['damaged-stock.view', 'damaged-stock.repair']);
    $this->actingAs($user)->post(route('inventory.damaged.resolve', $entry), ['operation_key' => (string) Str::uuid(), 'action' => 'write_off', 'quantity' => '1.000', 'notes' => 'Forged write-off'])->assertForbidden();
    expect($entry->fresh()->remaining_quantity)->toBe('2.000');
    $this->post(route('inventory.damaged.resolve', $entry), ['operation_key' => (string) Str::uuid(), 'action' => 'repair', 'quantity' => '1.000', 'notes' => 'Repaired and tested'])->assertSessionHasNoErrors();
    expect($entry->fresh()->remaining_quantity)->toBe('1.000')->and(InventoryStock::query()->sole()->quantity_on_hand)->toBe('1.000');
});

test('a report-only role can inspect the stock report without being offered unauthorized inventory links', function () {
    functionPermissionOwner();
    $business = Business::query()->sole();
    $location = Location::factory()->for($business)->create();
    $product = Product::factory()->for($business)->create();
    ProductVariant::factory()->for($business)->for($product)->create();
    $user = User::factory()->create();
    $location->users()->attach($user);
    $user->givePermissionTo('stock-reports.view');
    $this->actingAs($user)->get(route('reports.out-of-stock'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('reports/OutOfStock')->has('rows.data', 1)->where('rows.data.0.url', null));
    $this->get(route('inventory.locations.index'))->assertForbidden();
});
