<?php

namespace App\Support;

use App\Models\Setting;

/**
 * The fund's receiving accounts + payment instructions (MASTER_SPEC §25 "fund receiving numbers"), edited by the
 * Super Admin (Phase 7e) and shown on the member Payments page. Stored as one JSON setting `payment_accounts`.
 * Each part (bKash, Nagad, Rocket, bank, instructions) can be shown or hidden.
 */
class PaymentAccounts
{
    public const MOBILE = ['bkash', 'nagad', 'rocket'];

    public const TYPES = ['personal', 'agent', 'merchant'];

    /** Every field with its empty value — the shape the admin screen edits. */
    public static function defaults(): array
    {
        $out = [];
        foreach (self::MOBILE as $m) {
            $out[$m] = ['number' => null, 'type' => 'personal', 'visible' => false];
        }
        $out['bank'] = [
            'bank_name' => null,
            'account_name' => null,
            'account_number' => null,
            'branch' => null,
            'routing_number' => null,
            'visible' => false,
        ];
        $out['instructions'] = ['bn' => null, 'en' => null, 'visible' => false];

        return $out;
    }

    /** The saved values (all parts, shown or hidden), filled up with defaults. */
    public static function stored(): array
    {
        $saved = json_decode((string) Setting::valueOf(Setting::PAYMENT_ACCOUNTS, ''), true);
        $out = self::defaults();
        if (! is_array($saved)) {
            return $out;
        }
        foreach ($out as $part => $fields) {
            foreach ($fields as $field => $default) {
                if (is_array($saved[$part] ?? null) && array_key_exists($field, $saved[$part])) {
                    $out[$part][$field] = $saved[$part][$field];
                }
            }
        }

        return $out;
    }

    /** Only what members may see: hidden parts are null. */
    public static function forMembers(): array
    {
        $all = self::stored();
        $accounts = [];
        foreach (self::MOBILE as $m) {
            if ($all[$m]['visible'] && $all[$m]['number']) {
                $accounts[] = ['method' => $m, 'number' => $all[$m]['number'], 'type' => $all[$m]['type']];
            }
        }
        $bank = $all['bank'];
        $instructions = $all['instructions'];

        return [
            'mobile' => $accounts,
            'bank' => $bank['visible'] && $bank['account_number'] ? array_diff_key($bank, ['visible' => 1]) : null,
            'instructions' => $instructions['visible'] && $instructions['bn']
                ? ['bn' => $instructions['bn'], 'en' => $instructions['en']]
                : null,
        ];
    }
}
