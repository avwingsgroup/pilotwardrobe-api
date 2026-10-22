# Swaps

> Wire format (snake_case) exactly as the mock server emits it, captured from `src/mocks/` with
> `tests/unit/contract-payloads.spec.ts` (regenerate with `npm run docs:payloads`). Long arrays are truncated with `"… N more"`.
> Base URL: `{VITE_API_BASE_URL}` = `https://api.pilotwardrobe.com/api/v1`.

Item-for-item trades with optional cash adjustment (V8 swap builder, VB14).

- Proposing a swap requires **KYC approved** (403 `KYC_REQUIRED`) and that every `offered_listing_ids` item belongs to the proposer and is `active`.
- `cash_adjustment.amount` is signed minor units: positive = proposer pays the counterparty, negative = proposer receives.
- `POST /swap-requests/{id}/{accept|reject|cancel}`. Accepting reserves all listings involved and creates a linked order pair in `pending_payment` (cash adjustment + both shipping legs are paid through escrow).

## Samples

### Propose swap

`POST /listings/lst_003/swap-requests` · as `aviator@example.com` (aviator)

Request:

```json
{
  "offered_listing_ids": [
    "lst_001"
  ],
  "cash_adjustment": {
    "amount": 2000,
    "currency": "USD"
  },
  "message": "Straight swap plus $20?"
}
```

Response `201`:

```json
{
  "success": true,
  "message": "Swap proposal sent.",
  "data": {
    "id": "swp_h24wnf6",
    "kind": "swap",
    "direction": "sent",
    "status": "pending",
    "target_listing": {
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
    "offered_items": [
      {
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
          }
        },
        "estimated_value": {
          "amount": 74500,
          "currency": "USD"
        }
      }
    ],
    "cash_adjustment": {
      "amount": 2000,
      "currency": "USD"
    },
    "swap_fee": {
      "amount": 3060,
      "currency": "USD"
    },
    "message": "Straight swap plus $20?",
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
    "expires_at": "2026-10-23T13:08:31.545Z",
    "created_at": "2026-10-21T13:08:31.545Z",
    "history": [],
    "allowed_actions": [
      "cancel",
      "message"
    ]
  }
}
```

### Propose swap — KYC required (403)

`POST /listings/lst_003/swap-requests` · as `newbie@example.com` (user, KYC not started)

Request:

```json
{
  "offered_listing_ids": [],
  "message": "hi"
}
```

Response `403`:

```json
{
  "success": false,
  "message": "Verify your identity to propose swaps.",
  "code": "KYC_REQUIRED"
}
```

### My swap requests

`GET /me/swap-requests` · as `pilot@example.com` (user, KYC approved)

Response `200`:

```json
{
  "success": true,
  "data": [
    {
      "id": "swp_772",
      "kind": "swap",
      "direction": "received",
      "status": "pending",
      "target_listing": {
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
      "offered_items": [
        {
          "listing": {
            "id": "lst_007",
            "slug": "lightspeed-zulu-3-carry-case",
            "title": "Lightspeed Zulu 3, LEMO",
            "image": {
              "id": "med_lightspeed-zulu-3",
              "public_id": "pilotwardrobe/listings/lightspeed-zulu-3",
              "url": "/media/mock/lightspeed-zulu-3-300.jpg",
              "secure_url": "/media/mock/lightspeed-zulu-3-300.jpg",
              "width": 300,
              "height": 284,
              "format": "jpg",
              "bytes": 15336,
              "alt": "Lightspeed Zulu 3 ANR headset with bronze ear cups",
              "variants": {
                "thumbnail": "/media/mock/lightspeed-zulu-3-300.webp",
                "small": "/media/mock/lightspeed-zulu-3-300.webp",
                "medium": "/media/mock/lightspeed-zulu-3-300.webp",
                "large": "/media/mock/lightspeed-zulu-3-300.webp",
                "original": "/media/mock/lightspeed-zulu-3-300.jpg"
              },
              "sources": [
                {
                  "url": "/media/mock/lightspeed-zulu-3-300.avif",
                  "width": 300,
                  "format": "avif"
                },
                {
                  "url": "/media/mock/lightspeed-zulu-3-300.webp",
                  "width": 300,
                  "format": "webp"
                },
                "… 1 more"
              ],
              "placeholder": "#f5f6fa"
            },
            "price": {
              "amount": 54000,
              "currency": "USD"
            }
          },
          "estimated_value": {
            "amount": 54000,
            "currency": "USD"
          }
        }
      ],
      "cash_adjustment": {
        "amount": 18000,
        "currency": "USD"
      },
      "swap_fee": {
        "amount": 2880,
        "currency": "USD"
      },
      "message": "Zulu 3 is 2023, fresh battery cover. Happy to meet at LOS GA terminal.",
      "counterparty": {
        "id": "usr_11034",
        "display_name": "Ifeoma A.",
        "initials": "IA",
        "username": "ifeoma_a_",
        "verified": true,
        "rating": 4.9,
        "trades": 6,
        "location": "Lagos",
        "member_since": "2024-01-01T00:00:00Z"
      },
      "expires_at": "2026-10-22T19:08:31.283Z",
      "created_at": "2026-10-21T12:08:31.283Z",
      "history": [
        {
          "id": "h1",
          "label": "Swap proposed · Zulu 3 + $180",
          "detail": "Today · Ifeoma A.",
          "occurred_at": "2026-10-21T12:08:31.283Z",
          "kind": "offer"
        }
      ],
      "unread": true,
      "allowed_actions": [
        "accept",
        "reject",
        "… 1 more"
      ]
    }
  ]
}
```

