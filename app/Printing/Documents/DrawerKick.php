<?php

namespace App\Printing\Documents;

use Mike42\Escpos\Printer;

/**
 * The cash-drawer kick command (ESC/POS pulse), sent on its own to open the
 * drawer of a register's printer outside of a sale.
 */
class DrawerKick extends Document
{
    protected function build(Printer $printer): void
    {
        $printer->pulse();
    }
}
