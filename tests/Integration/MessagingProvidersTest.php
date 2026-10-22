<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Integrations\Kyc\KycCheckRequest;
use App\Integrations\Kyc\PremblyKycProvider;
use App\Integrations\Mail\ZeptoMailTransport;
use App\Integrations\Media\CloudinaryMediaStorage;
use App\Integrations\ProviderException;
use App\Integrations\Push\FcmPushProvider;
use App\Integrations\Push\InvalidDeviceToken;
use App\Integrations\Sms\FailoverSmsSender;
use App\Integrations\Sms\TermiiSmsProvider;
use App\Integrations\Sms\TwilioSmsProvider;
use App\Integrations\WhatsApp\MetaCloudWhatsAppProvider;
use App\Mail\TransactionalMail;
use App\Models\Media;
use App\Services\Auth\PermissionResolver;
use App\Services\Messaging\ConversationService;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/** Notification, media, KYC and RBAC building blocks against faked HTTP. */
final class MessagingProvidersTest extends TestCase
{
    public function test_sms_fails_over_from_termii_to_twilio_without_double_sending(): void
    {
        Http::fake([
            'api.ng.termii.com/*' => Http::response(['message' => 'Insufficient balance'], 400),
            'api.twilio.com/*' => Http::response(['sid' => 'SM123']),
        ]);
        $sender = new FailoverSmsSender([
            new TermiiSmsProvider(['api_key' => 'k', 'sender_id' => 'PW', 'base_url' => 'https://api.ng.termii.com']),
            new TwilioSmsProvider(['account_sid' => 'AC1', 'auth_token' => 't', 'from' => '+15550000000']),
        ]);

        $this->assertSame(['provider' => 'twilio', 'message_id' => 'SM123'], $sender->send('+2348000000000', 'Your code is 123456'));
        Http::assertSentCount(2);
    }

    public function test_sms_stops_after_first_success(): void
    {
        Http::fake(['api.ng.termii.com/*' => Http::response(['message_id' => 'T1']), 'api.twilio.com/*' => Http::response(['sid' => 'SM'])]);
        $sender = new FailoverSmsSender([
            new TermiiSmsProvider(['api_key' => 'k', 'sender_id' => 'PW', 'base_url' => 'https://api.ng.termii.com']),
            new TwilioSmsProvider(['account_sid' => 'AC1', 'auth_token' => 't', 'from' => '+1']),
        ]);

        $this->assertSame('termii', $sender->send('+2348000000000', 'x')['provider']);
        Http::assertSentCount(1);
    }

