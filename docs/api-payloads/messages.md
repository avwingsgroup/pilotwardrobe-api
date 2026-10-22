# Messaging

> Wire format (snake_case) exactly as the mock server emits it, captured from `src/mocks/` with
> `tests/unit/contract-payloads.spec.ts` (regenerate with `npm run docs:payloads`). Long arrays are truncated with `"… N more"`.
> Base URL: `{VITE_API_BASE_URL}` = `https://api.pilotwardrobe.com/api/v1`.

Buyer↔seller conversations (VB10/VB11).

- `POST /conversations` opens (or returns the existing) thread for a listing between viewer and seller.
- Message `body` is **plain text**. The SPA renders it as text, never as HTML. Strip control characters server-side; reject > 4,000 chars.
- Attachments are uploaded first via `POST /media` (`type=message`) and referenced by `attachment_ids`.
- The backend should flag and optionally block off-platform payment solicitation (phone numbers, bank details, "pay outside") and return `flags` on the message; users can report a conversation (`POST /conversations/{id}/report`).
- Real-time delivery is out of scope for v1; the SPA polls the open conversation every 10 s and the unread count every 60 s. A Laravel Reverb/Pusher channel `private-user.{id}` can replace polling without changing payloads.

## Samples

### Conversations

`GET /conversations` · as `pilot@example.com` (user, KYC approved)

Response `200`:

```json
{
  "success": true,
  "data": [
    {
      "id": "cnv_1",
      "context": "selling",
      "participant": {
        "id": "usr_09981k",
        "display_name": "Kunle Taiwo",
        "initials": "KT",
        "username": "kunle_t",
        "verified": true,
        "response_time": "Usually replies within 1 hour",
        "location": "Abuja",
        "rating": 4.9,
        "trades": 27,
        "kyc_verified": true,
        "member_since": "2024-02-01T00:00:00Z"
      },
      "listing": {
        "id": "lst_001",
        "slug": "bose-a20-aviation-headset-dual-ga",
        "title": "Bose A20 Aviation Headset",
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
        "condition_label": "Like new",
        "offers_count": 5,
        "reserved": false
      },
      "last_message_preview": "Offer: $712 for the Bose A20",
      "last_message_at": "2026-10-21T13:06:31.297Z",
      "unread_count": 1
    },
    {
      "id": "cnv_2",
      "context": "selling",
      "participant": {
        "id": "usr_11034",
        "display_name": "Ifeoma A.",
        "initials": "IA",
        "username": "ifeoma_a",
        "verified": false,
        "location": "Lagos",
        "rating": 4.7,
        "trades": 6,
        "kyc_verified": false
      },
      "listing": {
        "id": "lst_001",
        "slug": "bose-a20-aviation-headset-dual-ga",
        "title": "Bose A20 Aviation Headset",
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
        "condition_label": "Like new",
        "offers_count": 5,
        "reserved": false
      },
      "last_message_preview": "Swap: Zulu 3 + $180?",
      "last_message_at": "2026-10-21T12:08:31.297Z",
      "unread_count": 1
    },
    "… 3 more"
  ]
}
```

### Conversation messages

`GET /conversations/cnv_1/messages` · as `pilot@example.com` (user, KYC approved)

Response `200`:

```json
{
  "success": true,
  "data": [
    {
      "id": "msg_1",
      "conversation_id": "cnv_1",
      "sender_id": "usr_09981k",
      "mine": false,
      "kind": "text",
      "body": "Hi! Is the A20 still available? Does it come with the Bluetooth cable?",
      "sent_at": "2026-10-21T11:58:31.297Z"
    },
    {
      "id": "msg_2",
      "conversation_id": "cnv_1",
      "sender_id": "usr_10422",
      "mine": true,
      "kind": "text",
      "body": "Yes and yes — original case, cable and the spare ear seals.",
      "sent_at": "2026-10-21T12:02:31.297Z",
      "read_at": "2026-10-21T12:08:31.297Z"
    },
    "… 2 more"
  ],
  "meta": {
    "current_page": 1,
    "per_page": 100,
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

### Send message

`POST /conversations/cnv_1/messages` · as `pilot@example.com` (user, KYC approved)

Request:

```json
{
  "body": "Is the headset still available?",
  "attachment_ids": []
}
```

Response `201`:

```json
{
  "success": true,
  "message": "Created successfully.",
  "data": {
    "id": "msg_3bwefnk",
    "conversation_id": "cnv_1",
    "sender_id": "usr_10422",
    "mine": true,
    "kind": "text",
    "body": "Is the headset still available?",
    "sent_at": "2026-10-21T13:08:31.557Z"
  }
}
```

### Send message — empty (422)

`POST /conversations/cnv_1/messages` · as `pilot@example.com` (user, KYC approved)

Request:

```json
{
  "body": ""
}
```

Response `422`:

```json
{
  "success": false,
  "message": "The submitted data is invalid.",
  "code": "VALIDATION_ERROR",
  "errors": {
    "body": [
      "Write a message first."
    ]
  }
}
```
