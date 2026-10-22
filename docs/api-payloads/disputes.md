# Disputes

> Wire format (snake_case) exactly as the mock server emits it, captured from `src/mocks/` with
> `tests/unit/contract-payloads.spec.ts` (regenerate with `npm run docs:payloads`). Long arrays are truncated with `"… N more"`.
> Base URL: `{VITE_API_BASE_URL}` = `https://api.pilotwardrobe.com/api/v1`.

Buyer protection flow (VB8 open dispute, VB9 case view; AD9 staff mediation).

- A dispute can be opened by the buyer on an order in `delivered` or within the protection window after `completed`; opening **freezes escrow** (`escrow.status: frozen`).
- `reason ∈ not_as_described | not_received | damaged | counterfeit`, `requested ∈ full_refund | partial_refund | return_and_refund | replacement`. `description` ≥ 20 chars. Evidence is uploaded via `POST /media` (`type=dispute_evidence`, private assets).
- The seller has 48 h to respond (`respond_by`) before the case auto-escalates to `under_review`.
- `POST /disputes/{id}/proposal/{accept|decline}` lets the buyer accept a seller's settlement proposal.

## Samples

### My disputes

`GET /me/disputes` · as `pilot@example.com` (user, KYC approved)

Response `200`:

```json
{
  "success": true,
  "data": [
    {
      "id": "dsp_2041",
      "number": "DS-2041",
      "order_id": "ord_4468",
      "order_number": "PW-4468",
      "reason": "not_as_described",
      "reason_label": "Item not as described",
      "status": "under_review",
      "requested": "partial_refund",
      "requested_amount": {
        "amount": 4500,
        "currency": "USD"
      },
      "escrow_frozen": {
        "amount": 14500,
        "currency": "USD"
      },
      "mediator": {
        "display_name": "Amaka N.",
        "initials": "AN"
      },
      "opened_at": "2026-10-18T13:08:31.311Z",
      "decision_due_at": "2026-10-23T13:08:31.311Z",
      "description": "Label reads S. Chest 98 cm vs listing 104 cm. Requesting a $45 partial refund. 2 photos attached.",
      "evidence": [
        {
          "id": "med_uniform-shirt",
          "public_id": "pilotwardrobe/listings/uniform-shirt",
          "url": "/media/mock/uniform-shirt-232.jpg",
          "secure_url": "/media/mock/uniform-shirt-232.jpg",
          "width": 232,
          "height": 230,
          "format": "jpg",
          "bytes": 9605,
          "alt": "Shirt size label photo",
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
          "id": "med_uniform-shirt",
          "public_id": "pilotwardrobe/listings/uniform-shirt",
          "url": "/media/mock/uniform-shirt-232.jpg",
          "secure_url": "/media/mock/uniform-shirt-232.jpg",
          "width": 232,
          "height": 230,
          "format": "jpg",
          "bytes": 9605,
          "alt": "Shirt chest measurement photo",
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
        }
      ],
      "messages": [
        {
          "id": "dm_1",
          "author": {
            "display_name": "You",
            "initials": "NO",
            "role": "buyer"
          },
          "body": "Label reads S. Chest 98 cm vs listing 104 cm. Requesting a $45 partial refund. 2 photos attached.",
          "created_at": "2026-10-18T13:08:31.311Z"
        },
        {
          "id": "dm_2",
          "author": {
            "display_name": "Nehemiah O.",
            "initials": "NO",
            "role": "seller"
          },
          "body": "The shirt was listed as M as per the original tag. Happy to accept a return for a full refund.",
          "created_at": "2026-10-19T13:08:31.311Z"
        },
        "… 1 more"
      ],
      "steps": [
        {
          "label": "Dispute opened",
          "detail": "escrow frozen",
          "state": "done"
        },
        {
          "label": "Seller responded",
          "detail": "offered return",
          "state": "done"
        },
        "… 2 more"
      ],
      "seller_proposal": {
        "summary": "Return the item for a full refund of $145.00. Return label paid by seller.",
        "amount": {
          "amount": 14500,
          "currency": "USD"
        },
        "status": "pending"
      },
      "viewer_role": "buyer",
      "allowed_actions": [
        "reply",
        "withdraw",
        "… 3 more"
      ],
      "item": {
        "title": "Aviator Uniform Shirt — size M",
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
        }
      },
      "counterparty_name": "Nehemiah O."
    }
  ]
}
```

### Dispute case

`GET /disputes/dsp_2041` · as `pilot@example.com` (user, KYC approved)

Response `200`:

