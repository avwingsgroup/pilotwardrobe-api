# Support (Help Center, contact)

> Wire format (snake_case) exactly as the mock server emits it, captured from `src/mocks/` with
> `tests/unit/contract-payloads.spec.ts` (regenerate with `npm run docs:payloads`). Long arrays are truncated with `"… N more"`.
> Base URL: `{VITE_API_BASE_URL}` = `https://api.pilotwardrobe.com/api/v1`.

Help Center (VC10) and Contact us (VC11).

- `GET /help/summary` drives “Most read this week” and the system-status panel. Status must come from real monitoring (one entry per day for the window); the SPA shows whatever `overall` says, so never hardcode `operational`.
- `POST /contact` creates a support ticket. `topic ∈ order | escrow | dispute | selling | account | trust_safety | callback | other`. `phone` is required for `callback`; `order_id` must belong to the signed-in user; `attachment_ids` (≤ 5) are private media uploaded with `POST /media` (`type=message`). Visitors can contact support without an account.
- Guides and policies (VC1–VC9) are static content in the SPA (`src/content/info`); timings quoted there (5-day dispatch, 72-hour inspection, 48-hour seller reply, 5-business-day mediation, 8% commission) must match the backend’s escrow and fee settings.

## Samples

### Help Center summary

`GET /help/summary` · as Visitor (no session)

Response `200`:

```json
{
  "success": true,
  "data": {
    "popular": [
      {
        "faq_id": "confirm-landed",
        "question": "How long do I have to confirm goods landed?",
        "tag": "Escrow",
        "views": 4100
      },
      {
        "faq_id": "payout-pending",
        "question": "Why is my payout still pending?",
        "tag": "Wallet",
        "views": 3300
      },
      "… 3 more"
    ],
    "status": {
      "overall": "operational",
      "window_days": 30,
      "uptime_percent": 99.97,
      "components": [
        {
          "name": "Payments",
          "days": [
            "up",
            "up",
            "… 28 more"
          ]
        },
        {
          "name": "Messaging",
          "days": [
            "up",
            "up",
            "… 28 more"
          ]
        },
        "… 2 more"
      ]
    }
  }
}
```

### Contact page info

`GET /support/contact-info` · as Visitor (no session)

Response `200`:

```json
{
  "success": true,
  "data": {
    "chat_load": [
      3,
      2,
      "… 22 more"
    ],
    "avg_chat_reply_minutes": 4,
    "offices": [
      {
        "name": "Lagos · HQ",
        "address": "14 Kofo Abayomi St, Victoria Island"
      },
      {
        "name": "London",
        "address": "Hangar 3, Biggin Hill Airport, TN16 3BH"
      }
    ]
  }
}
```

### Contact — callback request

`POST /contact` · as `pilot@example.com` (user, KYC approved)

Request:

```json
{
  "name": "Nehemiah Okoye",
  "email": "pilot@example.com",
  "topic": "callback",
  "subject": "Tracking hasn’t updated in 3 days",
  "message": "The order shows Holding pattern in Frankfurt since Monday.",
  "order_id": "ord_4471",
  "phone": "+234 803 123 4567",
  "attachment_ids": []
}
```

Response `201`:

```json
{
  "success": true,
  "message": "Message received. We’ll call you back within 2 hours.",
  "data": {
    "ticket": "SUP-65133"
  }
}
```

### Contact — validation (422)

`POST /contact` · as Visitor (no session)

Request:

```json
{
  "name": "",
  "email": "nope",
  "topic": "callback",
  "subject": "",
  "message": "short",
  "phone": ""
}
```

Response `422`:

```json
{
  "success": false,
  "message": "The submitted data is invalid.",
  "code": "VALIDATION_ERROR",
  "errors": {
    "name": [
      "Please tell us your name."
    ],
    "email": [
      "Enter a valid email address."
    ],
    "subject": [
      "Add a short subject."
    ],
    "message": [
      "Please add a little more detail (10+ characters)."
    ],
    "phone": [
      "Enter a phone number we can call."
    ]
  }
}
```
