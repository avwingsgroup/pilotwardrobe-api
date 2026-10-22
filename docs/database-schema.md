# Database schema

PostgreSQL 15+ (developed on 18). Generated from the migrated schema (`database/migrations`) on 2026-10-21; regenerate after schema changes. Model `@property` docblocks are regenerated with `php artisan dev:model-docs`.

## Conventions

- **Primary keys** are opaque prefixed strings: `usr_01j…` (prefix + lowercase ULID, `App\Support\Ids\HasPrefixedId`). They sort by creation time, are safe to expose, and never leak row counts. Framework tables keep their defaults.
- **Money** is `bigint` minor units in `*_amount` columns with a sibling `currency` (ISO 4217). Percentages are basis points (`*_bps`). No floats.
- **Timestamps** are `timestamp(0)` in UTC; the API serialises ISO-8601 with `Z`.
- **Enums** are `varchar` validated by PHP backed enums (`app/Enums`) and, where it matters, `CHECK` constraints.
- **JSON** uses `jsonb` (settings, payloads, snapshots, size charts).
- **Case-insensitive uniqueness** (emails, usernames) uses unique indexes on `lower(column)`.
- **Images are never stored in the database**; `media` holds Cloudinary public ids and dimensions only.
- **Sensitive identity data** (KYC names, DOB, document numbers, 2FA secrets) is encrypted at rest with Laravel encrypted casts.
- **Polymorphic columns** store short morph aliases (`user`, `listing`, `order`, …) from an enforced morph map.

**79 tables**, 121 foreign keys, 16 check constraints, 15 partial indexes, 2 guard triggers.

## Integrity rules enforced by the database

| Table | Rule | Definition |
|---|---|---|
| `addresses` | unique partial index `addresses_one_default` | `btree (user_id) WHERE (is_default AND (deleted_at IS NULL))` |
| `audit_logs` | trigger | `audit_logs_immutable (BEFORE DELETE)` |
| `audit_logs` | trigger | `audit_logs_immutable (BEFORE UPDATE)` |
| `conversations` | unique partial index `conversations_listing_parties_unique` | `btree (listing_id, buyer_id, seller_id) WHERE (listing_id IS NOT NULL)` |
| `disputes` | unique partial index `disputes_one_active_per_order` | `btree (order_id) WHERE ((status)::text = ANY ((ARRAY['open'::character varying, 'awaiting_seller'::character varying, 'under_review'::character varying, 'appealed'::character varying])::text[]))` |
| `fit_kit_profiles` | unique partial index `fit_kit_one_default` | `btree (user_id) WHERE is_default` |
| `ledger_entries` | check `ledger_amount_positive` | `CHECK ((amount > 0))` |
| `ledger_entries` | trigger | `ledger_entries_guard (BEFORE DELETE)` |
| `ledger_entries` | trigger | `ledger_entries_guard (BEFORE UPDATE)` |
| `listings` | check `listings_status_check` | `CHECK (((status)::text = ANY ((ARRAY['draft'::character varying, 'pending_review'::character varying, 'needs_changes'::character varying, 'active'::character varying, 'paused'::character varying, 'reserved'::character varying, 'unavailable'::character varying, 'sold'::character varying, 'swapped'::character varying, 'archived'::character varying, 'rejected'::character varying, 'removed'::character varying])::text[])))` |
| `listings` | check `listings_price_nonneg` | `CHECK (((price_amount IS NULL) OR (price_amount >= 0)))` |
| `listings` | check `listings_reserved_le_stock` | `CHECK ((reserved_quantity <= stock))` |
| `listings` | partial index `listings_public_idx` | `btree (published_at DESC) WHERE (((status)::text = ANY ((ARRAY['active'::character varying, 'reserved'::character varying])::text[])) AND (deleted_at IS NULL))` |
| `listings` | partial index `listings_promoted_idx` | `btree (promoted_until) WHERE (promoted_until IS NOT NULL)` |
| `media` | partial index `media_unattached_idx` | `btree (created_at) WHERE ((attachable_id IS NULL) AND (deleted_at IS NULL))` |
| `notifications` | partial index `notifications_unread_idx` | `btree (user_id) WHERE (read_at IS NULL)` |
| `offers` | check `offers_amount_positive` | `CHECK ((amount > 0))` |
| `offers` | unique partial index `offers_one_pending_per_buyer` | `btree (listing_id, buyer_id) WHERE ((status)::text = 'pending'::text)` |
| `orders` | check `orders_status_check` | `CHECK (((status)::text = ANY ((ARRAY['pending_payment'::character varying, 'paid'::character varying, 'awaiting_dispatch'::character varying, 'in_transit'::character varying, 'out_for_delivery'::character varying, 'delivered'::character varying, 'completed'::character varying, 'cancelled'::character varying, 'disputed'::character varying, 'refunded'::character varying, 'partially_refunded'::character varying, 'on_hold'::character varying])::text[])))` |
| `orders` | check `orders_escrow_check` | `CHECK (((escrow_status)::text = ANY ((ARRAY['not_funded'::character varying, 'held'::character varying, 'frozen'::character varying, 'on_hold'::character varying, 'releasing'::character varying, 'released'::character varying, 'refunded'::character varying])::text[])))` |
| `orders` | check `orders_total_nonneg` | `CHECK (((total_amount >= 0) AND (refunded_amount >= 0) AND (refunded_amount <= total_amount)))` |
| `orders` | partial index `orders_pending_expiry_idx` | `btree (reserved_until) WHERE ((status)::text = 'pending_payment'::text)` |
| `orders` | partial index `orders_auto_release_idx` | `btree (auto_release_at) WHERE ((status)::text = 'delivered'::text)` |
| `orders` | partial index `orders_funds_pending_idx` | `btree (funds_available_at) WHERE ((funds_settled_at IS NULL) AND (funds_available_at IS NOT NULL))` |
| `payments` | check `payments_amount_positive` | `CHECK (((amount > 0) AND (refunded_amount >= 0) AND (refunded_amount <= amount)))` |
| `payments` | unique partial index `payments_gateway_reference_unique` | `btree (gateway, gateway_reference) WHERE (gateway_reference IS NOT NULL)` |
| `payments` | unique partial index `payments_one_open_per_gateway` | `btree (payable_type, payable_id, gateway) WHERE ((status)::text = ANY ((ARRAY['pending'::character varying, 'processing'::character varying])::text[]))` |
| `payout_methods` | unique partial index `payout_methods_one_default` | `btree (user_id) WHERE (is_default AND (deleted_at IS NULL))` |
| `payouts` | check `payouts_amount_positive` | `CHECK (((amount > 0) AND (net_amount >= 0)))` |
| `refunds` | check `refunds_amount_positive` | `CHECK ((amount > 0))` |
| `reviews` | check `reviews_rating_range` | `CHECK (((rating >= 1) AND (rating <= 5)))` |
| `user_permission_overrides` | check `upo_effect_check` | `CHECK (((effect)::text = ANY ((ARRAY['grant'::character varying, 'revoke'::character varying])::text[])))` |
| `users` | check `users_role_check` | `CHECK (((role)::text = ANY ((ARRAY['user'::character varying, 'aviator'::character varying, 'admin'::character varying, 'super_admin'::character varying])::text[])))` |
| `users` | check `users_status_check` | `CHECK (((status)::text = ANY ((ARRAY['active'::character varying, 'restricted'::character varying, 'suspended'::character varying, 'deactivated'::character varying, 'pending_verification'::character varying])::text[])))` |
| `wallets` | check `wallets_nonneg` | `CHECK (((available_amount >= 0) AND (escrow_amount >= 0) AND (reserved_amount >= 0) AND (pending_release_amount >= 0)))` |

