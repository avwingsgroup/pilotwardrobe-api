<?php

/*
|--------------------------------------------------------------------------
| Permission catalogue and role defaults (contract §2)
|--------------------------------------------------------------------------
|
| Effective permissions for a user =
|     role defaults (below)
|   ∪ staff-role bundle (admins only, from `staff_roles.matrix`)
|   ∪ per-user grants
|   − per-user revocations
|
| `super_admin` always holds every permission. `GET /auth/me` returns the
| effective list and every endpoint re-checks it server-side.
*/

$member = [
    'listings.create', 'listings.update', 'listings.promote',
    'offers.create', 'swaps.create', 'orders.create',
    'payouts.request', 'reviews.create', 'disputes.create', 'messages.send',
];

return [

    'all' => [
        // Member capabilities
        ...$member,
        'aviator.credentials.manage', 'listings.unlimited',
        // Staff capabilities
        'admin.access', 'dashboard.view',
        'users.view', 'users.update', 'users.suspend', 'users.create', 'users.impersonate',
        'kyc.view', 'kyc.review',
        'listings.view', 'listings.approve', 'listings.reject',
        'orders.view', 'orders.manage', 'transactions.view', 'transactions.refund',
        'disputes.view', 'disputes.resolve',
        'reports.view', 'reports.manage',
        'payouts.view', 'payouts.approve',
        'fees.view', 'fees.manage',
        'promotions.manage', 'broadcasts.manage', 'content.manage',
        'catalogue.manage', 'analytics.view',
        'team.view', 'roles.manage', 'permissions.manage',
        'audit.view',
        'settings.manage', 'payment_gateways.manage',
    ],

    'roles' => [
        'user' => $member,
        'aviator' => [...$member, 'aviator.credentials.manage', 'listings.unlimited'],
        // Admins get nothing by default beyond their staff-role bundle.
        'admin' => [],
        // Resolved to `all` at runtime.
        'super_admin' => ['*'],
    ],

    /*
    | AD18 permission matrix. Each area has three levels: none | view | manage.
    | `manage` implies everything in `view`.
    */
    'matrix' => [
        'view' => [
            'view' => ['admin.access', 'dashboard.view', 'analytics.view'],
            'manage' => ['admin.access', 'dashboard.view', 'analytics.view', 'audit.view', 'catalogue.manage'],
        ],
        'users' => [
            'view' => ['users.view', 'reports.view'],
            'manage' => ['users.view', 'users.update', 'users.suspend', 'reports.view', 'reports.manage'],
        ],
        'kyc' => [
            'view' => ['kyc.view'],
            'manage' => ['kyc.view', 'kyc.review'],
        ],
        'listings' => [
            'view' => ['listings.view'],
            'manage' => ['listings.view', 'listings.approve', 'listings.reject'],
        ],
        'orders' => [
            'view' => ['orders.view', 'transactions.view'],
            'manage' => ['orders.view', 'transactions.view', 'orders.manage'],
        ],
        'refunds' => [
            'view' => ['transactions.view'],
            'manage' => ['transactions.view', 'transactions.refund'],
        ],
        'disputes' => [
            'view' => ['disputes.view'],
            'manage' => ['disputes.view', 'disputes.resolve'],
        ],
        'payouts' => [
            'view' => ['payouts.view'],
            'manage' => ['payouts.view', 'payouts.approve'],
        ],
        'fees' => [
            'view' => ['fees.view'],
            'manage' => ['fees.view', 'fees.manage'],
        ],
        'content' => [
            'view' => [],
            'manage' => ['promotions.manage', 'broadcasts.manage', 'content.manage'],
        ],
        'team' => [
            'view' => ['team.view'],
            'manage' => ['team.view', 'users.create', 'roles.manage', 'permissions.manage'],
        ],
    ],

    // Default staff-role bundles seeded on install (AD18). Super admin is a system role and is never editable.
    'staff_roles' => [
        'super_admin' => ['name' => 'Super admin', 'system' => true, 'matrix' => 'manage'],
        'trust_safety' => ['name' => 'Trust & safety', 'matrix' => [
            'view' => 'manage', 'users' => 'manage', 'kyc' => 'manage', 'listings' => 'manage',
            'orders' => 'view', 'refunds' => 'none', 'disputes' => 'manage', 'payouts' => 'view',
            'fees' => 'none', 'content' => 'none', 'team' => 'none',
        ]],
        'finance' => ['name' => 'Finance', 'matrix' => [
            'view' => 'manage', 'users' => 'view', 'kyc' => 'view', 'listings' => 'view',
            'orders' => 'manage', 'refunds' => 'manage', 'disputes' => 'view', 'payouts' => 'manage',
            'fees' => 'manage', 'content' => 'none', 'team' => 'none',
        ]],
        'support_agent' => ['name' => 'Support agent', 'matrix' => [
            'view' => 'view', 'users' => 'view', 'kyc' => 'view', 'listings' => 'view',
            'orders' => 'view', 'refunds' => 'none', 'disputes' => 'view', 'payouts' => 'none',
            'fees' => 'none', 'content' => 'none', 'team' => 'none',
        ]],
        'marketing' => ['name' => 'Marketing', 'matrix' => [
            'view' => 'view', 'users' => 'none', 'kyc' => 'none', 'listings' => 'view',
            'orders' => 'none', 'refunds' => 'none', 'disputes' => 'none', 'payouts' => 'none',
            'fees' => 'none', 'content' => 'manage', 'team' => 'none',
        ]],
    ],
];
