<?php

namespace App\Printing;

use App\Enums\PrintJobType;
use App\Models\Printer;

/**
 * A single document to be printed at a resolved printer. The printer is null
 * when the routed destination has no active printer (recorded as a failed job).
 * The document is described by a compact spec, rendered later at send time.
 */
class PrintTask
{
    /**
     * @param  array<string, mixed>  $spec
     */
    public function __construct(
        public readonly ?Printer $printer,
        public readonly PrintJobType $type,
        public readonly string $label,
        public readonly array $spec,
    ) {}
}
