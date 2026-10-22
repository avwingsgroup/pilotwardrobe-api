# Offers

> Wire format (snake_case) exactly as the mock server emits it, captured from `src/mocks/` with
> `tests/unit/contract-payloads.spec.ts` (regenerate with `npm run docs:payloads`). Long arrays are truncated with `"… N more"`.
> Base URL: `{VITE_API_BASE_URL}` = `https://api.pilotwardrobe.com/api/v1`.

Price offers on a listing (VA14 → V3 offer modal, VB14 inbox).

- Amounts in requests are **integer minor units** plus a separate `currency` field. Responses use the `Money` object `{ amount, currency, formatted }`.
- The backend enforces the listing's `minimum_offer`: an offer below it is rejected with 422 on `amount` ("The seller doesn't accept offers below …").
- Accept/decline/counter are allowed only for the **seller** of the listing; cancel only for the **buyer** who made the offer. Return 403 otherwise, 409 `INVALID_STATE_TRANSITION` when the offer is no longer `pending`.
- A counter must be strictly between the current offer and the asking price.
- Offers expire after 24 h (`expires_at`); a scheduled job moves them to `expired` and notifies both parties.
- Accepting an offer moves the listing to `reserved` for that buyer; the buyer then checks out at the agreed price with `POST /orders` + `offer_id`. Each offer carries `allowed_actions` for the viewer, which the UI renders verbatim.

## Samples

### Make offer

`POST /listings/lst_003/offers` · as `pilot@example.com` (user, KYC approved)

Request:

```json
{
  "amount": 12325,
  "currency": "USD",
  "message": "Would you take this?"
}
```

Response `201`:

```json
{
  "success": true,
  "message": "Offer sent. The seller has 24h to respond.",
  "data": {
    "id": "off_inzm0p1",
    "kind": "offer",
    "direction": "sent",
    "status": "pending",
    "listing": {
      "id": "lst_003",
      "slug": "pilotwardrobe-aviator-uniform-shirt",
      "title": "Pilotwardrobe Aviator Uniform Shirt — epaulette loops, wrinkle-free",
      "image": {
        "id": "med_uniform-shirt",
        "public_id": "pilotwardrobe/listings/uniform-shirt",
        "url": "/media/mock/uniform-shirt-232.jpg",
        "secure_url": "/media/mock/uniform-shirt-232.jpg",
        "width": 232,
        "height": 230,
        "format": "jpg",
        "bytes": 9605,
        "alt": "White pilot uniform shirt with four-bar epaulettes and black tie",
        "variants": {
          "thumbnail": "/media/mock/uniform-shirt-232.webp",
          "small": "/media/mock/uniform-shirt-232.webp",
          "medium": "/media/mock/uniform-shirt-232.webp",
          "large": "/media/mock/uniform-shirt-232.webp",
          "original": "/media/mock/uniform-shirt-232.jpg"
        },
        "sources": [
          {
            "url": "/media/mock/uniform-shirt-232.avif",
            "width": 232,
            "format": "avif"
          },
          {
            "url": "/media/mock/uniform-shirt-232.webp",
            "width": 232,
            "format": "webp"
          },
          "… 1 more"
        ],
        "placeholder": "#f5f6fa"
      },
      "price": {
        "amount": 14500,
        "currency": "USD"
      },
      "condition": "new"
    },
    "amount": {
      "amount": 12325,
      "currency": "USD"
    },
    "below_asking_percent": 15,
    "message": "Would you take this?",
    "counterparty": {
      "id": "usr_1",
      "display_name": "Pilotwardrobe Official",
      "initials": "PI",
      "username": "pilotwardrobe",
      "verified": true,
      "rating": 4.9,
      "trades": 1204,
      "member_since": "2024-01-01T00:00:00Z"
    },
    "expires_at": "2026-10-22T13:08:31.539Z",
    "created_at": "2026-10-21T13:08:31.539Z",
    "history": [],
    "allowed_actions": [
      "cancel",
      "message"
    ]
  }
}
```

### Make offer — below floor (422)

`POST /listings/lst_003/offers` · as `pilot@example.com` (user, KYC approved)

Request:

```json
{
  "amount": 0,
  "currency": "USD"
}
```

Response `422`:

```json
{
  "success": false,
  "message": "The submitted data is invalid.",
  "code": "VALIDATION_ERROR",
  "errors": {
    "amount": [
      "The amount must be greater than zero."
    ]
  }
}
```

### My offers inbox

`GET /me/offers?box=received` · as `pilot@example.com` (user, KYC approved)

