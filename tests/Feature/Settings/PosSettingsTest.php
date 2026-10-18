<?php

use App\Models\Business;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('the owner can view POS screen settings', function () {
    $owner = User::factory()->create();
    Business::factory()->for($owner, 'owner')->create([
        'pos_show_brand_filter' => false,
        'pos_products_per_page' => 36,
    ]);

    $this->actingAs($owner)
        ->get(route('pos-settings.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Pos')
            ->where('settings.show_brand_filter', false)
            ->where('settings.allow_price_override', false)
            ->where('settings.require_imei_on_sale', true)
            ->where('settings.show_receipt_after_sale', true)
            ->where('settings.auto_print_receipt', false)
            ->where('settings.receipt_paper_size', 'a4')
            ->where('settings.receipt_thermal_width', 80)
            ->where('settings.products_per_page', 36));
});

test('the owner can customize the POS screen and omitted checkboxes are disabled', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner, 'owner')->create();

    $this->actingAs($owner)
        ->from(route('pos-settings.edit'))
        ->put(route('pos-settings.update'), [
            'pos_show_sale_source' => true,
            'pos_show_pay_exact' => true,
            'pos_allow_price_override' => true,
            'pos_show_sales_history' => true,
            'pos_show_location_filter' => true,
            'pos_show_receipt_after_sale' => true,
            'pos_auto_print_receipt' => true,
            'invoice_paper_size' => 'thermal',
            'invoice_thermal_width' => 58,
            'pos_products_per_page' => 12,
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('pos-settings.edit'));

    $business->refresh();

    expect($business->pos_show_sale_source)->toBeTrue()
        ->and($business->pos_show_customer_name)->toBeFalse()
        ->and($business->pos_show_customer_phone)->toBeFalse()
        ->and($business->pos_show_customer_email)->toBeFalse()
        ->and($business->pos_show_sale_notes)->toBeFalse()
        ->and($business->pos_show_pay_exact)->toBeTrue()
        ->and($business->pos_allow_price_override)->toBeTrue()
        ->and($business->pos_require_imei_on_sale)->toBeFalse()
        ->and($business->pos_show_date_time)->toBeFalse()
        ->and($business->pos_show_sales_history)->toBeTrue()
        ->and($business->pos_show_category_filter)->toBeFalse()
        ->and($business->pos_show_brand_filter)->toBeFalse()
        ->and($business->pos_show_location_filter)->toBeTrue()
        ->and($business->pos_show_receipt_after_sale)->toBeTrue()
        ->and($business->pos_auto_print_receipt)->toBeTrue()
        ->and($business->invoice_paper_size)->toBe('thermal')
        ->and($business->invoice_thermal_width)->toBe(58)
        ->and($business->pos_products_per_page)->toBe(12);
});

test('POS product page size must be one of the supported values', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner, 'owner')->create();

    $this->actingAs($owner)
        ->from(route('pos-settings.edit'))
        ->put(route('pos-settings.update'), [
            'invoice_paper_size' => 'legal',
            'invoice_thermal_width' => 76,
            'pos_products_per_page' => 100,
        ])
        ->assertSessionHasErrors([
            'invoice_paper_size',
            'invoice_thermal_width',
            'pos_products_per_page',
        ])
        ->assertRedirect(route('pos-settings.edit'));

    expect($business->refresh()->pos_products_per_page)->toBe(24);
});

test('a non-owner cannot access POS screen settings', function () {
    Business::factory()->create();
    $otherUser = User::factory()->create();

    $this->actingAs($otherUser)->get(route('pos-settings.edit'))->assertForbidden();
    $this->actingAs($otherUser)->put(route('pos-settings.update'), [])->assertForbidden();
});
