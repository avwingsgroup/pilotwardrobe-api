# Public profiles (sellers, photographers, directory)

> Wire format (snake_case) exactly as the mock server emits it, captured from `src/mocks/` with
> `tests/unit/contract-payloads.spec.ts` (regenerate with `npm run docs:payloads`). Long arrays are truncated with `"… N more"`.
> Base URL: `{VITE_API_BASE_URL}` = `https://api.pilotwardrobe.com/api/v1`.

Public, unauthenticated reads of seller storefronts (V7, VB19) and the directory. When a session is present, `is_following` reflects the viewer.

- Never include private fields (email, phone, exact address, KYC data) in public seller payloads.
- `rating_breakdown` keys are the strings `"1"`–`"5"`.

## Samples

### Seller directory

`GET /sellers?per_page=2` · as Visitor (no session)

Response `200`:

```json
{
  "success": true,
  "data": [
    {
      "id": "usr_1",
      "username": "pilotwardrobe",
      "display_name": "Pilotwardrobe Official",
      "verified": true,
      "is_aviator": false,
      "is_official_store": true,
      "rating": 4.9,
      "total_reviews": 1204,
      "total_sales": 1204,
      "location": "Lagos, Nigeria",
      "dispatch_time": "wheels-up in 24h"
    },
    {
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
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 2,
    "total": 7,
    "last_page": 4,
    "from": 1,
    "to": 2
  },
  "links": {
    "first": "?page=1",
    "last": "?page=4",
    "next": "?page=2",
    "prev": null
  }
}
```

### Seller profile

`GET /sellers/pilotwardrobe` · as Visitor (no session)

Response `200`:

```json
{
  "success": true,
  "data": {
    "id": "usr_1",
    "username": "pilotwardrobe",
    "display_name": "Pilotwardrobe Official",
    "verified": true,
    "is_aviator": false,
    "is_official_store": true,
    "rating": 4.9,
    "total_reviews": 1204,
    "total_sales": 1204,
    "location": "Lagos, Nigeria",
    "dispatch_time": "wheels-up in 24h",
    "bio": "Official Pilotwardrobe store — uniforms, epaulettes and merch, sized to your Fit Kit.",
    "cover_image": {
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
    "job_title": "Official store",
    "aircraft_type": null,
    "member_since": "2024-01-01T09:00:00Z",
    "response_time": "~1h",
    "response_rate": 100,
    "followers": 18400,
    "stats": {
      "trades": 1204,
      "on_time_dispatch_rate": 99,
      "listings_count": 48,
      "sold_count": 1204,
      "reviews_count": 1204
    },
    "rating_breakdown": {
      "1": 1,
      "2": 3,
      "3": 20,
      "4": 80,
      "5": 1100
    },
    "is_following": false
  }
}
```

### Seller listings

`GET /sellers/pilotwardrobe/listings?per_page=2` · as Visitor (no session)

Response `200`:

