# Users (self-service account)

> Wire format (snake_case) exactly as the mock server emits it, captured from `src/mocks/` with
> `tests/unit/contract-payloads.spec.ts` (regenerate with `npm run docs:payloads`). Long arrays are truncated with `"… N more"`.
> Base URL: `{VITE_API_BASE_URL}` = `https://api.pilotwardrobe.com/api/v1`.

Endpoints under `/me/*` act on the session user only. They never take a user id.

- Password change, session revocation, social unlinking and account deletion are security events: send a ZeptoMail security notice to the account email (see `docs/backend-api-contract.md` §Email notifications).
- `DELETE /me` and `DELETE /me/social/{provider}` return **409 `CONFLICT`** when the action would leave the account in an invalid state (open orders/escrow balance; last sign-in method).
- KYC documents are uploaded first through `POST /media` with `type=kyc`; KYC endpoints receive only media ids. KYC media must be stored as **private/authenticated** Cloudinary assets and never returned as public URLs to anyone but staff with `kyc.view`.
- `GET /me/hangar` is an aggregate for the seller dashboard (V5) to avoid N requests on page load.

## Samples

### Update profile

`PATCH /me` · as `pilot@example.com` (user, KYC approved)

Request:

```json
{
  "first_name": "Nehemiah",
  "bio": "A320 FO"
}
```

Response `200`:

```json
{
  "success": true,
  "message": "Profile updated.",
  "data": {
    "id": "usr_10422",
    "first_name": "Nehemiah",
    "last_name": "Okoye",
    "display_name": "Nehemiah Okoye",
    "username": "nehemiah_o",
    "email": "pilot@example.com",
    "phone": "+2348021184460",
    "role": "user",
    "permissions": [
      "listings.create",
      "listings.update",
      "… 8 more"
    ],
    "avatar": null,
    "verification": {
      "email": true,
      "identity": true,
      "aviator": false,
      "kyc_status": "approved"
    },
    "status": "active",
    "country_code": "NG",
    "currency": "USD",
    "locale": "en",
    "two_factor_enabled": false,
    "member_since": "2025-03-12T09:00:00Z",
    "counters": {
      "unread_messages": 2,
      "unread_notifications": 4,
      "cart_items": 2,
      "open_offers": 5,
      "active_orders": 3,
      "active_listings": 24,
      "open_disputes": 1
    }
  }
}
```

### Security overview

`GET /me/security` · as `pilot@example.com` (user, KYC approved)

Response `200`:

```json
{
  "success": true,
  "data": {
    "methods": [
      {
        "provider": "password",
        "connected": true,
        "detail": "changed 3 months ago"
      },
      {
        "provider": "google",
        "connected": true,
        "detail": "airboy@gmail.com"
      },
      "… 1 more"
    ],
    "two_factor": {
      "authenticator_enabled": true,
      "sms_backup_masked": "•••• 4417",
      "backup_codes_remaining": 8
    },
    "sessions": [
      {
        "id": "ses_1",
        "device": "Chrome on macOS",
        "location": "Lagos, NG",
        "last_active_at": "2026-10-21T13:08:31.245Z",
        "current": true
      },
      {
        "id": "ses_2",
        "device": "Pilotwardrobe iOS 3.2",
        "location": "iPhone 15 · Lagos, NG",
        "last_active_at": "2026-10-21T11:08:31.245Z",
        "current": false
      },
      "… 1 more"
    ],
    "password_changed_at": "2026-07-21T13:08:31.245Z"
  }
}
```

### Change password — validation (422)

`PUT /me/password` · as `pilot@example.com` (user, KYC approved)

Request:

```json
{
  "current_password": "wrong",
  "password": "Password2!",
  "password_confirmation": "Password2!"
}
```

Response `422`:

```json
{
  "success": false,
  "message": "The submitted data is invalid.",
  "code": "VALIDATION_ERROR",
  "errors": {
    "current_password": [
      "Your current password is incorrect."
    ]
  }
}
```

### Notification preferences

`GET /me/notification-preferences` · as `pilot@example.com` (user, KYC approved)

Response `200`:

```json
{
  "success": true,
  "data": [
    {
      "category": "offers_swaps",
      "label": "Offers & swaps",
      "email": true,
      "push": true
    },
    {
      "category": "orders",
      "label": "Order updates",
      "email": true,
      "push": true
    },
    "… 3 more"
  ]
}
```

