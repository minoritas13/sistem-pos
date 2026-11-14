<?php

namespace App\Console\Commands;

use App\Models\Receivable;
use App\Models\SupplierPayable;
use Illuminate\Console\Command;

class MarkOverdueCommand extends Command
{
    protected $signature = 'payables:mark-overdue';

    protected $description = 'Tandai utang supplier dan piutang pelanggan yang lewat jatuh tempo sebagai overdue';

    public function handle(): int
    {
        $today = today()->toDateString();

        $payables = SupplierPayable::whereIn('status', ['unpaid', 'partial'])
            ->where('due_date', '<', $today)
            ->update(['status' => 'overdue']);

        $receivables = Receivable::whereIn('status', ['unpaid', 'partial'])
            ->where('due_date', '<', $today)
            ->update(['status' => 'overdue']);

        $this->info("Utang supplier overdue: {$payables}. Piutang pelanggan overdue: {$receivables}.");

        return self::SUCCESS;
    }
}
