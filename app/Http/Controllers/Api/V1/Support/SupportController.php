<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Support;

use App\Enums\MediaType;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\FaqStat;
use App\Models\NewsletterSubscription;
use App\Models\Order;
use App\Models\Page;
use App\Models\StatusCheck;
use App\Models\SupportTicket;
use App\Services\Media\MediaService;
use App\Services\Notifications\NotificationService;
use App\Services\Platform\PlatformSettings;
use App\Support\Http\ApiResponse;
use App\Support\Ids\ReferenceGenerator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Help Center (VC10), Contact (VC11), newsletter and CMS pages. */
final class SupportController extends Controller
{
    private const COMPONENTS = ['Payments', 'Messaging', 'Marketplace', 'Notifications'];

    /** `GET /help/summary` — status comes from recorded checks, never hard-coded. */
    public function helpSummary(): JsonResponse
    {
        $payload = Cache::remember('help:summary', 300, function () {
            $popular = FaqStat::query()->orderByDesc('views')->limit(5)->get()
                ->map(fn (FaqStat $f) => ['faq_id' => $f->faq_id, 'question' => $f->question, 'tag' => $f->tag, 'views' => (int) $f->views])->values()->all();

            $since = now()->subDays(29)->startOfDay();
            $rows = StatusCheck::query()->where('day', '>=', $since->toDateString())->get()->groupBy('component');
            $components = [];
            $checks = 0;
            $failures = 0;
            foreach (self::COMPONENTS as $name) {
                $byDay = ($rows[$name] ?? collect())->keyBy(fn ($r) => $r->day->toDateString());
                $days = [];
                for ($d = $since->copy(); $d->lte(now()); $d->addDay()) {
                    $row = $byDay->get($d->toDateString());
                    $days[] = $row->state ?? 'no_data';
                    $checks += (int) ($row->checks ?? 0);
                    $failures += (int) ($row->failures ?? 0);
                }
                $components[] = ['name' => $name, 'days' => $days];
            }

            $today = array_map(fn ($c) => end($c['days']), $components);
            $overall = match (true) {
                $checks === 0 => 'unknown',
                in_array('down', $today, true) => 'major_outage',
                in_array('degraded', $today, true) => 'degraded',
                default => 'operational',
            };

            return [
                'popular' => $popular,
                'status' => [
                    'overall' => $overall,
                    'window_days' => 30,
                    'uptime_percent' => $checks > 0 ? round((1 - $failures / $checks) * 100, 2) : null,
                    'components' => $components,
                ],
            ];
        });

        return ApiResponse::ok($payload);
    }

    /** `GET /support/contact-info` */
    public function contactInfo(PlatformSettings $settings): JsonResponse
    {
        $payload = Cache::remember('support:contact-info', 3600, function () use ($settings) {
            // Average tickets per hour of day (WAT, UTC+1) over the last 30 days → relative load 0–5.
            $hours = DB::table('support_tickets')->where('created_at', '>=', now()->subDays(30))
                ->selectRaw("extract(hour from (created_at at time zone 'UTC' at time zone 'Africa/Lagos'))::int as h, count(*) as c")
                ->groupBy('h')->pluck('c', 'h');
            $max = max(1, (int) $hours->max());
            $load = [];
            for ($h = 0; $h < 24; $h++) {
                $load[] = (int) round(((int) ($hours[$h] ?? 0)) / $max * 5);
            }

            return [
                'chat_load' => $load,
                'avg_chat_reply_minutes' => (int) $settings->get('support.avg_chat_reply_minutes', 0),
                'offices' => $settings->get('support.offices', []),
            ];
        });

        return ApiResponse::ok($payload);
    }

    /** `POST /contact` — works for visitors and signed-in users. */
    public function contact(Request $request, MediaService $media, NotificationService $notifications): JsonResponse
    {
        $request->merge(['phone' => is_string($request->input('phone')) ? preg_replace('/[\s\-().]/', '', $request->input('phone')) : $request->input('phone')]);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:254'],
            'topic' => ['required', Rule::in(['order', 'escrow', 'dispute', 'selling', 'account', 'trust_safety', 'callback', 'other'])],
            'subject' => ['required', 'string', 'max:200'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'order_id' => ['nullable', 'string', 'max:40'],
            'phone' => ['required_if:topic,callback', 'nullable', 'regex:/^\+?[1-9]\d{7,14}$/'],
            'attachment_ids' => ['nullable', 'array', 'max:5'],
            'attachment_ids.*' => ['string', 'max:40'],
        ], [
            'name.required' => 'Please tell us your name.',
            'email.required' => 'Enter a valid email address.',
            'email.email' => 'Enter a valid email address.',
            'subject.required' => 'Add a short subject.',
            'message.required' => 'Please add a little more detail (10+ characters).',
            'message.min' => 'Please add a little more detail (10+ characters).',
            'phone.required_if' => 'Enter a phone number we can call.',
            'phone.regex' => 'Enter a phone number we can call.',
        ]);

