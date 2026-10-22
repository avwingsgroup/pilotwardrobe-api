# Admin (Control Tower)

> Wire format (snake_case) exactly as the mock server emits it, captured from `src/mocks/` with
> `tests/unit/contract-payloads.spec.ts` (regenerate with `npm run docs:payloads`). Long arrays are truncated with `"… N more"`.
> Base URL: `{VITE_API_BASE_URL}` = `https://api.pilotwardrobe.com/api/v1`.

All `/admin/*` endpoints require a staff session (`role ∈ admin | super_admin`) **and** the specific permission listed in `docs/backend-api-contract.md`. Missing permission → **403 `FORBIDDEN`**. Every mutating admin call writes an audit-log entry (actor, action, target, IP, before/after).

- Tables share one query contract: `tab`, `search`, `filter[...]` (flattened as `status=`, `country=`…), `sort` (`field` or `-field`), `page`, `per_page`; responses include `meta.counts` (badge count per tab, e.g. `{ "all": 12418, "pending_kyc": 38 }`) besides the standard pagination meta.
- **Dual control.** Refunds > $1,000, payouts > $2,000 and any fee/escrow/settings change return `{ "status": "pending_approval", "approver": "…" }` instead of applying immediately. A second staff member with the same permission approves in the relevant queue.
- Impersonation (`users.impersonate`) returns a short-lived, read-only token and is always audit-logged and visible to the user.
- KYC document URLs in `/admin/kyc/{id}` must be signed, short-lived Cloudinary *authenticated* delivery URLs.

## Samples

### Overview

`GET /admin/overview` · as `super@example.com` (super_admin)

Response `200`:

```json
{
  "success": true,
  "data": {
    "greeting_name": "Tolu",
    "period_label": "figures in USD · last 30 days vs previous",
    "kpis": [
      {
        "key": "gmv",
        "label": "Gross merchandise value",
        "value": "$482,910",
        "delta": "+18.2%",
        "trend": "up",
        "positive": true,
        "sparkline": [
          4,
          5,
          "… 8 more"
        ]
      },
      {
        "key": "escrow",
        "label": "Held in escrow",
        "value": "$96,420",
        "delta": "412 orders",
        "trend": "flat",
        "positive": true,
        "caption": "412 orders",
        "sparkline": [
          6,
          6,
          "… 8 more"
        ]
      },
      "… 3 more"
    ],
    "series": [
      {
        "date": "2026-07-01",
        "gmv": 12000,
        "orders": 108
      },
      {
        "date": "2026-07-02",
        "gmv": 13383,
        "orders": 117
      },
      "… 29 more"
    ],
    "queues": [
      {
        "key": "kyc",
        "label": "KYC reviews",
        "detail": "12 over 24h SLA",
        "count": 38,
        "to": "/admin/kyc",
        "permission": "kyc.view"
      },
      {
        "key": "disputes",
        "label": "Open disputes",
        "detail": "2 due today",
        "count": 6,
        "to": "/admin/disputes",
        "permission": "disputes.view"
      },
      "… 3 more"
    ],
    "latest_orders": [
      {
        "id": "ord_4479a",
        "number": "PW-4479",
        "item_title": "Bose A20 ×3",
        "item_image": {
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
        "buyer_name": "new_user_8812",
        "seller_name": "K. Taiwo",
        "total": {
          "amount": 223500,
          "currency": "USD"
        },
        "status": "on_hold",
        "escrow_status": "on_hold",
        "escrow_label": "On hold · risk",
        "freight_label": "—",
        "age_label": "6h",
        "risk": "high"
      },
      {
        "id": "ord_4481",
        "number": "PW-4481",
        "item_title": "Garmin GDL 52",
        "item_image": {
          "id": "med_captain-jacket",
          "public_id": "pilotwardrobe/listings/captain-jacket",
          "url": "/media/mock/captain-jacket-232.jpg",
          "secure_url": "/media/mock/captain-jacket-232.jpg",
          "width": 232,
          "height": 230,
          "format": "jpg",
          "bytes": 9605,
          "alt": "Navy double-breasted captain's uniform jacket with gold sleeve stripes",
          "variants": {
            "thumbnail": "/media/mock/captain-jacket-232.webp",
            "small": "/media/mock/captain-jacket-232.webp",
            "medium": "/media/mock/captain-jacket-232.webp",
            "large": "/media/mock/captain-jacket-232.webp",
            "original": "/media/mock/captain-jacket-232.jpg"
          },
          "sources": [
            {
              "url": "/media/mock/captain-jacket-232.avif",
              "width": 232,
              "format": "avif"
            },
            {
              "url": "/media/mock/captain-jacket-232.webp",
              "width": 232,
              "format": "webp"
            },
            "… 1 more"
          ],
          "placeholder": "#f5f6fa"
        },
        "buyer_name": "A. Musa",
        "seller_name": "Capt. A. Bello",
        "total": {
          "amount": 64820,
          "currency": "USD"
        },
        "status": "awaiting_dispatch",
        "escrow_status": "held",
        "escrow_label": "Held",
        "freight_label": "Label created",
        "age_label": "1h",
        "risk": "low"
      },
      "… 2 more"
    ],
    "risk_signals": [
      {
        "id": "rs_1",
        "title": "Velocity: 3 high-value buys from new account",
        "detail": "#PW-4479"
      },
      {
        "id": "rs_2",
        "title": "Same device on 4 accounts",
        "detail": "user_8812 +3"
      },
      "… 1 more"
    ],
    "system_status": "normal"
  }
}
```

