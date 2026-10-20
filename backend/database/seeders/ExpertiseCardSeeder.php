<?php

namespace Database\Seeders;

use App\Models\ExpertiseCard;
use Illuminate\Database\Seeder;

/**
 * The 3 default Home Expertise cards (MASTER_SPEC §13 "Decided": STOLL, SHIMA SEIKI, Sweater Programming).
 * Only runs on an empty table, so it never overwrites or duplicates the admin's cards.
 */
class ExpertiseCardSeeder extends Seeder
{
    public const CARDS = [
        [
            'icon' => 'chip',
            'title_bn' => 'STOLL',
            'title_en' => 'STOLL',
            'description_bn' => 'কম্পিউটারাইজড ফ্ল্যাট নিটিং — STOLL মেশিনে জ্যাকার্ড, ইন্টারসিয়া ও স্ট্রাকচার ডিজাইনের প্রোগ্রামিং।',
            'description_en' => 'Computerized Flat Knitting — programming jacquard, intarsia and structure designs on STOLL machines.',
        ],
        [
            'icon' => 'cog',
            'title_bn' => 'SHIMA SEIKI',
            'title_en' => 'SHIMA SEIKI',
            'description_bn' => 'অ্যাডভান্সড নিটিং টেকনোলজি — SHIMA SEIKI মেশিনে ডিজাইন, প্যাটার্ন ও হোলগার্মেন্ট প্রোগ্রামিং।',
            'description_en' => 'Advanced Knitting Technology — design, pattern and WHOLEGARMENT programming on SHIMA SEIKI machines.',
        ],
        [
            'icon' => 'code',
            'title_bn' => 'সোয়েটার প্রোগ্রামিং',
            'title_en' => 'Sweater Programming',
            'description_bn' => 'প্রফেশনাল প্রোগ্রামিং ও প্রোডাকশন — স্যাম্পল থেকে বাল্ক প্রোডাকশন পর্যন্ত নির্ভুল সোয়েটার প্রোগ্রাম।',
            'description_en' => 'Professional Programming & Production — accurate sweater programs from sample to bulk production.',
        ],
    ];

    public function run(): void
    {
        if (ExpertiseCard::exists()) {
            $this->command?->info('Expertise cards already exist — nothing added.');

            return;
        }

        foreach (self::CARDS as $position => $card) {
            ExpertiseCard::create([...$card, 'sort_order' => $position, 'is_published' => true]);
        }

        $this->command?->info(count(self::CARDS).' default expertise cards added.');
    }
}
