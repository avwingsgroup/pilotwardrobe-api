<?php

use App\Http\Controllers\Api\V1\Admin\AdminDisputeController;
use App\Http\Controllers\Api\V1\Admin\AdminGrowthController;
use App\Http\Controllers\Api\V1\Admin\AdminInsightsController;
use App\Http\Controllers\Api\V1\Admin\AdminKycController;
use App\Http\Controllers\Api\V1\Admin\AdminModerationController;
use App\Http\Controllers\Api\V1\Admin\AdminMoneyController;
use App\Http\Controllers\Api\V1\Admin\AdminOrderController;
use App\Http\Controllers\Api\V1\Admin\AdminPlatformController;
use App\Http\Controllers\Api\V1\Admin\AdminReportController;
use App\Http\Controllers\Api\V1\Admin\AdminUserController;
use Illuminate\Support\Facades\Route;

/*
| Control Tower — /api/v1/admin/*. Every route: authenticated staff session holding
| `admin.access` (EnsureStaff) plus the specific permission from contract §2.
| Every mutation writes an audit-log row inside its service/controller.
*/

Route::get('overview', [AdminInsightsController::class, 'overview'])->middleware('perm:dashboard.view');
Route::get('analytics', [AdminInsightsController::class, 'analytics'])->middleware('perm:analytics.view');
Route::post('exports', [AdminInsightsController::class, 'export']);

// Users (AD3/AD4)
Route::get('users', [AdminUserController::class, 'index'])->middleware('perm:users.view');
Route::post('users/invitations', [AdminUserController::class, 'invite'])->middleware('perm:users.create');
Route::get('users/{id}', [AdminUserController::class, 'show'])->middleware('perm:users.view');
Route::post('users/{id}/suspend', [AdminUserController::class, 'suspend'])->middleware('perm:users.suspend');
Route::post('users/{id}/reinstate', [AdminUserController::class, 'reinstate'])->middleware('perm:users.suspend');
Route::post('users/{id}/restrict', [AdminUserController::class, 'restrict'])->middleware('perm:users.update');
Route::patch('users/{id}/limits', [AdminUserController::class, 'limits'])->middleware('perm:users.update');
Route::post('users/{id}/notes', [AdminUserController::class, 'note'])->middleware('perm:users.update');
Route::post('users/{id}/impersonate', [AdminUserController::class, 'impersonate'])->middleware('perm:users.impersonate');
Route::post('users/{id}/role', [AdminUserController::class, 'role'])->middleware('perm:roles.manage');

// KYC (AD5) and aviator credentials
Route::get('kyc', [AdminKycController::class, 'index'])->middleware('perm:kyc.view');
Route::get('kyc/{id}', [AdminKycController::class, 'show'])->middleware('perm:kyc.view');
Route::post('kyc/{id}/approve', [AdminKycController::class, 'approve'])->middleware('perm:kyc.review');
Route::post('kyc/{id}/reject', [AdminKycController::class, 'reject'])->middleware('perm:kyc.review');
Route::post('kyc/{id}/escalate', [AdminKycController::class, 'escalate'])->middleware('perm:kyc.review');
Route::post('kyc/{id}/request-info', [AdminKycController::class, 'requestInfo'])->middleware('perm:kyc.review');
Route::get('aviators', [AdminKycController::class, 'aviators'])->middleware('perm:kyc.view');
Route::post('aviators/{id}/{decision}', [AdminKycController::class, 'decideAviator'])->whereIn('decision', ['approve', 'reject'])->middleware('perm:kyc.review');

// Moderation (AD6) and reports (AD10)
Route::get('moderation', [AdminModerationController::class, 'index'])->middleware('perm:listings.view');
Route::post('moderation/decisions', [AdminModerationController::class, 'decide'])->middleware('perm:listings.approve|listings.reject');
Route::get('reports', [AdminReportController::class, 'index'])->middleware('perm:reports.view');
Route::post('reports/{id}/actions', [AdminReportController::class, 'act'])->middleware('perm:reports.manage');

// Orders & escrow (AD7/AD8)
Route::get('orders', [AdminOrderController::class, 'index'])->middleware('perm:orders.view');
Route::get('orders/stats', [AdminOrderController::class, 'stats'])->middleware('perm:orders.view');
Route::post('orders/reconcile', [AdminOrderController::class, 'reconcile'])->middleware('perm:orders.manage');
Route::get('orders/{id}', [AdminOrderController::class, 'show'])->middleware('perm:orders.view');
Route::post('orders/{id}/refund', [AdminOrderController::class, 'refund'])->middleware('perm:transactions.refund');
Route::post('orders/{id}/release', [AdminOrderController::class, 'release'])->middleware('perm:orders.manage');
Route::post('orders/{id}/hold', [AdminOrderController::class, 'hold'])->middleware('perm:orders.manage');

