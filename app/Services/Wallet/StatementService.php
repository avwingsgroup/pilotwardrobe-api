<?php

declare(strict_types=1);

namespace App\Services\Wallet;

use App\Models\LedgerEntry;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Monthly wallet statements. The PDF is rendered on request into private storage and
 * returned as a 10-minute temporary signed URL (`GET /statements/{file}`).
 */
final class StatementService
{
    public function link(User $user, string $month, string $currency): array
    {
        $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $entries = LedgerEntry::query()->where('user_id', $user->id)->where('currency', $currency)
            ->whereBetween('occurred_at', [$start, $end])->orderBy('occurred_at')->get();

        $path = 'statements/'.$user->id.'/'.$month.'-'.$currency.'-'.bin2hex(random_bytes(6)).'.pdf';
        Storage::disk('local')->put($path, Pdf::loadView('pdf.statement', compact('user', 'entries', 'start', 'end', 'currency'))
            ->setOption(['isRemoteEnabled' => false])->output());

        return [
            'url' => URL::temporarySignedRoute('statements.download', now()->addMinutes(10), ['path' => base64_encode($path)]),
            'expires_at' => now()->addMinutes(10)->toIso8601ZuluString(),
            'month' => $month,
            'currency' => $currency,
            'entries' => $entries->count(),
        ];
    }
}
