@php
    /** @var \App\Models\Order $order */
    $m = fn (?int $amount) => $amount === null ? '—' : $order->money($amount)->format('en');
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Invoice {{ $order->number }}</title>
    <style>
        body { font-family: "DejaVu Sans", sans-serif; font-size: 11px; color: #1b2433; }
        h1 { font-size: 20px; color: #13294b; margin: 0 0 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 16px; }
        th { text-align: left; border-bottom: 1px solid #c9d1dc; padding: 6px 4px; font-size: 10px; text-transform: uppercase; color: #5b6676; }
        td { padding: 6px 4px; border-bottom: 1px solid #eef1f5; }
        .right { text-align: right; }
        .muted { color: #6b7686; }
        .totals td { border: none; padding: 3px 4px; }
        .grand td { font-weight: bold; font-size: 13px; border-top: 1px solid #13294b; }
    </style>
</head>
<body>
    <table style="margin-top:0">
        <tr>
            <td style="border:none">
                <h1>Pilotwardrobe</h1>
                <div class="muted">Aviation gear marketplace · escrow-protected</div>
            </td>
            <td class="right" style="border:none">
                <strong>Invoice / receipt</strong><br>
                Order #{{ $order->number }}<br>
                Paid {{ $order->paid_at?->format('j M Y H:i') }} UTC
            </td>
        </tr>
    </table>

    <table>
        <tr>
            <td style="border:none;width:50%;vertical-align:top">
                <div class="muted">Billed to</div>
                {{ $order->buyer->displayName() }}<br>
                @if ($order->shipping_address)
                    {{ $order->shipping_address['line1'] ?? '' }}<br>
                    {{ $order->shipping_address['city'] ?? '' }} {{ $order->shipping_address['state'] ?? '' }} {{ $order->shipping_address['postal_code'] ?? '' }}<br>
                    {{ $order->shipping_address['country_code'] ?? '' }}
                @endif
            </td>
            <td style="border:none;vertical-align:top">
                <div class="muted">Sold by</div>
                {{ $order->seller->displayName() }} ({{ '@'.$order->seller->username }})<br>
                <div class="muted" style="margin-top:6px">Payment</div>
                {{ ucfirst($order->successfulPayment?->gateway ?? '—') }} · {{ $order->successfulPayment?->method_label ?? '' }}<br>
                Ref {{ $order->successfulPayment?->reference }}
            </td>
        </tr>
    </table>

    <table>
        <thead><tr><th>Item</th><th>Size</th><th class="right">Qty</th><th class="right">Unit</th><th class="right">Total</th></tr></thead>
        <tbody>
        @foreach ($order->items as $item)
            <tr>
                <td>{{ $item->title }}</td>
                <td>{{ $item->size ?? '—' }}</td>
                <td class="right">{{ $item->quantity }}</td>
                <td class="right">{{ $m($item->unit_price) }}</td>
                <td class="right">{{ $m($item->total) }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>

    <table class="totals" style="width:45%;margin-left:55%">
        <tr><td>Items</td><td class="right">{{ $m($order->items_amount) }}</td></tr>
        <tr><td>Freight</td><td class="right">{{ $m($order->freight_amount) }}</td></tr>
        <tr><td>Buyer protection</td><td class="right">{{ $m($order->buyer_protection_amount) }}</td></tr>
        @if ($order->duties_amount)<tr><td>Duties (estimate)</td><td class="right">{{ $m($order->duties_amount) }}</td></tr>@endif
        @if ($order->swap_fee_amount)<tr><td>Swap fee</td><td class="right">{{ $m($order->swap_fee_amount) }}</td></tr>@endif
        @if ($order->cash_adjustment_amount)<tr><td>Cash top-up</td><td class="right">{{ $m($order->cash_adjustment_amount) }}</td></tr>@endif
        @if ($order->discount_amount)<tr><td>Discount</td><td class="right">−{{ $m($order->discount_amount) }}</td></tr>@endif
        <tr class="grand"><td>Total paid</td><td class="right">{{ $m($order->total_amount) }}</td></tr>
        @if ($order->refunded_amount)<tr><td>Refunded</td><td class="right">−{{ $m($order->refunded_amount) }}</td></tr>@endif
    </table>

    <p class="muted" style="margin-top:28px">Funds are held in escrow until the buyer confirms the item landed as described.
        Questions? Visit pilotwardrobe.com/help and quote order #{{ $order->number }}.</p>
</body>
</html>