```json
{
  "success": true,
  "data": {
    "id": "dsp_2041",
    "number": "DS-2041",
    "order_id": "ord_4468",
    "order_number": "PW-4468",
    "reason": "not_as_described",
    "reason_label": "Item not as described",
    "status": "under_review",
    "requested": "partial_refund",
    "requested_amount": {
      "amount": 4500,
      "currency": "USD"
    },
    "escrow_frozen": {
      "amount": 14500,
      "currency": "USD"
    },
    "mediator": {
      "display_name": "Amaka N.",
      "initials": "AN"
    },
    "opened_at": "2026-10-18T13:08:31.311Z",
    "decision_due_at": "2026-10-23T13:08:31.311Z",
    "description": "Label reads S. Chest 98 cm vs listing 104 cm. Requesting a $45 partial refund. 2 photos attached.",
    "evidence": [
      {
        "id": "med_uniform-shirt",
        "public_id": "pilotwardrobe/listings/uniform-shirt",
        "url": "/media/mock/uniform-shirt-232.jpg",
        "secure_url": "/media/mock/uniform-shirt-232.jpg",
        "width": 232,
        "height": 230,
        "format": "jpg",
        "bytes": 9605,
        "alt": "Shirt size label photo",
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
        "id": "med_uniform-shirt",
        "public_id": "pilotwardrobe/listings/uniform-shirt",
        "url": "/media/mock/uniform-shirt-232.jpg",
        "secure_url": "/media/mock/uniform-shirt-232.jpg",
        "width": 232,
        "height": 230,
        "format": "jpg",
        "bytes": 9605,
        "alt": "Shirt chest measurement photo",
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
      }
    ],
    "messages": [
      {
        "id": "dm_1",
        "author": {
          "display_name": "You",
          "initials": "NO",
          "role": "buyer"
        },
        "body": "Label reads S. Chest 98 cm vs listing 104 cm. Requesting a $45 partial refund. 2 photos attached.",
        "created_at": "2026-10-18T13:08:31.311Z"
      },
      {
        "id": "dm_2",
        "author": {
          "display_name": "Nehemiah O.",
          "initials": "NO",
          "role": "seller"
        },
        "body": "The shirt was listed as M as per the original tag. Happy to accept a return for a full refund.",
        "created_at": "2026-10-19T13:08:31.311Z"
      },
      "… 1 more"
    ],
    "steps": [
      {
        "label": "Dispute opened",
        "detail": "escrow frozen",
        "state": "done"
      },
      {
        "label": "Seller responded",
        "detail": "offered return",
        "state": "done"
      },
      "… 2 more"
    ],
    "seller_proposal": {
      "summary": "Return the item for a full refund of $145.00. Return label paid by seller.",
      "amount": {
        "amount": 14500,
        "currency": "USD"
      },
      "status": "pending"
    },
    "viewer_role": "buyer",
    "allowed_actions": [
      "reply",
      "withdraw",
      "… 3 more"
    ],
    "item": {
      "title": "Aviator Uniform Shirt — size M",
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
      }
    },
    "counterparty_name": "Nehemiah O."
  }
}
```

### Open dispute — validation (422)

`POST /disputes` · as `pilot@example.com` (user, KYC approved)

Request:

```json
{
  "order_id": "ord_4471",
  "reason": "",
  "requested": "full_refund",
  "amount": null,
  "description": "",
  "evidence_ids": []
}
```

Response `422`:

```json
{
  "success": false,
  "message": "The submitted data is invalid.",
  "code": "VALIDATION_ERROR",
  "errors": {
    "reason": [
      "Tell us what went wrong."
    ],
    "description": [
      "Describe the issue in at least 20 characters."
    ]
  }
}
```

### Reply on dispute

`POST /disputes/dsp_2041/messages` · as `pilot@example.com` (user, KYC approved)

Request:

```json
{
  "body": "Photos of the damaged ear cup attached.",
  "evidence_ids": []
}
```

Response `200`:

