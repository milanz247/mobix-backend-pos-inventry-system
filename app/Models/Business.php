<?php

namespace App\Models;

use App\BusinessStatus;
use App\BusinessType;
use App\TimeFormat;
use Database\Factories\BusinessFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $owner_id
 * @property string $setup_key
 * @property string $name
 * @property BusinessType $business_type
 * @property string|null $email
 * @property string $phone
 * @property string $address
 * @property string $city
 * @property string $country
 * @property string $currency
 * @property string $timezone
 * @property TimeFormat $time_format
 * @property string|null $logo_path
 * @property string|null $invoice_footer
 * @property string|null $invoice_return_policy
 * @property string $invoice_title
 * @property string $invoice_paper_size
 * @property string $invoice_accent_color
 * @property bool $invoice_show_logo
 * @property bool $invoice_show_business_details
 * @property bool $invoice_show_customer
 * @property bool $invoice_show_cashier
 * @property bool $invoice_show_payment_details
 * @property bool $invoice_show_tax
 * @property bool $pos_show_sale_source
 * @property bool $pos_show_customer_name
 * @property bool $pos_show_customer_phone
 * @property bool $pos_show_customer_email
 * @property bool $pos_show_sale_notes
 * @property bool $pos_show_pay_exact
 * @property bool $pos_show_date_time
 * @property bool $pos_show_sales_history
 * @property bool $pos_show_category_filter
 * @property bool $pos_show_brand_filter
 * @property bool $pos_show_location_filter
 * @property bool $pos_show_receipt_after_sale
 * @property int $pos_products_per_page
 * @property BusinessStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'business_type', 'email', 'phone', 'address', 'city', 'country', 'currency', 'timezone', 'time_format', 'logo_path', 'invoice_footer', 'invoice_return_policy', 'invoice_title', 'invoice_paper_size', 'invoice_accent_color', 'invoice_show_logo', 'invoice_show_business_details', 'invoice_show_customer', 'invoice_show_cashier', 'invoice_show_payment_details', 'invoice_show_tax', 'pos_show_sale_source', 'pos_show_customer_name', 'pos_show_customer_phone', 'pos_show_customer_email', 'pos_show_sale_notes', 'pos_show_pay_exact', 'pos_show_date_time', 'pos_show_sales_history', 'pos_show_category_filter', 'pos_show_brand_filter', 'pos_show_location_filter', 'pos_show_receipt_after_sale', 'pos_products_per_page', 'status'])]
class Business extends Model
{
    /** @use HasFactory<BusinessFactory> */
    use HasFactory;

    /**
     * The model's default attributes.
     *
     * @var array<string, bool|int|string>
     */
    protected $attributes = [
        'country' => 'Sri Lanka',
        'currency' => 'LKR',
        'timezone' => 'Asia/Colombo',
        'time_format' => '12',
        'status' => 'active',
        'invoice_title' => 'Sales receipt',
        'invoice_paper_size' => 'a4',
        'invoice_accent_color' => '#1e293b',
        'invoice_show_logo' => true,
        'invoice_show_business_details' => true,
        'invoice_show_customer' => true,
        'invoice_show_cashier' => true,
        'invoice_show_payment_details' => true,
        'invoice_show_tax' => true,
        'pos_show_sale_source' => true,
        'pos_show_customer_name' => true,
        'pos_show_customer_phone' => true,
        'pos_show_customer_email' => true,
        'pos_show_sale_notes' => true,
        'pos_show_pay_exact' => true,
        'pos_show_date_time' => true,
        'pos_show_sales_history' => true,
        'pos_show_category_filter' => true,
        'pos_show_brand_filter' => true,
        'pos_show_location_filter' => true,
        'pos_show_receipt_after_sale' => true,
        'pos_products_per_page' => 24,
    ];

    /**
     * Get the owner account for the business.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /** @return HasMany<Location, $this> */
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }

    /** @return HasMany<Sale, $this> */
    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    /** @return HasMany<Category, $this> */
    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    /** @return HasMany<Brand, $this> */
    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class);
    }

    /** @return HasMany<Product, $this> */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /** @return HasMany<ProductVariant, $this> */
    public function productVariants(): HasMany
    {
        return $this->hasMany(ProductVariant::class);
    }

    /** @return HasMany<InventoryStock, $this> */
    public function inventoryStocks(): HasMany
    {
        return $this->hasMany(InventoryStock::class);
    }

    /** @return HasMany<SerializedDevice, $this> */
    public function serializedDevices(): HasMany
    {
        return $this->hasMany(SerializedDevice::class);
    }

    /** @return HasMany<Supplier, $this> */
    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class);
    }

    /** @return HasMany<Purchase, $this> */
    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'invoice_show_logo' => 'boolean',
            'invoice_show_business_details' => 'boolean',
            'invoice_show_customer' => 'boolean',
            'invoice_show_cashier' => 'boolean',
            'invoice_show_payment_details' => 'boolean',
            'invoice_show_tax' => 'boolean',
            'pos_show_sale_source' => 'boolean',
            'pos_show_customer_name' => 'boolean',
            'pos_show_customer_phone' => 'boolean',
            'pos_show_customer_email' => 'boolean',
            'pos_show_sale_notes' => 'boolean',
            'pos_show_pay_exact' => 'boolean',
            'pos_show_date_time' => 'boolean',
            'pos_show_sales_history' => 'boolean',
            'pos_show_category_filter' => 'boolean',
            'pos_show_brand_filter' => 'boolean',
            'pos_show_location_filter' => 'boolean',
            'pos_show_receipt_after_sale' => 'boolean',
            'pos_products_per_page' => 'integer',
            'business_type' => BusinessType::class,
            'time_format' => TimeFormat::class,
            'status' => BusinessStatus::class,
        ];
    }
}