### Fit Kit profiles

`GET /me/fit-kit` · as `pilot@example.com` (user, KYC approved)

Response `200`:

```json
{
  "success": true,
  "data": [
    {
      "id": "fit_1",
      "name": "Uniform fit",
      "for_person": "Nehemiah",
      "initials": "NO",
      "is_default": true,
      "unit": "us_in",
      "jacket": "42R",
      "collar": "15.5\"",
      "sleeve": "34–35",
      "waist": "34",
      "inseam": "32",
      "shoe": "10.5",
      "bag": "crew",
      "preferences": {
        "filter_search_by_size": true,
        "warn_outside_measurements": true,
        "use_at_checkout": false
      },
      "updated_at": "2026-07-12T10:00:00Z",
      "orders_used_on": 6
    },
    {
      "id": "fit_2",
      "name": "Cadet fit",
      "for_person": "Adaeze",
      "initials": "AO",
      "is_default": false,
      "unit": "us_in",
      "jacket": "36S",
      "collar": "14\"",
      "sleeve": null,
      "waist": null,
      "inseam": null,
      "shoe": "7",
      "bag": "compact",
      "preferences": {
        "filter_search_by_size": false,
        "warn_outside_measurements": true,
        "use_at_checkout": false
      },
      "updated_at": "2026-06-03T10:00:00Z",
      "orders_used_on": 1
    }
  ]
}
```

### Update Fit Kit profile

`PATCH /me/fit-kit/fit_1` · as `pilot@example.com` (user, KYC approved)

Request:

```json
{
  "jacket": "40R",
  "collar": "15.5"
}
```

Response `200`:

```json
{
  "success": true,
  "message": "Profile saved.",
  "data": {
    "id": "fit_1",
    "name": "Uniform fit",
    "for_person": "Nehemiah",
    "initials": "NO",
    "is_default": true,
    "unit": "us_in",
    "jacket": "40R",
    "collar": "15.5",
    "sleeve": "34–35",
    "waist": "34",
    "inseam": "32",
    "shoe": "10.5",
    "bag": "crew",
    "preferences": {
      "filter_search_by_size": true,
      "warn_outside_measurements": true,
      "use_at_checkout": false
    },
    "updated_at": "2026-10-21T13:08:31.488Z",
    "orders_used_on": 6
  }
}
```

### Addresses

`GET /me/addresses` · as `pilot@example.com` (user, KYC approved)

Response `200`:

```json
{
  "success": true,
  "data": [
    {
      "id": "adr_1",
      "label": "Home",
      "full_name": "Nehemiah Okoye",
      "phone": "+234 802 118 4460",
      "line1": "18 Ikoyi Crescent",
      "city": "Lagos",
      "state": "Lagos",
      "postal_code": "106104",
      "country_code": "NG",
      "is_default": true
    },
    {
      "id": "adr_2",
      "label": "Crew lounge",
      "full_name": "Nehemiah Okoye",
      "phone": "+234 802 118 4460",
      "line1": "MMA2 Crew Lounge, Murtala Muhammed Airport",
      "city": "Ikeja",
      "state": "Lagos",
      "postal_code": "100001",
      "country_code": "NG",
      "is_default": false
    }
  ]
}
```

### Create address

`POST /me/addresses` · as `pilot@example.com` (user, KYC approved)

Request:

```json
{
  "label": "Crew house",
  "full_name": "Nehemiah Okafor",
  "phone": "+2348031234567",
  "line1": "12 Airport Rd",
  "city": "Ikeja",
  "state": "Lagos",
  "country_code": "NG",
  "is_default": false
}
```

Response `201`:

```json
{
  "success": true,
  "message": "Created successfully.",
  "data": {
    "label": "Crew house",
    "full_name": "Nehemiah Okafor",
    "phone": "+2348031234567",
    "line1": "12 Airport Rd",
    "city": "Ikeja",
    "state": "Lagos",
    "country_code": "NG",
    "is_default": false,
    "id": "adr_gspoo9d"
  }
}
```

### KYC status

`GET /me/kyc` · as `newbie@example.com` (user, KYC not started)

Response `200`:

```json
{
  "success": true,
  "data": {
    "id": "kyc_me",
    "status": "not_started",
    "step": "personal_details",
    "document_type": null,
    "personal": null,
    "document_front": null,
    "handoff_url": "https://pilotwardrobe.com/k/7Q2X9",
    "rejection_reason": null,
    "submitted_at": null
  }
}
```

