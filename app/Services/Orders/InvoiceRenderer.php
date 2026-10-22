<?php

declare(strict_types=1);

namespace App\Services\Orders;

use App\Models\Order;
use Barryvdh\DomPDF\Facade\Pdf;

/** Renders the order invoice PDF (`GET /orders/{id}/invoice`). Remote resources are disabled. */
final class InvoiceRenderer
{
    public function render(Order $order): string
    {
        $order->loadMissing(['items', 'buyer', 'seller', 'successfulPayment']);

        return Pdf::loadView('pdf.invoice', ['order' => $order])
            ->setOption(['isRemoteEnabled' => false, 'defaultFont' => 'DejaVu Sans'])
            ->setPaper('a4')
            ->output();
    }
}
