<?php

use App\Models\Business;
use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;

test('authorized staff can replace gallery photos without changing the main image', function () {
    Storage::fake('public');
    $user = User::factory()->create();
    $business = Business::factory()->for($user, 'owner')->create();
    $category = Category::factory()->for($business)->create();
    $product = Product::factory()->for($business)->for($category)->create(['image_path' => 'product-images/main.png']);
    foreach (['products.update', 'storefront.publish'] as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
    }
    $this->actingAs($user)->post(route('storefront.gallery.update', $product), [
        'images' => [UploadedFile::fake()->image('detail.png', 600, 600)],
    ])->assertRedirect()->assertSessionHasNoErrors();
    $product->refresh();
    expect($product->image_path)->toBe('product-images/main.png')
        ->and($product->gallery_paths)->toHaveCount(1);
    Storage::disk('public')->assertExists($product->gallery_paths[0]);
    $this->actingAs($user)->post(route('storefront.gallery.update', $product), [
        'images' => [UploadedFile::fake()->create('script.svg', 10, 'image/svg+xml')],
    ])->assertSessionHasErrors('images.0');
});

test('staff without gallery permissions cannot upload photos', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner, 'owner')->create();
    $category = Category::factory()->for($business)->create();
    $product = Product::factory()->for($business)->for($category)->create();
    $this->actingAs(User::factory()->create())
        ->post(route('storefront.gallery.update', $product), [])
        ->assertForbidden();
});
