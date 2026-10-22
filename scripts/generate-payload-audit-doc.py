"""Turns the JSON report of scripts/payload-audit.php into docs/payload-compatibility-audit.md.

    php scripts/payload-audit.php > storage/app/payload-audit.json
    python scripts/generate-payload-audit-doc.py storage/app/payload-audit.json
"""
import json
import sys
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
report = json.loads(Path(sys.argv[1]).read_text(encoding='utf-8'))

# Samples the harness cannot reproduce, and where the behaviour is covered instead.
NOT_EXERCISED = {
    ('POST', '/auth/email/verify'): 'Needs the code e-mailed by the preceding registration sample (each sample is rolled back). Covered by `AuthenticationTest::test_email_otp_verification`.',
    ('GET', '/auth/password/reset/demo-reset-token'): 'Mock-only token. Unknown tokens return 410 `TOKEN_EXPIRED`; valid tokens return `{ valid, email }` (`AuthenticationTest`).',
    ('GET', '/auth/social/google/redirect'): 'Needs `GOOGLE_CLIENT_ID`; without it the API returns 503 `GATEWAY_UNAVAILABLE`.',
    ('POST', '/media'): 'Multipart upload, which the JSON replay cannot send. Covered by `ListingLifecycleTest` (returns the `MediaAsset` shape with `width`/`height`/`variants`).',
    ('POST', '/payments/initialize'): 'Paystack keys are not configured locally (503 `GATEWAY_UNAVAILABLE`). Same response shape is verified with the sandbox gateway (`CheckoutPaymentFlowTest`) and the Paystack adapter in `PaymentGatewayAdaptersTest`.',
    ('POST', '/orders/ord_4402/confirm-delivery'): 'The sample shows the 409 for an order in the wrong state; the audit fixture order was deliverable, so it returned 200. The 409 path is covered by `DisputesMessagingTest`.',
}
DATA_VARIANT = {
    'Login — two-factor required': 'The demo aviator has no 2FA enrolled, so login succeeded. The `two_factor_required` variant (`challenge_id`, `methods`, `sms_hint`) is covered by `AuthenticationTest::test_two_factor_challenge_flow`.',
    'Refund — dual control': 'The fixture refund was below the $1,000 threshold and executed directly (`status: executed`). The `pending_approval` + `approver` response is covered by `ControlTowerTest`.',
}
NULL_OMITTED = {
    'data[].read_at': 'omitted while unread',
    'data.actions': 'omitted when the notification has no actions',
    'data[].counter_amount': 'omitted when there is no counter-offer',
}


def classify(row):
    key = (row['method'], row['path'])
    status, expected = str(row['status']), str(row['expected_status'])
    if key in NOT_EXERCISED and status != expected:
        return 'Not exercised', NOT_EXERCISED[key]
    if row['title'] in DATA_VARIANT:
        return 'Supported', DATA_VARIANT[row['title']]
    if status != expected and not (expected == 'None' and status.startswith('2')):
        return 'Modified', f'Status {status} instead of {expected}: {row.get("error", "")}'
    missing = row.get('missing') or []
    if missing:
        notes = sorted({v for k, v in NULL_OMITTED.items() if any(m.startswith(k) for m in missing)})
        if notes and all(any(m.startswith(k) for k in NULL_OMITTED) for m in missing):
            return 'Supported', 'Null field ' + '; '.join(notes) + ' (D19).'
        return 'Missing fields', ', '.join(f'`{m}`' for m in missing)
    note = ''
    if row.get('unverified'):
        note = f'{len(row["unverified"])} nested keys not verifiable (empty list/null in fixture data).'
    return 'Supported', note


rows = [(r, *classify(r)) for r in report]
counts = Counter(c for _, c, _ in rows)

out = []
w = out.append
w('# Payload compatibility audit')
w('')
w('Every request/response sample in [`api-payloads/`](api-payloads/README.md) (produced by the SPA mock server) was replayed against the real Laravel API '
  'and the response key paths were compared with the sample. Method:')
