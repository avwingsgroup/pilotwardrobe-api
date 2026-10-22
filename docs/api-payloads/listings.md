# Listings (seller side)

> Wire format (snake_case) exactly as the mock server emits it, captured from `src/mocks/` with
> `tests/unit/contract-payloads.spec.ts` (regenerate with `npm run docs:payloads`). Long arrays are truncated with `"… N more"`.
> Base URL: `{VITE_API_BASE_URL}` = `https://api.pilotwardrobe.com/api/v1`.

Create/edit/publish lifecycle for the listing wizard (V6) and *My listings* (VB3).

- A listing is created as `draft` and becomes `active` (or `pending_review` when moderation rules apply) through `POST /listings/{id}/publish`. Publish validates the full listing and returns 422 with field errors (e.g. `media_ids`).
- Unverified (KYC not approved) sellers are limited to **3 live listings**; the fourth publish returns **403 `KYC_REQUIRED`**.
- State actions use `POST /listings/{id}/{action}` with `action ∈ pause | resume | archive | mark-sold | mark-unavailable | relist | resubmit | promote` (allowed source states: pause←active, resume←paused, archive←active/paused, mark-sold←active/reserved/paused, relist←sold/archived, resubmit←needs_changes); illegal transitions return **409 `INVALID_STATE_TRANSITION`**.
- Photos are uploaded one at a time via `POST /media` (multipart `file` + `type=listing`). Laravel uploads to Cloudinary server-side and returns the `MediaAsset` (public id + transformation URLs). The browser never talks to the Cloudinary API.
- `POST /listings/fee-quote` returns the commission and net payout for a price so the wizard can display it; the backend is authoritative and re-computes at sale time.

## Samples

### Search listings

`GET /listings?search=headset&category=headsets&condition=like_new%2Cgood&min_price=10000&sort=price_asc&page=1&per_page=2` · as Visitor (no session)

Response `200`:

```json
{
  "success": true,
  "data": [
    {
      "id": "lst_001",
      "slug": "bose-a20-aviation-headset-dual-ga",
      "title": "Bose A20 Aviation Headset — dual GA plugs",
      "description": "Used for 140 hrs on a Cessna 172 and Seminole. ANR works on both sides, new ear seals fitted May 2025. Comes with original case and Bluetooth cable.",
      "category": {
        "id": "cat_anr",
        "slug": "anr-headsets",
        "name": "ANR headsets"
      },
      "breadcrumbs": [
        {
          "id": "cat_anr",
          "slug": "anr-headsets",
          "name": "ANR headsets"
        }
      ],
      "condition": "like_new",
      "format": "physical",
      "price": {
        "amount": 74500,
        "currency": "USD"
      },
      "compare_at_price": {
        "amount": 91000,
        "currency": "USD"
      },
      "minimum_offer": {
        "amount": 59600,
        "currency": "USD"
      },
      "seller": {
        "id": "usr_10422",
        "username": "nehemiah_o",
        "display_name": "Nehemiah Okoye",
        "verified": true,
        "is_aviator": true,
        "rating": 4.9,
        "total_reviews": 86,
        "total_sales": 112,
        "location": "Lagos, Nigeria",
        "dispatch_time": "ships in 2 days"
      },
      "images": [
        {
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
        {
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
        "… 1 more"
      ],
      "location": {
        "country_code": "NG",
        "state": "Lagos",
        "city": "Ikeja",
        "airport_code": "LOS"
      },
      "shipping": [
        {
          "id": "shp_std",
          "method": "standard",
          "label": "Standard cargo",
          "description": "5–10 business days · tracked",
          "price": {
            "amount": 2400,
            "currency": "USD"
          },
          "estimated_days": {
            "min": 5,
            "max": 10
          }
        },
        {
          "id": "shp_exp",
          "method": "express",
          "label": "Express airlift",
          "description": "2–3 business days · tracked & signed",
          "price": {
            "amount": 6200,
            "currency": "USD"
          },
          "estimated_days": {
            "min": 2,
            "max": 3
          }
        },
        "… 1 more"
      ],
      "freight_summary": "+$24 tracked freight",
      "negotiable": true,
      "swappable": true,
      "status": "active",
      "promoted": true,
      "badges": [
        {
          "kind": "best_seller",
          "label": "Best seller"
        }
      ],
      "brand": "Bose",
      "model": "A20",
      "attributes": [
        {
          "label": "Brand",
          "value": "Bose"
        },
        {
          "label": "Model",
          "value": "A20"
        },
        "… 1 more"
      ],
      "aircraft_types": [
        "B737",
        "A320",
        "… 1 more"
      ],
      "tags": [],
      "sizes": null,
      "rating": 4.9,
      "sold_count": 214,
      "views_count": 412,
      "favorites_count": 38,
      "offers_count": 5,
      "is_favorited": false,
      "created_at": "2026-10-21T07:08:31.261Z",
      "updated_at": "2026-10-21T07:08:31.261Z"
    },
    {
      "id": "lst_007",
      "slug": "lightspeed-zulu-3-carry-case",
      "title": "Lightspeed Zulu 3 with carry case",
      "description": "Lightspeed Zulu 3 with carry case. Well looked after and stored in a padded case between flights. Photos show the actual item. Happy to answer questions before you buy — all payments held in escrow until it lands.",
      "category": {
        "id": "cat_anr",
        "slug": "anr-headsets",
        "name": "ANR headsets"
      },
      "breadcrumbs": [
        {
          "id": "cat_anr",
          "slug": "anr-headsets",
          "name": "ANR headsets"
        }
      ],
      "condition": "good",
      "format": "physical",
      "price": {
        "amount": 78000,
        "currency": "USD"
      },
      "compare_at_price": null,
      "minimum_offer": {
        "amount": 62400,
        "currency": "USD"
      },
      "seller": {
        "id": "usr_10877",
        "username": "r_mensah",
        "display_name": "Richard Mensah",
        "verified": true,
        "is_aviator": true,
        "rating": 4.7,
        "total_reviews": 33,
        "total_sales": 41,
        "location": "Accra, Ghana"
      },
      "images": [
        {
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
        {
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
        }
      ],
      "location": {
        "country_code": "NG",
        "state": "Lagos",
        "city": "Ikeja",
        "airport_code": "LOS"
      },
      "shipping": [
        {
          "id": "shp_std",
          "method": "standard",
          "label": "Standard cargo",
          "description": "5–10 business days · tracked",
          "price": {
            "amount": 2400,
            "currency": "USD"
          },
          "estimated_days": {
            "min": 5,
            "max": 10
          }
        },
        {
          "id": "shp_exp",
          "method": "express",
          "label": "Express airlift",
          "description": "2–3 business days · tracked & signed",
          "price": {
            "amount": 6200,
            "currency": "USD"
          },
          "estimated_days": {
            "min": 2,
            "max": 3
          }
        },
        "… 1 more"
      ],
      "freight_summary": "+$24 tracked freight",
      "negotiable": true,
      "swappable": false,
      "status": "active",
      "promoted": false,
      "badges": [
        {
          "kind": "free_delivery",
          "label": "Free delivery"
        }
      ],
      "brand": "Lightspeed",
      "model": "Zulu 3",
      "attributes": [
        {
          "label": "Brand",
          "value": "Lightspeed"
        },
        {
          "label": "Model",
          "value": "Zulu 3"
        },
        "… 1 more"
      ],
      "aircraft_types": [],
      "tags": [],
      "sizes": null,
      "rating": 4.9,
      "sold_count": 127,
      "views_count": 412,
      "favorites_count": 38,
      "offers_count": 0,
      "is_favorited": false,
      "created_at": "2026-10-17T09:08:31.261Z",
      "updated_at": "2026-10-17T09:08:31.261Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 2,
    "total": 3,
    "last_page": 2,
    "from": 1,
    "to": 2,
    "facets": {
      "categories": [
        {
          "value": "anr-headsets",
          "label": "ANR headsets",
          "count": 612
        },
        {
          "value": "passive-headsets",
          "label": "Passive headsets",
          "count": 340
        },
        "… 2 more"
      ],
      "conditions": [
        {
          "value": "new",
          "label": "New",
          "count": 257
        },
        {
          "value": "like_new",
          "label": "Like new",
          "count": 234
        },
        "… 2 more"
      ],
      "deal_types": [
        {
          "value": "buy_now",
          "label": "Buy now",
          "count": 498
        },
        {
          "value": "offers",
          "label": "Accepts offers",
          "count": 286
        },
        "… 1 more"
      ],
      "seller": [
        {
          "value": "verified_only",
          "label": "KYC verified only",
          "count": 524
        },
        {
          "value": "rating_45",
          "label": "Rating 4.5+",
          "count": 470
        },
        "… 1 more"
      ],
      "price_range": {
        "min": 0,
        "max": 200000,
        "currency": "USD"
      }
    },
    "applied_currency": "USD"
  },
  "links": {
    "first": "?page=1",
    "last": "?page=2",
    "next": "?page=2",
    "prev": null
  }
}
```