`audit_logs` rows cannot be updated or deleted. `ledger_entries` amounts are immutable (corrections are new, reversing entries).

## Identity & access

Accounts, staff roles, sessions, 2FA, passkeys and invitations.

```mermaid
erDiagram
    media |o--o{ users : "avatar_media_id"
    media |o--o{ users : "cover_media_id"
    staff_roles |o--o{ invitations : "staff_role_id"
    staff_roles |o--o{ users : "staff_role_id"
    users |o--o{ auth_sessions : "impersonator_id"
    users |o--o{ invitations : "invited_by"
    users |o--o{ user_permission_overrides : "granted_by"
    users ||--o{ auth_sessions : "user_id"
    users ||--o{ known_devices : "user_id"
    users ||--o{ one_time_codes : "user_id"
    users ||--o{ social_accounts : "user_id"
    users ||--o{ trusted_devices : "user_id"
    users ||--o{ user_notes : "author_id"
    users ||--o{ user_notes : "user_id"
    users ||--o{ user_permission_overrides : "user_id"
    users ||--o{ webauthn_credentials : "user_id"
    users {
        varchar id PK
        varchar first_name
        varchar last_name
        varchar username
        varchar email
        varchar phone
        varchar password
        varchar role
        varchar status
        varchar staff_role_id FK
        character country_code
        character currency
        varchar locale
        text bio
        varchar avatar_media_id FK
        varchar cover_media_id FK
        varchar seller_type
        varchar job_title
        varchar aircraft_type
        varchar city
        varchar state
        varchar dispatch_time
        smallint response_rate
        varchar response_time
        numeric rating_avg
        int reviews_count
        int sales_count
        int followers_count
        jsonb rating_breakdown
        timestamp email_verified_at
        text two_factor_secret
        text two_factor_recovery_codes
        timestamp two_factor_confirmed_at
        timestamp password_changed_at
        int permissions_version
        timestamp last_login_at
        varchar signup_method
        boolean marketing_opt_in
        timestamp terms_accepted_at
        smallint risk_score
        jsonb limits
        varchar status_reason
        timestamp suspended_at
        timestamp deactivated_at
        varchar remember_token
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at
    }
    staff_roles {
        varchar id PK
        varchar slug UK
        varchar name
        boolean is_system
        jsonb matrix
        timestamp created_at
        timestamp updated_at
    }
    user_permission_overrides {
        bigint id PK
        varchar user_id FK
        varchar permission
        varchar effect
        varchar granted_by FK
        timestamp created_at
        timestamp updated_at
    }
    social_accounts {
        varchar id PK
        varchar user_id FK
        varchar provider
        varchar provider_user_id
        varchar email
        timestamp created_at
        timestamp updated_at
    }
    password_reset_tokens {
        varchar email PK
        varchar token
        timestamp created_at
    }
    sessions {
        varchar id PK
        varchar user_id
        varchar ip_address
        text user_agent
        text payload
        int last_activity
    }
    personal_access_tokens {
        bigint id PK
        varchar tokenable_type
        varchar tokenable_id
        text name
        varchar token UK
        text abilities
        timestamp last_used_at
        timestamp expires_at
        timestamp created_at
        timestamp updated_at
    }
    one_time_codes {
        bigint id PK
        varchar user_id FK
        varchar purpose
        varchar code_hash
        smallint attempts
        timestamp expires_at
        timestamp consumed_at
        timestamp created_at
        timestamp updated_at
    }
    auth_sessions {
        varchar id PK
        varchar user_id FK
        varchar session_id
        bigint token_id
        varchar device
        varchar location
        varchar ip_address
        text user_agent
        varchar device_fingerprint
        boolean is_staff
        boolean is_impersonation
        varchar impersonator_id FK
        timestamp last_active_at
        timestamp expires_at
        timestamp revoked_at
        timestamp created_at
        timestamp updated_at
    }
    trusted_devices {
        bigint id PK
        varchar user_id FK
        varchar token_hash UK
        varchar device
        timestamp expires_at
        timestamp created_at
        timestamp updated_at
    }
    known_devices {
        bigint id PK
        varchar user_id FK
        varchar fingerprint
        timestamp last_seen_at
        timestamp created_at
        timestamp updated_at
    }
    webauthn_credentials {
        varchar id PK
        varchar user_id FK
        varchar name
        text credential_id
        text public_key
        bigint sign_count
        timestamp last_used_at
        timestamp created_at
        timestamp updated_at
    }
    invitations {
        varchar id PK
        varchar kind
        varchar email
        varchar role
        varchar staff_role_id FK
        varchar token_hash UK
        varchar invited_by FK
        timestamp expires_at
        timestamp accepted_at
        timestamp created_at
        timestamp updated_at
    }
    user_notes {
        varchar id PK
        varchar user_id FK
        varchar author_id FK
        text body
        timestamp created_at
        timestamp updated_at
    }
    media {
        varchar id PK
    }
```