    public function test_whatsapp_template_payload(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]])]);
        $provider = new MetaCloudWhatsAppProvider(['access_token' => 'tok', 'phone_number_id' => '999', 'api_version' => 'v21.0']);

        $this->assertSame('wamid.X', $provider->sendTemplate('+2348000000000', 'order_paid', 'en', ['PW-1', '$190.61']));
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://graph.facebook.com/v21.0/999/messages'
            && $r['template']['name'] === 'order_paid' && $r['to'] === '2348000000000'
            && $r['template']['components'][0]['parameters'][1]['text'] === '$190.61');
    }

    public function test_fcm_flags_unregistered_tokens(): void
    {
        [$key, $pem] = $this->serviceAccountKey();
        Cache::forget('fcm:access_token');
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'ya29']),
            'fcm.googleapis.com/*' => Http::response(['error' => ['status' => 'NOT_FOUND', 'details' => [['errorCode' => 'UNREGISTERED']]]], 404),
        ]);
        $provider = new FcmPushProvider(['project_id' => 'pw-test', 'credentials' => json_encode(['client_email' => 'svc@pw.iam', 'private_key' => $pem, 'project_id' => 'pw-test'])]);

        $this->expectException(InvalidDeviceToken::class);
        $provider->send('stale-token', 'Hi', 'Body');
    }

    public function test_zeptomail_transport_maps_message(): void
    {
        Http::fake(['api.zeptomail.com/*' => Http::response(['request_id' => 'req-1'], 201)]);
        $transport = new ZeptoMailTransport('zm-token');
        $email = (new Email)->from(new Address('no-reply@pilotwardrobe.com', 'Pilotwardrobe'))
            ->to('ada@example.com')->subject('Hello')->html('<p>Hi</p>')->text('Hi');

        $transport->send($email, new Envelope(new Address('no-reply@pilotwardrobe.com'), [new Address('ada@example.com')]));

        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Zoho-enczapikey zm-token')
            && $r['subject'] === 'Hello' && $r['to'][0]['email_address']['address'] === 'ada@example.com' && $r['htmlbody'] === '<p>Hi</p>');
    }

    public function test_transactional_mail_has_html_and_text_parts(): void
    {
        $mail = new TransactionalMail(['subject' => 'Order #PW-1 confirmed', 'heading' => 'Payment secured', 'lines' => ['We received $190.61.'], 'cta' => ['label' => 'Track order', 'url' => 'https://pilotwardrobe.test/hangar/orders/x']]);
        $mail->assertSeeInHtml('Payment secured')->assertSeeInText('Track order: https://pilotwardrobe.test/hangar/orders/x')->assertHasSubject('Order #PW-1 confirmed');
    }

    public function test_cloudinary_builds_transformation_urls_without_api_calls(): void
    {
        $storage = new CloudinaryMediaStorage(['cloud_name' => 'pilotwardrobe', 'api_key' => 'k', 'api_secret' => 's']);
        $media = new Media(['public_id' => 'pilotwardrobe/listings/usr_1/med_1', 'resource_type' => 'image', 'delivery_type' => 'upload', 'format' => 'jpg', 'version' => 17]);

        $this->assertSame('https://res.cloudinary.com/pilotwardrobe/image/upload/c_limit,w_640,q_auto,f_auto/v17/pilotwardrobe/listings/usr_1/med_1.jpg',
            $storage->url($media, 'c_limit,w_640,q_auto,f_auto'));
        $signed = $storage->temporaryUrl(new Media(['public_id' => 'pilotwardrobe/verification/usr_1/med_2', 'resource_type' => 'image', 'delivery_type' => 'authenticated', 'format' => 'jpg']), 600);
        $this->assertStringContainsString('expires_at=', $signed);
        $this->assertStringContainsString('signature=', $signed);
        $this->assertStringNotContainsString('api_secret', $signed);
    }

    public function test_prembly_lookup_compares_names(): void
    {
        Http::fake([
            'api.prembly.com/identitypass/verification/vnin' => Http::response(['status' => true, 'response_code' => '00', 'data' => ['firstname' => 'ADA', 'lastname' => 'OBI', 'birthdate' => '1994-03-14']]),
        ]);
        $provider = new PremblyKycProvider(['api_key' => 'k', 'app_id' => 'a', 'base_url' => 'https://api.prembly.com']);

        $result = $provider->runChecks(new KycCheckRequest('KY-1', 'Ada', 'Obi', '1994-03-14', 'nin', '12345678901', 'NG', null, null));
        $this->assertTrue($result->checks[0]['passed']);
        $this->assertTrue($result->extracted[0]['match']);
        $this->assertTrue($result->extracted[1]['match']);
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('x-api-key', 'k') && $r->hasHeader('app-id', 'a'));

        $this->expectException(ProviderException::class);
        (new PremblyKycProvider(['api_key' => '', 'app_id' => '', 'base_url' => 'x']))->runChecks(new KycCheckRequest('KY', 'A', 'B', null, 'nin', '1', 'NG', null, null));
    }

    public function test_off_platform_payment_detection(): void
    {
        $flags = app(ConversationService::class);
        $this->assertSame([], $flags->flags('Is it still available? Can you ship to Abuja?'));
        $this->assertContains('off_platform_payment', $flags->flags('Pay outside the app and I will give discount'));
        $this->assertContains('phone_number', $flags->flags('Call me on +234 803 123 4567'));
        $this->assertContains('off_platform_payment', $flags->flags('Account number 0123456789 GTBank'));
    }

    public function test_permission_matrix_expansion(): void
    {
        $manage = PermissionResolver::fromMatrix(['refunds' => 'manage', 'fees' => 'view']);
        $this->assertContains('transactions.refund', $manage);
        $this->assertContains('fees.view', $manage);
        $this->assertNotContains('fees.manage', $manage);
        $this->assertSame([], PermissionResolver::fromMatrix(['users' => 'none']));
    }

    /** @return array{0: \OpenSSLAsymmetricKey, 1: string} */
    private function serviceAccountKey(): array
    {
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA];
        // Windows PHP builds ship openssl.cnf next to the binary rather than in a default location.
        $windowsConf = dirname(PHP_BINARY).'/extras/ssl/openssl.cnf';
        if (is_file($windowsConf)) {
            $options['config'] = $windowsConf;
        }
        $key = openssl_pkey_new($options);
        openssl_pkey_export($key, $pem, null, $options);

        return [$key, $pem];
    }
}