### Users table

`GET /admin/users?tab=all&per_page=2&sort=-joined_at` · as `admin@example.com` (admin, Trust & safety)

Response `200`:

```json
{
  "success": true,
  "data": [
    {
      "id": "usr_10422",
      "code": "U-10422",
      "display_name": "Nehemiah Okoye",
      "initials": "NO",
      "email": "airboy@gmail.com",
      "role": "user",
      "role_label": "Verified seller",
      "kyc_status": "approved",
      "country_code": "NG",
      "country_name": "Nigeria",
      "trades": 112,
      "gmv": {
        "amount": 1892000,
        "currency": "USD"
      },
      "risk": "low",
      "status": "active",
      "joined_at": "2025-03-12T00:00:00Z"
    },
    {
      "id": "usr_09981k",
      "code": "U-09981",
      "display_name": "Kunle Taiwo",
      "initials": "KT",
      "email": "kunle.t@outlook.com",
      "role": "user",
      "role_label": "Verified seller",
      "kyc_status": "approved",
      "country_code": "NG",
      "country_name": "Nigeria",
      "trades": 27,
      "gmv": {
        "amount": 641000,
        "currency": "USD"
      },
      "risk": "low",
      "status": "active",
      "joined_at": "2024-02-01T00:00:00Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 2,
    "total": 8,
    "last_page": 4,
    "from": 1,
    "to": 2,
    "counts": {
      "all": 12418,
      "verified": 3206,
      "buyers": 8970,
      "photographers": 186,
      "pending_kyc": 38,
      "suspended": 41
    }
  },
  "links": {
    "first": "?page=1",
    "last": "?page=4",
    "next": "?page=2",
    "prev": null
  }
}
```

### User detail

`GET /admin/users/usr_10422` · as `admin@example.com` (admin, Trust & safety)

Response `200`:

