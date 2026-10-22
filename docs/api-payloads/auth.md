# Authentication

> Wire format (snake_case) exactly as the mock server emits it, captured from `src/mocks/` with
> `tests/unit/contract-payloads.spec.ts` (regenerate with `npm run docs:payloads`). Long arrays are truncated with `"… N more"`.
> Base URL: `{VITE_API_BASE_URL}` = `https://api.pilotwardrobe.com/api/v1`.

Covers email-first sign-in (VA1/VA2), registration with email OTP (VA4–VA5), two-step verification (VA6), password reset (VA9–VA10), social sign-in (VA3/VA7/VA8) and the session principal.

- `GET /auth/me` is the frontend's single source of truth for the signed-in user, role and **effective permissions**. It is called on boot and after every auth transition.
- `POST /auth/login` returns either `status: "authenticated"` or `status: "two_factor_required"` with a `challenge_id`. Staff accounts never use this endpoint; they sign in via `/admin/auth/*` (Google Workspace + WebAuthn).
- In cookie mode (Sanctum SPA), the frontend calls `GET /sanctum/csrf-cookie` before the first state-changing request and sends `X-XSRF-TOKEN`. `access_token` in the login response is only used in token mode and may be omitted in cookie mode.
- Failed logins: after 5 consecutive failures return **423 `ACCOUNT_LOCKED`** with `meta.retry_after` (seconds). While 1–2 attempts remain, include `meta.attempts_remaining`.
- `POST /auth/password/forgot` always returns 200 with the same message, whether or not the email exists (no account enumeration).
- Email OTP is 4 digits, expires after 10 minutes (`verification.expires_in`), resend is throttled (`verification.resend_available_in`).

## Samples

### Email lookup

`POST /auth/email-lookup` · as Visitor (no session)

Request:

```json
{
  "email": "pilot@example.com"
}
```

Response `200`:

```json
{
  "success": true,
  "data": {
    "exists": true,
    "methods": [
      "password"
    ]
  }
}
```

### Login — authenticated

`POST /auth/login` · as Visitor (no session)

Request:

```json
{
  "email": "pilot@example.com",
  "password": "Password1",
  "remember": true
}
```

Response `200`:

```json
{
  "success": true,
  "data": {
    "status": "authenticated",
    "user": {
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
    },
    "access_token": "mock.usr_10422",
    "token_type": "Bearer",
    "expires_in": 3600
  }
}
```

### Login — two-factor required

`POST /auth/login` · as Visitor (no session)

Request:

```json
{
  "email": "aviator@example.com",
  "password": "Password1",
  "remember": false
}
```

Response `200`:

```json
{
  "success": true,
  "data": {
    "status": "two_factor_required",
    "challenge_id": "2fa_f61ym65",
    "methods": [
      "totp",
      "sms",
      "… 1 more"
    ],
    "sms_hint": "•••• 4417"
  }
}
```

### Login — wrong password (422)

`POST /auth/login` · as Visitor (no session)

Request:

```json
{
  "email": "newbie@example.com",
  "password": "nope",
  "remember": false
}
```

Response `422`:

```json
{
  "success": false,
  "message": "Incorrect email or password.",
  "code": "VALIDATION_ERROR",
  "errors": {
    "email": [
      "Incorrect email or password."
    ]
  },
  "meta": {
    "attempts_remaining": 4
  }
}
```

### Register

`POST /auth/register` · as Visitor (no session)

Request:

```json
{
  "first_name": "Ada",
  "last_name": "Obi",
  "email": "ada.obi@example.com",
  "phone": "+2348030000000",
  "password": "Password1!",
  "password_confirmation": "Password1!",
  "country_code": "NG",
  "account_type": "user",
  "terms_accepted": true,
  "marketing_opt_in": false
}
```

Response `201`:

```json
{
  "success": true,
  "message": "Account created. Check your email for a 4-digit code.",
  "data": {
    "user": {
      "id": "usr_lkz4ic2",
      "first_name": "Ada",
      "last_name": "Obi",
      "display_name": "Ada Obi",
      "username": "ada_obi",
      "email": "ada.obi@example.com",
      "phone": "+2348030000000",
      "role": "user",
      "permissions": [
        "listings.create",
        "listings.update",
        "… 8 more"
      ],
      "avatar": null,
      "verification": {
        "email": false,
        "identity": false,
        "aviator": false,
        "kyc_status": "not_started"
      },
      "status": "active",
      "country_code": "NG",
      "currency": "USD",
      "locale": "en",
      "two_factor_enabled": false,
      "member_since": "2026-10-21T13:08:31.477Z",
      "counters": {
        "unread_messages": 0,
        "unread_notifications": 0,
        "cart_items": 0,
        "open_offers": 0,
        "active_orders": 0,
        "active_listings": 0,
        "open_disputes": 0
      }
    },
    "verification": {
      "required": true,
      "expires_in": 600,
      "resend_available_in": 60
    }
  }
}
```

