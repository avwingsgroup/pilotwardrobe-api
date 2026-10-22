<?php

/*
|--------------------------------------------------------------------------
| Notification templates (contract §3)
|--------------------------------------------------------------------------
|
| One entry per template key. A single dispatch fans out to every channel the
| template defines and the recipient's preferences allow:
|
|   in_app   → `notifications` row (VB11)          title/body/link/actions
|   email    → ZeptoMail (queued)                   subject/heading/lines/cta
|   push     → Firebase Cloud Messaging (queued)    uses title/body
|   sms      → Termii, failover Twilio (queued)     `sms` text
|   whatsapp → WhatsApp Cloud API template (queued) `whatsapp` template name
|
| `transactional: true` emails always send (auth, security, orders, payments,
| disputes). Others respect /me/notification-preferences.
| `sensitive: true` templates carry codes or sign-in tokens; their stored delivery
| payload is replaced by `{redacted: true}` once delivery is final.
| Placeholders use `:name` and are filled from the dispatch data. Paths are
| relative to APP_FRONTEND_URL; the SPA only follows relative links.
*/

return [

    // ---------------------------------------------------------------- auth & security
    'auth.verify_email' => [
        // Carries a code or sign-in token: the stored delivery payload is redacted once sent.
        'category' => 'account', 'transactional' => true, 'sensitive' => true,
        'email' => ['subject' => 'Your Pilotwardrobe verification code', 'heading' => 'Confirm your email',
            'lines' => ['Use this code to verify your email address:', ':code', 'The code expires in 10 minutes. If you did not create an account, ignore this email.']],
    ],
    'auth.welcome' => [
        'category' => 'account', 'transactional' => true, 'icon' => 'verified', 'in_app' => true,
        'title' => 'Welcome aboard, :first_name', 'body' => 'Your account is ready. Set up your Fit Kit and list your first item.', 'link' => '/hangar',
        'email' => ['subject' => 'Welcome to Pilotwardrobe', 'heading' => 'Welcome aboard, :first_name',
            'lines' => ['Your account is ready. Buy, sell and swap aviation gear with funds held in escrow until it lands.'], 'cta' => ['Go to My Hangar', '/hangar']],
    ],
    'auth.password_reset' => [
        // Carries a code or sign-in token: the stored delivery payload is redacted once sent.
        'category' => 'account', 'transactional' => true, 'sensitive' => true,
        'email' => ['subject' => 'Reset your Pilotwardrobe password', 'heading' => 'Reset your password',
            'lines' => ['We received a request to reset your password. The link is valid for 60 minutes.', 'If you did not ask for this, you can ignore this email.'],
            'cta' => ['Reset password', '/auth/reset?token=:token&email=:email']],
    ],
    'auth.invitation' => [
        // Carries a code or sign-in token: the stored delivery payload is redacted once sent.
        'category' => 'account', 'transactional' => true, 'sensitive' => true,
        'email' => ['subject' => "You're invited to Pilotwardrobe", 'heading' => 'Join Pilotwardrobe',
            'lines' => [':inviter invited you to join Pilotwardrobe.'], 'cta' => ['Create your account', '/auth/signup?invite=:token']],
    ],
    'staff.invitation' => [
        'category' => 'account', 'transactional' => true,
        'email' => ['subject' => 'Your Pilotwardrobe Control Tower access', 'heading' => 'Control Tower invitation',
            'lines' => [':inviter added you to the :role team. Sign in with your company Google account and register a security key.'], 'cta' => ['Open Control Tower', '/admin/sign-in']],
    ],
    'security.two_factor_code' => [
        // Carries a code or sign-in token: the stored delivery payload is redacted once sent.
        'category' => 'account', 'transactional' => true, 'sensitive' => true,
        'sms' => 'Your Pilotwardrobe sign-in code is :code. It expires in 10 minutes. Never share it.',
    ],
    'security.new_device' => [
        'category' => 'account', 'transactional' => true, 'icon' => 'verified', 'in_app' => true,
        'title' => 'New sign-in from :device', 'body' => ':location · if this was not you, secure your account.', 'link' => '/hangar/settings/security',
        'email' => ['subject' => 'New sign-in to your Pilotwardrobe account', 'heading' => 'New sign-in detected',
            'lines' => ['Device: :device', 'Location: :location', 'Time: :time', 'If this was not you, change your password and sign out other devices.'], 'cta' => ['Review security', '/hangar/settings/security']],
    ],
    'security.password_changed' => [
        'category' => 'account', 'transactional' => true,
        'email' => ['subject' => 'Your password was changed', 'heading' => 'Password changed',
            'lines' => ['The password for your Pilotwardrobe account was just changed.', 'If you did not do this, reset your password immediately and contact support.'], 'cta' => ['Review security', '/hangar/settings/security']],
    ],
    'security.sessions_revoked' => [
        'category' => 'account', 'transactional' => true,
        'email' => ['subject' => 'You signed out of other devices', 'heading' => 'Other sessions ended',
            'lines' => ['All other devices have been signed out of your account.'], 'cta' => ['Review security', '/hangar/settings/security']],
    ],
    'security.provider_linked' => [
        'category' => 'account', 'transactional' => true,
        'email' => ['subject' => ':provider sign-in linked', 'heading' => ':provider linked',
            'lines' => ['You can now sign in to Pilotwardrobe with :provider.', 'If you did not do this, contact support immediately.'], 'cta' => ['Review security', '/hangar/settings/security']],
    ],
    'security.two_factor_changed' => [
        'category' => 'account', 'transactional' => true,
        'email' => ['subject' => 'Two-step verification :state', 'heading' => 'Two-step verification :state',
            'lines' => ['Two-step verification was :state on your account.'], 'cta' => ['Review security', '/hangar/settings/security']],
    ],
    'security.payout_method_added' => [
        'category' => 'payouts', 'transactional' => true,
        'email' => ['subject' => 'New payout method added', 'heading' => 'Payout method added',
            'lines' => [':label was added as a payout method.', 'If you did not add it, contact support immediately — withdrawals to new methods are reviewed.'], 'cta' => ['Open wallet', '/hangar/wallet']],
    ],
    'security.impersonated' => [
        'category' => 'account', 'icon' => 'verified', 'in_app' => true,
        'title' => 'Support viewed your account', 'body' => ':staff opened a read-only support session.', 'link' => '/hangar/settings/security',
    ],

    // ---------------------------------------------------------------- account status
    'account.deactivated' => ['category' => 'account', 'transactional' => true,
        'email' => ['subject' => 'Your account is deactivated', 'heading' => 'Account deactivated',
            'lines' => ['Your listings are hidden. Sign in again any time to reactivate your account.']]],
    'account.deleted' => ['category' => 'account', 'transactional' => true,
        'email' => ['subject' => 'Your account was deleted', 'heading' => 'Account deleted',
            'lines' => ['Your Pilotwardrobe account has been permanently deleted. Records we must keep by law are retained as described in our privacy policy.']]],
    'account.suspended' => ['category' => 'account', 'transactional' => true,
        'email' => ['subject' => 'Your account is suspended', 'heading' => 'Account suspended',
            'lines' => ['Your account has been suspended: :reason', 'If you believe this is a mistake, contact us.'], 'cta' => ['Contact support', '/contact']]],
    'account.reinstated' => ['category' => 'account', 'transactional' => true, 'icon' => 'verified', 'in_app' => true,
        'title' => 'Your account is active again', 'body' => 'Thanks for your patience.', 'link' => '/hangar',
        'email' => ['subject' => 'Your account is active again', 'heading' => 'Account reinstated',
            'lines' => ['Your account has been reinstated.'], 'cta' => ['Go to My Hangar', '/hangar']]],
    'account.restricted' => ['category' => 'account', 'transactional' => true, 'icon' => 'verified', 'in_app' => true,
        'title' => 'Some account features are restricted', 'body' => ':reason', 'link' => '/contact',
        'email' => ['subject' => 'Some features on your account are restricted', 'heading' => 'Account restricted',
            'lines' => ['We restricted some features on your account: :reason'], 'cta' => ['Contact support', '/contact']]],

    // ---------------------------------------------------------------- verification
    'kyc.submitted' => ['category' => 'account', 'transactional' => true, 'icon' => 'verified', 'in_app' => true,
        'title' => 'Identity verification submitted', 'body' => 'We usually review within 24 hours.', 'link' => '/hangar/verification',
        'email' => ['subject' => 'We received your verification', 'heading' => 'Verification submitted',
            'lines' => ['Thanks — we are reviewing your documents and usually finish within 24 hours.'], 'cta' => ['View status', '/hangar/verification']]],
    'kyc.approved' => ['category' => 'account', 'transactional' => true, 'icon' => 'verified', 'in_app' => true, 'push' => true,
        'title' => 'You are verified', 'body' => 'Swaps, unlimited listings and withdrawals are unlocked.', 'link' => '/hangar/verification',
        'email' => ['subject' => 'Your identity is verified', 'heading' => 'You are verified',
            'lines' => ['Swaps, unlimited listings and withdrawals are now unlocked.'], 'cta' => ['Go to My Hangar', '/hangar']]],
    'kyc.rejected' => ['category' => 'account', 'transactional' => true, 'icon' => 'verified', 'in_app' => true, 'push' => true,
        'title' => 'Verification unsuccessful', 'body' => ':reason', 'link' => '/hangar/verification',
        'email' => ['subject' => 'We could not verify your identity', 'heading' => 'Verification unsuccessful',
            'lines' => ['Reason: :reason', 'You can submit again with clearer documents.'], 'cta' => ['Try again', '/hangar/verification']]],
    'kyc.more_info' => ['category' => 'account', 'transactional' => true, 'icon' => 'verified', 'in_app' => true, 'push' => true,
        'title' => 'We need a little more information', 'body' => ':reason', 'link' => '/hangar/verification',
        'email' => ['subject' => 'More information needed for verification', 'heading' => 'More information needed',
            'lines' => [':reason'], 'cta' => ['Update verification', '/hangar/verification']]],
    'aviator.submitted' => ['category' => 'account', 'transactional' => true,
        'email' => ['subject' => 'Aviator credentials received', 'heading' => 'Credentials submitted',
            'lines' => ['We are checking your licence details.'], 'cta' => ['View status', '/hangar/aviator']]],
    'aviator.approved' => ['category' => 'account', 'transactional' => true, 'icon' => 'verified', 'in_app' => true, 'push' => true,
        'title' => 'Aviator badge approved', 'body' => 'Your profile now shows the verified aviator badge.', 'link' => '/hangar/aviator',
        'email' => ['subject' => 'You are a verified aviator', 'heading' => 'Aviator badge approved',
            'lines' => ['Your profile now shows the verified aviator badge, and your listing cap is lifted.'], 'cta' => ['View profile', '/hangar/aviator']]],
    'aviator.rejected' => ['category' => 'account', 'transactional' => true, 'icon' => 'verified', 'in_app' => true,
        'title' => 'Aviator credentials not approved', 'body' => ':reason', 'link' => '/hangar/aviator',
        'email' => ['subject' => 'Aviator credentials not approved', 'heading' => 'Credentials not approved',
            'lines' => ['Reason: :reason'], 'cta' => ['Update credentials', '/hangar/aviator']]],

    // ---------------------------------------------------------------- listings
    'listing.published' => ['category' => 'account', 'icon' => 'verified', 'in_app' => true,
        'title' => 'Your listing is live', 'body' => ':title', 'link' => '/marketplace/:slug',
        'email' => ['subject' => 'Your listing is live', 'heading' => 'Listing published', 'lines' => [':title is now live on the marketplace.'], 'cta' => ['View listing', '/marketplace/:slug']]],
    'listing.in_review' => ['category' => 'account', 'icon' => 'verified', 'in_app' => true,
        'title' => 'Listing submitted for review', 'body' => ':title', 'link' => '/hangar/listings/:listing_id/edit',
        'email' => ['subject' => 'Your listing is in review', 'heading' => 'Listing in review', 'lines' => ['We are reviewing :title and will notify you shortly.']]],
    'listing.approved' => ['category' => 'account', 'icon' => 'verified', 'in_app' => true, 'push' => true,
        'title' => 'Listing approved', 'body' => ':title is now live.', 'link' => '/marketplace/:slug',
        'email' => ['subject' => 'Your listing was approved', 'heading' => 'Listing approved', 'lines' => [':title is now live.'], 'cta' => ['View listing', '/marketplace/:slug']]],
    'listing.changes_requested' => ['category' => 'account', 'icon' => 'verified', 'in_app' => true, 'push' => true,
        'title' => 'Changes requested on your listing', 'body' => ':note', 'link' => '/hangar/listings/:listing_id/edit',
        'email' => ['subject' => 'Changes requested on your listing', 'heading' => 'Changes requested', 'lines' => [':title', 'Moderator note: :note'], 'cta' => ['Edit listing', '/hangar/listings/:listing_id/edit']]],
    'listing.removed' => ['category' => 'account', 'transactional' => true, 'icon' => 'verified', 'in_app' => true,
        'title' => 'Listing removed', 'body' => ':title · :note', 'link' => '/hangar/listings',
        'email' => ['subject' => 'Your listing was removed', 'heading' => 'Listing removed', 'lines' => [':title was removed: :note'], 'cta' => ['Contact support', '/contact']]],
    'listing.price_drop' => ['category' => 'marketing', 'icon' => 'offer', 'in_app' => true, 'push' => true,
        'title' => 'Price drop on a saved item', 'body' => ':title is now :price', 'link' => '/marketplace/:slug'],
    'seller.new_follower' => ['category' => 'account', 'icon' => 'verified', 'in_app' => true,
        'title' => ':follower followed your hangar', 'body' => 'They will see your new listings first.', 'link' => '/hangar'],

    // ---------------------------------------------------------------- offers & swaps
    'offer.received' => ['category' => 'offers_swaps', 'icon' => 'offer', 'in_app' => true, 'push' => true,
        'title' => 'New offer — :amount on :title', 'body' => ':counterparty · expires in 24h', 'link' => '/hangar/offers?id=:offer_id',
        'actions' => [['label' => 'Review offer', 'to' => '/hangar/offers?id=:offer_id', 'variant' => 'primary']],
        'email' => ['subject' => 'New offer: :amount on :title', 'heading' => 'You have a new offer', 'lines' => [':counterparty offered :amount for :title.', 'The offer expires in 24 hours.'], 'cta' => ['Review offer', '/hangar/offers?id=:offer_id']]],
    'offer.countered' => ['category' => 'offers_swaps', 'icon' => 'offer', 'in_app' => true, 'push' => true,
        'title' => 'Counter-offer — :amount on :title', 'body' => 'The seller countered your offer.', 'link' => '/hangar/offers?box=sent&id=:offer_id',
        'email' => ['subject' => 'Counter-offer on :title', 'heading' => 'The seller countered', 'lines' => ['The seller countered at :amount for :title.'], 'cta' => ['Review counter', '/hangar/offers?box=sent&id=:offer_id']]],
    'offer.accepted' => ['category' => 'offers_swaps', 'transactional' => true, 'icon' => 'offer', 'in_app' => true, 'push' => true,
        'title' => 'Offer accepted — :amount', 'body' => ':title is reserved for you. Check out within 24h.', 'link' => '/checkout?listing=:listing_id&offer=:offer_id',
        'actions' => [['label' => 'Check out', 'to' => '/checkout?listing=:listing_id&offer=:offer_id', 'variant' => 'primary']],
        'email' => ['subject' => 'Your offer was accepted', 'heading' => 'Offer accepted', 'lines' => ['Your offer of :amount for :title was accepted. The item is reserved for you for 24 hours.'], 'cta' => ['Check out now', '/checkout?listing=:listing_id&offer=:offer_id']]],
    'offer.declined' => ['category' => 'offers_swaps', 'icon' => 'offer', 'in_app' => true,
        'title' => 'Offer declined', 'body' => 'Your :amount offer on :title was declined.', 'link' => '/marketplace/:slug',
        'email' => ['subject' => 'Your offer was declined', 'heading' => 'Offer declined', 'lines' => ['Your offer of :amount for :title was declined.'], 'cta' => ['View listing', '/marketplace/:slug']]],
    'offer.expired' => ['category' => 'offers_swaps', 'icon' => 'offer', 'in_app' => true,
        'title' => 'Offer expired', 'body' => 'The :amount offer on :title expired.', 'link' => '/hangar/offers',
        'email' => ['subject' => 'An offer expired', 'heading' => 'Offer expired', 'lines' => ['The :amount offer on :title expired without a response.']]],
    'swap.proposed' => ['category' => 'offers_swaps', 'icon' => 'offer', 'in_app' => true, 'push' => true,
        'title' => 'Swap proposed for :title', 'body' => ':counterparty · :summary', 'link' => '/hangar/offers?tab=swaps&id=:swap_id',
        'email' => ['subject' => 'Swap proposal for :title', 'heading' => 'New swap proposal', 'lines' => [':counterparty proposed a swap for :title: :summary'], 'cta' => ['Review swap', '/hangar/offers?tab=swaps&id=:swap_id']]],
    'swap.accepted' => ['category' => 'offers_swaps', 'transactional' => true, 'icon' => 'offer', 'in_app' => true, 'push' => true,
        'title' => 'Swap accepted', 'body' => 'Complete payment for your leg to start shipping.', 'link' => '/hangar/orders?role=buying',
        'email' => ['subject' => 'Your swap was accepted', 'heading' => 'Swap accepted', 'lines' => ['Your swap for :title was accepted. Pay your leg (freight, fee and any cash top-up) to start shipping.'], 'cta' => ['View orders', '/hangar/orders?role=buying']]],
    'swap.rejected' => ['category' => 'offers_swaps', 'icon' => 'offer', 'in_app' => true,
        'title' => 'Swap declined', 'body' => 'Your swap proposal for :title was declined.', 'link' => '/hangar/offers?tab=swaps',
        'email' => ['subject' => 'Your swap was declined', 'heading' => 'Swap declined', 'lines' => ['Your swap proposal for :title was declined.']]],
    'swap.expired' => ['category' => 'offers_swaps', 'icon' => 'offer', 'in_app' => true,
        'title' => 'Swap expired', 'body' => 'The swap proposal for :title expired.', 'link' => '/hangar/offers?tab=swaps',
        'email' => ['subject' => 'A swap proposal expired', 'heading' => 'Swap expired', 'lines' => ['The swap proposal for :title expired.']]],

    // ---------------------------------------------------------------- orders
    'order.paid' => ['category' => 'orders', 'transactional' => true, 'icon' => 'order', 'in_app' => true, 'push' => true,
        'title' => 'Payment secured · Order #:number', 'body' => ':total held in escrow until it lands.', 'link' => '/hangar/orders/:order_id',
        'whatsapp' => 'order_paid',
        'email' => ['subject' => 'Order #:number confirmed', 'heading' => 'Payment secured in escrow', 'lines' => ['We received :total for order #:number.', 'The seller has been notified and will dispatch soon. Funds stay in escrow until you confirm the item landed.'], 'cta' => ['Track order', '/hangar/orders/:order_id']]],
    'order.new_sale' => ['category' => 'orders', 'transactional' => true, 'icon' => 'order', 'in_app' => true, 'push' => true,
        'title' => 'New sale · Order #:number', 'body' => 'Dispatch by :dispatch_due to keep your on-time rate.', 'link' => '/hangar/orders/:order_id',
        'sms' => 'Pilotwardrobe: new sale #:number. Dispatch by :dispatch_due.', 'whatsapp' => 'order_new_sale',
        'email' => ['subject' => 'You made a sale — order #:number', 'heading' => 'New sale', 'lines' => ['The buyer paid for order #:number. Funds are in escrow.', 'Dispatch by :dispatch_due and add tracking.'], 'cta' => ['Dispatch order', '/hangar/orders/:order_id']]],
    'order.dispatched' => ['category' => 'orders', 'transactional' => true, 'icon' => 'order', 'in_app' => true, 'push' => true,
        'title' => 'Order #:number is airborne', 'body' => ':carrier · :tracking_number', 'link' => '/hangar/orders/:order_id', 'whatsapp' => 'order_dispatched',
        'email' => ['subject' => 'Order #:number has shipped', 'heading' => 'Wheels up', 'lines' => ['Your order was dispatched with :carrier.', 'Tracking: :tracking_number'], 'cta' => ['Track order', '/hangar/orders/:order_id']]],
    'order.delivered' => ['category' => 'orders', 'transactional' => true, 'icon' => 'landed', 'in_app' => true, 'push' => true,
        'title' => 'Order #:number has landed', 'body' => 'Confirm it arrived as described, or open a dispute within :window.', 'link' => '/hangar/orders/:order_id?confirm=1',
        'email' => ['subject' => 'Order #:number was delivered', 'heading' => 'Your order landed', 'lines' => ['Check the item and confirm it arrived as described. Escrow releases automatically after :window.'], 'cta' => ['Confirm arrival', '/hangar/orders/:order_id?confirm=1']]],
    'order.delivered_confirm' => ['category' => 'orders', 'transactional' => true, 'icon' => 'landed', 'in_app' => true, 'push' => true,
        'title' => 'Did order #:number arrive OK?', 'body' => 'Escrow releases automatically soon.', 'link' => '/hangar/orders/:order_id?confirm=1',
        'email' => ['subject' => 'Please confirm order #:number', 'heading' => 'Did it arrive as described?', 'lines' => ['Order #:number was delivered. Confirm it landed as described, or open a dispute before escrow releases.'], 'cta' => ['Confirm arrival', '/hangar/orders/:order_id?confirm=1']]],
    'order.completed' => ['category' => 'orders', 'transactional' => true, 'icon' => 'payout', 'in_app' => true, 'push' => true,
        'title' => 'Escrow released · Order #:number', 'body' => ':net added to your wallet.', 'link' => '/hangar/wallet',
        'email' => ['subject' => 'Funds released for order #:number', 'heading' => 'Escrow released', 'lines' => [':net from order #:number is now in your wallet.'], 'cta' => ['Open wallet', '/hangar/wallet']]],
    'order.cancelled' => ['category' => 'orders', 'transactional' => true, 'icon' => 'order', 'in_app' => true, 'push' => true,
        'title' => 'Order #:number cancelled', 'body' => ':reason', 'link' => '/hangar/orders/:order_id',
        'email' => ['subject' => 'Order #:number was cancelled', 'heading' => 'Order cancelled', 'lines' => ['Order #:number was cancelled: :reason', 'Any payment is refunded to the original method.'], 'cta' => ['View order', '/hangar/orders/:order_id']]],
    'order.refunded' => ['category' => 'orders', 'transactional' => true, 'icon' => 'payout', 'in_app' => true, 'push' => true,
        'title' => 'Refund issued · Order #:number', 'body' => ':amount back to your original payment method.', 'link' => '/hangar/orders/:order_id',
        'email' => ['subject' => 'Refund for order #:number', 'heading' => 'Refund issued', 'lines' => ['We refunded :amount for order #:number to your original payment method.', 'Banks can take 5–10 business days to show it.'], 'cta' => ['View order', '/hangar/orders/:order_id']]],
    'order.payment_failed' => ['category' => 'orders', 'transactional' => true, 'icon' => 'order', 'in_app' => true,
        'title' => 'Payment for order #:number did not go through', 'body' => ':reason', 'link' => '/hangar/orders/:order_id'],

    // ---------------------------------------------------------------- wallet & payouts
    'payout.requested' => ['category' => 'payouts', 'transactional' => true, 'icon' => 'payout', 'in_app' => true,
        'title' => 'Withdrawal requested · :amount', 'body' => 'To :destination', 'link' => '/hangar/wallet',
        'email' => ['subject' => 'Withdrawal of :amount requested', 'heading' => 'Withdrawal requested', 'lines' => ['We are processing your withdrawal of :amount to :destination.'], 'cta' => ['Open wallet', '/hangar/wallet']]],
    'payout.sent' => ['category' => 'payouts', 'transactional' => true, 'icon' => 'payout', 'in_app' => true, 'push' => true,
        'title' => 'Payout sent · :amount', 'body' => 'To :destination', 'link' => '/hangar/wallet', 'sms' => 'Pilotwardrobe: payout of :amount sent to :destination.',
        'email' => ['subject' => 'Your payout of :amount is on its way', 'heading' => 'Payout sent', 'lines' => [':amount was sent to :destination.'], 'cta' => ['Open wallet', '/hangar/wallet']]],
    'payout.on_hold' => ['category' => 'payouts', 'transactional' => true, 'icon' => 'payout', 'in_app' => true,
        'title' => 'Payout on hold · :amount', 'body' => ':reason', 'link' => '/hangar/wallet',
        'email' => ['subject' => 'Your payout is on hold', 'heading' => 'Payout on hold', 'lines' => ['Your payout of :amount is on hold: :reason'], 'cta' => ['Contact support', '/contact']]],
    'payout.rejected' => ['category' => 'payouts', 'transactional' => true, 'icon' => 'payout', 'in_app' => true,
        'title' => 'Payout rejected · :amount', 'body' => 'Funds are back in your wallet. :reason', 'link' => '/hangar/wallet',
        'email' => ['subject' => 'Your payout was not processed', 'heading' => 'Payout rejected', 'lines' => ['Your payout of :amount was rejected and the funds returned to your wallet: :reason'], 'cta' => ['Open wallet', '/hangar/wallet']]],
    'payout.available' => ['category' => 'payouts', 'transactional' => true, 'icon' => 'payout', 'in_app' => true,
        'title' => ':amount available to withdraw', 'body' => 'From order #:number', 'link' => '/hangar/wallet',
        'email' => ['subject' => ':amount is available to withdraw', 'heading' => 'Funds available', 'lines' => [':amount from order #:number is now available in your wallet.'], 'cta' => ['Withdraw', '/hangar/wallet']]],

    // ---------------------------------------------------------------- disputes
    'dispute.opened' => ['category' => 'disputes', 'transactional' => true, 'icon' => 'dispute', 'in_app' => true, 'push' => true,
        'title' => 'Dispute opened · Order #:order_number', 'body' => 'Respond within 48h. Escrow is frozen.', 'link' => '/hangar/disputes/:dispute_id',
        'email' => ['subject' => 'A buyer opened a dispute on order #:order_number', 'heading' => 'Dispute opened', 'lines' => ['Reason: :reason', 'Please respond within 48 hours. Escrow is frozen until the case is resolved.'], 'cta' => ['Respond', '/hangar/disputes/:dispute_id']]],
    'dispute.received' => ['category' => 'disputes', 'transactional' => true, 'icon' => 'dispute', 'in_app' => true,
        'title' => 'We received your dispute :number', 'body' => 'The seller has 48h to respond.', 'link' => '/hangar/disputes/:dispute_id',
        'email' => ['subject' => 'Dispute :number received', 'heading' => 'Dispute received', 'lines' => ['Escrow is frozen. The seller has 48 hours to respond, then a mediator reviews the case.'], 'cta' => ['View case', '/hangar/disputes/:dispute_id']]],
    'dispute.updated' => ['category' => 'disputes', 'transactional' => true, 'icon' => 'dispute', 'in_app' => true, 'push' => true,
        'title' => 'Update on dispute :number', 'body' => ':summary', 'link' => '/hangar/disputes/:dispute_id',
        'email' => ['subject' => 'Update on dispute :number', 'heading' => 'Dispute update', 'lines' => [':summary'], 'cta' => ['View case', '/hangar/disputes/:dispute_id']]],
    'dispute.resolved' => ['category' => 'disputes', 'transactional' => true, 'icon' => 'dispute', 'in_app' => true, 'push' => true,
        'title' => 'Dispute :number resolved', 'body' => ':summary', 'link' => '/hangar/disputes/:dispute_id',
        'email' => ['subject' => 'Dispute :number resolved', 'heading' => 'Dispute resolved', 'lines' => [':summary'], 'cta' => ['View case', '/hangar/disputes/:dispute_id']]],

    // ---------------------------------------------------------------- reviews & messages
    'review.received' => ['category' => 'reviews', 'icon' => 'review', 'in_app' => true, 'push' => true,
        'title' => 'New :rating★ review', 'body' => ':author: “:comment”', 'link' => '/hangar/reviews',
        'email' => ['subject' => 'You received a :rating-star review', 'heading' => 'New review', 'lines' => [':author left you a :rating-star review.'], 'cta' => ['Read review', '/hangar/reviews']]],
    'message.received' => ['category' => 'messages', 'icon' => 'message', 'push' => true,
        'title' => 'New message from :sender', 'body' => ':preview', 'link' => '/messages/:conversation_id',
        'email' => ['subject' => 'New message from :sender', 'heading' => 'You have a new message', 'lines' => [':sender: “:preview”'], 'cta' => ['Reply', '/messages/:conversation_id']]],

    // ---------------------------------------------------------------- marketing, support, system
    'marketing.confirm_subscription' => ['category' => 'marketing', 'transactional' => true,
        'email' => ['subject' => 'Confirm your Pilotwardrobe newsletter subscription', 'heading' => 'One more step',
            'lines' => ['Confirm that you want news, drops and deals from Pilotwardrobe.'], 'cta' => ['Confirm subscription', '/newsletter/confirm?token=:token']]],
    'marketing.broadcast' => ['category' => 'marketing', 'icon' => 'verified', 'in_app' => true, 'push' => true,
        'title' => ':title', 'body' => ':body', 'link' => ':link',
        'email' => ['subject' => ':title', 'heading' => ':title', 'lines' => [':body'], 'cta' => ['Open Pilotwardrobe', ':link']]],
    'support.ticket_received' => ['category' => 'account', 'transactional' => true,
        'email' => ['subject' => 'We received your message (:ticket)', 'heading' => 'Message received',
            'lines' => ['Thanks, :name. Your reference is :ticket.', ':response_note']]],
    'staff.trust_safety_alert' => ['category' => 'account', 'transactional' => true,
        'email' => ['subject' => '[T&S] :subject', 'heading' => 'Trust & safety report', 'lines' => ['Ticket :ticket from :email', ':message']]],
    'staff.export_ready' => ['category' => 'account', 'transactional' => true,
        'email' => ['subject' => 'Your export is ready', 'heading' => 'Export ready', 'lines' => ['Your :table export (:rows rows) is ready. The link is valid for 24 hours.', ':url']]],
    'system.maintenance_scheduled' => ['category' => 'account', 'transactional' => true,
        'email' => ['subject' => 'Scheduled maintenance on :date', 'heading' => 'Scheduled maintenance',
            'lines' => ['Pilotwardrobe will be unavailable from :starts to :ends (UTC).', ':message']]],
];
