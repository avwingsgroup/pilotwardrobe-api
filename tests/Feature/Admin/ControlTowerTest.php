<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Enums\KycStatus;
use App\Enums\ListingStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserStatus;
use App\Integrations\Payments\Gateways\SandboxGateway;
use App\Models\Address;
use App\Models\ApprovalRequest;
use App\Models\AuditLog;
use App\Models\CommissionRule;
use App\Models\KycVerification;
use App\Models\LedgerEntry;
use App\Models\Listing;
use App\Models\Media;
use App\Models\Order;
use App\Models\StaffRole;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ControlTowerTest extends TestCase
{
    public function test_members_and_unauthenticated_users_cannot_reach_admin(): void
    {
        $this->assertErrorEnvelope($this->getJson('/api/v1/admin/users'), 401, 'UNAUTHENTICATED');
        $this->actingAsUser(User::factory()->kycApproved()->create());
        $this->assertErrorEnvelope($this->getJson('/api/v1/admin/overview'), 403, 'FORBIDDEN');
    }

    public function test_staff_role_bundles_enforce_permission_boundaries(): void
    {
        $ts = User::factory()->staff('trust_safety')->create();
        $this->actingAsUser($ts);

        $this->getJson('/api/v1/admin/users')->assertOk()->assertJsonStructure(['meta' => ['counts' => ['all', 'pending_kyc', 'suspended']]]);
        $this->getJson('/api/v1/admin/overview')->assertOk();
        $this->assertErrorEnvelope($this->getJson('/api/v1/admin/settings'), 403, 'FORBIDDEN');
        $this->assertErrorEnvelope($this->getJson('/api/v1/admin/fees'), 403, 'FORBIDDEN');
        $this->assertErrorEnvelope($this->getJson('/api/v1/admin/team'), 403, 'FORBIDDEN');
        $this->assertErrorEnvelope($this->postJson('/api/v1/admin/payouts/approve', ['ids' => ['x']]), 403, 'FORBIDDEN');

        $me = $this->getJson('/api/v1/auth/me')->json('data.permissions');
        $this->assertContains('kyc.review', $me);
        $this->assertNotContains('settings.manage', $me);
        $this->assertNotContains('listings.create', $me, 'staff do not get member selling permissions');

        $this->actingAsUser(User::factory()->staff('support_agent')->create());
        $target = User::factory()->create();
        $this->getJson("/api/v1/admin/users/{$target->id}")->assertOk();
        $this->assertErrorEnvelope($this->postJson("/api/v1/admin/users/{$target->id}/suspend", ['reason' => 'x']), 403, 'FORBIDDEN');
    }

    public function test_suspension_revokes_access_and_is_audited(): void
    {
        $member = User::factory()->create();
        $token = $member->createToken('device')->plainTextToken;
        $this->actingAsUser(User::factory()->staff('trust_safety')->create());

        $this->postJson("/api/v1/admin/users/{$member->id}/suspend", ['reason' => 'Off-platform payment solicitation'])
            ->assertOk()->assertJsonPath('data.status', 'suspended');

        $this->assertSame(UserStatus::Suspended, $member->fresh()->status);
        $this->assertSame(0, $member->tokens()->count());
        $this->assertTrue(AuditLog::query()->where('action', 'USER.SUSPENDED')->where('target_id', $member->id)->exists());

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me/security')->assertStatus(401);
    }

    public function test_kyc_review_flow_and_no_self_review(): void
    {
        $applicant = User::factory()->create();
        $kyc = KycVerification::query()->create(['user_id' => $applicant->id, 'code' => 'KY-123456', 'status' => KycStatus::Pending, 'step' => 'review',
            'document_type' => 'passport', 'submitted_at' => now()->subHours(30), 'legal_first_name' => 'Ada', 'legal_last_name' => 'Obi']);
        $reviewer = User::factory()->staff('trust_safety')->create();
        $this->actingAsUser($reviewer);

        $this->getJson('/api/v1/admin/kyc')->assertOk()->assertJsonPath('data.0.over_sla', true)->assertJsonPath('meta.counts.pending', 1);
        $this->getJson("/api/v1/admin/kyc/{$kyc->id}")->assertOk()->assertJsonPath('data.personal.first_name', 'Ada');
        $this->postJson("/api/v1/admin/kyc/{$kyc->id}/reject", [])->assertStatus(422);
        $this->postJson("/api/v1/admin/kyc/{$kyc->id}/approve")->assertOk()->assertJsonPath('data.kyc_status', 'approved');
        $this->assertErrorEnvelope($this->postJson("/api/v1/admin/kyc/{$kyc->id}/approve"), 409, 'INVALID_STATE_TRANSITION');

        $this->assertTrue($applicant->fresh()->isKycApproved());
        $this->assertDatabaseHas('notifications', ['user_id' => $applicant->id, 'type' => 'kyc.approved']);
    }

    public function test_listing_moderation_decisions(): void
    {
        $listing = Listing::factory()->status(ListingStatus::PendingReview)->create();
        Media::factory()->create(['owner_id' => $listing->seller_id])->attachTo($listing, 'images');
        $this->actingAsUser(User::factory()->staff('trust_safety')->create());

        $this->getJson('/api/v1/admin/moderation')->assertOk()->assertJsonPath('meta.counts.pending', 1);
        $this->postJson('/api/v1/admin/moderation/decisions', ['ids' => [$listing->id], 'action' => 'request_changes'])
            ->assertStatus(422)->assertJsonPath('errors.note.0', 'Tell the seller what to change.');
        $this->postJson('/api/v1/admin/moderation/decisions', ['ids' => [$listing->id], 'action' => 'request_changes', 'note' => 'Add a photo of the serial plate.'])
            ->assertOk()->assertJsonPath('data.updated', 1);
        $this->assertSame(ListingStatus::NeedsChanges, $listing->fresh()->status);
    }

    public function test_large_refund_requires_a_second_approver(): void
    {
        $order = $this->paidOrder(150000);
        $finance1 = User::factory()->staff('finance')->create();
        $finance2 = User::factory()->staff('finance')->create();

        $this->actingAsUser($finance1);
        $approvalId = $this->postJson("/api/v1/admin/orders/{$order->id}/refund", ['type' => 'full', 'reason' => 'not_as_described', 'note' => 'Wrong model shipped'])
            ->assertOk()->assertJsonPath('data.status', 'pending_approval')->json('data.approval_id');
        $this->assertSame(0, $order->fresh()->refunded_amount, 'nothing moves before approval');

        $this->assertErrorEnvelope($this->postJson("/api/v1/admin/approvals/{$approvalId}/approve"), 409, 'CONFLICT');

        $this->actingAsUser($finance2);
        $this->postJson("/api/v1/admin/approvals/{$approvalId}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        $order->refresh();
        $this->assertSame(OrderStatus::Refunded, $order->status);
        $this->assertSame($order->total_amount, $order->refunded_amount);
        $this->assertTrue(AuditLog::query()->where('action', 'REFUND.REQUESTED')->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'REFUND.APPROVED')->exists());
    }

    public function test_small_refund_executes_immediately_and_trust_safety_cannot_refund(): void
    {
        $order = $this->paidOrder(14500);

        $this->actingAsUser(User::factory()->staff('trust_safety')->create());
        $this->assertErrorEnvelope($this->postJson("/api/v1/admin/orders/{$order->id}/refund", ['type' => 'full', 'reason' => 'x']), 403, 'FORBIDDEN');

        $this->actingAsUser(User::factory()->staff('finance')->create());
        $this->postJson("/api/v1/admin/orders/{$order->id}/refund", ['type' => 'partial', 'amount' => 999999999, 'reason' => 'x'])->assertStatus(422);
        $this->postJson("/api/v1/admin/orders/{$order->id}/refund", ['type' => 'partial', 'amount' => 2000, 'reason' => 'goodwill'])
            ->assertOk()->assertJsonPath('data.status', 'executed');
        $this->assertSame(2000, $order->fresh()->refunded_amount);
    }

    public function test_commission_rule_changes_are_dual_controlled(): void
    {
        $finance1 = User::factory()->staff('finance')->create();
        $this->actingAsUser($finance1);
        $this->postJson('/api/v1/admin/commission-rules', ['name' => 'Headsets', 'rate' => 7.5])
            ->assertOk()->assertJsonPath('data.status', 'pending_approval');
        $this->assertSame(0, CommissionRule::query()->count());

        $this->actingAsUser(User::factory()->superAdmin()->create());
        $this->postJson('/api/v1/admin/approvals/'.ApprovalRequest::query()->value('id').'/approve')->assertOk();
        $this->assertSame(750, CommissionRule::query()->value('rate_bps'));
    }

    public function test_team_invariants(): void
    {
        $super = User::factory()->superAdmin()->create();
        $this->actingAsUser($super);

        $system = StaffRole::query()->create(['slug' => 'super_admin', 'name' => 'Super admin', 'is_system' => true, 'matrix' => []]);
        $this->assertErrorEnvelope($this->patchJson("/api/v1/admin/roles/{$system->id}", ['name' => 'x']), 409, 'CONFLICT');
        $this->assertErrorEnvelope($this->patchJson("/api/v1/admin/team/{$super->id}", ['role' => 'admin']), 409, 'CONFLICT');

        $this->postJson('/api/v1/admin/team/invitations', ['email' => 'someone@gmail.com', 'first_name' => 'A', 'last_name' => 'B', 'staff_role_id' => $system->id])
            ->assertStatus(422)->assertJsonStructure(['errors' => ['email']]);

        $role = $this->postJson('/api/v1/admin/roles', ['name' => 'Fraud desk', 'matrix' => ['orders' => 'manage', 'users' => 'view']])->assertCreated()->json('data');
        $this->assertSame('none', $role['matrix']['fees']);
        $this->postJson('/api/v1/admin/team/invitations', ['email' => 'fraud@pilotwardrobe.com', 'first_name' => 'Fe', 'last_name' => 'Ade', 'staff_role_id' => $role['id']])->assertCreated();
        $staff = User::query()->where('email', 'fraud@pilotwardrobe.com')->firstOrFail();
        $this->assertTrue($staff->hasPermission('orders.manage'));
        $this->assertFalse($staff->hasPermission('users.suspend'));
    }

    public function test_console_bootstraps_first_super_admin_on_staff_domain_only(): void
    {
        config(['platform.staff_email_domain' => 'pilotwardrobe.com']);

        $this->artisan('staff:create-super-admin', ['email' => 'ops@gmail.com'])->assertFailed();
        $this->artisan('staff:create-super-admin', ['email' => 'Ops@Pilotwardrobe.com', '--first-name' => 'Tolu', '--last-name' => 'Ade'])->assertSuccessful();
        $this->artisan('staff:create-super-admin', ['email' => 'ops@pilotwardrobe.com'])->assertFailed();

        $admin = User::query()->where('email', 'ops@pilotwardrobe.com')->firstOrFail();
        $this->assertTrue($admin->isSuperAdmin());
        $this->assertSame(UserStatus::Active, $admin->status);
        $this->assertNotNull($admin->email_verified_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'STAFF.SUPER_ADMIN_BOOTSTRAPPED']);
    }

    public function test_audit_log_rows_are_immutable_at_database_level(): void
    {
        $log = app(AuditLogger::class)->log('TEST.EVENT', 'something');

        $this->expectException(QueryException::class);
        AuditLog::query()->whereKey($log->id)->update(['action' => 'TAMPERED']);
    }

    public function test_ledger_amounts_are_immutable_and_rows_cannot_be_deleted(): void
    {
        $user = User::factory()->create();
        $wallet = Wallet::query()->create(['user_id' => $user->id, 'currency' => 'USD']);
        $entry = LedgerEntry::query()->create([
            'wallet_id' => $wallet->id, 'user_id' => $user->id, 'type' => 'adjustment', 'direction' => 'credit', 'bucket' => 'available',
            'amount' => 500, 'currency' => 'USD', 'status' => 'pending', 'description' => 'Test', 'occurred_at' => now(),
        ]);

        // Status may progress; money fields may not change, and rows cannot be removed.
        LedgerEntry::query()->whereKey($entry->id)->update(['status' => 'completed']);
        $this->assertSame('completed', $entry->fresh()->status);

        foreach ([fn () => LedgerEntry::query()->whereKey($entry->id)->update(['amount' => 1]), fn () => LedgerEntry::query()->whereKey($entry->id)->delete()] as $tamper) {
            try {
                DB::transaction($tamper);
                $this->fail('The ledger guard trigger should have rejected the change.');
            } catch (QueryException) {
                $this->assertSame(500, $entry->fresh()->amount);
            }
        }
    }

    public function test_audit_log_endpoint_is_read_only_listing(): void
    {
        app(AuditLogger::class)->log('USER.SUSPENDED', 'x', 'detail', 'danger');
        $this->actingAsUser(User::factory()->staff('trust_safety')->create());

        $this->getJson('/api/v1/admin/audit-logs?severity=danger')->assertOk()->assertJsonPath('data.0.action', 'USER.SUSPENDED');
        $this->deleteJson('/api/v1/admin/audit-logs')->assertStatus(405);
    }

    private function paidOrder(int $price): Order
    {
        $seller = User::factory()->kycApproved()->create();
        $listing = Listing::factory()->active()->create(['seller_id' => $seller->id, 'price_amount' => $price]);
        $buyer = User::factory()->create();
        $address = Address::query()->create(['user_id' => $buyer->id, 'full_name' => 'B', 'phone' => '+2348000000000', 'line1' => 'x', 'city' => 'Lagos', 'country_code' => 'NG', 'is_default' => true]);

        $this->actingAsUser($buyer);
        $orderId = $this->postJson('/api/v1/orders', ['listing_id' => $listing->id, 'address_id' => $address->id])->json('data.id');
        $ref = $this->postJson('/api/v1/payments/initialize', ['order_id' => $orderId, 'gateway' => 'sandbox', 'return_url' => 'https://pilotwardrobe.test/payment/callback'])->json('data.reference');
        SandboxGateway::setOutcome($ref, PaymentStatus::Successful);
        $this->getJson("/api/v1/payments/{$ref}")->assertJsonPath('data.status', 'successful');

        return Order::query()->findOrFail($orderId);
    }
}
