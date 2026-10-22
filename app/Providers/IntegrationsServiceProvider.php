<?php

declare(strict_types=1);

namespace App\Providers;

use App\Integrations\Kyc\FakeKycProvider;
use App\Integrations\Kyc\KycProvider;
use App\Integrations\Kyc\PremblyKycProvider;
use App\Integrations\Mail\ZeptoMailTransport;
use App\Integrations\Media\CloudinaryMediaStorage;
use App\Integrations\Media\LocalMediaStorage;
use App\Integrations\Media\MediaStorage;
use App\Integrations\Payments\GatewayRegistry;
use App\Integrations\Payouts\TransferProviderRegistry;
use App\Integrations\Push\FcmPushProvider;
use App\Integrations\Push\LogPushProvider;
use App\Integrations\Push\PushProvider;
use App\Integrations\Sms\FailoverSmsSender;
use App\Integrations\Sms\LogSmsProvider;
use App\Integrations\Sms\TermiiSmsProvider;
use App\Integrations\Sms\TwilioSmsProvider;
use App\Integrations\WhatsApp\LogWhatsAppProvider;
use App\Integrations\WhatsApp\MetaCloudWhatsAppProvider;
use App\Integrations\WhatsApp\WhatsAppProvider;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\ServiceProvider;

/**
 * Binds every third-party contract to its configured adapter. Business code depends
 * only on the contracts, so providers can be swapped by configuration.
 */
final class IntegrationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MediaStorage::class, fn () => match (config('services.media.driver')) {
            'local' => new LocalMediaStorage,
            default => new CloudinaryMediaStorage(config('services.cloudinary')),
        });

        $this->app->singleton(FailoverSmsSender::class, function () {
            $providers = [];
            foreach (config('services.sms.providers') as $name) {
                $providers[] = match (trim($name)) {
                    'termii' => new TermiiSmsProvider(config('services.termii')),
                    'twilio' => new TwilioSmsProvider(config('services.twilio')),
                    'log' => new LogSmsProvider,
                    default => null,
                };
            }

            return new FailoverSmsSender(array_values(array_filter($providers)));
        });

        $this->app->singleton(PushProvider::class, fn () => match (config('services.fcm.driver')) {
            'log' => new LogPushProvider,
            default => new FcmPushProvider(config('services.fcm')),
        });

        $this->app->singleton(WhatsAppProvider::class, fn () => match (config('services.whatsapp.driver')) {
            'log' => new LogWhatsAppProvider,
            default => new MetaCloudWhatsAppProvider(config('services.whatsapp')),
        });

        $this->app->singleton(KycProvider::class, fn () => match (config('services.kyc.driver')) {
            'fake' => new FakeKycProvider,
            default => new PremblyKycProvider(config('services.prembly')),
        });

        $this->app->singleton(GatewayRegistry::class);
        $this->app->singleton(TransferProviderRegistry::class);
    }

    public function boot(): void
    {
        Mail::extend('zeptomail', fn () => new ZeptoMailTransport(
            (string) config('services.zeptomail.token'),
            (string) config('services.zeptomail.endpoint'),
            config('services.zeptomail.bounce_address'),
        ));
    }
}
