<?php

// Default home rails (V1). Overridable at runtime via platform setting `home.sections`.
return [
    'sections' => [
        ['key' => 'fresh', 'title' => 'Fresh on the flightline', 'subtitle' => 'Recently listed by verified sellers',
            'view_all_to' => '/marketplace?sort=newest', 'sort' => 'newest', 'limit' => 8],
        ['key' => 'headsets', 'title' => 'Source flight deck audio & headsets',
            'subtitle' => 'Authentic Bose, David Clark and Lightspeed gear from verified sellers',
            'category' => 'headsets', 'view_all_to' => '/category/headsets', 'sort' => 'popular', 'limit' => 8],
        ['key' => 'uniforms', 'title' => 'Uniforms sized to your Fit Kit', 'subtitle' => 'Shirts, jackets and epaulettes',
            'category' => 'uniforms', 'view_all_to' => '/category/uniforms', 'sort' => 'promoted', 'limit' => 8],
    ],
];