```json
{
  "success": true,
  "data": {
    "id": "usr_10422",
    "code": "U-10422",
    "display_name": "Nehemiah Okoye",
    "initials": "NO",
    "email": "airboy@gmail.com",
    "role": "user",
    "role_label": "Verified seller",
    "kyc_status": "approved",
    "country_code": "NG",
    "country_name": "Nigeria",
    "trades": 112,
    "gmv": {
      "amount": 1892000,
      "currency": "USD"
    },
    "risk": "low",
    "status": "active",
    "joined_at": "2025-03-12T00:00:00Z",
    "phone_masked": "+234 •••• 4417",
    "city": "Lagos, NG",
    "signup_method": "Google",
    "stats": {
      "gmv_sold": {
        "amount": 1892000,
        "currency": "USD"
      },
      "trades_sold": 112,
      "gmv_bought": {
        "amount": 241000,
        "currency": "USD"
      },
      "orders_bought": 9,
      "rating": 4.9,
      "reviews": 86,
      "disputes": 2,
      "disputes_open": 1,
      "disputes_won": 1,
      "wallet": {
        "amount": 469455,
        "currency": "USD"
      },
      "wallet_escrow": {
        "amount": 128400,
        "currency": "USD"
      }
    },
    "identity": {
      "document_label": "Passport · NGA",
      "checks": [
        {
          "label": "Document authentic",
          "detail": "Passport · NGA",
          "passed": true
        },
        {
          "label": "Face match",
          "detail": "98.4%",
          "passed": true
        },
        "… 2 more"
      ],
      "image": null
    },
    "risk_score": 12,
    "risk_detail": "Low · 0 linked accounts · 1 device fingerprint",
    "limits": {
      "can_list": true,
      "can_receive_payouts": true,
      "can_trade": true,
      "instant_payouts": false,
      "daily_payout_limit": {
        "amount": 500000,
        "currency": "USD"
      }
    },
    "activity": [
      {
        "id": "a1",
        "label": "Withdrew $1,200.00 to GTBank •••• 0921",
        "detail": "Jul 26 · 2FA passed · IP 102.89.•.•",
        "state": "ok"
      },
      {
        "id": "a2",
        "label": "Disputed by buyer · #DS-2041",
        "detail": "Jul 29 · item not as described",
        "state": "flag"
      },
      "… 2 more"
    ],
    "notes": [
      {
        "id": "n1",
        "author": "Amaka N.",
        "initials": "AN",
        "body": "Seller offered return on DS-2041 promptly. Good faith.",
        "created_at": "2026-10-20T13:08:31.567Z"
      },
      {
        "id": "n2",
        "author": "Tolu A.",
        "initials": "TA",
        "body": "Raised listing cap after KYC.",
        "created_at": "2026-10-11T13:08:31.567Z"
      }
    ]
  }
}
```

### Forbidden for staff without permission (403)

`GET /admin/settings` · as `admin@example.com` (admin, Trust & safety)

Response `403`:

```json
{
  "success": false,
  "message": "This action is unauthorized.",
  "code": "FORBIDDEN"
}
```

### KYC review item

`GET /admin/kyc/kyc_7731` · as `super@example.com` (super_admin)

Response `200`:

```json
{
  "success": true,
  "data": {
    "id": "kyc_7731",
    "code": "KY-7731",
    "user_id": "usr_11034",
    "display_name": "Ifeoma Afolabi",
    "initials": "IA",
    "document_type": "passport",
    "document_label": "Passport · UK",
    "country_code": "GB",
    "age_hours": 26,
    "over_sla": true,
    "status": "pending",
    "submitted_at": "2026-10-20T11:08:31.568Z",
    "auto_checks_passed": 3,
    "auto_checks_total": 4,
    "document": null,
    "selfie": null,
    "extracted": [
      {
        "field": "Full name",
        "document": "IFEOMA AFOLABI",
        "profile": "Ifeoma Afolabi",
        "match": true
      },
      {
        "field": "Date of birth",
        "document": "14 MAR 1994",
        "profile": "14/03/1994",
        "match": true
      },
      "… 3 more"
    ],
    "automated_checks": [
      {
        "label": "Document authenticity",
        "result": "MRZ valid",
        "passed": true
      },
      {
        "label": "Face match",
        "result": "96.1%",
        "passed": true
      },
      "… 3 more"
    ]
  }
}
```

### Moderation decision

`POST /admin/moderation/decisions` · as `super@example.com` (super_admin)

Request:

```json
{
  "ids": [
    "mod_1"
  ],
  "action": "request_changes",
  "note": "Add a photo of the serial plate."
}
```

Response `200`:

```json
{
  "success": true,
  "data": {
    "updated": 1
  }
}
```

### Admin order detail

`GET /admin/orders/ord_4479a` · as `super@example.com` (super_admin)

Response `200`:

