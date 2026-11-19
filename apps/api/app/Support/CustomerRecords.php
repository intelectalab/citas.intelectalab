<?php

namespace App\Support;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CustomerRecords
{
    public const FILLABLE_FROM_DUPLICATE = ['type_document_id', 'document_number', 'email', 'phone', 'birth_date', 'biological_sex', 'blood_type', 'address', 'district', 'department', 'postal_code', 'emergency_contact_name', 'emergency_contact_phone', 'medical_notes'];

    private const PERSONAL = ['document_number', 'type_document_id', 'email', 'phone', 'birth_date', 'biological_sex', 'blood_type', 'address', 'district', 'department', 'postal_code', 'emergency_contact_name', 'emergency_contact_phone', 'medical_notes'];

    public static function ready(): bool
    {
        return Schema::hasColumn('customers', 'anonymized_at');
    }

    public static function normalizedDocument(?string $value): string
    {
        return mb_strtoupper(preg_replace('/[\s.-]+/', '', trim((string) $value)) ?? '');
    }

    public static function duplicateByDocument(string $tenantId, ?string $document, ?string $exceptId = null): ?Customer
    {
        $normalized = self::normalizedDocument($document);

        if ($normalized === '') {
            return null;
        }

        return Customer::where('tenant_id', $tenantId)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->whereRaw("upper(regexp_replace(coalesce(document_number, ''), '[\\s.-]', '', 'g')) = ?", [$normalized])
            ->first();
    }

    private static function references(): array
    {
        $rows = DB::select("select table_name, column_name from information_schema.columns where table_schema = 'public' and column_name in ('customer_id', 'actor_customer_id') and table_name <> 'customers'");

        return array_map(fn ($row) => [$row->table_name, $row->column_name], $rows);
    }

    public static function merge(Customer $keep, Customer $duplicate): array
    {
        $moved = [];

        DB::table('customer_consents')->where('customer_id', $duplicate->id)
            ->whereIn('channel', DB::table('customer_consents')->where('customer_id', $keep->id)->select('channel'))
            ->delete();

        if (Schema::hasTable('customer_tag_assignments')) {
            DB::table('customer_tag_assignments')->where('customer_id', $duplicate->id)
                ->whereIn('customer_tag_id', DB::table('customer_tag_assignments')->where('customer_id', $keep->id)->select('customer_tag_id'))
                ->delete();
        }

        if (Schema::hasTable('marketing_campaign_recipients')) {
            DB::table('marketing_campaign_recipients')->where('customer_id', $duplicate->id)
                ->whereIn('marketing_campaign_id', DB::table('marketing_campaign_recipients')->where('customer_id', $keep->id)->select('marketing_campaign_id'))
                ->delete();
        }

        foreach (self::references() as [$table, $column]) {
            $count = DB::table($table)->where($column, $duplicate->id)->update([$column => $keep->id]);

            if ($count > 0) {
                $moved[$table] = ($moved[$table] ?? 0) + $count;
            }
        }

        $fill = [];

        foreach (self::FILLABLE_FROM_DUPLICATE as $field) {
            if (($keep->{$field} === null || $keep->{$field} === '') && $duplicate->{$field} !== null && $duplicate->{$field} !== '') {
                $fill[$field] = $duplicate->{$field};
            }
        }

        if ($fill) {
            $keep->forceFill($fill)->save();
        }

        $duplicate->tokens()->delete();
        $duplicate->forceFill(['email' => null, 'password_hash' => null] + (self::ready() ? ['merged_into_id' => $keep->id] : []))->save();
        $duplicate->delete();

        CustomerStages::refreshFor($keep->tenant_id, $keep->id);

        return ['moved' => $moved, 'filled' => array_keys($fill)];
    }

    public static function anonymize(Customer $customer): void
    {
        $values = array_fill_keys(array_filter(self::PERSONAL, fn ($field) => Schema::hasColumn('customers', $field)), null);

        $customer->forceFill($values + [
            'first_name'    => 'Cliente',
            'last_name'     => 'anónimo ' . $customer->code,
            'password_hash' => null,
            'anonymized_at' => now(),
        ])->save();

        $customer->tokens()->delete();

        DB::table('customer_consents')->where('customer_id', $customer->id)->update(['granted' => false, 'revoked_at' => now(), 'source' => 'anonymized', 'updated_at' => now()]);
        DB::table('customer_dependents')->where('customer_id', $customer->id)->update(['name' => 'Anónimo', 'notes' => null, 'breed' => null, 'birth_date' => null]);

        foreach (['waitlist_entries' => 'notes', 'walkin_entries' => 'notes', 'messages' => 'to_address'] as $table => $column) {
            if (Schema::hasColumn($table, $column)) {
                DB::table($table)->where('customer_id', $customer->id)->update([$column => null]);
            }
        }
    }
}
