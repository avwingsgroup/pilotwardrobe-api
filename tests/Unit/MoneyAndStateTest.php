<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\EscrowStatus;
use App\Enums\ListingStatus;
use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\ApiException;
use App\Integrations\Payments\Amounts;
use App\Support\Money\Currency;
use App\Support\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MoneyAndStateTest extends TestCase
{
    public function test_money_arithmetic_is_integer_and_currency_safe(): void
    {
        $a = Money::of(74500, 'USD');
        $this->assertSame(76900, $a->plus(Money::of(2400, Currency::USD))->amount);
        $this->assertSame(5960, $a->percentage(800)->amount);
        $this->assertSame(1490, $a->percentage(200)->amount);
        $this->assertSame(3, Money::of(25, 'USD')->percentage(1000)->amount, 'half-up rounding: 2.5 → 3');

        $this->expectException(InvalidArgumentException::class);
        $a->plus(Money::of(1, 'NGN'));
    }

    #[DataProvider('amounts')]
    public function test_minor_major_conversion(int $minor, string $major): void
    {
        $this->assertSame($major, Amounts::toMajor($minor));
        $this->assertSame($minor, Amounts::toMinor($major));
    }

    public static function amounts(): array
    {
        return [[0, '0.00'], [5, '0.05'], [19061, '190.61'], [100000000, '1000000.00'], [-250, '-2.50']];
    }

    public function test_decimal_parsing_from_psps_rounds_half_up(): void
    {
        $this->assertSame(123450, Amounts::toMinor('1234.5'));
        $this->assertSame(1235, Amounts::toMinor('12.345'));
        $this->assertSame(1234, Amounts::toMinor('12.344'));
        $this->assertSame(1999, Amounts::toMinor(19.99));
    }

    public function test_order_state_machine_rejects_illegal_moves(): void
    {
        $this->assertTrue(OrderStatus::AwaitingDispatch->canTransitionTo(OrderStatus::InTransit));
        $this->assertFalse(OrderStatus::Completed->canTransitionTo(OrderStatus::PendingPayment));
        $this->assertFalse(OrderStatus::Cancelled->canTransitionTo(OrderStatus::Paid));
        $this->assertFalse(OrderStatus::InTransit->canTransitionTo(OrderStatus::Cancelled), 'no cancellation after dispatch');

        $this->expectException(ApiException::class);
        OrderStatus::Refunded->assertCanTransitionTo(OrderStatus::Completed);
    }

    public function test_other_state_machines(): void
    {
        $this->assertTrue(ListingStatus::Draft->canTransitionTo(ListingStatus::Active));
        $this->assertFalse(ListingStatus::Removed->canTransitionTo(ListingStatus::Active));
        $this->assertFalse(ListingStatus::Sold->canTransitionTo(ListingStatus::Paused));
        $this->assertFalse(OfferStatus::Declined->canTransitionTo(OfferStatus::Accepted));
        $this->assertTrue(EscrowStatus::Held->canTransitionTo(EscrowStatus::Frozen));
        $this->assertFalse(EscrowStatus::Released->canTransitionTo(EscrowStatus::Held));
        $this->assertFalse(PaymentStatus::Failed->canTransitionTo(PaymentStatus::Successful));
        $this->assertTrue(PaymentStatus::Expired->canTransitionTo(PaymentStatus::Successful), 'late PSP success is detectable (then refunded)');
    }

    public function test_api_exception_maps_codes_to_statuses(): void
    {
        $this->assertSame(409, ApiException::invalidTransition()->httpStatus());
        $this->assertSame(403, ApiException::kycRequired()->httpStatus());
        $this->assertSame(410, ApiException::tokenExpired()->httpStatus());
        $this->assertSame(503, ApiException::gatewayUnavailable()->httpStatus());
        $this->assertSame(['email' => ['x']], ApiException::validation(['email' => 'x'])->errors);
    }
}