```json
{
  "success": true,
  "data": {
    "id": "ord_4479a",
    "number": "PW-4479",
    "item_title": "Bose A20 ×3",
    "item_image": {
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
    "buyer_name": "new_user_8812",
    "seller_name": "K. Taiwo",
    "total": {
      "amount": 223500,
      "currency": "USD"
    },
    "status": "on_hold",
    "escrow_status": "on_hold",
    "escrow_label": "On hold · risk",
    "freight_label": "—",
    "age_label": "6h",
    "risk": "high",
    "created_at": "2026-10-21T07:08:31.569Z",
    "payment_gateway": "paystack",
    "payment_reference": "PSK_8812_a91f",
    "ledger": [
      {
        "at": "2026-10-21T07:08:31.569Z",
        "entry": "Card payment captured (Paystack)",
        "amount": {
          "amount": 223500,
          "currency": "USD"
        }
      },
      {
        "at": "2026-10-21T07:08:31.569Z",
        "entry": "Escrow hold created",
        "amount": {
          "amount": 223500,
          "currency": "USD"
        }
      },
      "… 1 more"
    ],
    "risk_signals": [
      "3 headsets from a 1-day-old account",
      "Card BIN country ≠ IP country",
      "… 2 more"
    ],
    "breakdown": {
      "subtotal": {
        "amount": 223500,
        "currency": "USD"
      },
      "platform_fee": {
        "amount": 17880,
        "currency": "USD"
      },
      "seller_would_receive": {
        "amount": 205620,
        "currency": "USD"
      }
    },
    "parties": [
      {
        "role": "buyer",
        "name": "new_user_8812",
        "detail": "Buyer · KYC not started · risk HIGH",
        "risk": "high"
      },
      {
        "role": "seller",
        "name": "Kunle Taiwo",
        "detail": "Seller · verified · risk LOW",
        "risk": "low"
      }
    ],
    "refund_method_label": "Visa •••• 0031"
  }
}
```

### Refund — dual control

`POST /admin/orders/ord_4479a/refund` · as `super@example.com` (super_admin)

Request:

```json
{
  "type": "full",
  "reason": "not_as_described",
  "note": "Seller confirmed wrong model shipped.",
  "suspend_buyer": false
}
```

Response `200`:

```json
{
  "success": true,
  "message": "Refund requested — a second approver must confirm.",
  "data": {
    "status": "pending_approval",
    "approver": "Amaka N."
  }
}
```

### Dispute decision

`POST /admin/disputes/dsp_2041/decision` · as `super@example.com` (super_admin)

Request:

```json
{
  "decision": "partial_refund",
  "amount_to_buyer": 5000,
  "policy_reference": "Buyer protection 4.2 (cosmetic damage)"
}
```

Response `200`:

```json
{
  "success": true,
  "message": "Decision issued. Both parties notified.",
  "data": {
    "status": "executed"
  }
}
```

### Report action

`POST /admin/reports/rep_1/actions` · as `super@example.com` (super_admin)

Request:

```json
{
  "action": "dismiss",
  "note": "No violation"
}
```

Response `200`:

```json
{
  "success": true,
  "message": "Action applied and report closed.",
  "data": {
    "id": "rep_1",
    "severity": "critical",
    "title": "Counterfeit Ray-Ban sold as genuine",
    "detail": "3 reports · same seller",
    "target": "Listing LS-81120 · jetdeals_ng",
    "source": "User reports",
    "reported_ago": "2h",
    "assignee": {
      "initials": "AN",
      "name": "Amaka N."
    },
    "primary_action": {
      "key": "remove",
      "label": "Remove",
      "tone": "danger"
    },
    "type": "listing"
  }
}
```

### Payouts table

`GET /admin/payouts?per_page=2` · as `super@example.com` (super_admin)

Response `200`:

```json
{
  "success": true,
  "data": [
    {
      "id": "po_1",
      "seller_name": "Nehemiah Okoye",
      "initials": "NO",
      "seller_detail": "Verified · 112 trades",
      "amount": {
        "amount": 250000,
        "currency": "USD"
      },
      "destination": "GTBank NGN •••• 0921",
      "reason": "Over $2,000 threshold",
      "requested_at": "Today 09:12",
      "flagged": false,
      "status": "awaiting_approval"
    },
    {
      "id": "po_2",
      "seller_name": "Tunde Shot Aviation",
      "initials": "TS",
      "seller_detail": "Photographer",
      "amount": {
        "amount": 184000,
        "currency": "USD"
      },
      "destination": "Wise USD •••• 4410",
      "reason": "First payout to Wise",
      "requested_at": "Today 08:40",
      "flagged": false,
      "status": "awaiting_approval"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 2,
    "total": 5,
    "last_page": 3,
    "from": 1,
    "to": 2,
    "counts": {
      "awaiting_approval": 5,
      "processing": 64,
      "failed": 2
    }
  },
  "links": {
    "first": "?page=1",
    "last": "?page=3",
    "next": "?page=2",
    "prev": null
  }
}
```

