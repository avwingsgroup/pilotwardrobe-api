# Products (catalogue, listing detail, categories)

> Wire format (snake_case) exactly as the mock server emits it, captured from `src/mocks/` with
> `tests/unit/contract-payloads.spec.ts` (regenerate with `npm run docs:payloads`). Long arrays are truncated with `"… N more"`.
> Base URL: `{VITE_API_BASE_URL}` = `https://api.pilotwardrobe.com/api/v1`.

Public catalogue reads used by Home (V1), Marketplace (V2), Listing detail (V3) and Categories.

- `GET /listings` is server-side paginated, filtered and sorted. The frontend mirrors every query parameter in the URL, so these must be stable and bookmarkable. Supported query params: `search`, `category` (comma list of slugs; a parent slug includes its children), `condition` (comma list), `deal_type` (comma list of `buy_now,offers,swaps`), `min_price`/`max_price` (minor units), `verified_only`, `min_rating`, `free_delivery` (booleans as `true`), `format` (`physical | digital | print`), `seller` (username), `aircraft_type`, `promoted`, `sort` (`newest | recommended | price_asc | price_desc | popular | rating | nearest`), `page`, `per_page` (≤ 100).
- Paginated responses include `meta.facets` (`categories`, `conditions`, `deal_types`, … each `[{ value, label, count }]`) so the filter panel can show counts.
- `GET /listings/{slug}` returns `sizes.fit_match` computed against the viewer's Fit Kit when signed in.
- Every image is a `MediaAsset` (see `docs/frontend-backend-handoff.md` §Media).

## Samples

### Listing detail

`GET /listings/pilotwardrobe-aviator-uniform-shirt` · as Visitor (no session)

Response `200`:

```json
{
  "success": true,
  "data": {
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
    "views_count": 413,
    "favorites_count": 38,
    "offers_count": 0,
    "is_favorited": false,
    "created_at": "2026-10-20T07:08:31.261Z",
    "updated_at": "2026-10-20T07:08:31.261Z"
  }
}
```

### Related listings

`GET /listings/pilotwardrobe-aviator-uniform-shirt/related` · as Visitor (no session)

Response `200`:

```json
{
  "success": true,
  "data": [
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
      "updated_at": "2026-10-20T17:08:31.261Z"
    },
    {
      "id": "lst_004",
      "slug": "david-clark-one-x-enc-headset",
      "title": "David Clark ONE-X ENC headset, carry case",
      "description": "David Clark ONE-X ENC headset, carry case. Well looked after and stored in a padded case between flights. Photos show the actual item. Happy to answer questions before you buy — all payments held in escrow until it lands.",
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
      "condition": "very_good",
      "format": "physical",
      "price": {
        "amount": 69000,
        "currency": "USD"
      },
      "compare_at_price": {
        "amount": 78000,
        "currency": "USD"
      },
      "minimum_offer": {
        "amount": 55200,
        "currency": "USD"
      },
      "seller": {
        "id": "usr_102",
        "username": "captain-james",
        "display_name": "Captain James",
        "verified": true,
        "is_aviator": true,
        "rating": 4.9,
        "total_reviews": 127,
        "total_sales": 140,
        "location": "Abuja, Nigeria",
        "dispatch_time": "ships in 24h"
      },
      "images": [
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
        {
          "id": "med_david-clark-h10",
          "public_id": "pilotwardrobe/listings/david-clark-h10",
          "url": "/media/mock/david-clark-h10-300.jpg",
          "secure_url": "/media/mock/david-clark-h10-300.jpg",
          "width": 300,
          "height": 284,
          "format": "jpg",
          "bytes": 15336,
          "alt": "David Clark H10-13.4 passive headset",
          "variants": {
            "thumbnail": "/media/mock/david-clark-h10-300.webp",
            "small": "/media/mock/david-clark-h10-300.webp",
            "medium": "/media/mock/david-clark-h10-300.webp",
            "large": "/media/mock/david-clark-h10-300.webp",
            "original": "/media/mock/david-clark-h10-300.jpg"
          },
          "sources": [
            {
              "url": "/media/mock/david-clark-h10-300.avif",
              "width": 300,
              "format": "avif"
            },
            {
              "url": "/media/mock/david-clark-h10-300.webp",
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
      "swappable": true,
      "status": "active",
      "promoted": false,
      "badges": [
        {
          "kind": "swap_ok",
          "label": "Swap OK"
        }
      ],
      "brand": "David Clark",
      "model": "ONE-X",
      "attributes": [
        {
          "label": "Brand",
          "value": "David Clark"
        },
        {
          "label": "Model",
          "value": "ONE-X"
        },
        "… 1 more"
      ],
      "aircraft_types": [],
      "tags": [],
      "sizes": null,
      "rating": 4.7,
      "sold_count": 143,
      "views_count": 412,
      "favorites_count": 38,
      "offers_count": 3,
      "is_favorited": true,
      "created_at": "2026-10-19T09:08:31.261Z",
      "updated_at": "2026-10-19T09:08:31.261Z"
    },
    "… 2 more"
  ]
}
```