### My listings

`GET /me/listings?status=active&per_page=2` · as `pilot@example.com` (user, KYC approved)

Response `200`:

```json
{
  "success": true,
  "data": [
    {
      "id": "lst_001",
      "slug": "bose-a20-aviation-headset-dual-ga",
      "title": "Bose A20 Aviation Headset — dual GA plugs",
      "description": "Used for 140 hrs on a Cessna 172 and Seminole. ANR works on both sides, new ear seals fitted May 2025. Comes with original case and Bluetooth cable.",
      "category": {
        "id": "cat_anr",
        "slug": "anr-headsets",
        "name": "ANR headsets"
      },
      "breadcrumbs": [
        {
          "id": "cat_anr",
          "slug": "anr-headsets",
          "name": "ANR headsets"
        }
      ],
      "condition": "like_new",
      "format": "physical",
      "price": {
        "amount": 74500,
        "currency": "USD"
      },
      "compare_at_price": {
        "amount": 91000,
        "currency": "USD"
      },
      "minimum_offer": {
        "amount": 59600,
        "currency": "USD"
      },
      "seller": {
        "id": "usr_10422",
        "username": "nehemiah_o",
        "display_name": "Nehemiah Okoye",
        "verified": true,
        "is_aviator": true,
        "rating": 4.9,
        "total_reviews": 86,
        "total_sales": 112,
        "location": "Lagos, Nigeria",
        "dispatch_time": "ships in 2 days"
      },
      "images": [
        {
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
        {
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
        "… 1 more"
      ],
      "location": {
        "country_code": "NG",
        "state": "Lagos",
        "city": "Ikeja",
        "airport_code": "LOS"
      },
      "shipping": [
        {
          "id": "shp_std",
          "method": "standard",
          "label": "Standard cargo",
          "description": "5–10 business days · tracked",
          "price": {
            "amount": 2400,
            "currency": "USD"
          },
          "estimated_days": {
            "min": 5,
            "max": 10
          }
        },
        {
          "id": "shp_exp",
          "method": "express",
          "label": "Express airlift",
          "description": "2–3 business days · tracked & signed",
          "price": {
            "amount": 6200,
            "currency": "USD"
          },
          "estimated_days": {
            "min": 2,
            "max": 3
          }
        },
        "… 1 more"
      ],
      "freight_summary": "+$24 tracked freight",
      "negotiable": true,
      "swappable": true,
      "status": "active",
      "promoted": true,
      "badges": [
        {
          "kind": "best_seller",
          "label": "Best seller"
        }
      ],
      "brand": "Bose",
      "model": "A20",
      "attributes": [
        {
          "label": "Brand",
          "value": "Bose"
        },
        {
          "label": "Model",
          "value": "A20"
        },
        "… 1 more"
      ],
      "aircraft_types": [
        "B737",
        "A320",
        "… 1 more"
      ],
      "tags": [],
      "sizes": null,
      "rating": 4.9,
      "sold_count": 214,
      "views_count": 412,
      "favorites_count": 38,
      "offers_count": 5,
      "is_favorited": false,
      "created_at": "2026-10-21T07:08:31.261Z",
      "updated_at": "2026-10-21T07:08:31.261Z",
      "allowed_actions": [
        "edit",
        "preview",
        "… 5 more"
      ]
    },
    {
      "id": "lst_002",
      "slug": "randolph-aviator-cr-55mm",
      "title": "Randolph Aviator CR — 55mm, bayonet temples",
      "description": "Randolph Aviator CR — 55mm, bayonet temples. Well looked after and stored in a padded case between flights. Photos show the actual item. Happy to answer questions before you buy — all payments held in escrow until it lands.",
      "category": {
        "id": "cat_eyewear",
        "slug": "eyewear",
        "name": "Eyewear"
      },
      "breadcrumbs": [
        {
          "id": "cat_eyewear",
          "slug": "eyewear",
          "name": "Eyewear"
        }
      ],
      "condition": "like_new",
      "format": "physical",
      "price": {
        "amount": 21000,
        "currency": "USD"
      },
      "compare_at_price": {
        "amount": 24900,
        "currency": "USD"
      },
      "minimum_offer": {
        "amount": 16800,
        "currency": "USD"
      },
      "seller": {
        "id": "usr_10422",
        "username": "nehemiah_o",
        "display_name": "Nehemiah Okoye",
        "verified": true,
        "is_aviator": true,
        "rating": 4.9,
        "total_reviews": 86,
        "total_sales": 112,
        "location": "Lagos, Nigeria",
        "dispatch_time": "ships in 2 days"
      },
      "images": [
        {
          "id": "med_randolph-aviator",
          "public_id": "pilotwardrobe/listings/randolph-aviator",
          "url": "/media/mock/randolph-aviator-300.jpg",
          "secure_url": "/media/mock/randolph-aviator-300.jpg",
          "width": 300,
          "height": 284,
          "format": "jpg",
          "bytes": 15336,
          "alt": "Black aviator sunglasses with green lenses",
          "variants": {
            "thumbnail": "/media/mock/randolph-aviator-300.webp",
            "small": "/media/mock/randolph-aviator-300.webp",
            "medium": "/media/mock/randolph-aviator-300.webp",
            "large": "/media/mock/randolph-aviator-300.webp",
            "original": "/media/mock/randolph-aviator-300.jpg"
          },
          "sources": [
            {
              "url": "/media/mock/randolph-aviator-300.avif",
              "width": 300,
              "format": "avif"
            },
            {
              "url": "/media/mock/randolph-aviator-300.webp",
              "width": 300,
              "format": "webp"
            },
            "… 1 more"
          ],
          "placeholder": "#f5f6fa"
        }
      ],
      "location": {
        "country_code": "NG",
        "state": "Lagos",
        "city": "Ikeja",
        "airport_code": "LOS"
      },
      "shipping": [
        {
          "id": "shp_std",
          "method": "standard",
          "label": "Standard cargo",
          "description": "5–10 business days · tracked",
          "price": {
            "amount": 2400,
            "currency": "USD"
          },
          "estimated_days": {
            "min": 5,
            "max": 10
          }
        },
        {
          "id": "shp_exp",
          "method": "express",
          "label": "Express airlift",
          "description": "2–3 business days · tracked & signed",
          "price": {
            "amount": 6200,
            "currency": "USD"
          },
          "estimated_days": {
            "min": 2,
            "max": 3
          }
        },
        "… 1 more"
      ],
      "freight_summary": "+$24 tracked freight",
      "negotiable": true,
      "swappable": false,
      "status": "active",
      "promoted": false,
      "badges": [],
      "brand": "Randolph",
      "model": "Aviator CR",
      "attributes": [
        {
          "label": "Brand",
          "value": "Randolph"
        },
        {
          "label": "Model",
          "value": "Aviator CR"
        },
        "… 1 more"
      ],
      "aircraft_types": [],
      "tags": [],
      "sizes": null,
      "rating": 4.8,
      "sold_count": 96,
      "views_count": 412,
      "favorites_count": 38,
      "offers_count": 0,
      "is_favorited": false,
      "created_at": "2026-10-20T17:08:31.261Z",
      "updated_at": "2026-10-20T17:08:31.261Z",
      "allowed_actions": [
        "edit",
        "preview",
        "… 5 more"
      ]
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 2,
    "total": 6,
    "last_page": 3,
    "from": 1,
    "to": 2,
    "counts": {
      "all": 10,
      "live": 6,
      "drafts": 1,
      "paused": 1,
      "sold": 1,
      "removed": 1
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

### Create draft listing

`POST /listings` · as `pilot@example.com` (user, KYC approved)

Request:

```json
{
  "title": "David Clark H10-13.4 headset",
  "description": "Lightly used, new ear seals, original case and receipt included.",
  "category_id": "headsets",
  "brand": "David Clark",
  "model": "H10-13.4",
  "attributes": {
    "connector": "Dual GA"
  },
  "serial_number": "",
  "year_purchased": "2023",
  "tags": [
    "headset"
  ],
  "condition": "good",
  "format": "physical",
  "price": {
    "amount": 18500,
    "currency": "USD"
  },
  "negotiable": true,
  "minimum_offer": {
    "amount": 16000,
    "currency": "USD"
  },
  "auto_decline_below_minimum": true
}
```

Response `201`:

```json
{
  "success": true,
  "message": "Draft saved.",
  "data": {
    "id": "lst_p12jfpt",
    "slug": "david-clark-h10-13-4-headset-3jjm",
    "title": "David Clark H10-13.4 headset",
    "description": "Lightly used, new ear seals, original case and receipt included.",
    "category": {
      "id": "cat_anr",
      "slug": "anr-headsets",
      "name": "ANR headsets"
    },
    "breadcrumbs": [
      {
        "id": "cat_anr",
        "slug": "anr-headsets",
        "name": "ANR headsets"
      }
    ],
    "condition": "good",
    "format": "physical",
    "price": {
      "amount": 18500,
      "currency": "USD"
    },
    "compare_at_price": {
      "amount": 91000,
      "currency": "USD"
    },
    "minimum_offer": {
      "amount": 16000,
      "currency": "USD"
    },
    "seller": {
      "id": "usr_10422",
      "username": "nehemiah_o",
      "display_name": "Nehemiah Okoye",
      "verified": true,
      "is_aviator": true,
      "rating": 4.9,
      "total_reviews": 86,
      "total_sales": 112,
      "location": "Lagos, Nigeria",
      "dispatch_time": "ships in 2 days"
    },
    "images": [],
    "location": {
      "country_code": "NG",
      "state": "Lagos",
      "city": "Ikeja",
      "airport_code": "LOS"
    },
    "shipping": [
      {
        "id": "shp_std",
        "method": "standard",
        "label": "Standard cargo",
        "description": "5–10 business days · tracked",
        "price": {
          "amount": 2400,
          "currency": "USD"
        },
        "estimated_days": {
          "min": 5,
          "max": 10
        }
      },
      {
        "id": "shp_exp",
        "method": "express",
        "label": "Express airlift",
        "description": "2–3 business days · tracked & signed",
        "price": {
          "amount": 6200,
          "currency": "USD"
        },
        "estimated_days": {
          "min": 2,
          "max": 3
        }
      },
      "… 1 more"
    ],
    "freight_summary": "+$24 tracked freight",
    "negotiable": true,
    "swappable": true,
    "status": "draft",
    "promoted": false,
    "badges": [],
    "brand": "David Clark",
    "model": "H10-13.4",
    "attributes": [
      {
        "label": "Brand",
        "value": "Bose"
      },
      {
        "label": "Model",
        "value": "A20"
      },
      "… 1 more"
    ],
    "aircraft_types": [
      "B737",
      "A320",
      "… 1 more"
    ],
    "tags": [
      "headset"
    ],
    "sizes": null,
    "rating": 4.9,
    "sold_count": 0,
    "views_count": 0,
    "favorites_count": 0,
    "offers_count": 0,
    "is_favorited": false,
    "created_at": "2026-10-21T13:08:31.525Z",
    "updated_at": "2026-10-21T13:08:31.526Z",
    "allowed_actions": [
      "finish",
      "preview",
      "… 1 more"
    ]
  }
}
```

### Publish listing — no photos (422)

`POST /listings/lst_p12jfpt/publish` · as `pilot@example.com` (user, KYC approved)

Response `422`:

```json
{
  "success": false,
  "message": "The submitted data is invalid.",
  "code": "VALIDATION_ERROR",
  "errors": {
    "media_ids": [
      "Add at least one photo."
    ]
  }
}
```

### Fee quote

`POST /listings/fee-quote` · as `pilot@example.com` (user, KYC approved)

Request:

```json
{
  "price": {
    "amount": 18500,
    "currency": "USD"
  }
}
```

Response `200`:

```json
{
  "success": true,
  "data": {
    "platform_fee_rate": 8,
    "platform_fee": {
      "amount": 1480,
      "currency": "USD"
    },
    "seller_receives": {
      "amount": 17020,
      "currency": "USD"
    }
  }
}
```

### Bulk action

`POST /listings/bulk` · as `pilot@example.com` (user, KYC approved)

Request:

```json
{
  "ids": [
    "lst_001"
  ],
  "action": "pause"
}
```

Response `200`:

```json
{
  "success": true,
  "message": "1 listing updated.",
  "data": {
    "updated": 1
  }
}
```

### Upload media (multipart → MediaAsset)

`POST /media` · as `pilot@example.com` (user, KYC approved)

Request:

`multipart/form-data: file=<binary>, type=listing`

Response `201`:

```json
{
  "success": true,
  "message": "Uploaded.",
  "data": {
    "id": "med_ciy3r1x",
    "public_id": "pilotwardrobe/listing/photo.jpg",
    "url": "blob:mock",
    "secure_url": "blob:mock",
    "width": 1600,
    "height": 1200,
    "format": "jpeg",
    "bytes": 1,
    "alt": "photo",
    "variants": {
      "thumbnail": "blob:mock",
      "small": "blob:mock",
      "medium": "blob:mock",
      "large": "blob:mock",
      "original": "blob:mock"
    }
  }
}
```