        $user = $request->user();
        if (! empty($data['order_id'])) {
            $owns = $user !== null && Order::query()->whereKey($data['order_id'])
                ->where(fn ($q) => $q->where('buyer_id', $user->id)->orWhere('seller_id', $user->id))->exists();
            if (! $owns) {
                throw ApiException::validation(['order_id' => 'Choose one of your orders.']);
            }
        }
        if (! empty($data['attachment_ids']) && $user === null) {
            throw ApiException::validation(['attachment_ids' => 'Sign in to attach files.']);
        }

        $ticket = DB::transaction(function () use ($data, $user, $media) {
            $ticket = SupportTicket::query()->create([
                'number' => ReferenceGenerator::unique(SupportTicket::class, 'number', fn () => ReferenceGenerator::prefixed('SUP', 5)),
                'user_id' => $user?->id,
                'name' => strip_tags($data['name']),
                'email' => mb_strtolower($data['email']),
                'phone' => $data['phone'] ?? null,
                'topic' => $data['topic'],
                'subject' => strip_tags($data['subject']),
                'message' => strip_tags($data['message']),
                'order_id' => $data['order_id'] ?? null,
                'priority' => in_array($data['topic'], ['trust_safety', 'callback'], true) ? 'high' : 'normal',
            ]);
            if ($user !== null) {
                foreach ($media->claim($user, $data['attachment_ids'] ?? [], [MediaType::Message, MediaType::DisputeEvidence], 'attachment_ids', $ticket) as $i => $file) {
                    $file->attachTo($ticket, 'attachments', $i);
                }
            }

            return $ticket;
        });

        $responseNote = match ($ticket->topic) {
            'callback' => "We'll call you back within 2 hours during office hours.",
            'trust_safety' => 'Our Trust & Safety team has been alerted.',
            default => 'We usually reply within a few hours.',
        };
        $notifications->emailAddress($ticket->email, 'support.ticket_received', [
            'name' => $ticket->name, 'ticket' => $ticket->number, 'response_note' => $responseNote,
        ], $ticket->name, "support.ticket_received:{$ticket->id}");

        if ($ticket->topic === 'trust_safety' && ($alerts = config('mail.trust_safety_address'))) {
            $notifications->emailAddress($alerts, 'staff.trust_safety_alert', [
                'subject' => $ticket->subject, 'ticket' => $ticket->number, 'email' => $ticket->email, 'message' => Str::limit($ticket->message, 500),
            ], 'Trust & Safety', "staff.ts_alert:{$ticket->id}");
        }

        return ApiResponse::created(['ticket' => $ticket->number], 'Message received. '.$responseNote);
    }

    /** `POST /newsletter` — double opt-in. Always 204 (no enumeration). */
    public function newsletter(Request $request, NotificationService $notifications): Response
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:254']], ['email.email' => 'Enter a valid email address.']);
        $email = mb_strtolower($data['email']);

        $subscription = NewsletterSubscription::query()->whereRaw('lower(email) = ?', [$email])->first();
        if ($subscription?->status === 'confirmed') {
            return ApiResponse::noContent();
        }

        $token = Str::random(40);
        $subscription ??= new NewsletterSubscription(['email' => $email]);
        $subscription->fill(['status' => 'pending', 'token_hash' => hash('sha256', $token)])->save();
        $notifications->emailAddress($email, 'marketing.confirm_subscription', ['token' => $token], null, 'newsletter:'.$subscription->id.':'.now()->format('YmdH'));

        return ApiResponse::noContent();
    }

    /** `POST /newsletter/confirm` (addition) — completes the double opt-in. */
    public function confirmNewsletter(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:80']]);
        $subscription = NewsletterSubscription::query()->where('token_hash', hash('sha256', $data['token']))->first()
            ?? throw ApiException::tokenExpired();
        $subscription->forceFill(['status' => 'confirmed', 'confirmed_at' => now()])->save();

        return ApiResponse::ok(['confirmed' => true], "You're subscribed.");
    }

    /** `GET /pages/{slug}` — `body_html` was sanitised when it was saved. */
    public function page(string $slug): JsonResponse
    {
        $page = Page::query()->where('slug', $slug)->where('status', 'published')->first()
            ?? throw ApiException::notFound('Page not found.');

        return ApiResponse::ok([
            'slug' => $page->slug,
            'title' => $page->title,
            'summary' => $page->summary,
            'kind' => $page->kind,
            'body_html' => $page->body_html,
            'updated_at' => $page->updated_at?->toIso8601ZuluString(),
        ]);
    }
}