| Table | Columns | Foreign keys (on delete) | Indexes |
|---|---|---|---|
| `users` | 48 | `avatar_media_id` → `media` (set null), `cover_media_id` → `media` (set null), `staff_role_id` → `staff_roles` (restrict) | `users_created_at_index`, **`users_email_lower_unique`**, `users_role_status_index`, `users_staff_role_id_index`, **`users_username_lower_unique`** |
| `staff_roles` | 7 | — | **`staff_roles_slug_unique`** |
| `user_permission_overrides` | 7 | `granted_by` → `users` (restrict), `user_id` → `users` (cascade) | **`user_permission_overrides_user_id_permission_unique`** |
| `social_accounts` | 7 | `user_id` → `users` (cascade) | **`social_accounts_provider_provider_user_id_unique`**, **`social_accounts_user_id_provider_unique`** |
| `password_reset_tokens` | 3 | — | — |
| `sessions` | 6 | — | `sessions_last_activity_index`, `sessions_user_id_index` |
| `personal_access_tokens` | 10 | — | `personal_access_tokens_expires_at_index`, **`personal_access_tokens_token_unique`**, `personal_access_tokens_tokenable_type_tokenable_id_index` |
| `one_time_codes` | 9 | `user_id` → `users` (cascade) | `one_time_codes_user_id_purpose_consumed_at_index` |
| `auth_sessions` | 17 | `impersonator_id` → `users` (restrict), `user_id` → `users` (cascade) | `auth_sessions_session_id_index`, `auth_sessions_token_id_index`, `auth_sessions_user_id_revoked_at_index` |
| `trusted_devices` | 7 | `user_id` → `users` (cascade) | **`trusted_devices_token_hash_unique`** |
| `known_devices` | 6 | `user_id` → `users` (cascade) | **`known_devices_user_id_fingerprint_unique`** |
| `webauthn_credentials` | 9 | `user_id` → `users` (cascade) | **`webauthn_credential_id_unique`** |
| `invitations` | 11 | `invited_by` → `users` (restrict), `staff_role_id` → `staff_roles` (restrict) | `invitations_email_index`, **`invitations_token_hash_unique`** |
| `user_notes` | 6 | `author_id` → `users` (restrict), `user_id` → `users` (cascade) | — |

## Profile, media & verification

Uploaded media metadata (files live in Cloudinary), addresses, Fit Kit, KYC and aviator credentials.

```mermaid
erDiagram
    media |o--o{ aviator_credentials : "licence_media_id"
    media |o--o{ kyc_verifications : "document_back_media_id"
    media |o--o{ kyc_verifications : "document_front_media_id"
    media |o--o{ kyc_verifications : "selfie_media_id"
    users |o--o{ aviator_credentials : "reviewed_by"
    users |o--o{ kyc_verifications : "reviewed_by"
    users |o--o{ media : "owner_id"
    users ||--o{ addresses : "user_id"
    users ||--o{ aviator_credentials : "user_id"
    users ||--o{ device_tokens : "user_id"
    users ||--o{ fit_kit_profiles : "user_id"
    users ||--o{ follows : "follower_id"
    users ||--o{ follows : "seller_id"
    users ||--o{ kyc_verifications : "user_id"
    users ||--o{ notification_preferences : "user_id"
    media {
        varchar id PK
        varchar owner_id FK
        varchar type
        varchar visibility
        varchar public_id UK
        varchar resource_type
        varchar delivery_type
        varchar format
        int width
        int height
        bigint bytes
        bigint version
        varchar folder
        text secure_url
        varchar alt
        varchar placeholder
        varchar original_filename
        varchar attachable_type
        varchar attachable_id
        varchar collection
        smallint position
        timestamp attached_at
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at
    }
    addresses {
        varchar id PK
        varchar user_id FK,UK
        varchar label
        varchar full_name
        varchar phone
        varchar line1
        varchar line2
        varchar city
        varchar state
        varchar postal_code
        character country_code
        boolean is_default
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at
    }
    fit_kit_profiles {
        varchar id PK
        varchar user_id FK,UK
        varchar name
        varchar for_person
        boolean is_default
        varchar unit
        varchar jacket
        varchar collar
        varchar sleeve
        varchar waist
        varchar inseam
        varchar shoe
        varchar bag
        varchar apparel
        jsonb preferences
        int orders_used_on
        timestamp created_at
        timestamp updated_at
    }
    notification_preferences {
        bigint id PK
        varchar user_id FK
        varchar category
        boolean email
        boolean push
        boolean sms
        boolean whatsapp
        timestamp created_at
        timestamp updated_at
    }
    device_tokens {
        varchar id PK
        varchar user_id FK
        text token
        varchar platform
        varchar device_name
        varchar app_version
        timestamp last_used_at
        timestamp invalidated_at
        timestamp created_at
        timestamp updated_at
    }
    follows {
        varchar follower_id PK,FK
        varchar seller_id PK,FK
        timestamp created_at
    }
    kyc_verifications {
        varchar id PK
        varchar user_id FK,UK
        varchar code UK
        varchar status
        varchar step
        text legal_first_name
        text legal_last_name
        text date_of_birth
        character nationality
        varchar document_type
        character document_country
        text document_number
        date document_expires_on
        varchar document_front_media_id FK
        varchar document_back_media_id FK
        varchar selfie_media_id FK
        varchar handoff_token_hash UK
        timestamp handoff_expires_at
        varchar provider
        varchar provider_reference
        jsonb automated_checks
        jsonb extracted
        varchar checks_status
        text rejection_reason
        text reviewer_note
        varchar reviewed_by FK
        timestamp submitted_at
        timestamp reviewed_at
        timestamp created_at
        timestamp updated_at
    }
    aviator_credentials {
        varchar id PK
        varchar user_id FK,UK
        varchar licence_type
        text licence_number
        varchar licence_last4
        varchar issuing_authority
        varchar job_title
        varchar organisation
        jsonb type_ratings
        varchar licence_media_id FK
        varchar status
        text rejection_reason
        varchar reviewed_by FK
        timestamp submitted_at
        timestamp verified_at
        timestamp created_at
        timestamp updated_at
    }
    users {
        varchar id PK
    }
```

| Table | Columns | Foreign keys (on delete) | Indexes |
|---|---|---|---|
| `media` | 25 | `owner_id` → `users` (restrict) | `media_attachable_type_attachable_id_collection_position_index`, `media_owner_id_index`, **`media_public_id_unique`**, `media_unattached_idx` |
| `addresses` | 15 | `user_id` → `users` (cascade) | **`addresses_one_default`** |
| `fit_kit_profiles` | 18 | `user_id` → `users` (cascade) | **`fit_kit_one_default`** |
| `notification_preferences` | 9 | `user_id` → `users` (cascade) | **`notification_preferences_user_id_category_unique`** |
| `device_tokens` | 10 | `user_id` → `users` (cascade) | **`device_tokens_token_unique`** |
| `follows` | 3 | `follower_id` → `users` (cascade), `seller_id` → `users` (cascade) | `follows_seller_id_index` |
| `kyc_verifications` | 30 | `document_back_media_id` → `media` (restrict), `document_front_media_id` → `media` (restrict), `reviewed_by` → `users` (restrict), `selfie_media_id` → `media` (restrict), `user_id` → `users` (cascade) | **`kyc_verifications_code_unique`**, **`kyc_verifications_handoff_token_hash_unique`**, `kyc_verifications_status_submitted_at_index`, **`kyc_verifications_user_id_unique`** |
| `aviator_credentials` | 17 | `licence_media_id` → `media` (restrict), `reviewed_by` → `users` (restrict), `user_id` → `users` (cascade) | `aviator_credentials_status_index`, **`aviator_credentials_user_id_unique`** |

