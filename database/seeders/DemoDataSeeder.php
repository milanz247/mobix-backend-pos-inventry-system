<?php

namespace Database\Seeders;

use App\Actions\Fortify\CreateNewUser;
use App\LocationStatus;
use App\LocationType;
use App\Models\Business;
use App\Models\InventoryOperation;
use App\Models\Location;
use App\Models\PurchasePayment;
use App\Models\Sale;
use App\Models\User;
use App\ProductCondition;
use App\Services\InventoryService;
use App\Services\PurchaseService;
use App\Services\SaleService;
use App\SystemRole;
use App\UserStatus;
use App\VariantTrackingMode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use LogicException;

class DemoDataSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        if (app()->isProduction()) {
            throw new LogicException('Demo data cannot be seeded in production.');
        }

        $this->call(RolePermissionSeeder::class);

        $business = Business::query()->with('owner')->where('setup_key', 'initial')->first();

        if ($business === null) {
            $business = $this->createInitialBusiness();
        }

        $this->call(DemoCatalogSeeder::class);

        $locations = DB::transaction(function () use ($business): Collection {
            $locations = $this->seedLocations($business);
            $this->seedMilanUser($locations);

            $allLocationIds = $locations->pluck('id')->all();
            $business->owner->locations()->syncWithoutDetaching($allLocationIds);

            return $locations;
        });

        $this->seedInventory($business, $business->owner, $locations);
        $this->seedPurchase($business, $business->owner, $locations['main']);
        $this->seedSales($business, $business->owner, $locations);
    }

    /** @return Collection<string, Location> */
    private function seedLocations(Business $business): Collection
    {
        $main = $business->locations()->where('is_main', true)->first();

        if ($main === null) {
            $main = $business->locations()->firstOrNew(['code' => 'MAIN']);
            $main->fill([
                'name' => $business->name.' Main Shop',
                'type' => LocationType::Shop,
                'phone' => $business->phone,
                'email' => $business->email,
                'address' => $business->address,
                'city' => $business->city,
                'status' => LocationStatus::Active,
            ]);
            $main->forceFill(['is_main' => true])->save();
        }

        $warehouse = $business->locations()->firstOrCreate(
            ['code' => 'WH-01'],
            [
                'name' => 'Central Warehouse',
                'type' => LocationType::Warehouse,
                'phone' => '0712000101',
                'email' => 'warehouse@nalakamobile.test',
                'address' => '25 Industrial Road',
                'city' => 'Kurunegala',
                'status' => LocationStatus::Active,
            ],
        );
        $repair = $business->locations()->firstOrCreate(
            ['code' => 'REP-01'],
            [
                'name' => 'Mobile Repair Centre',
                'type' => LocationType::RepairCentre,
                'phone' => '0712000102',
                'email' => 'repairs@nalakamobile.test',
                'address' => '48 Service Lane',
                'city' => 'Kurunegala',
                'status' => LocationStatus::Active,
            ],
        );
        $branch = $business->locations()->firstOrCreate(
            ['code' => 'KDY-01'],
            [
                'name' => 'Kandy City Branch',
                'type' => LocationType::Shop,
                'phone' => '0712000103',
                'email' => 'kandy@nalakamobile.test',
                'address' => '120 Peradeniya Road',
                'city' => 'Kandy',
                'status' => LocationStatus::Active,
            ],
        );

        return collect([
            'main' => $main,
            'warehouse' => $warehouse,
            'repair' => $repair,
            'branch' => $branch,
        ]);
    }

    private function createInitialBusiness(): Business
    {
        $password = config('demo.milan_password');

        if (! is_string($password) || $password === '') {
            throw new LogicException('Set DEMO_MILAN_PASSWORD before seeding a new NalakaMobile demo business.');
        }

        $owner = app(CreateNewUser::class)->create([
            'business_name' => 'NalakaMobile Demo Shop',
            'business_type' => 'mobile_sales_and_repairs',
            'business_email' => 'hello@nalakamobile.test',
            'business_phone' => '0713000100',
            'business_address' => '25 Main Street',
            'business_city' => 'Kurunegala',
            'currency' => 'LKR',
            'name' => 'Milan',
            'email' => 'milan@nalakamobile.test',
            'phone' => '0713000105',
            'password' => $password,
            'password_confirmation' => $password,
        ]);

        return $owner->ownedBusiness()->with('owner')->firstOrFail();
    }

    /** @param Collection<string, Location> $locations */
    private function seedMilanUser(Collection $locations): void
    {
        $password = config('demo.milan_password');

        if (! is_string($password) || $password === '') {
            return;
        }

        $milan = User::query()->firstOrCreate(
            ['email' => 'milan@nalakamobile.test'],
            [
                'name' => 'Milan',
                'phone' => '0713000105',
                'password' => $password,
                'status' => UserStatus::Active,
            ],
        );

        if ($milan->wasRecentlyCreated) {
            $milan->assignRole(SystemRole::Admin->value);
            $milan->locations()->syncWithoutDetaching($locations->pluck('id')->all());
        }
    }

    /** @param Collection<string, Location> $locations */
    private function seedInventory(Business $business, User $actor, Collection $locations): void
    {
        $inventory = app(InventoryService::class);
        $variants = $business->productVariants()->with('product')
            ->where('status', 'active')
            ->orderBy('id')
            ->get();

        foreach ($locations->unique('id') as $location) {
            foreach ($variants as $variant) {
                if ($variant->product->status->value !== 'active' || $variant->product->product_type->value === 'combo') {
                    continue;
                }

                $operationKey = $this->stableUuid("demo-opening:{$business->id}:{$location->id}:{$variant->id}");

                if (InventoryOperation::query()->where('business_id', $business->id)->where('operation_key', $operationKey)->exists()) {
                    continue;
                }

                if ($variant->tracking_mode === VariantTrackingMode::QuantityBased) {
                    $inventory->receive($actor, [
                        'operation_key' => $operationKey,
                        'location_id' => $location->id,
                        'product_variant_id' => $variant->id,
                        'mode' => 'quantity',
                        'quantity' => (string) (8 + ($location->id % 4)),
                        'unit_cost' => $variant->cost_price,
                        'reference' => 'DEMO-OPENING-'.$location->code,
                        'reason' => 'Demo opening stock',
                        'notes' => 'Repeatable demo fixture; no supplier invoice is implied.',
                    ], true);

                    continue;
                }

                $condition = $variant->condition;
                $inventory->receive($actor, [
                    'operation_key' => $operationKey,
                    'location_id' => $location->id,
                    'product_variant_id' => $variant->id,
                    'mode' => 'serialized',
                    'reference' => 'DEMO-OPENING-'.$location->code,
                    'reason' => 'Demo opening stock',
                    'notes' => 'Sample device for POS and IMEI lookup testing.',
                    'devices' => collect(range(1, 2))->map(function (int $unit) use ($business, $location, $variant, $condition): array {
                        $imei = $this->stableImei("demo-imei:{$business->id}:{$location->id}:{$variant->id}:{$unit}");

                        return [
                            'imei' => $imei,
                            'serial_number' => 'DEMO-'.strtoupper(substr(hash('sha256', $imei), 0, 10)),
                            'condition' => $condition instanceof ProductCondition ? $condition->value : ProductCondition::New->value,
                            'unit_cost' => $variant->cost_price,
                            'unit_selling_price' => $variant->selling_price,
                        ];
                    })->all(),
                ], true);
            }
        }
    }

    private function seedPurchase(Business $business, User $actor, Location $location): void
    {
        $invoiceNumber = 'DEMO-PO-001';
        $purchase = $business->purchases()->where('invoice_number', $invoiceNumber)->first();
        $purchases = app(PurchaseService::class);

        if ($purchase === null) {
            $supplier = $business->suppliers()->firstOrCreate(
                ['name' => 'Nalaka Demo Supplier'],
                [
                    'contact_person' => 'Demo Contact',
                    'email' => 'supplier@nalakamobile.test',
                    'phone' => '0713000201',
                    'address' => 'Demo Supplier Road',
                    'city' => $location->city,
                    'status' => 'active',
                ],
            );
            $variant = $business->productVariants()
                ->where('sku', 'DEMO-ACC-CHG-25W')
                ->where('tracking_mode', VariantTrackingMode::QuantityBased->value)
                ->first();

            if ($variant === null) {
                return;
            }

            $purchase = $purchases->createDraft($actor, [
                'supplier_id' => $supplier->id,
                'location_id' => $location->id,
                'invoice_number' => $invoiceNumber,
                'invoice_date' => now()->toDateString(),
                'notes' => 'Demo purchase fixture for supplier and payment workflow testing.',
                'lines' => [[
                    'product_variant_id' => $variant->id,
                    'quantity' => '3.000',
                    'unit_cost' => $variant->cost_price,
                    'discount_amount' => '0.00',
                ]],
            ]);
        }

        if ($purchase->status->value === 'draft') {
            $purchase = $purchases->post($actor, $purchase);
        }

        $paymentKey = $this->stableUuid('demo-purchase-payment:'.$business->id);

        if (PurchasePayment::query()->where('business_id', $business->id)->where('operation_key', $paymentKey)->exists()) {
            return;
        }

        $purchases->recordPayment($actor, $purchase, [
            'operation_key' => $paymentKey,
            'amount' => $purchase->total,
            'payment_date' => now()->toDateString(),
            'method' => 'bank_transfer',
            'reference' => 'DEMO-BANK-001',
            'notes' => 'Demo supplier payment.',
        ]);
    }

    /** @param Collection<string, Location> $locations */
    private function seedSales(Business $business, User $cashier, Collection $locations): void
    {
        $variant = $business->productVariants()->where('sku', 'DEMO-ACC-CHG-25W')->first();

        if ($variant === null) {
            return;
        }

        foreach ($locations->unique('id') as $location) {
            $operationKey = $this->stableUuid("demo-sale:{$business->id}:{$location->id}");

            if (Sale::query()->where('business_id', $business->id)->where('operation_key', $operationKey)->exists()) {
                continue;
            }

            app(SaleService::class)->checkout($cashier, [
                'operation_key' => $operationKey,
                'location_id' => $location->id,
                'source' => 'in_store_pos',
                'customer_name' => 'Demo Walk-in Customer',
                'customer_phone' => '0713000301',
                'order_discount' => '0.00',
                'lines' => [[
                    'product_variant_id' => $variant->id,
                    'quantity' => '1.000',
                    'discount_amount' => '0.00',
                ]],
                'payments' => [[
                    'amount' => $variant->selling_price,
                    'method' => 'cash',
                    'cash_tendered' => $variant->selling_price,
                    'reference' => null,
                ]],
                'notes' => 'Demo sale fixture.',
            ]);
        }

        $serializedVariant = $business->productVariants()->where('sku', 'DEMO-PHN-S24-128-BLK')->first();
        $mainLocation = $locations->get('main');

        if ($serializedVariant === null || ! $mainLocation instanceof Location) {
            return;
        }

        $operationKey = $this->stableUuid("demo-sale:serialized:{$business->id}:{$mainLocation->id}");

        if (Sale::query()->where('business_id', $business->id)->where('operation_key', $operationKey)->exists()) {
            return;
        }

        $imei = $this->stableImei("demo-imei:{$business->id}:{$mainLocation->id}:{$serializedVariant->id}:1");
        app(SaleService::class)->checkout($cashier, [
            'operation_key' => $operationKey,
            'location_id' => $mainLocation->id,
            'source' => 'in_store_pos',
            'customer_name' => 'Demo Phone Customer',
            'customer_phone' => '0713000302',
            'order_discount' => '0.00',
            'lines' => [[
                'product_variant_id' => $serializedVariant->id,
                'quantity' => '1.000',
                'device_identifier' => $imei,
                'discount_amount' => '0.00',
            ]],
            'payments' => [[
                'amount' => $serializedVariant->selling_price,
                'method' => 'cash',
                'cash_tendered' => $serializedVariant->selling_price,
                'reference' => null,
            ]],
            'notes' => 'Demo serialized phone sale fixture.',
        ]);
    }

    private function stableUuid(string $key): string
    {
        $hex = substr(hash('sha256', $key), 0, 32);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-4'.substr($hex, 13, 3).'-a'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }

    private function stableImei(string $key): string
    {
        return str_pad((string) hexdec(substr(hash('sha256', $key), 0, 13)), 15, '0', STR_PAD_LEFT);
    }
}