### Categories

`GET /categories` · as Visitor (no session)

Response `200`:

```json
{
  "success": true,
  "data": [
    {
      "id": "cat_official",
      "slug": "pilotwardrobe",
      "name": "Pilotwardrobe",
      "listings_count": 1532,
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
      "sized": true
    },
    {
      "id": "cat_headsets",
      "slug": "headsets",
      "name": "Headsets & audio",
      "listings_count": 2140,
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
      "children": [
        {
          "id": "cat_anr",
          "slug": "anr-headsets",
          "name": "ANR headsets",
          "parent_id": "cat_headsets",
          "listings_count": 612
        },
        {
          "id": "cat_passive",
          "slug": "passive-headsets",
          "name": "Passive headsets",
          "parent_id": "cat_headsets",
          "listings_count": 340
        },
        "… 2 more"
      ]
    },
    "… 10 more"
  ]
}
```

### Category detail

`GET /categories/headsets` · as Visitor (no session)

Response `200`:

```json
{
  "success": true,
  "data": {
    "id": "cat_headsets",
    "slug": "headsets",
    "name": "Headsets & audio",
    "listings_count": 2140,
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
    "children": [
      {
        "id": "cat_anr",
        "slug": "anr-headsets",
        "name": "ANR headsets",
        "parent_id": "cat_headsets",
        "listings_count": 612
      },
      {
        "id": "cat_passive",
        "slug": "passive-headsets",
        "name": "Passive headsets",
        "parent_id": "cat_headsets",
        "listings_count": 340
      },
      "… 2 more"
    ]
  }
}
```

### Home feed

`GET /home` · as Visitor (no session)

Response `200`:

```json
{
  "success": true,
  "data": {
    "hero_slides": [
      {
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
        }
      },
      {
        "id": "hs_2",
        "position": 2,
        "headline": "Headset Week · free buyer protection",
        "subtext": "ANR headsets from verified crews, with buyer protection on us until Sunday.",
        "button_label": "Shop headsets",
        "link": "/category/headsets",
        "audience": "NG, GH · Aug 4–10",
        "locales": "EN",
        "status": "scheduled",
        "schedule": "Aug 4–10",
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
        }
      },
      "… 1 more"
    ],
    "categories": [
      {
        "id": "cat_official",
        "slug": "pilotwardrobe",
        "name": "Pilotwardrobe",
        "listings_count": 1532,
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
        "sized": true
      },
      {
        "id": "cat_headsets",
        "slug": "headsets",
        "name": "Headsets & audio",
        "listings_count": 2140,
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
        "children": [
          {
            "id": "cat_anr",
            "slug": "anr-headsets",
            "name": "ANR headsets",
            "parent_id": "cat_headsets",
            "listings_count": 612
          },
          {
            "id": "cat_passive",
            "slug": "passive-headsets",
            "name": "Passive headsets",
            "parent_id": "cat_headsets",
            "listings_count": 340
          },
          "… 2 more"
        ]
      },
      "… 6 more"
    ],
    "sections": [
      {
        "key": "fresh",
        "title": "Fresh on the flightline",
        "subtitle": "Recently listed by verified sellers",
        "view_all_to": "/marketplace?sort=newest",
        "listings": [
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
            "updated_at": "2026-10-20T17:08:31.261Z"
          },
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
            "views_count": 413,
            "favorites_count": 38,
            "offers_count": 0,
            "is_favorited": false,
            "created_at": "2026-10-20T07:08:31.261Z",
            "updated_at": "2026-10-20T07:08:31.261Z"
          },
          "… 2 more"
        ]
      },
      {
        "key": "headsets",
        "title": "Source flight deck audio & headsets",
        "subtitle": "Authentic Bose, David Clark and Lightspeed gear from verified sellers",
        "view_all_to": "/category/headsets",
        "listings": [
          {
            "id": "lst_005",
            "slug": "bose-a30-bluetooth-low-clamp",
            "title": "Bose A30, Bluetooth, low clamp force",
            "description": "Bose A30, Bluetooth, low clamp force. Well looked after and stored in a padded case between flights. Photos show the actual item. Happy to answer questions before you buy — all payments held in escrow until it lands.",
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
              "amount": 105000,
              "currency": "USD"
            },
            "compare_at_price": {
              "amount": 119500,
              "currency": "USD"
            },
            "minimum_offer": {
              "amount": 84000,
              "currency": "USD"
            },
            "seller": {
              "id": "usr_09981k",
              "username": "kunle_t",
              "display_name": "Kunle Taiwo",
              "verified": true,
              "is_aviator": false,
              "rating": 4.9,
              "total_reviews": 27,
              "total_sales": 27,
              "location": "Abuja, Nigeria"
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
            "swappable": true,
            "status": "active",
            "promoted": false,
            "badges": [
              {
                "kind": "swap_ok",
                "label": "Swap OK"
              }
            ],
            "brand": "Bose",
            "model": "A30",
            "attributes": [
              {
                "label": "Brand",
                "value": "Bose"
              },
              {
                "label": "Model",
                "value": "A30"
              },
              "… 1 more"
            ],
            "aircraft_types": [],
            "tags": [],
            "sizes": null,
            "rating": 4.9,
            "sold_count": 88,
            "views_count": 412,
            "favorites_count": 38,
            "offers_count": 0,
            "is_favorited": false,
            "created_at": "2026-10-18T15:08:31.261Z",
            "updated_at": "2026-10-18T15:08:31.261Z"
          },
          {
            "id": "lst_006",
            "slug": "david-clark-h10-13-4-passive",
            "title": "David Clark H10-13.4 passive, new gel seals",
            "description": "David Clark H10-13.4 passive, new gel seals. Well looked after and stored in a padded case between flights. Photos show the actual item. Happy to answer questions before you buy — all payments held in escrow until it lands.",
            "category": {
              "id": "cat_passive",
              "slug": "passive-headsets",
              "name": "Passive headsets"
            },
            "breadcrumbs": [
              {
                "id": "cat_passive",
                "slug": "passive-headsets",
                "name": "Passive headsets"
              }
            ],
            "condition": "very_good",
            "format": "physical",
            "price": {
              "amount": 29500,
              "currency": "USD"
            },
            "compare_at_price": {
              "amount": 34000,
              "currency": "USD"
            },
            "minimum_offer": {
              "amount": 23600,
              "currency": "USD"
            },
            "seller": {
              "id": "usr_09981",
              "username": "capt_bello",
              "display_name": "Capt. A. Bello",
              "verified": true,
              "is_aviator": true,
              "rating": 4.8,
              "total_reviews": 41,
              "total_sales": 58,
              "location": "Kano, Nigeria"
            },
            "images": [
              {
                "id": "med_david-clark-h10",
                "public_id": "pilotwardrobe/listings/david-clark-h10",
                "url": "/media/mock/david-clark-h10-300.jpg",
                "secure_url": "/media/mock/david-clark-h10-300.jpg",
                "width": 300,
                "height": 284,
                "format": "jpg",
                "bytes": 15336,
                "alt": "David Clark H10-13.4 passive headset",
                "variants": {
                  "thumbnail": "/media/mock/david-clark-h10-300.webp",
                  "small": "/media/mock/david-clark-h10-300.webp",
                  "medium": "/media/mock/david-clark-h10-300.webp",
                  "large": "/media/mock/david-clark-h10-300.webp",
                  "original": "/media/mock/david-clark-h10-300.jpg"
                },
                "sources": [
                  {
                    "url": "/media/mock/david-clark-h10-300.avif",
                    "width": 300,
                    "format": "avif"
                  },
                  {
                    "url": "/media/mock/david-clark-h10-300.webp",
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
            "brand": "David Clark",
            "model": "H10-13.4",
            "attributes": [
              {
                "label": "Brand",
                "value": "David Clark"
              },
              {
                "label": "Model",
                "value": "H10-13.4"
              },
              "… 1 more"
            ],
            "aircraft_types": [],
            "tags": [],
            "sizes": null,
            "rating": 4.8,
            "sold_count": 311,
            "views_count": 412,
            "favorites_count": 38,
            "offers_count": 0,
            "is_favorited": false,
            "created_at": "2026-10-17T19:08:31.261Z",
            "updated_at": "2026-10-17T19:08:31.261Z"
          },
          "… 2 more"
        ]
      }
    ],
    "gallery": [
      {
        "id": "g1",
        "title": "Santorini Rollout",
        "photographer": "Tunde Shot Aviation",
        "image": {
          "id": "med_photo-santorini-rollout",
          "public_id": "pilotwardrobe/photography/photo-santorini-rollout",
          "url": "/media/mock/photo-santorini-rollout-598.jpg",
          "secure_url": "/media/mock/photo-santorini-rollout-598.jpg",
          "width": 598,
          "height": 392,
          "format": "jpg",
          "bytes": 42195,
          "alt": "Airliner rolling out beneath a mountain on Santorini",
          "variants": {
            "thumbnail": "/media/mock/photo-santorini-rollout-320.webp",
            "small": "/media/mock/photo-santorini-rollout-320.webp",
            "medium": "/media/mock/photo-santorini-rollout-598.webp",
            "large": "/media/mock/photo-santorini-rollout-598.webp",
            "original": "/media/mock/photo-santorini-rollout-598.jpg"
          },
          "sources": [
            {
              "url": "/media/mock/photo-santorini-rollout-320.avif",
              "width": 320,
              "format": "avif"
            },
            {
              "url": "/media/mock/photo-santorini-rollout-598.avif",
              "width": 598,
              "format": "avif"
            },
            "… 4 more"
          ],
          "placeholder": "#7d8796"
        },
        "to": "/marketplace/print-santorini-rollout"
      },
      {
        "id": "g2",
        "title": "Warbird Formation, B&W",
        "photographer": "Tunde Shot Aviation",
        "image": {
          "id": "med_photo-warbird-formation",
          "public_id": "pilotwardrobe/photography/photo-warbird-formation",
          "url": "/media/mock/photo-warbird-formation-598.jpg",
          "secure_url": "/media/mock/photo-warbird-formation-598.jpg",
          "width": 598,
          "height": 392,
          "format": "jpg",
          "bytes": 42195,
          "alt": "Black-and-white warbird formation trailing smoke",
          "variants": {
            "thumbnail": "/media/mock/photo-warbird-formation-320.webp",
            "small": "/media/mock/photo-warbird-formation-320.webp",
            "medium": "/media/mock/photo-warbird-formation-598.webp",
            "large": "/media/mock/photo-warbird-formation-598.webp",
            "original": "/media/mock/photo-warbird-formation-598.jpg"
          },
          "sources": [
            {
              "url": "/media/mock/photo-warbird-formation-320.avif",
              "width": 320,
              "format": "avif"
            },
            {
              "url": "/media/mock/photo-warbird-formation-598.avif",
              "width": 598,
              "format": "avif"
            },
            "… 4 more"
          ],
          "placeholder": "#7d8796"
        },
        "to": "/marketplace/print-warbird-formation-bw"
      },
      "… 4 more"
    ],
    "trusted_count": 18400
  }
}
```

### Favourite listing

`POST /listings/lst_003/favorite` · as `pilot@example.com` (user, KYC approved)

Response `200`:

```json
{
  "success": true,
  "data": {
    "favorites_count": 39
  }
}
```

### Not found (404)

`GET /listings/does-not-exist` · as Visitor (no session)

Response `404`:

```json
{
  "success": false,
  "message": "Listing not found.",
  "code": "NOT_FOUND"
}
```
