<?php

namespace App\Http\Requests\Catalog\Concerns;

use App\Models\Business;

trait ScopesCatalogValidation
{
    protected function currentBusiness(): Business
    {
        return Business::query()->sole();
    }
}