## Catalogue

Categories, listings (full-text `search_vector`), favourites and paid boosts.

```mermaid
erDiagram
    boost_packages ||--o{ listing_promotions : "boost_package_id"
    categories |o--o{ categories : "parent_id"
    categories |o--o{ listings : "category_id"
    listings ||--o{ favorites : "listing_id"
    listings ||--o{ listing_promotions : "listing_id"
    media |o--o{ categories : "image_media_id"
    users |o--o{ listings : "moderated_by"
    users |o--o{ listings : "reserved_for_user_id"
    users ||--o{ favorites : "user_id"
    users ||--o{ listing_promotions : "user_id"
    users ||--o{ listings : "seller_id"
    categories {
        varchar id PK
        varchar parent_id FK
        varchar slug UK
        varchar name
        text description
        varchar image_media_id FK
        boolean sized
        jsonb attributes
        jsonb size_chart
        jsonb sizes
        smallint position
        boolean is_active
        int listings_count
        timestamp created_at
        timestamp updated_at
    }
    listings {
        varchar id PK
        varchar seller_id FK
        varchar category_id FK
        varchar slug UK
        varchar title
        text description
        varchar brand
        varchar model
        varchar condition
        varchar format
        varchar status
        character currency
        bigint price_amount
        bigint compare_at_amount
        bigint minimum_offer_amount
        boolean negotiable
        boolean swappable
        boolean auto_decline_below_minimum
        int stock
        int reserved_quantity
        varchar reserved_for_user_id FK
        timestamp reserved_until
        varchar reserved_reason
        jsonb attributes
        jsonb tags
        jsonb aircraft_types
        jsonb sizes
        varchar serial_number
        varchar year_purchased
        jsonb location
        jsonb shipping
        jsonb freight
        boolean free_delivery
        jsonb licensing
        timestamp promoted_until
        int views_count
        int favorites_count
        int offers_count
        int sold_count
        numeric rating_avg
        text moderation_note
        varchar moderation_flags
        varchar moderated_by FK
        timestamp moderated_at
        timestamp submitted_at
        timestamp published_at
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at
        tsvector search_vector
    }
    favorites {
        varchar user_id PK,FK
        varchar listing_id PK,FK
        bigint price_at_save
        timestamp created_at
    }
    boost_packages {
        varchar id PK
        varchar code UK
        varchar name
        varchar description
        varchar placement
        smallint duration_days
        bigint price_amount
        character currency
        boolean is_active
        smallint position
        timestamp created_at
        timestamp updated_at
    }
    listing_promotions {
        varchar id PK
        varchar listing_id FK
        varchar boost_package_id FK
        varchar user_id FK
        varchar status
        bigint amount
        character currency
        timestamp starts_at
        timestamp ends_at
        timestamp created_at
        timestamp updated_at
    }
    media {
        varchar id PK
    }
    users {
        varchar id PK
    }
```

| Table | Columns | Foreign keys (on delete) | Indexes |
|---|---|---|---|
| `categories` | 15 | `image_media_id` → `media` (restrict), `parent_id` → `categories` (set null) | `categories_parent_id_index`, **`categories_slug_unique`** |
| `listings` | 50 | `category_id` → `categories` (restrict), `moderated_by` → `users` (restrict), `reserved_for_user_id` → `users` (restrict), `seller_id` → `users` (restrict) | `listings_aircraft_types_idx`, `listings_category_id_index`, `listings_category_id_status_price_amount_index`, `listings_promoted_idx`, `listings_public_idx`, `listings_search_vector_idx`, `listings_seller_id_index`, `listings_seller_id_status_index`, **`listings_slug_unique`**, `listings_status_published_at_index` |
| `favorites` | 4 | `listing_id` → `listings` (cascade), `user_id` → `users` (cascade) | `favorites_listing_id_index` |
| `boost_packages` | 12 | — | **`boost_packages_code_unique`** |
| `listing_promotions` | 11 | `boost_package_id` → `boost_packages` (restrict), `listing_id` → `listings` (cascade), `user_id` → `users` (restrict) | `listing_promotions_status_ends_at_index` |

## Trading & orders

Offers, swaps, cart, pricing rules, orders and reviews.