### Fee rule change — dual control

`POST /admin/commission-rules` · as `super@example.com` (super_admin)

Request:

```json
{
  "name": "Headsets",
  "rate": 7.5
}
```

Response `200`:

```json
{
  "success": true,
  "message": "Fee change submitted — a second approver must confirm.",
  "data": {
    "status": "pending_approval",
    "approver": "Super admin"
  }
}
```

### Hero slide update

`PATCH /admin/content/hero-slides/hs_1` · as `super@example.com` (super_admin)

Request:

```json
{
  "headline": "Buy, sell or swap premium aviation gear",
  "active": true
}
```

Response `200`:

```json
{
  "success": true,
  "message": "Slide saved as draft.",
  "data": {
    "id": "hs_1",
    "position": 1,
    "headline": "Buy, sell or swap premium aviation gear",
    "subtext": "The trusted marketplace for pilots, mechanics and fleet operators. Turn surplus hangar kit and flight gear into the assets you need — funds held in escrow until goods land.",
    "button_label": "Shop verified gear",
    "link": "/marketplace",
    "audience": "All users · always",
    "locales": "EN · FR · HA",
    "status": "live",
    "schedule": "always",
    "image": {
      "id": "med_hero-cockpit",
      "public_id": "pilotwardrobe/listings/hero-cockpit",
      "url": "/media/mock/hero-cockpit-2560.jpg",
      "secure_url": "/media/mock/hero-cockpit-2560.jpg",
      "width": 2816,
      "height": 1536,
      "format": "jpg",
      "bytes": 778568,
      "alt": "Pilot's lap in a light-aircraft cockpit with headset and iPad kneeboard",
      "variants": {
        "thumbnail": "/media/mock/hero-cockpit-640.webp",
        "small": "/media/mock/hero-cockpit-640.webp",
        "medium": "/media/mock/hero-cockpit-640.webp",
        "large": "/media/mock/hero-cockpit-1440.webp",
        "original": "/media/mock/hero-cockpit-2560.jpg"
      },
      "sources": [
        {
          "url": "/media/mock/hero-cockpit-640.avif",
          "width": 640,
          "format": "avif"
        },
        {
          "url": "/media/mock/hero-cockpit-960.avif",
          "width": 960,
          "format": "avif"
        },
        "… 13 more"
      ],
      "placeholder": "#2b3442"
    },
    "active": true
  }
}
```

### Roles

`GET /admin/roles` · as `super@example.com` (super_admin)

Response `200`:

```json
{
  "success": true,
  "data": [
    {
      "id": "role_super",
      "name": "Super admin",
      "members": 2,
      "matrix": {
        "view": "manage",
        "users": "manage",
        "kyc": "manage",
        "listings": "manage",
        "orders": "manage",
        "refunds": "manage",
        "disputes": "manage",
        "payouts": "manage",
        "fees": "manage",
        "content": "manage",
        "team": "manage"
      }
    },
    {
      "id": "role_ts",
      "name": "Trust & safety",
      "members": 4,
      "matrix": {
        "view": "manage",
        "users": "manage",
        "kyc": "manage",
        "listings": "manage",
        "orders": "view",
        "refunds": "none",
        "disputes": "manage",
        "payouts": "view",
        "fees": "none",
        "content": "none",
        "team": "none"
      }
    },
    "… 3 more"
  ]
}
```

### Update role permissions

`PATCH /admin/roles/role_ts` · as `super@example.com` (super_admin)

Request:

```json
{
  "matrix": {
    "view": "manage",
    "users": "manage",
    "kyc": "manage",
    "listings": "manage",
    "orders": "view",
    "refunds": "none",
    "disputes": "manage",
    "payouts": "none",
    "fees": "none",
    "content": "none",
    "team": "none"
  }
}
```

Response `200`:

