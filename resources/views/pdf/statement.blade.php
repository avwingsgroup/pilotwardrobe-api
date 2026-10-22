<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Statement {{ $start->format('F Y') }}</title>
    <style>
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #1b2433; }
        h1 { font-size: 18px; color: #13294b; margin: 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 14px; }
        th { text-align: left; border-bottom: 1px solid #c9d1dc; padding: 5px 4px; color: #5b6676; }
        td { padding: 5px 4px; border-bottom: 1px solid #eef1f5; }
        .right { text-align: right; }
    </style>
</head>
<body>
    <h1>Pilotwardrobe wallet statement</h1>
    <p>{{ $user->displayName() }} · {{ $currency }} · {{ $start->format('j M Y') }} – {{ $end->format('j M Y') }}</p>
    <table>
        <thead><tr><th>Date</th><th>Description</th><th>Detail</th><th>Status</th><th class="right">Amount</th></tr></thead>
        <tbody>
        @forelse ($entries as $entry)
            <tr>
                <td>{{ $entry->occurred_at->format('j M Y') }}</td>
                <td>{{ $entry->description }}</td>
                <td>{{ $entry->detail }}</td>
                <td>{{ str_replace('_', ' ', $entry->status) }}</td>
                <td class="right">{{ $entry->direction === 'debit' ? '−' : '+' }}{{ \App\Support\Money\Money::of($entry->amount, $entry->currency)->format('en') }}</td>
            </tr>
        @empty
            <tr><td colspan="5">No activity this month.</td></tr>
        @endforelse
        </tbody>
    </table>
</body>
</html>
