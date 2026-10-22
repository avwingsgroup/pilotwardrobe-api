<?php

/*
| Identity verification rules (VB16 / AD5).
|
| Prembly endpoint paths vary by product and plan. Confirm them against the
| Prembly dashboard for the account before going live and override here.
*/

return [
    'document_types' => [
        'passport' => 'Passport',
        'national_id' => 'National ID card',
        'nin' => 'NIN slip',
        'drivers_licence' => "Driver's licence",
        'voters_card' => "Voter's card",
        'bvn' => 'BVN',
    ],

    // Types that need a back image.
    'two_sided' => ['national_id', 'drivers_licence', 'voters_card'],

    'face_match_threshold' => 80.0,

    'review_sla_hours' => 24,

    'handoff_ttl_minutes' => 30,

    'prembly' => [
        'lookup_endpoints' => [
            'nin' => env('PREMBLY_NIN_ENDPOINT', '/identitypass/verification/vnin'),
            'national_id' => env('PREMBLY_NIN_ENDPOINT', '/identitypass/verification/vnin'),
            'bvn' => env('PREMBLY_BVN_ENDPOINT', '/identitypass/verification/bvn'),
            'passport' => env('PREMBLY_PASSPORT_ENDPOINT', '/identitypass/verification/national_passport'),
            'drivers_licence' => env('PREMBLY_DL_ENDPOINT', '/identitypass/verification/drivers_license'),
            'voters_card' => env('PREMBLY_VOTERS_ENDPOINT', '/identitypass/verification/voters_card'),
        ],
        'face_endpoint' => env('PREMBLY_FACE_ENDPOINT', '/identitypass/verification/biometrics/face/comparison'),
    ],
];