// Disputes (AD9)
Route::get('disputes', [AdminDisputeController::class, 'index'])->middleware('perm:disputes.view');
Route::get('disputes/{id}', [AdminDisputeController::class, 'show'])->middleware('perm:disputes.view');
Route::post('disputes/{id}/messages', [AdminDisputeController::class, 'message'])->middleware('perm:disputes.resolve');
Route::post('disputes/{id}/decision', [AdminDisputeController::class, 'decide'])->middleware('perm:disputes.resolve');

// Money: payouts (AD11), fees (AD12), approvals
Route::get('payouts', [AdminMoneyController::class, 'payouts'])->middleware('perm:payouts.view');
Route::get('payouts/stats', [AdminMoneyController::class, 'payoutStats'])->middleware('perm:payouts.view');
Route::post('payouts/approve', [AdminMoneyController::class, 'approvePayouts'])->middleware('perm:payouts.approve');
Route::post('payouts/batches', [AdminMoneyController::class, 'createBatch'])->middleware(['perm:payouts.approve', 'idempotent']);
Route::post('payouts/{id}/hold', [AdminMoneyController::class, 'holdPayout'])->middleware('perm:payouts.approve');
Route::post('payouts/{id}/reject', [AdminMoneyController::class, 'rejectPayout'])->middleware('perm:payouts.approve');
Route::post('payouts/{id}/mark-paid', [AdminMoneyController::class, 'markPaid'])->middleware('perm:payouts.approve');
Route::get('fees', [AdminMoneyController::class, 'fees'])->middleware('perm:fees.view');
Route::post('commission-rules', [AdminMoneyController::class, 'saveRule'])->middleware('perm:fees.manage');
Route::patch('commission-rules/{id}', [AdminMoneyController::class, 'saveRule'])->middleware('perm:fees.manage');
Route::put('escrow-timings', [AdminMoneyController::class, 'escrowTimings'])->middleware('perm:fees.manage');
Route::get('approvals', [AdminMoneyController::class, 'approvalsIndex']);
Route::post('approvals/{id}/approve', [AdminMoneyController::class, 'approve']);
Route::post('approvals/{id}/reject', [AdminMoneyController::class, 'reject']);

// Growth & content (AD13–AD16)
Route::get('campaigns', [AdminGrowthController::class, 'campaigns'])->middleware('perm:promotions.manage');
Route::post('campaigns/{id}/duplicate', [AdminGrowthController::class, 'duplicateCampaign'])->middleware('perm:promotions.manage');
Route::get('promo-codes', [AdminGrowthController::class, 'promoCodes'])->middleware('perm:promotions.manage');
Route::post('promo-codes', [AdminGrowthController::class, 'createPromoCode'])->middleware('perm:promotions.manage');
Route::post('broadcasts/reach', [AdminGrowthController::class, 'reach'])->middleware('perm:broadcasts.manage');
Route::post('broadcasts/test', [AdminGrowthController::class, 'testBroadcast'])->middleware('perm:broadcasts.manage');
Route::post('broadcasts', [AdminGrowthController::class, 'sendBroadcast'])->middleware('perm:broadcasts.manage');
Route::get('content/hero-slides', [AdminGrowthController::class, 'heroSlides'])->middleware('perm:content.manage');
Route::put('content/hero-slides/order', [AdminGrowthController::class, 'reorderHeroSlides'])->middleware('perm:content.manage');
Route::patch('content/hero-slides/{id}', [AdminGrowthController::class, 'updateHeroSlide'])->middleware('perm:content.manage');
Route::post('content/publish', [AdminGrowthController::class, 'publish'])->middleware('perm:content.manage');
Route::put('content/pages/{slug}', [AdminGrowthController::class, 'savePage'])->middleware('perm:content.manage');
Route::get('catalogue', [AdminGrowthController::class, 'catalogue'])->middleware('perm:catalogue.manage');
Route::get('catalogue/{id}', [AdminGrowthController::class, 'category'])->middleware('perm:catalogue.manage');

// Team, audit, settings (AD18–AD20)
Route::get('roles', [AdminPlatformController::class, 'roles'])->middleware('perm:team.view');
Route::post('roles', [AdminPlatformController::class, 'createRole'])->middleware('perm:roles.manage');
Route::patch('roles/{id}', [AdminPlatformController::class, 'updateRole'])->middleware('perm:roles.manage');
Route::get('team', [AdminPlatformController::class, 'team'])->middleware('perm:team.view');
Route::post('team/invitations', [AdminPlatformController::class, 'inviteStaff'])->middleware('perm:users.create');
Route::patch('team/{id}', [AdminPlatformController::class, 'updateMember'])->middleware('perm:roles.manage');
Route::get('audit-logs', [AdminPlatformController::class, 'auditLogs'])->middleware('perm:audit.view');
Route::get('settings', [AdminPlatformController::class, 'settings'])->middleware('perm:settings.manage');
Route::patch('settings', [AdminPlatformController::class, 'updateSettings'])->middleware('perm:settings.manage');
Route::post('maintenance-windows', [AdminPlatformController::class, 'scheduleMaintenance'])->middleware('perm:settings.manage');