### Accept swap

`POST /swap-requests/swp_772/accept` · as `pilot@example.com` (user, KYC approved)

Response `200`:

```json
{
  "success": true,
  "data": {
    "id": "swp_772",
    "kind": "swap",
    "direction": "received",
    "status": "accepted",
    "target_listing": {
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
    "offered_items": [
      {
        "listing": {
          "id": "lst_007",
          "slug": "lightspeed-zulu-3-carry-case",
          "title": "Lightspeed Zulu 3, LEMO",
          "image": {
            "id": "med_lightspeed-zulu-3",
            "public_id": "pilotwardrobe/listings/lightspeed-zulu-3",
            "url": "/media/mock/lightspeed-zulu-3-300.jpg",
            "secure_url": "/media/mock/lightspeed-zulu-3-300.jpg",
            "width": 300,
            "height": 284,
            "format": "jpg",
            "bytes": 15336,
            "alt": "Lightspeed Zulu 3 ANR headset with bronze ear cups",
            "variants": {
              "thumbnail": "/media/mock/lightspeed-zulu-3-300.webp",
              "small": "/media/mock/lightspeed-zulu-3-300.webp",
              "medium": "/media/mock/lightspeed-zulu-3-300.webp",
              "large": "/media/mock/lightspeed-zulu-3-300.webp",
              "original": "/media/mock/lightspeed-zulu-3-300.jpg"
            },
            "sources": [
              {
                "url": "/media/mock/lightspeed-zulu-3-300.avif",
                "width": 300,
                "format": "avif"
              },
              {
                "url": "/media/mock/lightspeed-zulu-3-300.webp",
                "width": 300,
                "format": "webp"
              },
              "… 1 more"
            ],
            "placeholder": "#f5f6fa"
          },
          "price": {
            "amount": 54000,
            "currency": "USD"
          }
        },
        "estimated_value": {
          "amount": 54000,
          "currency": "USD"
        }
      }
    ],
    "cash_adjustment": {
      "amount": 18000,
      "currency": "USD"
    },
    "swap_fee": {
      "amount": 2880,
      "currency": "USD"
    },
    "message": "Zulu 3 is 2023, fresh battery cover. Happy to meet at LOS GA terminal.",
    "counterparty": {
      "id": "usr_11034",
      "display_name": "Ifeoma A.",
      "initials": "IA",
      "username": "ifeoma_a_",
      "verified": true,
      "rating": 4.9,
      "trades": 6,
      "location": "Lagos",
      "member_since": "2024-01-01T00:00:00Z"
    },
    "expires_at": "2026-10-22T19:08:31.283Z",
    "created_at": "2026-10-21T12:08:31.283Z",
    "history": [
      {
        "id": "h1",
        "label": "Swap proposed · Zulu 3 + $180",
        "detail": "Today · Ifeoma A.",
        "occurred_at": "2026-10-21T12:08:31.283Z",
        "kind": "offer"
      }
    ],
    "unread": true,
    "allowed_actions": [
      "message"
    ]
  }
}
```