```mermaid
erDiagram
    campaigns |o--o{ promo_codes : "campaign_id"
    listings |o--o{ reviews : "listing_id"
    listings ||--o{ cart_items : "listing_id"
    listings ||--o{ offers : "listing_id"
    listings ||--o{ order_items : "listing_id"
    listings ||--o{ swap_request_items : "listing_id"
    listings ||--o{ swap_requests : "target_listing_id"
    media |o--o{ order_items : "image_media_id"
    media ||--o{ download_grants : "media_id"
    offers |o--o{ offers : "parent_offer_id"
    offers |o--o{ orders : "offer_id"
    offers ||--o{ offer_events : "offer_id"
    orders ||--o{ download_grants : "order_id"
    orders ||--o{ order_events : "order_id"
    orders ||--o{ order_items : "order_id"
    orders ||--o{ reviews : "order_id"
    promo_codes |o--o{ orders : "promo_code_id"
    swap_requests |o--o{ orders : "swap_request_id"
    swap_requests ||--o{ swap_events : "swap_request_id"
    swap_requests ||--o{ swap_request_items : "swap_request_id"
    users |o--o{ campaigns : "created_by"
    users |o--o{ offer_events : "actor_id"
    users |o--o{ order_events : "actor_id"
    users |o--o{ promo_codes : "created_by"
    users |o--o{ swap_events : "actor_id"
    users ||--o{ cart_items : "user_id"
    users ||--o{ download_grants : "user_id"
    users ||--o{ offers : "buyer_id"
    users ||--o{ offers : "proposer_id"
    users ||--o{ offers : "recipient_id"
    users ||--o{ offers : "seller_id"
    users ||--o{ orders : "buyer_id"
    users ||--o{ orders : "seller_id"
    users ||--o{ reviews : "author_id"
    users ||--o{ reviews : "subject_id"
    users ||--o{ swap_requests : "counterparty_id"
    users ||--o{ swap_requests : "proposer_id"
    offers {
        varchar id PK
        varchar listing_id FK
        varchar buyer_id FK
        varchar seller_id FK
        varchar proposer_id FK
        varchar recipient_id FK
        varchar parent_offer_id FK
        bigint amount
        character currency
        bigint counter_amount
        varchar size
        text message
        varchar status
        timestamp expires_at
        timestamp responded_at
        timestamp recipient_read_at
        timestamp created_at
        timestamp updated_at
    }
    offer_events {
        varchar id PK
        varchar offer_id FK
        varchar kind
        varchar label
        varchar detail
        varchar actor_id FK
        timestamp occurred_at
    }
    swap_requests {
        varchar id PK
        varchar target_listing_id FK
        varchar proposer_id FK
        varchar counterparty_id FK
        bigint cash_adjustment_amount
        character currency
        bigint swap_fee_amount
        text message
        varchar status
        timestamp expires_at
        timestamp responded_at
        timestamp counterparty_read_at
        timestamp created_at
        timestamp updated_at
    }
    swap_request_items {
        bigint id PK
        varchar swap_request_id FK
        varchar listing_id FK
        bigint estimated_value
    }
    swap_events {
        varchar id PK
        varchar swap_request_id FK
        varchar kind
        varchar label
        varchar detail
        varchar actor_id FK
        timestamp occurred_at
    }
    cart_items {
        varchar id PK
        varchar user_id FK
        varchar listing_id FK
        varchar size
        boolean size_from_fit_kit
        smallint quantity
        timestamp created_at
        timestamp updated_at
    }
    campaigns {
        varchar id PK
        varchar name
        varchar kind
        varchar status
        varchar audience
        jsonb rules
        timestamp starts_at
        timestamp ends_at
        varchar created_by FK
        timestamp created_at
        timestamp updated_at
    }
    promo_codes {
        varchar id PK
        varchar campaign_id FK
        varchar code
        varchar discount_type
        int discount_value
        character currency
        bigint min_order_amount
        bigint max_discount_amount
        int usage_limit
        int per_user_limit
        int times_used
        timestamp starts_at
        timestamp ends_at
        boolean is_active
        varchar created_by FK
        timestamp created_at
        timestamp updated_at
    }
    commission_rules {
        varchar id PK
        varchar name
        varchar description
        varchar kind
        varchar scope
        jsonb scope_values
        int rate_bps
        bigint min_amount
        bigint cap_amount
        character currency
        smallint priority
        varchar status
        timestamp starts_at
        timestamp ends_at
        timestamp created_at
        timestamp updated_at
    }
    orders {
        varchar id PK
        varchar number UK
        varchar buyer_id FK
        varchar seller_id FK
        varchar status
        varchar status_before_hold
        varchar escrow_status
        varchar escrow_status_before_hold
        varchar kind
        varchar offer_id FK
        varchar swap_request_id FK
        varchar promo_code_id FK
        character currency
        bigint items_amount
        bigint freight_amount
        bigint buyer_protection_amount
        bigint duties_amount
        bigint discount_amount
        bigint swap_fee_amount
        bigint cash_adjustment_amount
        bigint total_amount
        int platform_fee_bps
        bigint platform_fee_amount
        bigint seller_net_amount
        bigint refunded_amount
        jsonb shipping_address
        jsonb shipping_option
        varchar carrier
        varchar tracking_number
        varchar tracking_url
        jsonb route
        timestamp estimated_delivery_from
        timestamp estimated_delivery_to
        varchar risk
        jsonb risk_signals
        varchar hold_reason
        timestamp reserved_until
        timestamp paid_at
        timestamp dispatch_due_at
        timestamp dispatched_at
        timestamp delivered_at
        timestamp auto_release_at
        timestamp dispute_window_ends_at
        timestamp completed_at
        timestamp funds_available_at
        timestamp funds_settled_at
        timestamp delivered_reminder_sent_at
        timestamp cancelled_at
        varchar cancellation_reason
        timestamp created_at
        timestamp updated_at
    }
    order_items {
        varchar id PK
        varchar order_id FK
        varchar listing_id FK
        varchar title
        varchar listing_slug
        varchar image_media_id FK
        varchar condition
        varchar format
        varchar size
        boolean size_from_fit_kit
        smallint quantity
        bigint unit_price
        bigint total
        timestamp created_at
        timestamp updated_at
    }
    order_events {
        varchar id PK
        varchar order_id FK
        varchar code
        varchar label
        varchar detail
        varchar actor_id FK
        timestamp occurred_at
    }
    download_grants {
        bigint id PK
        varchar order_id FK
        varchar media_id FK
        varchar user_id FK
        varchar ip_address
        timestamp expires_at
        timestamp created_at
        timestamp updated_at
    }
    reviews {
        varchar id PK
        varchar order_id FK
        varchar author_id FK
        varchar subject_id FK
        varchar listing_id FK
        varchar role
        smallint rating
        text comment
        jsonb tags
        boolean is_hidden
        timestamp created_at
        timestamp updated_at
    }
    listings {
        varchar id PK
    }
    media {
        varchar id PK
    }
    users {
        varchar id PK
    }
```

| Table | Columns | Foreign keys (on delete) | Indexes |
|---|---|---|---|
| `offers` | 18 | `buyer_id` → `users` (restrict), `listing_id` → `listings` (cascade), `parent_offer_id` → `offers` (set null), `proposer_id` → `users` (restrict), `recipient_id` → `users` (restrict), `seller_id` → `users` (restrict) | **`offers_one_pending_per_buyer`**, `offers_proposer_id_status_index`, `offers_recipient_id_status_index`, `offers_status_expires_at_index` |
| `offer_events` | 7 | `actor_id` → `users` (restrict), `offer_id` → `offers` (cascade) | `offer_events_offer_id_occurred_at_index` |
| `swap_requests` | 14 | `counterparty_id` → `users` (restrict), `proposer_id` → `users` (restrict), `target_listing_id` → `listings` (restrict) | `swap_requests_counterparty_id_status_index`, `swap_requests_proposer_id_status_index`, `swap_requests_status_expires_at_index` |
| `swap_request_items` | 4 | `listing_id` → `listings` (restrict), `swap_request_id` → `swap_requests` (cascade) | **`swap_request_items_swap_request_id_listing_id_unique`** |
| `swap_events` | 7 | `actor_id` → `users` (restrict), `swap_request_id` → `swap_requests` (cascade) | — |
| `cart_items` | 8 | `listing_id` → `listings` (cascade), `user_id` → `users` (cascade) | **`cart_items_user_id_listing_id_unique`** |
| `campaigns` | 11 | `created_by` → `users` (restrict) | — |
| `promo_codes` | 17 | `campaign_id` → `campaigns` (restrict), `created_by` → `users` (restrict) | **`promo_codes_code_upper_unique`** |
| `commission_rules` | 16 | — | `commission_rules_kind_status_index` |
| `orders` | 51 | `buyer_id` → `users` (restrict), `offer_id` → `offers` (restrict), `promo_code_id` → `promo_codes` (restrict), `seller_id` → `users` (restrict), `swap_request_id` → `swap_requests` (restrict) | `orders_auto_release_idx`, `orders_buyer_id_index`, `orders_escrow_status_index`, `orders_funds_pending_idx`, **`orders_number_unique`**, `orders_pending_expiry_idx`, `orders_seller_id_index`, `orders_status_created_at_index`, `orders_swap_request_id_index` |
| `order_items` | 15 | `image_media_id` → `media` (restrict), `listing_id` → `listings` (restrict), `order_id` → `orders` (cascade) | — |
| `order_events` | 7 | `actor_id` → `users` (restrict), `order_id` → `orders` (cascade) | `order_events_order_id_occurred_at_index` |
| `download_grants` | 8 | `media_id` → `media` (restrict), `order_id` → `orders` (cascade), `user_id` → `users` (restrict) | — |
| `reviews` | 12 | `author_id` → `users` (restrict), `listing_id` → `listings` (restrict), `order_id` → `orders` (restrict), `subject_id` → `users` (restrict) | `reviews_listing_id_index`, **`reviews_order_id_author_id_unique`**, `reviews_subject_id_index` |

