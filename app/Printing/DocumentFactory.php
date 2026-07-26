<?php

namespace App\Printing;

use App\Enums\PrintJobType;
use App\Models\Order;
use App\Printing\Documents\CustomerReceipt;
use App\Printing\Documents\DepartmentTicket;
use App\Printing\Documents\Document;
use App\Printing\Documents\PickupStub;
use App\Printing\Documents\TestTicket;
use App\Settings\EventSettings;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;

/**
 * Rebuilds a printable Document from the compact spec frozen on a PrintJob plus
 * its (immutable) order. Rendering is deferred to send time, so no raw ESC/POS
 * bytes are stored and checkout stays fast.
 */
class DocumentFactory
{
    /**
     * @param  array<string, mixed>  $spec
     */
    public function make(PrintJobType $type, array $spec, ?Order $order): Document
    {
        return match ($type) {
            PrintJobType::CustomerReceipt => new CustomerReceipt(
                $this->requireOrder($order, $type),
                $spec['eventName'] ?? '',
                (bool) ($spec['openDrawer'] ?? false),
                $this->logoPath(),
            ),
            PrintJobType::DepartmentTicket => new DepartmentTicket(
                $this->requireOrder($order, $type),
                $spec['items'] ?? [],
            ),
            PrintJobType::PickupStub => new PickupStub(
                $this->requireOrder($order, $type),
                $spec['eventName'] ?? '',
                $spec['station'] ?? '',
                $spec['items'] ?? [],
            ),
            PrintJobType::Test => new TestTicket(
                $spec['eventName'] ?? '',
                $spec['printerName'] ?? '',
            ),
        };
    }

    private function requireOrder(?Order $order, PrintJobType $type): Order
    {
        return $order ?? throw new InvalidArgumentException("Il documento {$type->value} richiede un ordine.");
    }

    /**
     * Absolute filesystem path of the configured receipt logo, or null when
     * unset/missing. Resolved live from settings at render time.
     */
    private function logoPath(): ?string
    {
        $logo = app(EventSettings::class)->logo;

        if ($logo === null || ! Storage::disk('public')->exists($logo)) {
            return null;
        }

        return Storage::disk('public')->path($logo);
    }
}
