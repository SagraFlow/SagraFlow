<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Casts\Attribute;

trait NormalizesName
{
    /**
     * Collapse inner whitespace and trim the model's name on write.
     */
    protected function name(): Attribute
    {
        return Attribute::make(
            set: fn (string $value) => preg_replace('/\s+/', ' ', trim($value)),
        );
    }
}
