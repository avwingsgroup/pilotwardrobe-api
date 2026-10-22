# Orders, cart & checkout

> Wire format (snake_case) exactly as the mock server emits it, captured from `src/mocks/` with
> `tests/unit/contract-payloads.spec.ts` (regenerate with `npm run docs:payloads`). Long arrays are truncated with `"… N more"`.
> Base URL: `{VITE_API_BASE_URL}` = `https://api.pilotwardrobe.com/api/v1`.

Cart (VB17), checkout (V4), order list/detail (VB4, VB6).

- `POST /checkout/quote` is a pure calculation (no side effects) and is called whenever address, shipping option or promo code changes. `POST /orders` **re-quotes server-side**; never trust totals sent by the client (the frontend does not send any).
- `POST /orders` creates the order in `pending_payment` and reserves the listing for a short window (15 min). Payment is a separate step (`/payments/initialize`).
- Order actions: `cancel` (buyer, before dispatch), `dispatch` (seller, requires `carrier` + `tracking_number`), `confirm-delivery` (buyer; releases escrow), `reviews` (after completion). Illegal transitions → 409 `INVALID_STATE_TRANSITION`.
- `GET /orders/{id}/invoice` streams a PDF (`application/pdf`).
- Digital/print downloads: `POST /orders/{order}/downloads/{asset}/authorize` returns a short-lived signed URL (`expires_at`) after checking that the order is paid and belongs to the viewer. Never put permanent download URLs in listing or order payloads.

## Samples

### Cart

`GET /cart` · as `pilot@example.com` (user, KYC approved)

Response `200`:

```json
{
  "success": true,
  "data": {
    "id": "cart_me",
    "items": [
      {
        "id": "ci_0_lst_003",
        "listing_id": "lst_003",
        "listing_slug": "pilotwardrobe-aviator-uniform-shirt",
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
        "condition": "new",
        "size": "M",
        "size_from_fit_kit": true,
        "price": {
          "amount": 14500,
          "currency": "USD"
        },
        "seller": {
          "id": "usr_1",
          "display_name": "Pilotwardrobe Official",
          "username": "pilotwardrobe",
          "verified": true
        },
        "available": true
      },
      {
        "id": "ci_1_lst_013",
        "listing_id": "lst_013",
        "listing_slug": "tri-fold-aviation-kneeboard",
        "title": "Aviation kneeboard, tri-fold",
        "image": {
          "id": "med_tri-fold-kneeboard",
          "public_id": "pilotwardrobe/listings/tri-fold-kneeboard",
          "url": "/media/mock/tri-fold-kneeboard-1440.jpg",
          "secure_url": "/media/mock/tri-fold-kneeboard-1440.jpg",
          "width": 1536,
          "height": 1024,
          "format": "jpg",
          "bytes": 283116,
          "alt": "Tri-fold aviation kneeboard with checklist clip",
          "variants": {
            "thumbnail": "/media/mock/tri-fold-kneeboard-320.webp",
            "small": "/media/mock/tri-fold-kneeboard-320.webp",
            "medium": "/media/mock/tri-fold-kneeboard-640.webp",
            "large": "/media/mock/tri-fold-kneeboard-1440.webp",
            "original": "/media/mock/tri-fold-kneeboard-1440.jpg"
          },
          "sources": [
            {
              "url": "/media/mock/tri-fold-kneeboard-320.avif",
              "width": 320,
              "format": "avif"
            },
            {
              "url": "/media/mock/tri-fold-kneeboard-640.avif",
              "width": 640,
              "format": "avif"
            },
            "… 10 more"
          ],
          "placeholder": "#f5f6fa"
        },
        "condition": "good",
        "size": null,
        "size_from_fit_kit": false,
        "price": {
          "amount": 6200,
          "currency": "USD"
        },
        "seller": {
          "id": "usr_10422",
          "display_name": "Nehemiah Okoye",
          "username": "nehemiah_o",
          "verified": true
        },
        "available": true
      }
    ],
    "subtotal": {
      "amount": 20700,
      "currency": "USD"
    },
    "currency": "USD"
  }
}
```

### Add to cart

`POST /cart/items` · as `pilot@example.com` (user, KYC approved)

Request:

```json
{
  "listing_id": "lst_003",
  "size": "XS"
}
```

Response `200`:

