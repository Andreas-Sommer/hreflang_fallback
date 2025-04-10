<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Hreflang Fallback',
    'description' => 'Provides hreflang meta tags for pages in fallback mode.',
    'category' => 'fe',
    'author' => 'Andreas Sommer',
    'author_email' => 'sommer@belsignum.com',
    'state' => 'alpha',
    'version' => '1.0.1',
    'constraints' => [
        'depends' => [
            'typo3' => '10.4.0-10.4.99',
        ],
    ],
    'autoload' => [
        'psr-4' => [
            'Belsignum\\HreflangFallback\\' => 'Classes/',
        ],
    ],
];
