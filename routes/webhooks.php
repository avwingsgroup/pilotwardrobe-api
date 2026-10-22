<?php

use App\Http\Controllers\Webhooks\PaymentWebhookController;
use App\Http\Controllers\Webhooks\PremblyWebhookController;
use App\Http\Controllers\Webhooks\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Provider -> Laravel callbacks under /api/v1/webhooks. No session, no CSRF, no auth
| middleware: each controller authenticates the provider signature itself.
*/

Route::get('whatsapp', [WhatsAppWebhookController::class, 'verify']);
Route::post('whatsapp', [WhatsAppWebhookController::class, 'handle']);
Route::post('prembly', PremblyWebhookController::class);
Route::post('{gateway}', PaymentWebhookController::class)->whereIn('gateway', ['paystack', 'flutterwave', 'monnify', 'stripe', 'paypal']);
