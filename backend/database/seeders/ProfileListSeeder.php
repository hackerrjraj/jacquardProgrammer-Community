<?php

namespace Database\Seeders;

use App\Models\ProfileListItem;
use Illuminate\Database\Seeder;

/**
 * Starting Machine + Skill lists for a NEW installation (Phase 11d-1). On an existing database the lists were made
 * from the members' saved values by the migration, so a list that already has items is never touched.
 */
class ProfileListSeeder extends Seeder
{
    public const DEFAULTS = [
        ProfileListItem::TYPE_MACHINE => [
            'stoll' => ['STOLL', 'STOLL'],
            'shima-seiki' => ['SHIMA SEIKI', 'SHIMA SEIKI'],
            'steiger' => ['Steiger', 'Steiger'],
            'cixing' => ['Cixing', 'Cixing'],
        ],
        ProfileListItem::TYPE_SKILL => [
            'm1plus' => ['M1PLUS', 'M1PLUS'],
            'sds-one' => ['SDS-ONE', 'SDS-ONE'],
            'sds-one-apex' => ['SDS-ONE APEX', 'SDS-ONE APEX'],
            'jacquard' => ['জ্যাকার্ড', 'Jacquard'],
            'intarsia' => ['ইন্টারশিয়া', 'Intarsia'],
        ],
    ];

    public function run(): void
    {
        foreach (self::DEFAULTS as $type => $items) {
            if (ProfileListItem::where('type', $type)->exists()) {
                continue;
            }
            $order = 0;
            foreach ($items as $key => [$bn, $en]) {
                ProfileListItem::create([
                    'type' => $type,
                    'key' => $key,
                    'name_bn' => $bn,
                    'name_en' => $en,
                    'is_active' => true,
                    'sort_order' => $order++,
                ]);
            }
        }
    }
}
