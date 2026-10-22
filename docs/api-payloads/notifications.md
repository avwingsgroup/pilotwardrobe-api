# Notifications

> Wire format (snake_case) exactly as the mock server emits it, captured from `src/mocks/` with
> `tests/unit/contract-payloads.spec.ts` (regenerate with `npm run docs:payloads`). Long arrays are truncated with `"… N more"`.
> Base URL: `{VITE_API_BASE_URL}` = `https://api.pilotwardrobe.com/api/v1`.

In-app notification centre (VB12). Each notification is also eligible for email (ZeptoMail) and push according to `/me/notification-preferences`.

- `category ∈ orders | offers_swaps | messages | account | disputes | payouts | reviews` drives the tabs/filters; `icon ∈ offer | order | landed | dispute | review | payout | verified | message` drives the glyph. `actions` are optional inline buttons (`{ label, to }`).
- `link` is an **internal path** (e.g. `/hangar/orders/ord_4471`). The SPA only follows relative paths; absolute URLs are ignored.

## Samples

### Notifications

`GET /notifications?per_page=2` · as `pilot@example.com` (user, KYC approved)

Response `200`:

```json
{
  "success": true,
  "data": [
    {
      "id": "ntf_1",
      "category": "offers_swaps",
      "icon": "offer",
      "title": "New offer — $712 on Bose A20",
      "body": "Kunle T. · expires in 9h",
      "read_at": null,
      "created_at": "2026-10-21T13:06:31.303Z",
      "actions": [
        {
          "label": "Review offer",
          "to": "/hangar/offers?id=off_9281",
          "variant": "primary"
        }
      ]
    },
    {
      "id": "ntf_2",
      "category": "orders",
      "icon": "order",
      "title": "Order #PW-4471 is airborne",
      "body": "Departed LOS sort facility · ETA Aug 2–4",
      "read_at": null,
      "created_at": "2026-10-21T10:08:31.303Z",
      "link": "/hangar/orders/ord_4471"
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

### Unread count

`GET /notifications/unread-count` · as `pilot@example.com` (user, KYC approved)

Response `200`:

```json
{
  "success": true,
  "data": {
    "count": 4
  }
}
```

### Mark read

`PATCH /notifications/ntf_1/read` · as `pilot@example.com` (user, KYC approved)

Response `200`:

```json
{
  "success": true,
  "data": {
    "id": "ntf_1",
    "category": "offers_swaps",
    "icon": "offer",
    "title": "New offer — $712 on Bose A20",
    "body": "Kunle T. · expires in 9h",
    "read_at": "2026-10-21T13:08:31.560Z",
    "created_at": "2026-10-21T13:06:31.303Z",
    "actions": [
      {
        "label": "Review offer",
        "to": "/hangar/offers?id=off_9281",
        "variant": "primary"
      }
    ]
  }
}
```

### Mark all read

`POST /notifications/read-all` · as `pilot@example.com` (user, KYC approved)

Response `204`:

_(empty body)_
