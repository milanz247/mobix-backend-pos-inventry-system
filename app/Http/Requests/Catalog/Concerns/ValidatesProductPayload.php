<?php

namespace App\Http\Requests\Catalog\Concerns;

use App\CatalogStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\PermissionName;
use App\ProductCondition;
use App\ProductType;
use App\VariantTrackingMode;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

trait ValidatesProductPayload
{
    use ScopesCatalogValidation;

    /** @return array<string, mixed> */
    protected function productRules(): array
    {
        $businessId = $this->currentBusiness()->id;
        $routeProduct = $this->route('product');
        $product = $routeProduct instanceof Product ? $routeProduct : null;

        $rules = [
            'name' => ['required', 'string', 'max:180'],
            'category_id' => [
                'required', 'integer',
                Rule::exists(Category::class, 'id')->where(fn (Builder $query) => $query
                    ->where('business_id', $businessId)
                    ->where(function (Builder $query) use ($product): void {
                        $query->where('status', CatalogStatus::Active->value);

                        if ($product instanceof Product) {
                            $query->orWhere('id', $product->category_id);
                        }
                    })),
            ],
            'brand_id' => [
                'nullable', 'integer',
                Rule::exists(Brand::class, 'id')->where(fn (Builder $query) => $query
                    ->where('business_id', $businessId)
                    ->where(function (Builder $query) use ($product): void {
                        $query->where('status', CatalogStatus::Active->value);

                        if ($product?->brand_id !== null) {
                            $query->orWhere('id', $product->brand_id);
                        }
                    })),
            ],
            'product_type' => ['required', Rule::enum(ProductType::class)],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['required', Rule::enum(CatalogStatus::class)],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'extensions:jpg,jpeg,png,webp', 'max:5120'],
            'variants' => ['required', 'array', 'min:1', 'max:100'],
            'variants.*.id' => [
                'nullable', 'integer',
                Rule::exists(ProductVariant::class, 'id')->where(fn (Builder $query) => $query
                    ->where('business_id', $businessId)
                    ->when($product instanceof Product, fn (Builder $query) => $query->where('product_id', $product->id))),
            ],
            'variants.*.name' => ['required', 'string', 'max:120'],
            'variants.*.sku' => ['exclude'],
            'variants.*.barcode' => ['nullable', 'string', 'max:64'],
            'variants.*.model_number' => ['nullable', 'string', 'max:150'],
            'variants.*.storage_capacity' => ['nullable', 'string', 'max:40'],
            'variants.*.color' => ['nullable', 'string', 'max:80'],
            'variants.*.other_attributes' => ['nullable', 'string', 'max:1000'],
            'variants.*.variant_attributes' => ['nullable', 'array'],
            'variants.*.tracking_mode' => ['required', Rule::enum(VariantTrackingMode::class)],
            'variants.*.unit' => ['required', 'string', 'max:24'],
            'variants.*.condition' => ['nullable', Rule::enum(ProductCondition::class)],
            'variants.*.cost_price' => ['required', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'variants.*.selling_price' => ['required', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'variants.*.minimum_selling_price' => ['nullable', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'variants.*.status' => ['required', Rule::enum(CatalogStatus::class)],
            'variants.*.components' => [
                Rule::requiredIf(fn (): bool => $this->input('product_type') === ProductType::Combo->value),
                Rule::prohibitedIf(fn (): bool => $this->input('product_type') !== ProductType::Combo->value),
                'array',
            ],
            'variants.*.components.*.component_variant_id' => [
                'required', 'integer',
                Rule::exists(ProductVariant::class, 'id')->where(fn (Builder $query) => $query
                    ->where('business_id', $businessId)),
            ],
            'variants.*.components.*.quantity' => ['required', 'numeric', 'gt:0', 'max:9999999.999'],
        ];

        foreach ($this->input('variants', []) as $index => $variant) {
            if (! is_array($variant)) {
                continue;
            }

            $variantId = filter_var($variant['id'] ?? null, FILTER_VALIDATE_INT);
            $ignoreVariantId = $variantId !== false && $product instanceof Product
                && $product->variants()->whereKey($variantId)->exists()
                ? $variantId
                : null;

            $barcodeRule = Rule::unique('product_variants', 'barcode')->where(
                fn (Builder $query) => $query->where('business_id', $businessId),
            );

            if ($ignoreVariantId !== null) {
                $barcodeRule->ignore($ignoreVariantId);
            }

            $rules["variants.{$index}.barcode"][] = $barcodeRule;
            $rules["variants.{$index}.condition"][] = Rule::requiredIf(
                fn (): bool => ($variant['tracking_mode'] ?? null) === VariantTrackingMode::Serialized->value,
            );
            $rules["variants.{$index}.condition"][] = Rule::prohibitedIf(
                fn (): bool => ($variant['tracking_mode'] ?? null) !== VariantTrackingMode::Serialized->value,
            );
        }

        return $rules;
    }

    /** @return list<callable(Validator): void> */
    protected function productAfter(): array
    {
        return [function (Validator $validator): void {
            $variants = $this->input('variants', []);

            if (! is_array($variants) || $validator->errors()->count() > 0) {
                return;
            }

            $type = ProductType::tryFrom((string) $this->input('product_type'));

            if ($this->input('status') === CatalogStatus::Active->value) {
                $businessId = $this->currentBusiness()->id;
                $categoryIsActive = Category::query()
                    ->whereKey((int) $this->input('category_id'))
                    ->where('business_id', $businessId)
                    ->where('status', CatalogStatus::Active->value)
                    ->exists();

                if (! $categoryIsActive) {
                    $validator->errors()->add('category_id', 'An active product must use an active category.');
                }

                $brandId = filter_var($this->input('brand_id'), FILTER_VALIDATE_INT);

                if ($brandId !== false && ! Brand::query()
                    ->whereKey($brandId)
                    ->where('business_id', $businessId)
                    ->where('status', CatalogStatus::Active->value)
                    ->exists()) {
                    $validator->errors()->add('brand_id', 'An active product must use an active brand.');
                }
            }

            if ($type === ProductType::Simple && count($variants) !== 1) {
                $validator->errors()->add('variants', 'A simple product must have exactly one default variant.');
            }

            if ($type === ProductType::Variable && count($variants) < 2) {
                $validator->errors()->add('variants', 'A variable product must have at least two variants.');
            }

            if ($type === ProductType::Simple && ($variants[0]['tracking_mode'] ?? null) !== VariantTrackingMode::QuantityBased->value) {
                $validator->errors()->add('variants.0.tracking_mode', 'Simple products must use quantity-based tracking.');
            }

            if ($type === ProductType::Serialized && collect($variants)->contains(
                fn (array $variant): bool => ($variant['tracking_mode'] ?? null) !== VariantTrackingMode::Serialized->value,
            )) {
                $validator->errors()->add('variants', 'All variants of a serialized product must use serialized tracking.');
            }

            if ($type === ProductType::Combo && (count($variants) !== 1
                || ($variants[0]['tracking_mode'] ?? null) !== VariantTrackingMode::QuantityBased->value
                || empty($variants[0]['components']))) {
                $validator->errors()->add('variants', 'A combo needs one quantity-based bundle variant and at least one component.');
            }

            if ($this->input('status') === CatalogStatus::Active->value
                && ! collect($variants)->contains(fn (array $variant): bool => ($variant['status'] ?? null) === CatalogStatus::Active->value)) {
                $validator->errors()->add('variants', 'An active product must have at least one active variant.');
            }

            $routeProduct = $this->route('product');
            $product = $routeProduct instanceof Product ? $routeProduct : null;

            if ($type === ProductType::Combo && $product instanceof Product
                && $product->product_type !== ProductType::Combo
                && $product->variants()->whereHas('includedInBundles')->exists()) {
                $validator->errors()->add('product_type', 'This product has variants used in existing bundles and cannot become a bundle.');
            }

            if ($product instanceof Product) {
                $submittedVariantIds = collect($variants)
                    ->pluck('id')
                    ->filter(fn (mixed $id): bool => filter_var($id, FILTER_VALIDATE_INT) !== false)
                    ->map(fn (mixed $id): int => (int) $id)
                    ->all();

                if ($product->variants()
                    ->whereNotIn('id', $submittedVariantIds)
                    ->whereHas(
                        'includedInBundles.bundleVariant.product',
                        fn (EloquentBuilder $query) => $query->where('status', CatalogStatus::Active->value),
                    )->exists()) {
                    $validator->errors()->add('variants', 'A variant used by an active combo cannot be removed or omitted.');
                }
            }

            $seenBarcodes = [];
            $selectedComponentIds = [];

            foreach ($variants as $index => $variant) {
                if (! is_array($variant)) {
                    continue;
                }

                $barcode = mb_strtolower(trim((string) ($variant['barcode'] ?? '')));

                if ($barcode !== '' && isset($seenBarcodes[$barcode])) {
                    $validator->errors()->add("variants.{$index}.barcode", 'The barcode is duplicated in this product form.');
                }

                if ($barcode !== '') {
                    $seenBarcodes[$barcode] = true;
                }

                $sellingPrice = $variant['selling_price'] ?? null;
                $minimumPrice = $variant['minimum_selling_price'] ?? null;

                if (is_numeric($sellingPrice) && is_numeric($minimumPrice)
                    && $this->decimalIsGreater((string) $minimumPrice, (string) $sellingPrice)) {
                    $validator->errors()->add("variants.{$index}.minimum_selling_price", 'The minimum selling price cannot exceed the selling price.');
                }

                $variantId = filter_var($variant['id'] ?? null, FILTER_VALIDATE_INT);

                if ($product instanceof Product
                    && $variantId !== false
                    && ($variant['status'] ?? null) === CatalogStatus::Inactive->value
                    && $product->variants()->whereKey($variantId)->whereHas(
                        'includedInBundles.bundleVariant.product',
                        fn (EloquentBuilder $query) => $query->where('status', CatalogStatus::Active->value),
                    )->exists()) {
                    $validator->errors()->add("variants.{$index}.status", 'This variant is used by an active combo and cannot be deactivated.');
                }

                if ($type === ProductType::Combo && isset($variant['components']) && is_array($variant['components'])) {
                    $componentIds = [];

                    foreach ($variant['components'] as $componentIndex => $component) {
                        if (! is_array($component)) {
                            continue;
                        }

                        $componentId = filter_var($component['component_variant_id'] ?? null, FILTER_VALIDATE_INT);

                        if ($componentId === false) {
                            continue;
                        }

                        if (isset($componentIds[$componentId])) {
                            $validator->errors()->add("variants.{$index}.components.{$componentIndex}.component_variant_id", 'A bundle component can only be listed once.');
                        }

                        $componentIds[$componentId] = true;
                        $selectedComponentIds[$componentId] = true;

                        $bundleVariant = $product instanceof Product
                            ? $product->variants()->whereKey($variant['id'] ?? 0)->first()
                            : null;

                        if ($bundleVariant?->id === $componentId) {
                            $validator->errors()->add("variants.{$index}.components.{$componentIndex}.component_variant_id", 'A bundle cannot contain itself.');
                        }
                    }
                }
            }

            if ($type === ProductType::Combo && $selectedComponentIds !== []) {
                $validComponentIds = ProductVariant::query()
                    ->whereIn('id', array_keys($selectedComponentIds))
                    ->where('business_id', $this->currentBusiness()->id)
                    ->whereHas('product', fn (EloquentBuilder $query) => $query
                        ->where('product_type', '!=', ProductType::Combo->value)
                        ->when($product instanceof Product, fn (EloquentBuilder $query) => $query->where('id', '!=', $product->id)))
                    ->pluck('id')
                    ->map(fn (int|string $id): int => (int) $id)
                    ->all();

                if (count($validComponentIds) !== count($selectedComponentIds)) {
                    $validator->errors()->add('variants.0.components', 'Choose active, non-bundle variants belonging to this business.');
                }

                $comboWillBeActive = $this->input('status') === CatalogStatus::Active->value
                    && ($variants[0]['status'] ?? null) === CatalogStatus::Active->value;

                if ($product instanceof Product) {
                    foreach ($variants[0]['components'] ?? [] as $componentIndex => $component) {
                        $componentId = (int) ($component['component_variant_id'] ?? 0);
                        $isAlreadyLinked = $product->variants()
                            ->whereKey($variants[0]['id'] ?? 0)
                            ->whereHas('bundleComponents', fn (EloquentBuilder $query) => $query->where('component_variant_id', $componentId))
                            ->exists();
                        $componentIsActive = ProductVariant::query()
                            ->whereKey($componentId)
                            ->where('business_id', $this->currentBusiness()->id)
                            ->where('status', CatalogStatus::Active->value)
                            ->whereHas('product', fn (EloquentBuilder $query) => $query
                                ->where('status', CatalogStatus::Active->value)
                                ->where('product_type', '!=', ProductType::Combo->value)
                                ->where('id', '!=', $product->id))
                            ->exists();

                        if (! $componentIsActive && ($comboWillBeActive || ! $isAlreadyLinked)) {
                            $validator->errors()->add("variants.0.components.{$componentIndex}.component_variant_id", 'An active combo may only contain active product variants.');
                        }
                    }
                } elseif ($comboWillBeActive && (count($validComponentIds) !== count($selectedComponentIds)
                    || ProductVariant::query()
                        ->whereIn('id', array_keys($selectedComponentIds))
                        ->where('business_id', $this->currentBusiness()->id)
                        ->where('status', CatalogStatus::Active->value)
                        ->whereHas('product', fn (EloquentBuilder $query) => $query->where('status', CatalogStatus::Active->value))
                        ->count() !== count($selectedComponentIds))) {
                    $validator->errors()->add('variants.0.components', 'An active combo may only contain active product variants.');
                }
            }
        }];
    }

    protected function mayManageProduct(?ProductType $type): bool
    {
        $user = $this->user();

        return $user !== null
            && $user->can(PermissionName::VariantsManage->value)
            && ($type !== ProductType::Combo || $user->can(PermissionName::BundlesManage->value));
    }

    private function decimalIsGreater(string $left, string $right): bool
    {
        [$leftWhole, $leftFraction] = array_pad(explode('.', $left, 2), 2, '');
        [$rightWhole, $rightFraction] = array_pad(explode('.', $right, 2), 2, '');
        $leftWhole = ltrim($leftWhole, '0') ?: '0';
        $rightWhole = ltrim($rightWhole, '0') ?: '0';
        $leftFraction = str_pad($leftFraction, 2, '0');
        $rightFraction = str_pad($rightFraction, 2, '0');

        if (strlen($leftWhole) !== strlen($rightWhole)) {
            return strlen($leftWhole) > strlen($rightWhole);
        }

        if ($leftWhole !== $rightWhole) {
            return strcmp($leftWhole, $rightWhole) > 0;
        }

        return strcmp($leftFraction, $rightFraction) > 0;
    }
}
