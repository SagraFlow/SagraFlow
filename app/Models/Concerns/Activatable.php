<?php

namespace App\Models\Concerns;

trait Activatable
{
    /**
     * Limit the query to active records.
     */
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }
}
