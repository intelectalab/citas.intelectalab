<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const NONE = '00000000-0000-0000-0000-000000000000';

    public function up(): void
    {
        if (!Schema::hasTable('commission_rules')) {
            return;
        }

        DB::transaction(function () {
            foreach (DB::table('commission_rules')->where('kind', 'service')->whereNull('service_id')->orderBy('updated_at')->get() as $rule) {
                $services = $rule->category_id
                    ? DB::table('services')->where('tenant_id', $rule->tenant_id)->where('category_id', $rule->category_id)->pluck('id')
                    : collect();

                if (!$rule->category_id && (float) $rule->fixed <= 0) {
                    DB::table('professionals')->where('tenant_id', $rule->tenant_id)
                        ->when($rule->professional_id, fn ($q, $id) => $q->where('id', $id), fn ($q) => $q->whereNull('commission_percent'))
                        ->update(['commission_percent' => $rule->percent]);
                } elseif (!$rule->category_id) {
                    $services = DB::table('services')->where('tenant_id', $rule->tenant_id)->pluck('id');
                }

                foreach ($services as $serviceId) {
                    DB::table('commission_rules')->insert([
                        'id'              => (string) Str::uuid(),
                        'tenant_id'       => $rule->tenant_id,
                        'name'            => $rule->name,
                        'kind'            => 'service',
                        'professional_id' => $rule->professional_id,
                        'service_id'      => $serviceId,
                        'percent'         => $rule->percent,
                        'fixed'           => $rule->fixed,
                        'active'          => $rule->active,
                        'created_by'      => $rule->created_by,
                        'created_at'      => $rule->created_at,
                        'updated_at'      => $rule->updated_at,
                    ]);
                }

                DB::table('commission_rules')->where('id', $rule->id)->delete();
            }

            DB::table('commission_rules')->whereNotNull('category_id')->update(['category_id' => null]);
            DB::table('commission_rules')->where('kind', 'product')->update(['professional_id' => null, 'service_id' => null]);

            $keep = [];
            foreach (DB::table('commission_rules')->orderByDesc('updated_at')->orderByDesc('created_at')->get() as $rule) {
                $key = implode(':', [$rule->tenant_id, $rule->kind, $rule->professional_id ?? '', $rule->service_id ?? '', $rule->product_id ?? '']);

                if (isset($keep[$key])) {
                    DB::table('commission_rules')->where('id', $rule->id)->delete();
                } else {
                    $keep[$key] = true;
                }
            }
        });

        $none = self::NONE;
        DB::statement("CREATE UNIQUE INDEX IF NOT EXISTS commission_rules_one_per_case ON commission_rules (tenant_id, kind, coalesce(professional_id, '{$none}'), coalesce(service_id, '{$none}'), coalesce(product_id, '{$none}'))");
        DB::statement("ALTER TABLE commission_rules DROP CONSTRAINT IF EXISTS commission_rules_shape_check");
        DB::statement("ALTER TABLE commission_rules ADD CONSTRAINT commission_rules_shape_check CHECK ((kind = 'service' AND service_id IS NOT NULL AND product_id IS NULL AND category_id IS NULL) OR (kind = 'product' AND professional_id IS NULL AND service_id IS NULL AND category_id IS NULL))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE commission_rules DROP CONSTRAINT IF EXISTS commission_rules_shape_check');
        DB::statement('DROP INDEX IF EXISTS commission_rules_one_per_case');
    }
};