```json
{
  "success": true,
  "data": {
    "id": "dsp_2041",
    "number": "DS-2041",
    "order_id": "ord_4468",
    "order_number": "PW-4468",
    "reason": "not_as_described",
    "reason_label": "Item not as described",
    "status": "under_review",
    "requested": "partial_refund",
    "requested_amount": {
      "amount": 4500,
      "currency": "USD"
    },
    "escrow_frozen": {
      "amount": 14500,
      "currency": "USD"
    },
    "mediator": {
      "display_name": "Amaka N.",
      "initials": "AN"
    },
    "opened_at": "2026-10-18T13:08:31.311Z",
    "decision_due_at": "2026-10-23T13:08:31.311Z",
    "description": "Label reads S. Chest 98 cm vs listing 104 cm. Requesting a $45 partial refund. 2 photos attached.",
    "evidence": [
      {
        "id": "med_uniform-shirt",
        "public_id": "pilotwardrobe/listings/uniform-shirt",
        "url": "/media/mock/uniform-shirt-232.jpg",
        "secure_url": "/media/mock/uniform-shirt-232.jpg",
        "width": 232,
        "height": 230,
        "format": "jpg",
        "bytes": 9605,
        "alt": "Shirt size label photo",
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
        "id": "med_uniform-shirt",
        "public_id": "pilotwardrobe/listings/uniform-shirt",
        "url": "/media/mock/uniform-shirt-232.jpg",
        "secure_url": "/media/mock/uniform-shirt-232.jpg",
        "width": 232,
        "height": 230,
        "format": "jpg",
        "bytes": 9605,
        "alt": "Shirt chest measurement photo",
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
      }
    ],
    "messages": [
      {
        "id": "dm_1",
        "author": {
          "display_name": "You",
          "initials": "NO",
          "role": "buyer"
        },
        "body": "Label reads S. Chest 98 cm vs listing 104 cm. Requesting a $45 partial refund. 2 photos attached.",
        "created_at": "2026-10-18T13:08:31.311Z"
      },
      {
        "id": "dm_2",
        "author": {
          "display_name": "Nehemiah O.",
          "initials": "NO",
          "role": "seller"
        },
        "body": "The shirt was listed as M as per the original tag. Happy to accept a return for a full refund.",
        "created_at": "2026-10-19T13:08:31.311Z"
      },
      "… 2 more"
    ],
    "steps": [
      {
        "label": "Dispute opened",
        "detail": "escrow frozen",
        "state": "done"
      },
      {
        "label": "Seller responded",
        "detail": "offered return",
        "state": "done"
      },
      "… 2 more"
    ],
    "seller_proposal": {
      "summary": "Return the item for a full refund of $145.00. Return label paid by seller.",
      "amount": {
        "amount": 14500,
        "currency": "USD"
      },
      "status": "pending"
    },
    "viewer_role": "buyer",
    "allowed_actions": [
      "reply",
      "withdraw",
      "… 3 more"
    ],
    "item": {
      "title": "Aviator Uniform Shirt — size M",
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
      }
    },
    "counterparty_name": "Nehemiah O."
  }
}
```

### Accept seller proposal

`POST /disputes/dsp_2041/proposal/accept` · as `pilot@example.com` (user, KYC approved)

Response `200`:

```json
{
  "success": true,
  "data": {
    "id": "dsp_2041",
    "number": "DS-2041",
    "order_id": "ord_4468",
    "order_number": "PW-4468",
    "reason": "not_as_described",
    "reason_label": "Item not as described",
    "status": "resolved_buyer",
    "requested": "partial_refund",
    "requested_amount": {
      "amount": 4500,
      "currency": "USD"
    },
    "escrow_frozen": {
      "amount": 14500,
      "currency": "USD"
    },
    "mediator": {
      "display_name": "Amaka N.",
      "initials": "AN"
    },
    "opened_at": "2026-10-18T13:08:31.311Z",
    "decision_due_at": "2026-10-23T13:08:31.311Z",
    "description": "Label reads S. Chest 98 cm vs listing 104 cm. Requesting a $45 partial refund. 2 photos attached.",
    "evidence": [
      {
        "id": "med_uniform-shirt",
        "public_id": "pilotwardrobe/listings/uniform-shirt",
        "url": "/media/mock/uniform-shirt-232.jpg",
        "secure_url": "/media/mock/uniform-shirt-232.jpg",
        "width": 232,
        "height": 230,
        "format": "jpg",
        "bytes": 9605,
        "alt": "Shirt size label photo",
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
        "id": "med_uniform-shirt",
        "public_id": "pilotwardrobe/listings/uniform-shirt",
        "url": "/media/mock/uniform-shirt-232.jpg",
        "secure_url": "/media/mock/uniform-shirt-232.jpg",
        "width": 232,
        "height": 230,
        "format": "jpg",
        "bytes": 9605,
        "alt": "Shirt chest measurement photo",
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
      }
    ],
    "messages": [
      {
        "id": "dm_1",
        "author": {
          "display_name": "You",
          "initials": "NO",
          "role": "buyer"
        },
        "body": "Label reads S. Chest 98 cm vs listing 104 cm. Requesting a $45 partial refund. 2 photos attached.",
        "created_at": "2026-10-18T13:08:31.311Z"
      },
      {
        "id": "dm_2",
        "author": {
          "display_name": "Nehemiah O.",
          "initials": "NO",
          "role": "seller"
        },
        "body": "The shirt was listed as M as per the original tag. Happy to accept a return for a full refund.",
        "created_at": "2026-10-19T13:08:31.311Z"
      },
      "… 2 more"
    ],
    "steps": [
      {
        "label": "Dispute opened",
        "detail": "escrow frozen",
        "state": "done"
      },
      {
        "label": "Seller responded",
        "detail": "offered return",
        "state": "done"
      },
      "… 2 more"
    ],
    "seller_proposal": {
      "summary": "Return the item for a full refund of $145.00. Return label paid by seller.",
      "amount": {
        "amount": 14500,
        "currency": "USD"
      },
      "status": "accepted"
    },
    "viewer_role": "buyer",
    "allowed_actions": [],
    "item": {
      "title": "Aviator Uniform Shirt — size M",
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
      }
    },
    "counterparty_name": "Nehemiah O."
  }
}
```