```json
{
  "success": true,
  "message": "Added to your cart.",
  "data": {
    "id": "cart_me",
    "items": [
      {
        "id": "ci_0_lst_003",
        "listing_id": "lst_003",
        "listing_slug": "pilotwardrobe-aviator-uniform-shirt",
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
        "condition": "new",
        "size": "M",
        "size_from_fit_kit": true,
        "price": {
          "amount": 14500,
          "currency": "USD"
        },
        "seller": {
          "id": "usr_1",
          "display_name": "Pilotwardrobe Official",
          "username": "pilotwardrobe",
          "verified": true
        },
        "available": true
      },
      {
        "id": "ci_1_lst_013",
        "listing_id": "lst_013",
        "listing_slug": "tri-fold-aviation-kneeboard",
        "title": "Aviation kneeboard, tri-fold",
        "image": {
          "id": "med_tri-fold-kneeboard",
          "public_id": "pilotwardrobe/listings/tri-fold-kneeboard",
          "url": "/media/mock/tri-fold-kneeboard-1440.jpg",
          "secure_url": "/media/mock/tri-fold-kneeboard-1440.jpg",
          "width": 1536,
          "height": 1024,
          "format": "jpg",
          "bytes": 283116,
          "alt": "Tri-fold aviation kneeboard with checklist clip",
          "variants": {
            "thumbnail": "/media/mock/tri-fold-kneeboard-320.webp",
            "small": "/media/mock/tri-fold-kneeboard-320.webp",
            "medium": "/media/mock/tri-fold-kneeboard-640.webp",
            "large": "/media/mock/tri-fold-kneeboard-1440.webp",
            "original": "/media/mock/tri-fold-kneeboard-1440.jpg"
          },
          "sources": [
            {
              "url": "/media/mock/tri-fold-kneeboard-320.avif",
              "width": 320,
              "format": "avif"
            },
            {
              "url": "/media/mock/tri-fold-kneeboard-640.avif",
              "width": 640,
              "format": "avif"
            },
            "… 10 more"
          ],
          "placeholder": "#f5f6fa"
        },
        "condition": "good",
        "size": null,
        "size_from_fit_kit": false,
        "price": {
          "amount": 6200,
          "currency": "USD"
        },
        "seller": {
          "id": "usr_10422",
          "display_name": "Nehemiah Okoye",
          "username": "nehemiah_o",
          "verified": true
        },
        "available": true
      }
    ],
    "subtotal": {
      "amount": 20700,
      "currency": "USD"
    },
    "currency": "USD"
  }
}
```

### Checkout quote

`POST /checkout/quote` · as `pilot@example.com` (user, KYC approved)

Request:

```json
{
  "listing_id": "lst_003",
  "size": "XS",
  "address_id": "adr_1",
  "shipping_option_id": "shp_std"
}
```

Response `200`:

```json
{
  "success": true,
  "data": {
    "items": [
      {
        "id": "ci_lst_003",
        "listing_id": "lst_003",
        "listing_slug": "pilotwardrobe-aviator-uniform-shirt",
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
        "condition": "new",
        "size": "XS",
        "size_from_fit_kit": true,
        "price": {
          "amount": 14500,
          "currency": "USD"
        },
        "seller": {
          "id": "usr_1",
          "display_name": "Pilotwardrobe Official",
          "username": "pilotwardrobe",
          "verified": true
        },
        "available": true
      }
    ],
    "shipping_options": [
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
    "selected_shipping_id": "shp_std",
    "totals": {
      "items": {
        "amount": 14500,
        "currency": "USD"
      },
      "freight": {
        "amount": 2400,
        "currency": "USD"
      },
      "buyer_protection": {
        "amount": 321,
        "currency": "USD"
      },
      "duties": {
        "amount": 1840,
        "currency": "USD"
      },
      "total": {
        "amount": 19061,
        "currency": "USD"
      }
    },
    "dispatch_note": "Seller ships within 5 days — estimated arrival in 6–8 days.",
    "escrow_note": "Funds are held in escrow until you confirm arrival — or released automatically 72h after landing."
  }
}
```

### Create order

`POST /orders` · as `pilot@example.com` (user, KYC approved)

Request:

```json
{
  "listing_id": "lst_003",
  "size": "XS",
  "address_id": "adr_1",
  "shipping_option_id": "shp_std",
  "promo_code": null
}
```

Response `201`:

```json
{
  "success": true,
  "message": "Order created. Complete payment to secure it.",
  "data": {
    "id": "ord_4485",
    "number": "PW-4485",
    "status": "pending_payment",
    "escrow_status": "not_funded",
    "role": "buyer",
    "items": [
      {
        "id": "oi_lst_003",
        "listing_id": "lst_003",
        "listing_slug": "pilotwardrobe-aviator-uniform-shirt",
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
        "condition": "new",
        "size": "XS",
        "size_from_fit_kit": false,
        "quantity": 1,
        "unit_price": {
          "amount": 14500,
          "currency": "USD"
        },
        "total": {
          "amount": 14500,
          "currency": "USD"
        }
      }
    ],
    "totals": {
      "items": {
        "amount": 14500,
        "currency": "USD"
      },
      "freight": {
        "amount": 2400,
        "currency": "USD"
      },
      "buyer_protection": {
        "amount": 321,
        "currency": "USD"
      },
      "duties": {
        "amount": 1840,
        "currency": "USD"
      },
      "total": {
        "amount": 19061,
        "currency": "USD"
      }
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
    "buyer": {
      "id": "usr_10422",
      "display_name": "Nehemiah Okoye",
      "initials": "NO"
    },
    "shipping_address": {
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
    "shipping": {
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
    "tracking": [],
    "allowed_actions": [
      "pay",
      "cancel"
    ],
    "placed_at": "2026-10-21T13:08:31.548Z",
    "updated_at": "2026-10-21T13:08:31.548Z"
  }
}
```

### My orders

`GET /me/orders?role=buying&per_page=2` · as `pilot@example.com` (user, KYC approved)

Response `200`:

```json
{
  "success": true,
  "data": [],
  "meta": {
    "current_page": 1,
    "per_page": 2,
    "total": 0,
    "last_page": 1,
    "from": null,
    "to": null
  },
  "links": {
    "first": "?page=1",
    "last": "?page=1",
    "next": null,
    "prev": null
  }
}
```

### Order detail

`GET /orders/ord_4471` · as `pilot@example.com` (user, KYC approved)

Response `200`:

```json
{
  "success": true,
  "data": {
    "id": "ord_4471",
    "number": "PW-4471",
    "status": "in_transit",
    "escrow_status": "held",
    "role": "buyer",
    "items": [
      {
        "id": "oi_010",
        "listing_id": "lst_010",
        "listing_slug": "jeppesen-captain-flight-bag-leather",
        "title": "Jeppesen Captain Flight Bag, leather trim",
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
        "condition": "good",
        "quantity": 1,
        "unit_price": {
          "amount": 18000,
          "currency": "USD"
        },
        "total": {
          "amount": 18000,
          "currency": "USD"
        }
      }
    ],
    "totals": {
      "items": {
        "amount": 18000,
        "currency": "USD"
      },
      "freight": {
        "amount": 980,
        "currency": "USD"
      },
      "buyer_protection": {
        "amount": 360,
        "currency": "USD"
      },
      "duties": null,
      "total": {
        "amount": 19340,
        "currency": "USD"
      }
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
    "buyer": {
      "id": "usr_10422",
      "display_name": "Nehemiah Okoye",
      "initials": "NO"
    },
    "shipping_address": {
      "id": "adr_1",
      "label": "Home",
      "full_name": "Gbenga Ebuko",
      "phone": "+234 802 118 4460",
      "line1": "14 Aminu Kano Crescent, Wuse II",
      "city": "Abuja",
      "state": "FCT",
      "postal_code": "904101",
      "country_code": "NG",
      "is_default": true
    },
    "shipping": {
      "id": "shp_std",
      "method": "pilotwardrobe_freight",
      "label": "PW Freight · DHL Express",
      "description": "Tracked",
      "price": {
        "amount": 980,
        "currency": "USD"
      }
    },
    "carrier": {
      "name": "PW Freight · DHL Express",
      "tracking_number": "JD014600006812345",
      "tracking_url": null
    },
    "route": {
      "from": "LOS",
      "to": "ABV",
      "progress": 0.62
    },
    "estimated_delivery": {
      "from": "2026-10-22T13:08:31.273Z",
      "to": "2026-10-24T13:08:31.273Z"
    },
    "tracking": [
      {
        "id": "t1",
        "code": "payment_secured",
        "label": "Payment secured in escrow",
        "detail": "$193.40",
        "occurred_at": "2026-10-18T13:08:31.273Z",
        "state": "done"
      },
      {
        "id": "t2",
        "code": "dispatched",
        "label": "Seller dispatched · wheels up",
        "detail": "Lagos hub",
        "occurred_at": "2026-10-19T13:08:31.273Z",
        "state": "done"
      },
      "… 3 more"
    ],
    "payment": {
      "gateway": "paystack",
      "status": "successful",
      "reference": "PW-2026-918271",
      "method_label": "Visa •••• 4417"
    },
    "allowed_actions": [
      "message",
      "open_dispute"
    ],
    "placed_at": "2026-10-18T13:08:31.273Z",
    "updated_at": "2026-10-20T17:08:31.273Z"
  }
}
```

### Confirm delivery — wrong state (409)

`POST /orders/ord_4402/confirm-delivery` · as `pilot@example.com` (user, KYC approved)

Response `409`:

```json
{
  "success": false,
  "message": "This order cannot be confirmed yet.",
  "code": "INVALID_STATE_TRANSITION"
}
```
