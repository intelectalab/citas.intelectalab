<?php

use App\Support\Access;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('marketing_campaign_recipients', 'message_id')) {
            DB::statement('ALTER TABLE marketing_campaign_recipients ADD COLUMN message_id uuid NULL REFERENCES messages(id) ON DELETE SET NULL');
            DB::statement('CREATE INDEX idx_campaign_recipients_campaign ON marketing_campaign_recipients(marketing_campaign_id, status)');
        }

        if (!Schema::hasColumn('marketing_campaigns', 'audience_label')) {
            DB::statement('ALTER TABLE marketing_campaigns ADD COLUMN audience_label varchar(200) NULL');
        }

        $ids = Access::permissionIds();
        $names = array_values(array_intersect(Access::factoryPermissions('manager'), array_filter(array_keys($ids), fn ($name) => str_starts_with($name, 'campaigns.'))));

        if (!$names) {
            return;
        }

        $roles = DB::table('roles')->where('slug', 'manager')->whereNotNull('permissions_seeded_at')->pluck('id');

        foreach ($roles as $roleId) {
            DB::table('role_permissions')->insertOrIgnore(array_map(fn ($name) => ['role_id' => $roleId, 'permission_id' => $ids[$name]], $names));
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS idx_campaign_recipients_campaign');
        DB::statement('ALTER TABLE marketing_campaign_recipients DROP COLUMN IF EXISTS message_id');
        DB::statement('ALTER TABLE marketing_campaigns DROP COLUMN IF EXISTS audience_label');
    }
};
