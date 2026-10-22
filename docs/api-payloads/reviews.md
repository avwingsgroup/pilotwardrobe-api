# Reviews

> Wire format (snake_case) exactly as the mock server emits it, captured from `src/mocks/` with
> `tests/unit/contract-payloads.spec.ts` (regenerate with `npm run docs:payloads`). Long arrays are truncated with `"… N more"`.
> Base URL: `{VITE_API_BASE_URL}` = `https://api.pilotwardrobe.com/api/v1`.

- A review can be left once per completed order per side (`role: buyer | seller`). Duplicate → 409 `CONFLICT`.
- `rating` is an integer 1–5; `tags` are chosen from a fixed list and sent as their display labels: `As described`, `Fast dispatch`, `Well packed`, `Great communication`. Reject anything else.
- Reviews update the seller's `rating`, `total_reviews` and `rating_breakdown`.

## Samples

### Seller reviews (public)

`GET /sellers/pilotwardrobe/reviews?per_page=2` · as Visitor (no session)

Response `200`:

```json
{
  "success": true,
  "data": [
    {
      "id": "rv_1",
      "rating": 5,
      "comment": "Fast dispatch, well packed. Exactly as described.",
      "tags": [
        "Fast dispatch",
        "Well packed"
      ],
      "author": {
        "display_name": "Gbenga E.",
        "initials": "GE"
      },
      "created_at": "2026-10-17T13:08:31.245Z",
      "role": "buyer",
      "order_id": "ord_4468"
    },
    {
      "id": "rv_2",
      "rating": 5,
      "comment": "Great communication. Headset was immaculate.",
      "tags": [
        "Great communication"
      ],
      "author": {
        "display_name": "Kunle T.",
        "initials": "KT"
      },
      "created_at": "2026-09-29T13:08:31.245Z",
      "role": "buyer"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 2,
    "total": 4,
    "last_page": 2,
    "from": 1,
    "to": 2
  },
  "links": {
    "first": "?page=1",
    "last": "?page=2",
    "next": "?page=2",
    "prev": null
  }
}
```

### My reviews

`GET /me/reviews` · as `pilot@example.com` (user, KYC approved)

Response `200`:

```json
{
  "success": true,
  "data": [
    {
      "id": "rv_1",
      "rating": 5,
      "comment": "Fast dispatch, well packed. Exactly as described.",
      "tags": [
        "Fast dispatch",
        "Well packed"
      ],
      "author": {
        "display_name": "Gbenga E.",
        "initials": "GE"
      },
      "created_at": "2026-10-17T13:08:31.245Z",
      "role": "buyer",
      "order_id": "ord_4468"
    },
    {
      "id": "rv_2",
      "rating": 5,
      "comment": "Great communication. Headset was immaculate.",
      "tags": [
        "Great communication"
      ],
      "author": {
        "display_name": "Kunle T.",
        "initials": "KT"
      },
      "created_at": "2026-09-29T13:08:31.245Z",
      "role": "buyer"
    },
    "… 2 more"
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 4,
    "last_page": 1,
    "from": 1,
    "to": 4
  },
  "links": {
    "first": "?page=1",
    "last": "?page=1",
    "next": null,
    "prev": null
  }
}
```

### Review an order

`POST /orders/ord_4471/reviews` · as `pilot@example.com` (user, KYC approved)

Request:

```json
{
  "rating": 5,
  "comment": "Fast dispatch, exactly as described.",
  "tags": [
    "as_described",
    "fast_dispatch"
  ]
}
```

Response `204`:

_(empty body)_
