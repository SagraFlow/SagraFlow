<?php

namespace App\Printing\Documents;

use App\Models\Order;
use Mike42\Escpos\EscposImage;
use Mike42\Escpos\GdEscposImage;
use Mike42\Escpos\Printer;
use Throwable;

/**
 * Customer receipt (scontrino) printed at the ordering register: full order
 * with prices and total, optionally kicking the cash drawer.
 */
class CustomerReceipt extends Document
{
    /** Max logo width in dots (about half the 80mm printable width). */
    private const LOGO_WIDTH = 256;

    public function __construct(
        private Order $order,
        private string $eventName,
        private bool $openDrawer,
        private ?string $logoPath = null,
    ) {}

    protected function build(Printer $printer): void
    {
        $order = $this->order;

        // Header block, centered: logo, event name (large), order number.
        $printer->setJustification(Printer::JUSTIFY_CENTER);

        if (($logo = $this->logo()) !== null) {
            $printer->bitImage($logo);
            $printer->feed(1);
        }

        if ($this->eventName !== '') {
            $printer->setEmphasis(true);
            $printer->setTextSize(1, 2);
            $printer->text($this->eventName."\n");
            $printer->setTextSize(1, 1);
            $printer->setEmphasis(false);
        }

        $printer->setJustification(Printer::JUSTIFY_LEFT);

        // Order details, separated from the header above by a blank gap.
        $printer->feed(2);
        $printer->text($this->columns('N. Ordine', "#{$order->number}"));
        if ($order->table_number !== null) {
            $printer->text($this->columns('Tavolo', (string) $order->table_number));
        }
        if ($order->customer_name) {
            $printer->text($this->columns('Cliente', $order->customer_name));
        }
        $printer->text($this->divider());

        $first = true;
        foreach ($order->lines as $line) {
            // Blank line between products only (not before the first).
            if (! $first) {
                $printer->feed(1);
            }
            $first = false;

            // Row 1: name on the left, line total on the right.
            $printer->text($this->columns($line->food_name, $this->money($line->line_total)));

            // Line details: quantity, unit price, customizations, note.
            $printer->text("{$line->quantity} x {$this->money($line->unit_price)}\n");
            foreach ($line->deviations() as $deviation) {
                $surcharge = $deviation['surcharge'] > 0 ? ' (+ '.$this->money($deviation['surcharge']).')' : '';
                $printer->text('   '.$deviation['label'].$surcharge."\n");
            }
            if ($line->note) {
                $printer->text('   "'.$line->note."\"\n");
            }
        }
        $printer->text($this->divider());

        $printer->text($this->columns('Subtotale', $this->money($order->subtotal)));
        // Cover charge between subtotal and discount/total, shown whenever there
        // are covers (even when free), with the count in the label.
        if (($order->covers ?? 0) > 0) {
            $printer->text($this->columns("Coperto ({$order->covers} x {$this->money($order->cover_charge)})", $this->money($order->coverTotal())));
        }
        if ($order->discount_amount > 0) {
            $printer->text($this->columns('Sconto', '-'.$this->money($order->discount_amount)));
        }
        $printer->setEmphasis(true);
        $printer->text($this->columns('TOTALE', $this->money($order->total)));
        $printer->setEmphasis(false);
        $printer->feed(1);
        $printer->text($this->columns('Pagamento', $order->payment_method?->getLabel() ?? '-'));
        if ($order->cash_received !== null) {
            $printer->text($this->columns('Ricevuto', $this->money($order->cash_received)));
            $printer->text($this->columns('Resto', $this->money($order->changeGiven())));
        }

        $printer->text($this->divider());
        $printer->feed(1);
        // Footer: date/time on the left, order number on the right.
        $printer->text($this->columns($order->paid_at?->format('d/m/Y H:i') ?? '', "#{$order->number}"));

        $printer->feed(2);
        if ($this->openDrawer) {
            $printer->pulse();
        }
        $printer->cut();
    }

    /**
     * Load the configured logo, downscaled to the paper width. Returns null
     * (and never throws) when there is no logo or it cannot be processed, so a
     * bad image never blocks the receipt.
     */
    private function logo(): ?EscposImage
    {
        if ($this->logoPath === null || ! is_file($this->logoPath)) {
            return null;
        }

        try {
            $source = @imagecreatefromstring((string) file_get_contents($this->logoPath));

            if ($source === false) {
                return null;
            }

            $width = imagesx($source);
            $height = imagesy($source);
            $targetWidth = min($width, self::LOGO_WIDTH);
            $targetHeight = max(1, (int) round($height * $targetWidth / $width));

            // Flatten onto white (handles transparency) and downscale to the paper width.
            $canvas = imagecreatetruecolor($targetWidth, $targetHeight);
            imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
            imagecopyresampled($canvas, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

            $image = new GdEscposImage;
            $image->readImageFromGdResource($canvas);

            return $image;
        } catch (Throwable) {
            return null;
        }
    }
}
