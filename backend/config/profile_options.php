<?php

/**
 * Fixed choice lists for member profiles (MASTER_SPEC §19). Stored as the key;
 * bn/en labels are served by GET /api/profile-options.
 */
return [
    'employment_statuses' => [
        'running_job' => ['bn' => 'চাকরিরত', 'en' => 'Running job'],
        'jobless' => ['bn' => 'বেকার', 'en' => 'Jobless'],
    ],

    'document_types' => [
        'nid' => ['bn' => 'জাতীয় পরিচয়পত্র (NID)', 'en' => 'National ID (NID)'],
        'birth_certificate' => ['bn' => 'জন্ম নিবন্ধন সনদ', 'en' => 'Birth certificate'],
        'certificate' => ['bn' => 'সনদপত্র', 'en' => 'Certificate'],
        'other' => ['bn' => 'অন্যান্য', 'en' => 'Other'],
    ],

    'portfolio_categories' => [
        'jacquard' => ['bn' => 'জ্যাকার্ড', 'en' => 'Jacquard'],
        'intarsia' => ['bn' => 'ইন্টারশিয়া', 'en' => 'Intarsia'],
        'programming' => ['bn' => 'প্রোগ্রামিং', 'en' => 'Programming'],
        'production' => ['bn' => 'প্রোডাকশন', 'en' => 'Production'],
        'sampling' => ['bn' => 'স্যাম্পলিং', 'en' => 'Sampling'],
        'optimisation' => ['bn' => 'অপটিমাইজেশন', 'en' => 'Optimisation'],
        'training' => ['bn' => 'প্রশিক্ষণ', 'en' => 'Training'],
        'other' => ['bn' => 'অন্যান্য', 'en' => 'Other'],
    ],
];
