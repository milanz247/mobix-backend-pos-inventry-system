<?php

namespace App\Http\Requests\Concerns;

use BackedEnum;
use Illuminate\Database\Eloquent\Model;

trait AuthorizesStatusChanges
{
    protected function maySetStatus(Model $record, string $permission): bool
    {
        $status = $record->getAttribute('status');
        $current = $status instanceof BackedEnum ? $status->value : $status;

        return ! $this->exists('status') || $this->input('status') === $current || $this->user()->can($permission);
    }
}