### Aviator credentials

`GET /me/aviator` · as `aviator@example.com` (aviator)

Response `200`:

```json
{
  "success": true,
  "data": {
    "licence_type": "atpl",
    "licence_number_masked": "NCAA/ATPL/••••12",
    "issuing_authority": "NCAA (Nigeria)",
    "job_title": "Captain",
    "organisation": "Air Peace",
    "type_ratings": [
      "A320",
      "B737"
    ],
    "status": "approved",
    "verified_at": "2024-02-10T09:00:00Z"
  }
}
```

### Hangar summary

`GET /me/hangar` · as `pilot@example.com` (user, KYC approved)

Response `200`:

```json
{
  "success": true,
  "data": {
    "seller_since": "2025-03-12T09:00:00Z",
    "verified_seller": true,
    "stats": {
      "in_escrow": {
        "amount": 128400,
        "currency": "USD"
      },
      "escrow_orders": 2,
      "available_to_pay_out": {
        "amount": 341055,
        "currency": "USD"
      },
      "week_delta": {
        "amount": 48200,
        "currency": "USD"
      },
      "live_listings": 6,
      "listings_need_photos": 4,
      "seller_rating": 4.9,
      "previous_rating": 4.8
    },
    "wallet": {
      "total": {
        "amount": 469455,
        "currency": "USD"
      },
      "available": {
        "amount": 341055,
        "currency": "USD"
      },
      "in_escrow": {
        "amount": 128400,
        "currency": "USD"
      }
    },
    "attention": [
      {
        "id": "att1",
        "tone": "warning",
        "title": "2 orders awaiting wheels-up",
        "detail": "Dispatch by tomorrow to keep your on-time rate",
        "to": "/hangar/orders?role=seller"
      },
      {
        "id": "att2",
        "tone": "info",
        "title": "2 open offers",
        "detail": "Highest: $712 on the Bose A20 Aviation Headset — expires in 9h",
        "to": "/hangar/offers"
      },
      "… 1 more"
    ],
    "feed": [
      {
        "id": "f-lst_001",
        "kind": "listing",
        "title": "Bose A20 Aviation Headset — dual GA plugs",
        "subtitle": "Listed Oct 21 · 412 views · 5 offers",
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
        "status": {
          "label": "Live",
          "tone": "success"
        },
        "bucket": "live",
        "next_action": "Review highest offer — $712",
        "to": "/hangar/offers"
      },
      {
        "id": "f-ord_4471",
        "kind": "order",
        "title": "Jeppesen Captain Flight Bag, leather trim",
        "subtitle": "Order #PW-4471 · buyer Nehemiah Okoye",
        "image": {
          "id": "med_jeppesen-flight-bag",
          "public_id": "pilotwardrobe/listings/jeppesen-flight-bag",
          "url": "/media/mock/jeppesen-flight-bag-232.jpg",
          "secure_url": "/media/mock/jeppesen-flight-bag-232.jpg",
          "width": 232,
          "height": 230,
          "format": "jpg",
          "bytes": 9605,
          "alt": "Black Jeppesen Captain flight bag",
          "variants": {
            "thumbnail": "/media/mock/jeppesen-flight-bag-232.webp",
            "small": "/media/mock/jeppesen-flight-bag-232.webp",
            "medium": "/media/mock/jeppesen-flight-bag-232.webp",
            "large": "/media/mock/jeppesen-flight-bag-232.webp",
            "original": "/media/mock/jeppesen-flight-bag-232.jpg"
          },
          "sources": [
            {
              "url": "/media/mock/jeppesen-flight-bag-232.avif",
              "width": 232,
              "format": "avif"
            },
            {
              "url": "/media/mock/jeppesen-flight-bag-232.webp",
              "width": 232,
              "format": "webp"
            },
            "… 1 more"
          ],
          "placeholder": "#f5f6fa"
        },
        "price": {
          "amount": 18000,
          "currency": "USD"
        },
        "status": {
          "label": "Airborne",
          "tone": "info"
        },
        "bucket": "airborne",
        "next_action": "En route — Lagos hub · ETA in 2–4 days",
        "to": "/hangar/orders/ord_4471"
      },
      "… 3 more"
    ]
  }
}
```