Response `200`:

```json
{
  "success": true,
  "data": [
    {
      "id": "off_9281",
      "kind": "offer",
      "direction": "received",
      "status": "pending",
      "listing": {
        "id": "lst_001",
        "slug": "bose-a20-aviation-headset-dual-ga",
        "title": "Bose A20 Aviation Headset — dual GA plugs",
        "image": {
          "id": "med_bose-a20",
          "public_id": "pilotwardrobe/listings/bose-a20",
          "url": "/media/mock/bose-a20-1408.jpg",
          "secure_url": "/media/mock/bose-a20-1408.jpg",
          "width": 1408,
          "height": 768,
          "format": "jpg",
          "bytes": 194642,
          "alt": "Bose A20 aviation headset with dual GA plugs on a light surface",
          "variants": {
            "thumbnail": "/media/mock/bose-a20-320.webp",
            "small": "/media/mock/bose-a20-320.webp",
            "medium": "/media/mock/bose-a20-640.webp",
            "large": "/media/mock/bose-a20-1408.webp",
            "original": "/media/mock/bose-a20-1408.jpg"
          },
          "sources": [
            {
              "url": "/media/mock/bose-a20-320.avif",
              "width": 320,
              "format": "avif"
            },
            {
              "url": "/media/mock/bose-a20-640.avif",
              "width": 640,
              "format": "avif"
            },
            "… 10 more"
          ],
          "placeholder": "#dddddd"
        },
        "price": {
          "amount": 74500,
          "currency": "USD"
        },
        "condition": "like_new",
        "minimum_offer": {
          "amount": 60000,
          "currency": "USD"
        }
      },
      "amount": {
        "amount": 71200,
        "currency": "USD"
      },
      "below_asking_percent": 4.4,
      "you_receive": {
        "amount": 65504,
        "currency": "USD"
      },
      "message": "Can you do $712 if I pay today? Need it before my checkride on the 14th.",
      "counterparty": {
        "id": "usr_09981k",
        "display_name": "Kunle Taiwo",
        "initials": "KT",
        "username": "kunle_taiwo",
        "verified": true,
        "rating": 4.9,
        "trades": 27,
        "location": "Abuja",
        "member_since": "2024-01-01T00:00:00Z"
      },
      "expires_at": "2026-10-21T22:08:31.283Z",
      "created_at": "2026-10-21T13:06:07.283Z",
      "history": [
        {
          "id": "h1",
          "label": "Offer received · $712",
          "detail": "Today 09:41 · Kunle T.",
          "occurred_at": "2026-10-21T13:05:31.283Z",
          "kind": "offer"
        },
        {
          "id": "h2",
          "label": "Listing viewed 6 times",
          "detail": "Jul 26 – Jul 30",
          "occurred_at": "2026-10-19T13:08:31.283Z",
          "kind": "view"
        },
        "… 1 more"
      ],
      "unread": true,
      "allowed_actions": [
        "accept",
        "decline",
        "… 2 more"
      ]
    },
    {
      "id": "off_9282",
      "kind": "offer",
      "direction": "received",
      "status": "countered",
      "listing": {
        "id": "lst_004",
        "slug": "david-clark-one-x-enc-headset",
        "title": "DC ONE-X ENC",
        "image": {
          "id": "med_david-clark-onex",
          "public_id": "pilotwardrobe/listings/david-clark-onex",
          "url": "/media/mock/david-clark-onex-232.jpg",
          "secure_url": "/media/mock/david-clark-onex-232.jpg",
          "width": 232,
          "height": 230,
          "format": "jpg",
          "bytes": 9605,
          "alt": "David Clark ONE-X ENC headset",
          "variants": {
            "thumbnail": "/media/mock/david-clark-onex-232.webp",
            "small": "/media/mock/david-clark-onex-232.webp",
            "medium": "/media/mock/david-clark-onex-232.webp",
            "large": "/media/mock/david-clark-onex-232.webp",
            "original": "/media/mock/david-clark-onex-232.jpg"
          },
          "sources": [
            {
              "url": "/media/mock/david-clark-onex-232.avif",
              "width": 232,
              "format": "avif"
            },
            {
              "url": "/media/mock/david-clark-onex-232.webp",
              "width": 232,
              "format": "webp"
            },
            "… 1 more"
          ],
          "placeholder": "#f5f6fa"
        },
        "price": {
          "amount": 61500,
          "currency": "USD"
        },
        "condition": "very_good"
      },
      "amount": {
        "amount": 56000,
        "currency": "USD"
      },
      "counter_amount": {
        "amount": 59000,
        "currency": "USD"
      },
      "below_asking_percent": 8.9,
      "message": null,
      "counterparty": {
        "id": "usr_11201",
        "display_name": "Jide O.",
        "initials": "JO",
        "username": "jide_o_",
        "verified": true,
        "rating": 4.6,
        "trades": 8,
        "location": "Abuja",
        "member_since": "2024-01-01T00:00:00Z"
      },
      "expires_at": "2026-10-22T09:08:31.283Z",
      "created_at": "2026-10-21T10:08:31.283Z",
      "history": [
        {
          "id": "h1",
          "label": "Offer received · $560",
          "detail": "Today 09:41 · Jide O.",
          "occurred_at": "2026-10-21T13:05:31.283Z",
          "kind": "offer"
        },
        {
          "id": "h2",
          "label": "Listing viewed 6 times",
          "detail": "Jul 26 – Jul 30",
          "occurred_at": "2026-10-19T13:08:31.283Z",
          "kind": "view"
        },
        "… 1 more"
      ],
      "allowed_actions": [
        "message"
      ]
    },
    "… 1 more"
  ]
}
```