```json
{
  "success": true,
  "data": {
    "id": "role_ts",
    "name": "Trust & safety",
    "members": 4,
    "matrix": {
      "view": "manage",
      "users": "manage",
      "kyc": "manage",
      "listings": "manage",
      "orders": "view",
      "refunds": "none",
      "disputes": "manage",
      "payouts": "none",
      "fees": "none",
      "content": "none",
      "team": "none"
    }
  }
}
```

### Audit log

`GET /admin/audit-logs?per_page=2` · as `super@example.com` (super_admin)

Response `200`:

```json
{
  "success": true,
  "data": [
    {
      "id": "au_lj4rr29",
      "at": "2026-10-21T13:08:31.574Z",
      "actor": {
        "name": "Tolu Adeyemi",
        "initials": "TA",
        "system": false
      },
      "action": "ROLE.UPDATED",
      "tone": "warning",
      "target": "Trust & safety",
      "details": "Permission matrix changed",
      "ip": "102.89.4.12"
    },
    {
      "id": "au_6ap8opw",
      "at": "2026-10-21T13:08:31.572Z",
      "actor": {
        "name": "Tolu Adeyemi",
        "initials": "TA",
        "system": false
      },
      "action": "FEE_RULE.CREATED",
      "tone": "warning",
      "target": "new",
      "details": "Headsets 7.5% · awaiting approver",
      "ip": "102.89.4.12"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 2,
    "total": 14,
    "last_page": 7,
    "from": 1,
    "to": 2
  },
  "links": {
    "first": "?page=1",
    "last": "?page=7",
    "next": "?page=2",
    "prev": null
  }
}
```

### Platform settings

`GET /admin/settings` · as `super@example.com` (super_admin)

Response `200`:

```json
{
  "success": true,
  "data": {
    "markets": [
      {
        "country_code": "NG",
        "name": "Nigeria",
        "detail": "NGN · Paystack · KYC: Smile ID",
        "enabled": true,
        "status": "live"
      },
      {
        "country_code": "GH",
        "name": "Ghana",
        "detail": "GHS · Paystack + MoMo · KYC: Smile ID",
        "enabled": true,
        "status": "live"
      },
      "… 2 more"
    ],
    "display_currencies": [
      "USD",
      "NGN",
      "… 3 more"
    ],
    "settlement_currency": "USD",
    "fx_margin_percent": 1.5,
    "feature_flags": [
      {
        "key": "fit_kit",
        "label": "Fit Kit size matching",
        "detail": "100% · all markets",
        "enabled": true
      },
      {
        "key": "swaps_cash",
        "label": "Swaps with cash top-up",
        "detail": "100%",
        "enabled": true
      },
      "… 3 more"
    ],
    "gateways": [
      {
        "code": "paystack",
        "name": "Paystack",
        "enabled": true,
        "currencies": [
          "NGN",
          "GHS",
          "… 1 more"
        ],
        "countries": [
          "NG",
          "GH"
        ],
        "mode": "live",
        "public_key_masked": "pk_live_••••3f1a",
        "webhook_status": "healthy"
      },
      {
        "code": "flutterwave",
        "name": "Flutterwave",
        "enabled": true,
        "currencies": [
          "NGN",
          "GHS",
          "… 1 more"
        ],
        "countries": [
          "NG",
          "GH",
          "… 1 more"
        ],
        "mode": "live",
        "public_key_masked": "FLWPUBK-••••-X",
        "webhook_status": "healthy"
      },
      "… 3 more"
    ],
    "maintenance": {
      "scheduled": false,
      "window": null
    }
  }
}
```

### Suspend user

`POST /admin/users/usr_10422/suspend` · as `super@example.com` (super_admin)

Request:

```json
{
  "reason": "Off-platform payment solicitation",
  "note": "Second strike"
}
```

Response `200`:

```json
{
  "success": true,
  "data": {
    "id": "usr_10422",
    "code": "U-10422",
    "display_name": "Nehemiah Okoye",
    "initials": "NO",
    "email": "airboy@gmail.com",
    "role": "user",
    "role_label": "Verified seller",
    "kyc_status": "approved",
    "country_code": "NG",
    "country_name": "Nigeria",
    "trades": 112,
    "gmv": {
      "amount": 1892000,
      "currency": "USD"
    },
    "risk": "low",
    "status": "suspended",
    "joined_at": "2025-03-12T00:00:00Z",
    "phone_masked": "+234 •••• 4417",
    "city": "Lagos, NG",
    "signup_method": "Google",
    "stats": {
      "gmv_sold": {
        "amount": 1892000,
        "currency": "USD"
      },
      "trades_sold": 112,
      "gmv_bought": {
        "amount": 241000,
        "currency": "USD"
      },
      "orders_bought": 9,
      "rating": 4.9,
      "reviews": 86,
      "disputes": 2,
      "disputes_open": 1,
      "disputes_won": 1,
      "wallet": {
        "amount": 469455,
        "currency": "USD"
      },
      "wallet_escrow": {
        "amount": 128400,
        "currency": "USD"
      }
    },
    "identity": {
      "document_label": "Passport · NGA",
      "checks": [
        {
          "label": "Document authentic",
          "detail": "Passport · NGA",
          "passed": true
        },
        {
          "label": "Face match",
          "detail": "98.4%",
          "passed": true
        },
        "… 2 more"
      ],
      "image": null
    },
    "risk_score": 12,
    "risk_detail": "Low · 0 linked accounts · 1 device fingerprint",
    "limits": {
      "can_list": true,
      "can_receive_payouts": true,
      "can_trade": true,
      "instant_payouts": false,
      "daily_payout_limit": {
        "amount": 500000,
        "currency": "USD"
      }
    },
    "activity": [
      {
        "id": "a1",
        "label": "Withdrew $1,200.00 to GTBank •••• 0921",
        "detail": "Jul 26 · 2FA passed · IP 102.89.•.•",
        "state": "ok"
      },
      {
        "id": "a2",
        "label": "Disputed by buyer · #DS-2041",
        "detail": "Jul 29 · item not as described",
        "state": "flag"
      },
      "… 2 more"
    ],
    "notes": [
      {
        "id": "n1",
        "author": "Amaka N.",
        "initials": "AN",
        "body": "Seller offered return on DS-2041 promptly. Good faith.",
        "created_at": "2026-10-20T13:08:31.577Z"
      },
      {
        "id": "n2",
        "author": "Tolu A.",
        "initials": "TA",
        "body": "Raised listing cap after KYC.",
        "created_at": "2026-10-11T13:08:31.577Z"
      }
    ]
  }
}
```

### KYC approve

`POST /admin/kyc/kyc_7731/approve` · as `super@example.com` (super_admin)

Request:

```json
{
  "reason": ""
}
```

Response `200`:

```json
{
  "success": true,
  "data": {
    "id": "kyc_7731",
    "code": "KY-7731",
    "user_id": "usr_11034",
    "display_name": "Ifeoma Afolabi",
    "initials": "IA",
    "document_type": "passport",
    "document_label": "Passport · UK",
    "country_code": "GB",
    "age_hours": 26,
    "over_sla": true,
    "status": "done",
    "submitted_at": "2026-10-20T11:08:31.577Z",
    "auto_checks_passed": 3,
    "auto_checks_total": 4,
    "document": null,
    "selfie": null,
    "extracted": [
      {
        "field": "Full name",
        "document": "IFEOMA AFOLABI",
        "profile": "Ifeoma Afolabi",
        "match": true
      },
      {
        "field": "Date of birth",
        "document": "14 MAR 1994",
        "profile": "14/03/1994",
        "match": true
      },
      "… 3 more"
    ],
    "automated_checks": [
      {
        "label": "Document authenticity",
        "result": "MRZ valid",
        "passed": true
      },
      {
        "label": "Face match",
        "result": "96.1%",
        "passed": true
      },
      "… 3 more"
    ]
  }
}
```

### Hold payout

`POST /admin/payouts/po_1/hold` · as `super@example.com` (super_admin)

Request:

```json
{
  "reason": "Pending dispute on linked order"
}
```

Response `200`:

```json
{
  "success": true,
  "data": {
    "id": "po_1",
    "seller_name": "Nehemiah Okoye",
    "initials": "NO",
    "seller_detail": "Verified · 112 trades",
    "amount": {
      "amount": 250000,
      "currency": "USD"
    },
    "destination": "GTBank NGN •••• 0921",
    "reason": "Over $2,000 threshold",
    "requested_at": "Today 09:12",
    "flagged": false,
    "status": "awaiting_approval"
  }
}
```