```json
{
  "success": true,
  "data": [
    {
      "id": "lst_003",
      "slug": "pilotwardrobe-aviator-uniform-shirt",
      "title": "Pilotwardrobe Aviator Uniform Shirt — epaulette loops, wrinkle-free",
      "description": "Pilotwardrobe Aviator Uniform Shirt — epaulette loops, wrinkle-free. Well looked after and stored in a padded case between flights. Photos show the actual item. Happy to answer questions before you buy — all payments held in escrow until it lands.",
      "category": {
        "id": "cat_uniforms",
        "slug": "uniforms",
        "name": "Uniform & apparel"
      },
      "breadcrumbs": [
        {
          "id": "cat_uniforms",
          "slug": "uniforms",
          "name": "Uniform & apparel"
        }
      ],
      "condition": "new",
      "format": "physical",
      "price": {
        "amount": 14500,
        "currency": "USD"
      },
      "compare_at_price": null,
      "minimum_offer": {
        "amount": 11600,
        "currency": "USD"
      },
      "seller": {
        "id": "usr_1",
        "username": "pilotwardrobe",
        "display_name": "Pilotwardrobe Official",
        "verified": true,
        "is_aviator": false,
        "is_official_store": true,
        "rating": 4.9,
        "total_reviews": 1204,
        "total_sales": 1204,
        "location": "Lagos, Nigeria",
        "dispatch_time": "wheels-up in 24h"
      },
      "images": [
        {
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
        {
          "id": "med_pilot-chronograph",
          "public_id": "pilotwardrobe/listings/pilot-chronograph",
          "url": "/media/mock/pilot-chronograph-120.jpg",
          "secure_url": "/media/mock/pilot-chronograph-120.jpg",
          "width": 120,
          "height": 120,
          "format": "jpg",
          "bytes": 2592,
          "alt": "Stainless steel pilot chronograph watch with blue dial",
          "variants": {
            "thumbnail": "/media/mock/pilot-chronograph-120.webp",
            "small": "/media/mock/pilot-chronograph-120.webp",
            "medium": "/media/mock/pilot-chronograph-120.webp",
            "large": "/media/mock/pilot-chronograph-120.webp",
            "original": "/media/mock/pilot-chronograph-120.jpg"
          },
          "sources": [
            {
              "url": "/media/mock/pilot-chronograph-120.avif",
              "width": 120,
              "format": "avif"
            },
            {
              "url": "/media/mock/pilot-chronograph-120.webp",
              "width": 120,
              "format": "webp"
            },
            "… 1 more"
          ],
          "placeholder": "#f5f6fa"
        },
        "… 3 more"
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
      "promoted": false,
      "badges": [
        {
          "kind": "fits_you",
          "label": "Fits you · M"
        },
        {
          "kind": "official_store",
          "label": "Official store"
        },
        "… 1 more"
      ],
      "brand": "Pilotwardrobe",
      "model": null,
      "attributes": [
        {
          "label": "Brand",
          "value": "Pilotwardrobe"
        },
        {
          "label": "Category",
          "value": "Uniform & apparel"
        }
      ],
      "aircraft_types": [],
      "tags": [],
      "sizes": {
        "available": [
          "XS",
          "S",
          "… 2 more"
        ],
        "unavailable": [
          "XL"
        ],
        "chart": {
          "columns": [
            "Chest",
            "Shoulder",
            "… 3 more"
          ],
          "rows": [
            {
              "size": "XS",
              "measurements": {
                "chest": "84",
                "shoulder": "37",
                "length": "64",
                "sleeve": "19.5",
                "us alpha": "XS"
              }
            },
            {
              "size": "S",
              "measurements": {
                "chest": "88",
                "shoulder": "38.5",
                "length": "66",
                "sleeve": "20",
                "us alpha": "S"
              }
            },
            "… 3 more"
          ],
          "note": "Measurements in cm · tolerance ±1.5 cm · Tall +5 cm length · Slim −2.5 cm chest"
        },
        "fit_match": {
          "size": "M",
          "profile_name": "Uniform fit",
          "summary": "Collar 15.5″ · sleeve 34–35 · from your saved profile"
        }
      },
      "rating": 4.9,
      "sold_count": 1200,
      "views_count": 412,
      "favorites_count": 38,
      "offers_count": 0,
      "is_favorited": false,
      "created_at": "2026-10-20T07:08:31.261Z",
      "updated_at": "2026-10-20T07:08:31.261Z"
    },
    {
      "id": "lst_009",
      "slug": "captains-uniform-jacket-4-bar",
      "title": "Captain's uniform jacket — 4-bar, double-breasted",
      "description": "Captain's uniform jacket — 4-bar, double-breasted. Well looked after and stored in a padded case between flights. Photos show the actual item. Happy to answer questions before you buy — all payments held in escrow until it lands.",
      "category": {
        "id": "cat_uniforms",
        "slug": "uniforms",
        "name": "Uniform & apparel"
      },
      "breadcrumbs": [
        {
          "id": "cat_uniforms",
          "slug": "uniforms",
          "name": "Uniform & apparel"
        }
      ],
      "condition": "like_new",
      "format": "physical",
      "price": {
        "amount": 32000,
        "currency": "USD"
      },
      "compare_at_price": null,
      "minimum_offer": {
        "amount": 25600,
        "currency": "USD"
      },
      "seller": {
        "id": "usr_1",
        "username": "pilotwardrobe",
        "display_name": "Pilotwardrobe Official",
        "verified": true,
        "is_aviator": false,
        "is_official_store": true,
        "rating": 4.9,
        "total_reviews": 1204,
        "total_sales": 1204,
        "location": "Lagos, Nigeria",
        "dispatch_time": "wheels-up in 24h"
      },
      "images": [
        {
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
          "kind": "official_store",
          "label": "Official store"
        }
      ],
      "brand": "Pilotwardrobe",
      "model": null,
      "attributes": [
        {
          "label": "Brand",
          "value": "Pilotwardrobe"
        },
        {
          "label": "Category",
          "value": "Uniform & apparel"
        }
      ],
      "aircraft_types": [],
      "tags": [],
      "sizes": {
        "available": [
          "XS",
          "S",
          "… 2 more"
        ],
        "unavailable": [
          "XL"
        ],
        "chart": {
          "columns": [
            "Chest",
            "Shoulder",
            "… 3 more"
          ],
          "rows": [
            {
              "size": "XS",
              "measurements": {
                "chest": "84",
                "shoulder": "37",
                "length": "64",
                "sleeve": "19.5",
                "us alpha": "XS"
              }
            },
            {
              "size": "S",
              "measurements": {
                "chest": "88",
                "shoulder": "38.5",
                "length": "66",
                "sleeve": "20",
                "us alpha": "S"
              }
            },
            "… 3 more"
          ],
          "note": "Measurements in cm · tolerance ±1.5 cm · Tall +5 cm length · Slim −2.5 cm chest"
        },
        "fit_match": null
      },
      "rating": 4.9,
      "sold_count": 302,
      "views_count": 412,
      "favorites_count": 38,
      "offers_count": 0,
      "is_favorited": false,
      "created_at": "2026-10-15T17:08:31.261Z",
      "updated_at": "2026-10-15T17:08:31.261Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 2,
    "total": 5,
    "last_page": 3,
    "from": 1,
    "to": 2
  },
  "links": {
    "first": "?page=1",
    "last": "?page=3",
    "next": "?page=2",
    "prev": null
  }
}
```

### Follow seller

`POST /sellers/pilotwardrobe/follow` · as `pilot@example.com` (user, KYC approved)

Response `200`:

```json
{
  "success": true,
  "data": {
    "followers": 18401
  }
}
```
