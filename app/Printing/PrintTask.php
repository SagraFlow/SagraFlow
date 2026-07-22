<?php

namespace App\Printing;

use App\Enums\PrintJobType;
use App\Models\Printer;
use App\Printing\Documents\Document;

/**
 * A single document to be printed at a resolved printer. The printer is null
 * when the routed destination has no active printer (recorded as a failed job).
 */
class PrintTask
{
    public function __construct(
        public readonly ?Printer $printer,
        public readonly PrintJobType $type,
        public readonly string $label,
        public readonly Document $document,
    ) {}
}
