<?php

// In-app notifications (Phase 10a, MASTER_SPEC §36). :amount already has Bengali digits.
return [
    'member_approved' => [
        'title' => 'স্বাগতম! আপনার অ্যাকাউন্ট অনুমোদিত হয়েছে',
        'body' => 'এখন আপনি সদস্যদের সব সুবিধা ব্যবহার করতে পারবেন: পেমেন্ট, সহায়তার আবেদন ও চ্যাট।',
    ],
    'payment_approved' => [
        'title' => 'পেমেন্ট যাচাই হয়েছে',
        'body' => 'আপনার ৳:amount পেমেন্ট যাচাই করা হয়েছে। ধন্যবাদ!',
    ],
    'payment_rejected' => [
        'title' => 'পেমেন্ট গ্রহণ করা হয়নি',
        'body' => 'আপনার ৳:amount পেমেন্ট গ্রহণ করা হয়নি। কারণ: :reason',
    ],
    'aid_approved' => [
        'title' => 'সহায়তার আবেদন অনুমোদিত',
        'body' => 'আপনার সহায়তার আবেদন :case_id ৳:amount-এর জন্য অনুমোদিত হয়েছে। শীঘ্রই টাকা পাঠানো হবে।',
    ],
    'aid_rejected' => [
        'title' => 'সহায়তার আবেদন অনুমোদিত হয়নি',
        'body' => 'আপনার সহায়তার আবেদন :case_id অনুমোদিত হয়নি। কারণ: :reason',
    ],
    'aid_paid' => [
        'title' => 'সহায়তার টাকা পাঠানো হয়েছে',
        'body' => 'আপনার সহায়তার আবেদন :case_id-এর ৳:amount পাঠানো হয়েছে।',
    ],
    'aid_vote_open' => [
        'title' => 'ভোট দেওয়ার জন্য নতুন সহায়তার আবেদন',
        'body' => 'সহায়তার আবেদন :case_id খোলা আছে। অনুগ্রহ করে আপনার মতামত দিন (হ্যাঁ / না)।',
    ],
    'payment_due_reminder' => [
        'title' => 'পেমেন্ট রিমাইন্ডার',
        'body' => ':message (বকেয়া: ৳:amount)',
    ],
    'notice_published' => [
        'title' => 'নতুন নোটিশ',
        'body' => ':title',
    ],
];