### Counter offer

`POST /offers/off_9281/counter` · as `pilot@example.com` (user, KYC approved)

Request:

```json
{
  "amount": 72850,
  "currency": "USD",
  "message": "Meet me in the middle?"
}
```

Response `200`:

```json
{
  "success": true,
  "data": {
    "id": "off_9281",
    "kind": "offer",
    "direction": "received",
    "status": "countered",
    "listing": {
      "id": "lst_001",
      "slug": "bose-a20-aviation-headset-dual-ga",
      "title": "Bose A20 Aviation Headset — dual GA plugs",
      "image": {
        "id": "med_bose-a20",
        "public_id": "pilotwardrobe/listings/bose-a20",
        "url": "/media/mock/bose-a20-1408.jpg",
        "secure_url": "/media/mock/bose-a20-1408.jpg",
        "width": 1408,
        "height": 768,
        "format": "jpg",
        "bytes": 194642,
        "alt": "Bose A20 aviation headset with dual GA plugs on a light surface",
        "variants": {
          "thumbnail": "/media/mock/bose-a20-320.webp",
          "small": "/media/mock/bose-a20-320.webp",
          "medium": "/media/mock/bose-a20-640.webp",
          "large": "/media/mock/bose-a20-1408.webp",
          "original": "/media/mock/bose-a20-1408.jpg"
        },
        "sources": [
          {
            "url": "/media/mock/bose-a20-320.avif",
            "width": 320,
            "format": "avif"
          },
          {
            "url": "/media/mock/bose-a20-640.avif",
            "width": 640,
            "format": "avif"
          },
          "… 10 more"
        ],
        "placeholder": "#dddddd"
      },
      "price": {
        "amount": 74500,
        "currency": "USD"
      },
      "condition": "like_new",
      "minimum_offer": {
        "amount": 60000,
        "currency": "USD"
      }
    },
    "amount": {
      "amount": 71200,
      "currency": "USD"
    },
    "below_asking_percent": 4.4,
    "you_receive": {
      "amount": 65504,
      "currency": "USD"
    },
    "message": "Can you do $712 if I pay today? Need it before my checkride on the 14th.",
    "counterparty": {
      "id": "usr_09981k",
      "display_name": "Kunle Taiwo",
      "initials": "KT",
      "username": "kunle_taiwo",
      "verified": true,
      "rating": 4.9,
      "trades": 27,
      "location": "Abuja",
      "member_since": "2024-01-01T00:00:00Z"
    },
    "expires_at": "2026-10-21T22:08:31.283Z",
    "created_at": "2026-10-21T13:06:07.283Z",
    "history": [
      {
        "id": "h_8p4ia22",
        "label": "Counter sent · $728.50",
        "detail": "Buyer has 24h to respond",
        "occurred_at": "2026-10-21T13:08:31.544Z",
        "kind": "counter"
      },
      {
        "id": "h1",
        "label": "Offer received · $712",
        "detail": "Today 09:41 · Kunle T.",
        "occurred_at": "2026-10-21T13:05:31.283Z",
        "kind": "offer"
      },
      "… 2 more"
    ],
    "unread": true,
    "allowed_actions": [
      "message"
    ],
    "counter_amount": {
      "amount": 72850,
      "currency": "USD"
    }
  }
}
```