### Register — validation failure (422)

`POST /auth/register` · as Visitor (no session)

Request:

```json
{
  "first_name": "",
  "last_name": "Obi",
  "email": "pilot@example.com",
  "password": "short",
  "password_confirmation": "x",
  "country_code": "NG",
  "account_type": "user",
  "terms_accepted": false
}
```

Response `422`:

```json
{
  "success": false,
  "message": "The submitted data is invalid.",
  "code": "VALIDATION_ERROR",
  "errors": {
    "first_name": [
      "The first name field is required."
    ],
    "email": [
      "An account with this email already exists."
    ],
    "password": [
      "The password must be at least 8 characters."
    ],
    "password_confirmation": [
      "Passwords don't match."
    ],
    "terms_accepted": [
      "You must accept the terms."
    ]
  }
}
```

### Verify email OTP

`POST /auth/email/verify` · as Visitor (no session)

Request:

```json
{
  "email": "ada.obi@example.com",
  "code": "1234"
}
```

Response `200`:

```json
{
  "success": true,
  "message": "Email verified.",
  "data": {
    "id": "usr_lkz4ic2",
    "first_name": "Ada",
    "last_name": "Obi",
    "display_name": "Ada Obi",
    "username": "ada_obi",
    "email": "ada.obi@example.com",
    "phone": "+2348030000000",
    "role": "user",
    "permissions": [
      "listings.create",
      "listings.update",
      "… 8 more"
    ],
    "avatar": null,
    "verification": {
      "email": true,
      "identity": false,
      "aviator": false,
      "kyc_status": "not_started"
    },
    "status": "active",
    "country_code": "NG",
    "currency": "USD",
    "locale": "en",
    "two_factor_enabled": false,
    "member_since": "2026-10-21T13:08:31.477Z",
    "counters": {
      "unread_messages": 0,
      "unread_notifications": 0,
      "cart_items": 0,
      "open_offers": 0,
      "active_orders": 0,
      "active_listings": 0,
      "open_disputes": 0
    }
  }
}
```

### Resend email OTP

`POST /auth/email/resend` · as Visitor (no session)

Request:

```json
{
  "email": "ada.obi@example.com"
}
```

Response `200`:

```json
{
  "success": true,
  "message": "A new code is on its way.",
  "data": {
    "resend_available_in": 60
  }
}
```

### Forgot password

`POST /auth/password/forgot` · as Visitor (no session)

Request:

```json
{
  "email": "pilot@example.com"
}
```

Response `200`:

```json
{
  "success": true,
  "message": "If an account exists for that email, a reset link is on its way.",
  "data": {
    "resend_available_in": 60
  }
}
```

### Check reset token

`GET /auth/password/reset/demo-reset-token` · as Visitor (no session)

Response `200`:

```json
{
  "success": true,
  "data": {
    "valid": true,
    "email": "pilot@example.com"
  }
}
```

### Reset password — expired token (410)

`POST /auth/password/reset` · as Visitor (no session)

Request:

```json
{
  "token": "expired-token",
  "email": "pilot@example.com",
  "password": "Password2!",
  "password_confirmation": "Password2!",
  "logout_other_devices": true
}
```

Response `410`:

```json
{
  "success": false,
  "message": "This link has expired.",
  "code": "TOKEN_EXPIRED"
}
```

### Current user (/auth/me)

`GET /auth/me` · as `pilot@example.com` (user, KYC approved)

Response `200`:

```json
{
  "success": true,
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

### Current user — signed out (401)

`GET /auth/me` · as Visitor (no session)

Response `401`:

```json
{
  "success": false,
  "message": "Unauthenticated.",
  "code": "UNAUTHENTICATED"
}
```

### Username availability

`GET /auth/username-availability?username=captain_ada` · as Visitor (no session)

Response `200`:

```json
{
  "success": true,
  "data": {
    "available": true,
    "suggestion": null
  }
}
```

### Social redirect URL

`GET /auth/social/google/redirect` · as Visitor (no session)

Response `200`:

```json
{
  "success": true,
  "data": {
    "url": "/auth/google/callback?provider=google&code=mock-new&state=st_bkdmyhu"
  }
}
```

### Logout

`POST /auth/logout` · as `pilot@example.com` (user, KYC approved)

Response `204`:

_(empty body)_