## Money

Payments, refunds, webhooks, wallets with an append-only ledger, payouts, FX, idempotency and dual control.

```mermaid
erDiagram
    orders |o--o{ ledger_entries : "order_id"
    orders |o--o{ refunds : "order_id"
    payments ||--o{ refunds : "payment_id"
    payout_batches |o--o{ payouts : "batch_id"
    payout_methods |o--o{ payout_schedules : "payout_method_id"
    payout_methods ||--o{ payouts : "payout_method_id"
    users |o--o{ approval_requests : "decided_by"
    users |o--o{ payouts : "approved_by"
    users |o--o{ refunds : "approved_by"
    users |o--o{ refunds : "requested_by"
    users ||--o{ approval_requests : "requested_by"
    users ||--o{ ledger_entries : "user_id"
    users ||--o{ payments : "user_id"
    users ||--o{ payout_batches : "created_by"
    users ||--o{ payout_methods : "user_id"
    users ||--o{ payout_schedules : "user_id"
    users ||--o{ payouts : "user_id"
    users ||--o{ wallets : "user_id"
    wallets ||--o{ ledger_entries : "wallet_id"
    wallets ||--o{ payouts : "wallet_id"
    payments {
        varchar id PK
        varchar reference UK
        varchar payable_type
        varchar payable_id
        varchar user_id FK
        varchar gateway
        varchar gateway_reference
        bigint amount
        character currency
        bigint refunded_amount
        bigint gateway_fee_amount
        varchar status
        text authorization_url
        varchar return_url
        varchar method_label
        varchar failure_reason
        jsonb gateway_metadata
        timestamp verified_at
        timestamp expires_at
        timestamp created_at
        timestamp updated_at
    }
    refunds {
        varchar id PK
        varchar reference UK
        varchar payment_id FK
        varchar order_id FK
        bigint amount
        character currency
        varchar status
        varchar reason
        text note
        varchar gateway_refund_id
        varchar failure_reason
        varchar requested_by FK
        varchar approved_by FK
        timestamp processed_at
        timestamp created_at
        timestamp updated_at
    }
    webhook_events {
        varchar id PK
        varchar provider
        varchar event_id
        varchar event_type
        varchar reference
        boolean signature_valid
        jsonb payload
        jsonb headers
        varchar status
        smallint attempts
        text last_error
        varchar request_id
        timestamp processed_at
        timestamp created_at
        timestamp updated_at
    }
    wallets {
        varchar id PK
        varchar user_id FK
        character currency
        bigint available_amount
        bigint escrow_amount
        bigint pending_release_amount
        bigint reserved_amount
        bigint lifetime_earnings_amount
        timestamp created_at
        timestamp updated_at
    }
    ledger_entries {
        varchar id PK
        varchar wallet_id FK
        varchar user_id FK
        varchar type
        varchar direction
        varchar bucket
        bigint amount
        character currency
        varchar status
        varchar description
        varchar detail
        varchar order_id FK
        varchar payout_id
        varchar idempotency_key UK
        timestamp occurred_at
        timestamp created_at
    }
    wallet_movements {
        varchar key PK
        varchar wallet_id
        varchar order_id
        varchar payout_id
        timestamp created_at
    }
    payout_methods {
        varchar id PK
        varchar user_id FK,UK
        varchar kind
        varchar label
        character currency
        character country_code
        varchar last4
        varchar bank_code
        varchar bank_name
        text account_name
        text account_number
        varchar provider
        text provider_recipient_code
        boolean is_default
        timestamp verified_at
        timestamp created_at
        timestamp updated_at
        timestamp deleted_at
    }
    payout_schedules {
        bigint id PK
        varchar user_id FK,UK
        varchar frequency
        smallint day
        bigint minimum_amount
        character currency
        varchar payout_method_id FK
        timestamp created_at
        timestamp updated_at
    }
    payout_batches {
        varchar id PK
        varchar reference UK
        varchar provider
        character currency
        int payouts_count
        bigint total_amount
        varchar status
        varchar created_by FK
        timestamp created_at
        timestamp updated_at
    }
    payouts {
        varchar id PK
        varchar reference UK
        varchar user_id FK
        varchar wallet_id FK
        varchar payout_method_id FK
        varchar batch_id FK
        bigint amount
        character currency
        bigint fee_amount
        bigint net_amount
        character destination_currency
        bigint destination_amount
        numeric exchange_rate
        varchar status
        boolean requires_approval
        varchar approval_reason
        boolean flagged
        varchar hold_reason
        varchar rejection_reason
        varchar provider
        varchar provider_reference
        varchar failure_reason
        varchar approved_by FK
        timestamp approved_at
        timestamp paid_at
        timestamp created_at
        timestamp updated_at
    }
    exchange_rates {
        bigint id PK
        character base
        character quote
        numeric rate
        varchar source
        timestamp created_at
        timestamp updated_at
    }
    idempotency_keys {
        bigint id PK
        varchar user_id
        varchar key
        varchar route
        varchar request_hash
        smallint response_status
        jsonb response_body
        timestamp completed_at
        timestamp expires_at
        timestamp created_at
        timestamp updated_at
    }
    approval_requests {
        varchar id PK
        varchar type
        varchar permission
        varchar target_type
        varchar target_id
        varchar summary
        jsonb payload
        varchar status
        varchar requested_by FK
        varchar decided_by FK
        text decision_note
        text failure_reason
        timestamp decided_at
        timestamp created_at
        timestamp updated_at
    }
    orders {
        varchar id PK
    }
    users {
        varchar id PK
    }
```

