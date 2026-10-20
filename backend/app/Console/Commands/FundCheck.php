<?php

namespace App\Console\Commands;

use App\Support\FundIntegrity;
use Illuminate\Console\Command;

/** Read-only fund integrity check (Phase 12b) — the same list as the Super Admin page. Never changes data. */
class FundCheck extends Command
{
    protected $signature = 'fund:check';

    protected $description = 'List mismatches between the fund ledger, aid cases and payments (read-only, fixes nothing)';

    public function handle(): int
    {
        $result = FundIntegrity::run();

        $this->info("Ledger entries checked: {$result['checked_entries']} — fund balance: ".number_format($result['balance']).' taka');
        if (! $result['issues']) {
            $this->info('No mismatches found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Severity', 'Problem', 'Details'],
            array_map(fn (array $i) => [
                $i['severity'],
                $i['code'],
                collect($i)->except(['code', 'severity'])->map(fn ($v, $k) => "$k=".($v ?? '—'))->implode(', '),
            ], $result['issues']),
        );

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