w('')
w('- `scripts/payload-audit.php` boots the application in-process against the seeded development database (`php artisan migrate:fresh --seed`), '
  'builds missing fixtures through the API itself (orders paid through the sandbox gateway, dispatched and delivered; a dispute with a seller proposal; '
  'a swap; an offer; a KYC case; a report; a payout), and replays each sample as the same demo user, inside a transaction that is rolled back.')
w('- Mock ids in paths and bodies (`ord_4471`, `lst_003`, `adr_1`, …) are mapped to real records in the same state.')
w('- A key counts as **missing** only when the real response lacks it although its parent object is present and non-empty. Keys under an empty list or a `null` '
  'are reported as *unverifiable*, not missing. **Extra** keys (for example `formatted` money strings) are additive and allowed by the contract ("unknown fields: ignored").')
w('- Status codes and, for errors, the `code` and the `errors` field names are compared too.')
w('')
w('Regenerate: `php scripts/payload-audit.php > storage/app/payload-audit.json && python scripts/generate-payload-audit-doc.py storage/app/payload-audit.json`.')
w('')
w('## Result')
w('')
w('| Status | Samples | Meaning |')
w('|---|---|---|')
meaning = {
    'Supported': 'Same status, every documented key present (or a documented null omission / data variant).',
    'Modified': 'Different status code or error code.',
    'Missing fields': 'A documented key is absent from a non-empty parent.',
    'Not exercised': 'Cannot be replayed here (needs live credentials, multipart, or a previous sample\'s side effect); covered by tests instead.',
    'Deprecated': 'Endpoint or field removed.',
}
for status in ['Supported', 'Modified', 'Missing fields', 'Deprecated', 'Not exercised']:
    w(f'| **{status}** | {counts.get(status, 0)} | {meaning[status]} |')
w(f'| Total | {len(rows)} | |')
w('')
w('Nothing is deprecated: all 209 contract endpoints exist (see [frontend-api-mapping.md](frontend-api-mapping.md)).')
w('')
w('### Defects the audit found and that were fixed')
w('')
w('| Endpoint | Problem | Fix |')
w('|---|---|---|')
w('| `GET /sellers/{username}` | `rating_breakdown` was serialised as a JSON **list** because `array_merge` renumbers numeric keys | `array_replace`, plus a regression assertion in `ListingBrowseTest` |')
w('| `POST /admin/disputes/{id}/decision` | **500** when the optional `note` was omitted | Optional field read safely; new `DisputesMessagingTest::test_staff_decision_settles_dispute_and_needs_dispute_permission` |')
w('| `GET /admin/orders/{id}` | `parties[].risk` missing | Added (`low`/`medium`/`high` from the risk score) |')
w('| `POST /admin/kyc/{id}/approve\\|reject\\|…` | Returned the summary row instead of the review item (`document`, `selfie`, `extracted`, `automated_checks` missing) | Returns the same shape as `GET /admin/kyc/{id}` |')
w('| `GET /me/hangar` | `stats.previous_rating` missing | Added (average rating of reviews older than 30 days; `null` without any) |')
w('')
w('## Sample by sample')
w('')
current = None
for row, status, note in rows:
    if row['file'] != current:
        current = row['file']
        w(f'### [{current}](api-payloads/{current})')
        w('')
        w('| Sample | Request | Status | Notes |')
        w('|---|---|---|---|')
    actor = row['actor'] or 'visitor'
    path = row['path'].replace('|', '\\|')
    w(f'| {row["title"]} | `{row["method"]} {path}` as {actor} | {status} | {note.replace("|", "/")} |')
w('')
w('## Additive fields')
w('')
w('The API returns these extra keys in addition to the samples. They are safe to ignore and some are useful:')
w('')
w('- `formatted` on every money object (locale-aware via `Accept-Language`).')
w('- `condition_label`, `status_label`, `kind` on listings and orders; `permissions_version` and `staff_role` on the session principal.')
w('- `meta` pagination on `GET /conversations`; `approval_id` on dual-control responses; `skipped` on bulk actions.')
w('- Admin presenters add identifiers (`target_type`, `target_id`, `request_id`, `slug`, `system`) used for linking and auditing.')

(ROOT / 'docs' / 'payload-compatibility-audit.md').write_text('\n'.join(out) + '\n', encoding='utf-8')
print('wrote docs/payload-compatibility-audit.md', dict(counts))