| Table | Columns | Foreign keys (on delete) | Indexes |
|---|---|---|---|
| `payments` | 21 | `user_id` → `users` (restrict) | **`payments_gateway_reference_unique`**, **`payments_one_open_per_gateway`**, `payments_payable_type_payable_id_index`, **`payments_reference_unique`**, `payments_status_created_at_index`, `payments_user_id_index` |
| `refunds` | 16 | `approved_by` → `users` (restrict), `order_id` → `orders` (restrict), `payment_id` → `payments` (restrict), `requested_by` → `users` (restrict) | `refunds_order_id_index`, **`refunds_reference_unique`** |
| `webhook_events` | 15 | — | **`webhook_events_provider_event_id_unique`**, `webhook_events_reference_index`, `webhook_events_status_created_at_index` |
| `wallets` | 10 | `user_id` → `users` (cascade) | **`wallets_user_id_currency_unique`** |
| `ledger_entries` | 16 | `order_id` → `orders` (restrict), `user_id` → `users` (restrict), `wallet_id` → `wallets` (restrict) | **`ledger_entries_idempotency_key_unique`**, `ledger_entries_order_id_index`, `ledger_entries_payout_id_index`, `ledger_entries_user_id_index`, `ledger_entries_user_id_occurred_at_index` |
| `wallet_movements` | 5 | — | `wallet_movements_order_id_index`, `wallet_movements_wallet_id_index` |
| `payout_methods` | 18 | `user_id` → `users` (cascade) | **`payout_methods_one_default`** |
| `payout_schedules` | 9 | `payout_method_id` → `payout_methods` (restrict), `user_id` → `users` (cascade) | **`payout_schedules_user_id_unique`** |
| `payout_batches` | 10 | `created_by` → `users` (restrict) | **`payout_batches_reference_unique`** |
| `payouts` | 27 | `approved_by` → `users` (restrict), `batch_id` → `payout_batches` (restrict), `payout_method_id` → `payout_methods` (restrict), `user_id` → `users` (restrict), `wallet_id` → `wallets` (restrict) | **`payouts_reference_unique`**, `payouts_status_created_at_index`, `payouts_user_id_index` |
| `exchange_rates` | 7 | — | **`exchange_rates_base_quote_unique`** |
| `idempotency_keys` | 11 | — | `idempotency_keys_expires_at_index`, **`idempotency_keys_user_id_key_route_unique`** |
| `approval_requests` | 15 | `decided_by` → `users` (restrict), `requested_by` → `users` (restrict) | `approval_requests_status_type_index` |

## Community, disputes & notifications

Messaging, disputes, reports, support and the notification centre.

```mermaid
erDiagram
    conversations ||--o{ messages : "conversation_id"
    disputes ||--o{ dispute_messages : "dispute_id"
    disputes ||--o{ dispute_proposals : "dispute_id"
    listings |o--o{ conversations : "listing_id"
    orders |o--o{ support_tickets : "order_id"
    orders ||--o{ disputes : "order_id"
    users |o--o{ dispute_messages : "author_id"
    users |o--o{ disputes : "mediator_id"
    users |o--o{ messages : "sender_id"
    users |o--o{ reports : "assignee_id"
    users |o--o{ reports : "reporter_id"
    users |o--o{ reports : "resolved_by"
    users |o--o{ support_tickets : "user_id"
    users ||--o{ conversations : "buyer_id"
    users ||--o{ conversations : "seller_id"
    users ||--o{ dispute_proposals : "proposed_by"
    users ||--o{ disputes : "buyer_id"
    users ||--o{ disputes : "seller_id"
    users ||--o{ notifications : "user_id"
    conversations {
        varchar id PK
        varchar listing_id FK
        varchar buyer_id FK
        varchar seller_id FK
        varchar last_message_preview
        timestamp last_message_at
        timestamp buyer_last_read_at
        timestamp seller_last_read_at
        timestamp buyer_notified_at
        timestamp seller_notified_at
        boolean is_flagged
        timestamp created_at
        timestamp updated_at
    }
    messages {
        varchar id PK
        varchar conversation_id FK
        varchar sender_id FK
        varchar kind
        text body
        jsonb flags
        jsonb context
        timestamp read_at
        timestamp created_at
        timestamp updated_at
    }
    disputes {
        varchar id PK
        varchar number UK
        varchar order_id FK,UK
        varchar buyer_id FK
        varchar seller_id FK
        varchar mediator_id FK
        varchar reason
        varchar requested
        bigint requested_amount
        character currency
        bigint frozen_amount
        text description
        varchar status
        varchar decision
        bigint amount_to_buyer
        varchar policy_reference
        varchar previous_order_status
        timestamp respond_by
        timestamp decision_due_at
        timestamp resolved_at
        timestamp created_at
        timestamp updated_at
    }
    dispute_messages {
        varchar id PK
        varchar dispute_id FK
        varchar author_id FK
        varchar author_role
        text body
        boolean is_internal
        timestamp created_at
        timestamp updated_at
    }
    dispute_proposals {
        varchar id PK
        varchar dispute_id FK
        varchar proposed_by FK
        varchar kind
        varchar summary
        bigint amount
        varchar status
        timestamp responded_at
        timestamp created_at
        timestamp updated_at
    }
    reports {
        varchar id PK
        varchar reporter_id FK
        varchar target_type
        varchar target_id
        varchar reason
        text detail
        varchar severity
        varchar source
        varchar status
        varchar assignee_id FK
        varchar resolution
        text resolution_note
        varchar resolved_by FK
        timestamp resolved_at
        timestamp created_at
        timestamp updated_at
    }
    support_tickets {
        varchar id PK
        varchar number UK
        varchar user_id FK
        varchar name
        varchar email
        varchar phone
        varchar topic
        varchar subject
        text message
        varchar order_id FK
        varchar status
        varchar priority
        timestamp first_response_at
        timestamp created_at
        timestamp updated_at
    }
    notifications {
        varchar id PK
        varchar user_id FK
        varchar type
        varchar category
        varchar icon
        varchar title
        varchar body
        varchar link
        jsonb actions
        jsonb data
        varchar dedupe_key UK
        timestamp read_at
        timestamp created_at
        timestamp updated_at
    }
    notification_deliveries {
        bigint id PK
        varchar notification_id
        varchar user_id
        varchar channel
        varchar template
        varchar recipient
        jsonb payload
        varchar provider
        varchar provider_message_id
        varchar status
        smallint attempts
        text error
        varchar dedupe_key UK
        timestamp sent_at
        timestamp created_at
        timestamp updated_at
    }
    newsletter_subscriptions {
        bigint id PK
        varchar email
        varchar status
        varchar token_hash UK
        timestamp confirmed_at
        timestamp created_at
        timestamp updated_at
    }
    listings {
        varchar id PK
    }
    orders {
        varchar id PK
    }
    users {
        varchar id PK
    }
```

