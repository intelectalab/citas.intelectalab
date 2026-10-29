<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ORDER = [
        'agenda' => 1, 'crm' => 2, 'booking' => 3, 'walkin' => 4, 'classes' => 5,
        'quotes' => 6, 'records' => 7, 'resources' => 8,
        'pos' => 9, 'invoicing' => 10, 'commissions' => 11, 'inventory' => 12,
        'memberships' => 13, 'packages' => 14, 'loyalty' => 15,
        'marketing' => 16, 'automations' => 17,
        'multi_branch' => 18, 'reports_adv' => 19, 'api' => 20,
    ];

    private const PREVIOUS = [
        'agenda' => 1, 'crm' => 2, 'booking' => 3, 'walkin' => 4, 'classes' => 5,
        'records' => 6, 'resources' => 7, 'pos' => 8, 'invoicing' => 9, 'quotes' => 10,
        'commissions' => 11, 'inventory' => 12, 'packages' => 13, 'memberships' => 14, 'loyalty' => 15,
        'marketing' => 16, 'automations' => 17, 'multi_branch' => 18, 'reports_adv' => 19, 'api' => 20,
    ];

    public function up(): void
    {
        $this->apply(self::ORDER);
    }

    public function down(): void
    {
        $this->apply(self::PREVIOUS);
    }

    private function apply(array $order): void
    {
        if (!Schema::hasTable('saas_modules')) {
            return;
        }

        foreach ($order as $key => $position) {
            DB::table('saas_modules')->where('key', $key)->update(['sort_order' => $position]);
        }
    }
};
