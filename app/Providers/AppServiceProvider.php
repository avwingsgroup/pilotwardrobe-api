<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Resources\ListingViewContext;
use App\Models\AviatorCredential;
use App\Models\Category;
use App\Models\Dispute;
use App\Models\DisputeMessage;
use App\Models\HeroSlide;
use App\Models\KycVerification;
use App\Models\Listing;
use App\Models\ListingPromotion;
use App\Models\Message;
use App\Models\Order;
use App\Models\SupportTicket;
use App\Models\User;
use App\Services\Auth\PermissionResolver;
use App\Services\Catalogue\FitMatcher;
use App\Services\Platform\PlatformSettings;
use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Per-request state (reset between Octane/queue requests).
        $this->app->scoped(FitMatcher::class);
        $this->app->scoped(ListingViewContext::class);
        $this->app->scoped(PermissionResolver::class);
        $this->app->scoped(PlatformSettings::class);
    }

    public function boot(): void
    {
        $this->registerSchemaMacros();
        $this->configureRateLimiting();
        $this->registerValidationRules();

        // OpenAPI UI at /api/documentation, raw JSON at /openapi (access: viewApiDocs gate).
        Scramble::configure()->expose(ui: 'api/documentation', document: 'openapi');

        // Fail loudly outside production when code writes attributes that are not fillable.
        Model::preventSilentlyDiscardingAttributes(! $this->app->isProduction());
        // Relations touched on one model of a collection are eager-loaded for the whole collection (no N+1).
        Model::automaticallyEagerLoadRelationships();

        // Short, stable morph names instead of class names in polymorphic columns.
        Relation::enforceMorphMap([
            'user' => User::class,
            'listing' => Listing::class,
            'order' => Order::class,
            'dispute' => Dispute::class,
            'dispute_message' => DisputeMessage::class,
            'message' => Message::class,
            'kyc' => KycVerification::class,
            'aviator_credential' => AviatorCredential::class,
            'support_ticket' => SupportTicket::class,
            'category' => Category::class,
            'hero_slide' => HeroSlide::class,
            'listing_promotion' => ListingPromotion::class,
        ]);
    }

    private function registerValidationRules(): void
    {
        // `unique_ci:table,column[,ignoreId]` — case-insensitive uniqueness (emails, usernames).
        Validator::extend('unique_ci', function (string $attribute, mixed $value, array $parameters): bool {
            [$table, $column, $ignore] = array_pad($parameters, 3, null);
            $query = DB::table($table)->whereRaw('lower('.$column.') = ?', [mb_strtolower((string) $value)]);
            if ($ignore) {
                $query->where('id', '!=', $ignore);
            }

            return ! $query->exists();
        });
    }

    /** Endpoint-specific limits (contract §1.9). Exceeding one yields 429 RATE_LIMITED with Retry-After. */
    private function configureRateLimiting(): void
    {
        $byUserOrIp = fn (Request $request) => $request->user()?->id ?: $request->ip();

        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(120)->by($byUserOrIp($request)));
        RateLimiter::for('auth', fn (Request $request) => Limit::perMinute(10)->by('auth:'.$request->ip()));
        RateLimiter::for('otp', fn (Request $request) => [
            Limit::perMinute(1)->by('otp:'.strtolower((string) $request->input('email', $request->ip()))),
            Limit::perHour(10)->by('otp-ip:'.$request->ip()),
        ]);
        RateLimiter::for('two-factor-sms', fn (Request $request) => Limit::perMinutes(1, 2)->by('2fa-sms:'.$request->input('challenge_id', $request->ip())));
        RateLimiter::for('password-reset', fn (Request $request) => [
            Limit::perMinute(1)->by('pwreset:'.strtolower((string) $request->input('email'))),
            Limit::perHour(20)->by('pwreset-ip:'.$request->ip()),
        ]);
        RateLimiter::for('search', fn (Request $request) => Limit::perMinute(90)->by($byUserOrIp($request)));
        RateLimiter::for('messages', fn (Request $request) => Limit::perMinute(30)->by('msg:'.$byUserOrIp($request)));
        RateLimiter::for('offers', fn (Request $request) => Limit::perHour((int) config('platform.offers.per_hour_limit'))->by('offers:'.$byUserOrIp($request)));
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(30)->by('upload:'.$byUserOrIp($request)));
        RateLimiter::for('payments', fn (Request $request) => Limit::perMinute(10)->by('pay:'.$byUserOrIp($request)));
        RateLimiter::for('verification', fn (Request $request) => Limit::perHour(20)->by('kyc:'.$byUserOrIp($request)));
        RateLimiter::for('contact', fn (Request $request) => Limit::perMinute(5)->by('contact:'.$request->ip()));
        RateLimiter::for('admin', fn (Request $request) => Limit::perMinute(300)->by('admin:'.$byUserOrIp($request)));
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(600)->by('wh:'.$request->ip()));
        RateLimiter::for('health', fn (Request $request) => Limit::perMinute(60)->by('health:'.$request->ip()));
    }

    private function registerSchemaMacros(): void
    {
        /** Prefixed string primary key (`usr_01j…`). */
        Blueprint::macro('prefixedId', function (string $column = 'id'): ColumnDefinition {
            /** @var Blueprint $this */
            return $this->string($column, 40)->primary();
        });

        /** Foreign key to a prefixed-id table. `$onDelete`: restrict | cascade | null. */
        Blueprint::macro('foreignPrefixed', function (string $column, string $on, string $onDelete = 'restrict'): ColumnDefinition {
            /** @var Blueprint $this */
            $definition = $this->string($column, 40);
            $foreign = $this->foreign($column)->references('id')->on($on);
            match ($onDelete) {
                'cascade' => $foreign->cascadeOnDelete(),
                'null' => $foreign->nullOnDelete(),
                default => $foreign->restrictOnDelete(),
            };

            return $definition;
        });
    }
}
