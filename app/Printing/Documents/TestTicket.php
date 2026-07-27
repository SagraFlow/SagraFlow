<?php

namespace App\Printing\Documents;

use Mike42\Escpos\Printer;

/**
 * A diagnostic ticket triggered from the admin panel to confirm a printer is
 * reachable and prints correctly. Mirrors the customer receipt layout - logo
 * and event name on top, a rule, then a large centered "Stampa di prova" in
 * place of the products, a rule, and the date/time plus printer name in the footer.
 */
class TestTicket extends Document
{
    public function __construct(
        private string $eventName,
        private string $printerName,
        private ?string $logoPath = null,
    ) {}

    protected function build(Printer $printer): void
    {
        // Header block, centered: logo then event name (large), like the receipt.
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $hasHeader = false;

        if (($logo = $this->logoImage($this->logoPath)) !== null) {
            $printer->bitImage($logo);
            $printer->feed(1);
            $hasHeader = true;
        }

        if ($this->eventName !== '') {
            $printer->setEmphasis(true);
            $printer->setTextSize(1, 2);
            $printer->text($this->eventName."\n");
            $printer->setTextSize(1, 1);
            $printer->setEmphasis(false);
            $hasHeader = true;
        }

        // A blank gap after the header (only when one was printed, so an empty
        // header never starts with blank lines), then a rule.
        if ($hasHeader) {
            $printer->feed(1);
        }
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->text($this->divider());

        // Body: "Stampa di prova" large (2x2) and bold, centered, in place of
        // the product list on a real receipt, with a blank line above and below.
        $printer->feed(1);
        $printer->setJustification(Printer::JUSTIFY_CENTER);
        $printer->setEmphasis(true);
        $printer->setTextSize(2, 2);
        $printer->text("Stampa di prova\n");
        $printer->setTextSize(1, 1);
        $printer->setEmphasis(false);
        $printer->setJustification(Printer::JUSTIFY_LEFT);
        $printer->feed(1);

        $printer->text($this->divider());

        // Footer: date/time on the left and the printer name on the right, like
        // the other receipts pair the time with the order number.
        $printer->feed(1);
        $printer->text($this->columns($this->localDateTime(now()), $this->printerName));

        $printer->feed(2);
        $printer->cut();
    }
}