| Table | Columns | Foreign keys (on delete) | Indexes |
|---|---|---|---|
| `conversations` | 13 | `buyer_id` → `users` (restrict), `listing_id` → `listings` (restrict), `seller_id` → `users` (restrict) | `conversations_buyer_id_last_message_at_index`, **`conversations_listing_parties_unique`**, `conversations_seller_id_last_message_at_index` |
| `messages` | 10 | `conversation_id` → `conversations` (cascade), `sender_id` → `users` (restrict) | `messages_conversation_id_created_at_index` |
| `disputes` | 22 | `buyer_id` → `users` (restrict), `mediator_id` → `users` (restrict), `order_id` → `orders` (restrict), `seller_id` → `users` (restrict) | `disputes_buyer_id_index`, **`disputes_number_unique`**, **`disputes_one_active_per_order`**, `disputes_seller_id_index`, `disputes_status_created_at_index` |
| `dispute_messages` | 8 | `author_id` → `users` (restrict), `dispute_id` → `disputes` (cascade) | `dispute_messages_dispute_id_created_at_index` |
| `dispute_proposals` | 10 | `dispute_id` → `disputes` (cascade), `proposed_by` → `users` (restrict) | — |
| `reports` | 16 | `assignee_id` → `users` (restrict), `reporter_id` → `users` (restrict), `resolved_by` → `users` (restrict) | `reports_status_severity_index`, `reports_target_type_target_id_index` |
| `support_tickets` | 15 | `order_id` → `orders` (restrict), `user_id` → `users` (restrict) | **`support_tickets_number_unique`**, `support_tickets_status_created_at_index` |
| `notifications` | 14 | `user_id` → `users` (cascade) | **`notifications_dedupe_key_unique`**, `notifications_unread_idx`, `notifications_user_id_created_at_index` |
| `notification_deliveries` | 16 | — | **`notification_deliveries_dedupe_key_unique`**, `notification_deliveries_notification_id_index`, `notification_deliveries_provider_message_id_index`, `notification_deliveries_user_id_index` |
| `newsletter_subscriptions` | 7 | — | **`newsletter_email_lower_unique`**, **`newsletter_subscriptions_token_hash_unique`** |

## Platform & content

CMS pages, home rails, status history, broadcasts, exports, maintenance, settings and the audit log.

```mermaid
erDiagram
    users ||--o{ broadcasts : "created_by"
    users ||--o{ exports : "requested_by"
    users ||--o{ maintenance_windows : "created_by"
    pages {
        varchar id PK
        varchar slug UK
        varchar title
        varchar summary
        text body_html
        varchar kind
        varchar status
        timestamp published_at
        timestamp created_at
        timestamp updated_at
    }
    hero_slides {
        varchar id PK
        smallint position
        jsonb published
        jsonb draft
        boolean active
        timestamp created_at
        timestamp updated_at
    }
    faq_stats {
        bigint id PK
        varchar faq_id UK
        varchar question
        varchar tag
        int views
        timestamp created_at
        timestamp updated_at
    }
    status_checks {
        bigint id PK
        varchar component
        date day
        varchar state
        int checks
        int failures
        timestamp created_at
        timestamp updated_at
    }
    broadcasts {
        varchar id PK
        varchar title
        text body
        jsonb channels
        jsonb audience
        varchar link
        varchar status
        int recipients_count
        timestamp scheduled_at
        timestamp sent_at
        varchar created_by FK
        timestamp created_at
        timestamp updated_at
    }
    exports {
        varchar id PK
        varchar table
        jsonb filters
        varchar status
        varchar path
        int rows
        varchar requested_by FK
        timestamp completed_at
        timestamp created_at
        timestamp updated_at
    }
    maintenance_windows {
        varchar id PK
        timestamp starts_at
        timestamp ends_at
        varchar message
        boolean notify_users
        varchar created_by FK
        timestamp cancelled_at
        timestamp created_at
        timestamp updated_at
    }
    platform_settings {
        varchar key PK
        jsonb value
        timestamp created_at
        timestamp updated_at
    }
    audit_logs {
        varchar id PK
        varchar actor_id
        varchar actor_name
        boolean actor_is_system
        varchar action
        varchar severity
        varchar target_type
        varchar target_id
        varchar target_label
        text detail
        jsonb before
        jsonb after
        jsonb metadata
        varchar ip_address
        text user_agent
        varchar request_id
        timestamp created_at
    }
    users {
        varchar id PK
    }
```

| Table | Columns | Foreign keys (on delete) | Indexes |
|---|---|---|---|
| `pages` | 10 | — | **`pages_slug_unique`** |
| `hero_slides` | 7 | — | — |
| `faq_stats` | 7 | — | **`faq_stats_faq_id_unique`** |
| `status_checks` | 8 | — | **`status_checks_component_day_unique`** |
| `broadcasts` | 13 | `created_by` → `users` (restrict) | — |
| `exports` | 10 | `requested_by` → `users` (restrict) | — |
| `maintenance_windows` | 9 | `created_by` → `users` (restrict) | `maintenance_windows_starts_at_ends_at_index` |
| `platform_settings` | 4 | — | — |
| `audit_logs` | 17 | — | `audit_logs_action_created_at_index`, `audit_logs_actor_id_index`, `audit_logs_created_at_index`, `audit_logs_target_type_target_id_index` |

## Framework

Laravel infrastructure tables (cache/queue fallbacks when Redis is not used).

| Table | Columns | Foreign keys (on delete) | Indexes |
|---|---|---|---|
| `cache` | 3 | — | `cache_expiration_index` |
| `cache_locks` | 3 | — | `cache_locks_expiration_index` |
| `jobs` | 7 | — | `jobs_queue_index` |
| `job_batches` | 10 | — | — |
| `failed_jobs` | 7 | — | `failed_jobs_connection_queue_failed_at_index`, **`failed_jobs_uuid_unique`** |
| `migrations` | 3 | — | — |

## Backups and retention

See [deployment.md](deployment.md#backup-and-recovery) for backup, point-in-time recovery and restore drills. Short-lived data is pruned daily by `php artisan pilotwardrobe:prune` (unattached media older than 24 h, expired idempotency keys, one-time codes and trusted devices, auth sessions revoked over 90 days ago, generated statement and export files older than 2 days) plus `sanctum:prune-expired` and `queue:prune-failed`.
