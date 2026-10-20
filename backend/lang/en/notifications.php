<?php

// In-app notifications (Phase 10a, MASTER_SPEC §36). :amount already has digits in the right language.
return [
    'member_approved' => [
        'title' => 'Welcome! Your account is approved',
        'body' => 'You can now use all member features: payments, aid requests and chat.',
    ],
    'payment_approved' => [
        'title' => 'Payment verified',
        'body' => 'Your payment of ৳:amount has been verified. Thank you!',
    ],
    'payment_rejected' => [
        'title' => 'Payment not accepted',
        'body' => 'Your payment of ৳:amount was not accepted. Reason: :reason',
    ],
    'aid_approved' => [
        'title' => 'Aid request approved',
        'body' => 'Your aid request :case_id was approved for ৳:amount. The payout will follow.',
    ],
    'aid_rejected' => [
        'title' => 'Aid request not approved',
        'body' => 'Your aid request :case_id was not approved. Reason: :reason',
    ],
    'aid_paid' => [
        'title' => 'Aid paid out',
        'body' => '৳:amount for your aid request :case_id has been paid.',
    ],
    'aid_vote_open' => [
        'title' => 'New aid request to vote on',
        'body' => 'Aid request :case_id is open. Please give your opinion (Yes / No).',
    ],
    'payment_due_reminder' => [
        'title' => 'Payment reminder',
        'body' => ':message (Due: ৳:amount)',
    ],
    'notice_published' => [
        'title' => 'New notice',
        'body' => ':title',
    ],
];
