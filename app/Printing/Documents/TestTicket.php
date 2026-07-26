<?php

namespace App\Printing\Documents;

use Mike42\Escpos\Printer;

/**
 * A short diagnostic ticket triggered from the admin panel to confirm a printer
 * is reachable and prints correctly.
 */
class TestTicket extends Document
{
    public function __construct(
        private string $eventName,
        private string $printerName,
    ) {}

    protected function build(Printer $printer): void
    {
        $printer->setJustification(Printer::JUSTIFY_CENTER);

        if ($this->eventName !== '') {
            $printer->setEmphasis(true);
            $printer->setTextSize(1, 2);
            $printer->text($this->eventName."\n");
            $printer->setTextSize(1, 1);
            $printer->setEmphasis(false);
            $printer->feed(1);
        }

        $printer->setEmphasis(true);
        $printer->setTextSize(1, 2);
        $printer->text("TEST DI STAMPA\n");
        $printer->setTextSize(1, 1);
        $printer->setEmphasis(false);
        $printer->feed(1);

        $printer->text($this->printerName."\n");
        $printer->text($this->localDateTime(now())."\n");

        $printer->feed(2);
        $printer->cut();
    }
}
