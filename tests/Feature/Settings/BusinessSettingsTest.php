<?php

use App\BusinessType;
use App\Models\Business;
use App\Models\User;
use App\TimeFormat;
use Inertia\Testing\AssertableInertia as Assert;

function validBusinessSettingsData(Business $business, array $overrides = []): array
{
    return array_replace([
        'name' => $business->name,
        'business_type' => $business->business_type->value,
        'email' => $business->email,
        'phone' => $business->phone,
        'address' => $business->address,
        'city' => $business->city,
        'country' => $business->country,
        'currency' => $business->currency,
        'timezone' => $business->timezone,
        'time_format' => $business->time_format->value,
    ], $overrides);
}

/** @return array<string, mixed> */
function validInvoiceSettingsData(array $overrides = []): array
{
    return array_replace([
        'invoice_title' => 'Sales receipt',
        'invoice_paper_size' => 'a4',
        'invoice_accent_color' => '#1e293b',
        'invoice_show_logo' => true,
        'invoice_show_business_details' => true,
        'invoice_show_customer' => true,
        'invoice_show_cashier' => true,
        'invoice_show_payment_details' => true,
        'invoice_show_tax' => true,
        'invoice_footer' => 'Thank you for your purchase.',
        'invoice_return_policy' => 'Please keep this receipt.',
    ], $overrides);
}

test('guests are redirected from business settings to login', function () {
    $this->get(route('business-settings.edit'))
        ->assertRedirect(route('login'));
});

test('the owner can view business settings and active time preferences', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner, 'owner')->create([
        'timezone' => 'Asia/Colombo',
        'time_format' => TimeFormat::TwentyFourHour,
    ]);

    $this->actingAs($owner)
        ->get(route('business-settings.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/Business')
            ->where('business.name', $business->name)
            ->where('business.timezone', 'Asia/Colombo')
            ->where('business.time_format', '24')
            ->where('businessPreferences.timezone', 'Asia/Colombo')
            ->where('businessPreferences.timeFormat', '24')
            ->has('businessTypes', 3)
            ->has('timezones')
            ->has('timeFormats', 2),
        );

    expect(config('app.timezone'))->toBe('Asia/Colombo');
    expect(config('app.time_format'))->toBe('24');
});

test('the owner can update business and time preferences', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner, 'owner')->create();

    $response = $this->actingAs($owner)
        ->from(route('business-settings.edit'))
        ->put(route('business-settings.update'), validBusinessSettingsData($business, [
            'name' => 'Nalaka Mobile Centre',
            'business_type' => BusinessType::MobileSalesOnly->value,
            'timezone' => 'Asia/Singapore',
            'time_format' => TimeFormat::TwentyFourHour->value,
        ]));

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('business-settings.edit'));

    $business->refresh();

    expect($business->name)->toBe('Nalaka Mobile Centre');
    expect($business->business_type)->toBe(BusinessType::MobileSalesOnly);
    expect($business->timezone)->toBe('Asia/Singapore');
    expect($business->time_format)->toBe(TimeFormat::TwentyFourHour);
});

test('a user who does not own the business cannot access business settings', function () {
    Business::factory()->create();
    $otherUser = User::factory()->create();

    $this->actingAs($otherUser)
        ->get(route('business-settings.edit'))
        ->assertForbidden();

    $this->actingAs($otherUser)
        ->put(route('business-settings.update'), [])
        ->assertForbidden();
});

test('timezone and time format must be valid', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner, 'owner')->create();

    $response = $this->actingAs($owner)
        ->from(route('business-settings.edit'))
        ->put(route('business-settings.update'), validBusinessSettingsData($business, [
            'timezone' => 'Not/A_Timezone',
            'time_format' => '18',
        ]));

    $response
        ->assertSessionHasErrors(['timezone', 'time_format'])
        ->assertRedirect(route('business-settings.edit'));

    expect($business->refresh()->timezone)->toBe('Asia/Colombo');
    expect($business->time_format)->toBe(TimeFormat::TwelveHour);
});

test('the owner can customize the invoice template and hide optional details', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner, 'owner')->create();
    $settings = validInvoiceSettingsData([
        'invoice_title' => 'Tax invoice',
        'invoice_paper_size' => 'thermal',
        'invoice_accent_color' => '#1464A5',
        'invoice_show_customer' => true,
        'invoice_footer' => 'Thank you, come again!',
    ]);
    unset($settings['invoice_show_logo'], $settings['invoice_show_business_details'], $settings['invoice_show_cashier'], $settings['invoice_show_tax']);

    $response = $this->actingAs($owner)
        ->from(route('invoice-settings.edit'))
        ->put(route('invoice-settings.update'), $settings);

    $response->assertSessionHasNoErrors()->assertRedirect(route('invoice-settings.edit'));

    $business->refresh();

    expect($business->invoice_title)->toBe('Tax invoice')
        ->and($business->invoice_paper_size)->toBe('thermal')
        ->and($business->invoice_accent_color)->toBe('#1464A5')
        ->and($business->invoice_show_logo)->toBeFalse()
        ->and($business->invoice_show_business_details)->toBeFalse()
        ->and($business->invoice_show_customer)->toBeTrue()
        ->and($business->invoice_show_cashier)->toBeFalse()
        ->and($business->invoice_show_tax)->toBeFalse()
        ->and($business->invoice_footer)->toBe('Thank you, come again!');
});

test('invoice template rejects an invalid paper format or accent color', function () {
    $owner = User::factory()->create();
    $business = Business::factory()->for($owner, 'owner')->create();

    $response = $this->actingAs($owner)
        ->from(route('invoice-settings.edit'))
        ->put(route('invoice-settings.update'), validInvoiceSettingsData([
            'invoice_paper_size' => 'legal',
            'invoice_accent_color' => 'navy',
        ]));

    $response->assertSessionHasErrors(['invoice_paper_size', 'invoice_accent_color']);

    expect($business->refresh()->invoice_paper_size)->toBe('a4')
        ->and($business->invoice_accent_color)->toBe('#1e293b');
});
