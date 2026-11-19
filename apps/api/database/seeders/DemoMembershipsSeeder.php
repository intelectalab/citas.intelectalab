<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Cart;
use App\Support\Invoicing;
use App\Support\Memberships;
use App\Support\Sales;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DemoMembershipsSeeder extends Seeder
{
    private const SLUG = 'bella-aura';

    private Tenant $tenant;
    private User $owner;
    private string $branchId;

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->error('Los datos de muestra no se cargan en producción.');

            return;
        }

        $tenant = Tenant::where('slug', self::SLUG)->first();

        if (!$tenant || !Memberships::enabled($tenant)) {
            $this->command?->warn('  Membresías de muestra: Salón Bella Aura no existe o no tiene el módulo de membresías (o falta php artisan migrate).');

            return;
        }

        if (DB::table('membership_plans')->where('tenant_id', $tenant->id)->exists()) {
            $this->command?->line('  Membresías de muestra: Salón Bella Aura ya tiene planes, no se cambia nada.');

            return;
        }

        $this->tenant = $tenant;
        $this->owner = User::where('tenant_id', $tenant->id)->where('email', 'admin@bellaaura.pe')->first() ?? User::where('tenant_id', $tenant->id)->firstOrFail();
        $this->branchId = DB::table('branches')->where('tenant_id', $tenant->id)->orderBy('created_at')->value('id');

        $brushing = $this->service('brushing');
        $manicure = $this->service('manicure');

        if (!$brushing || !$manicure) {
            $this->command?->warn('  Membresías de muestra: faltan los servicios Brushing o Manicure en Salón Bella Aura.');

            return;
        }

        $club = $this->plan('Club Bella', 'Para clientas frecuentes: dos brushing al mes y descuento en todo servicio.', 120, 15, 'services', [[$brushing->id, 2]]);
        $premium = $this->plan('Club Bella Premium', 'El plan completo: descuento también en productos, brushing y manicure incluidos.', 200, 25, 'all', [[$brushing->id, 4], [$manicure->id, 2]]);
        $this->plan('Club Bella Anual', 'Un año con descuento permanente, pagado de una vez.', 1100, 15, 'services', [], 'yearly');

        $customers = Customer::where('tenant_id', $tenant->id)->whereNull('deleted_at')->orderBy('last_name')->orderBy('first_name')->limit(8)->get()->values();

        if ($customers->count() < 7) {
            $this->command?->warn('  Membresías de muestra: hacen falta al menos 7 clientes en Salón Bella Aura.');

            return;
        }

        $today = Carbon::now($tenant->zone())->startOfDay();
        $at = fn (int $daysAgo, string $time = '11:00') => $today->copy()->subDays($daysAgo)->setTimeFromTimeString($time);

        $grace = $today->copy()->subMonthNoOverflow()->subDays(2);
        $old = $today->copy()->subMonthsNoOverflow(2)->subDays(10);
        $renewed = $customers[7] ?? $customers[6];
        $steps = [
            [$at(10), fn () => $this->sell($customers[0], $club)],
            [$at(6, '16:30'), fn () => $this->sell($customers[0], null, [$brushing->id])],
            [$at(26), fn () => $this->sell($customers[1], $club)],
            [$at(19, '10:15'), fn () => $this->sell($customers[1], null, [$brushing->id, $brushing->id])],
            [$at(12, '17:00'), fn () => $this->sell($customers[1], null, [$brushing->id])],
            [$grace->copy()->setTime(12, 0), fn () => $this->sell($customers[2], $club)],
            [$grace->copy()->addDays(8)->setTime(15, 0), fn () => $this->sell($customers[2], null, [$brushing->id])],
            [$at(14), fn () => $this->sell($customers[3], $club)],
            [$at(4, '09:30'), fn () => Memberships::pause($tenant, $this->current($customers[3])->id, 'Viaja a Cusco por trabajo hasta fin de mes', $this->owner)],
            [$at(18), fn () => $this->sell($customers[4], $club)],
            [$at(5, '18:10'), fn () => Memberships::cancel($tenant, $this->current($customers[4])->id, 'Se mudó a Arequipa', $this->owner)],
            [$at(3, '13:00'), fn () => $this->sell($customers[5], $premium)],
            [$at(1, '12:00'), fn () => $this->sell($customers[5], null, [$brushing->id, $manicure->id])],
            [$old->copy()->setTime(11, 0), fn () => $this->sell($customers[6], $club)],
            [$at(40), fn () => $this->sell($renewed, $club)],
            [$at(9, '10:00'), fn () => $this->sell($renewed, $club)],
        ];
        usort($steps, fn ($a, $b) => $a[0] <=> $b[0]);

        try {
            foreach ($steps as [$when, $step]) {
                Carbon::setTestNow($when->copy()->setTimezone('UTC'));
                $this->owner->unsetRelations();
                DB::transaction($step);
            }
        } finally {
            Carbon::setTestNow();
        }

        Memberships::expireDue($tenant);
        $this->command?->line('  Ejemplos: membresías de Salón Bella Aura (vigentes, por vencer, en gracia, pausada, cancelada, vencida y renovada)');
    }

    private function service(string $name): ?object
    {
        return DB::table('services')->where('tenant_id', $this->tenant->id)->where('name', 'ilike', "%{$name}%")->orderBy('name')->first();
    }

    private function plan(string $name, string $description, float $price, float $discount, string $scope, array $items, string $period = 'monthly'): string
    {
        $id = (string) Str::uuid();
        DB::table('membership_plans')->insert([
            'id' => $id, 'tenant_id' => $this->tenant->id, 'name' => $name, 'description' => $description, 'price' => $price,
            'billing_period' => $period, 'discount_percent' => $discount, 'discount_scope' => $scope,
            'sessions_included' => array_sum(array_column($items, 1)), 'status' => 'active', 'currency' => \App\Support\Currency::of($this->tenant),
            'created_at' => now()->subDays(60), 'updated_at' => now()->subDays(60),
        ]);

        foreach ($items as $position => [$serviceId, $sessions]) {
            DB::table('membership_plan_items')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'membership_plan_id' => $id,
                'service_id' => $serviceId, 'sessions' => $sessions, 'position' => $position,
            ]);
        }

        return $id;
    }

    private function current(Customer $customer): object
    {
        return DB::table('memberships')->where('tenant_id', $this->tenant->id)->where('customer_id', $customer->id)->orderByDesc('created_at')->first();
    }

    private function sell(Customer $customer, ?string $planId, array $services = []): void
    {
        $items = $planId ? [['type' => 'membership', 'id' => $planId, 'quantity' => 1]] : [];

        foreach (array_count_values($services) as $serviceId => $quantity) {
            $items[] = ['type' => 'service', 'id' => $serviceId, 'quantity' => $quantity];
        }

        $sale = Cart::save($this->tenant, $this->owner, ['branch_id' => $this->branchId, 'customer_id' => $customer->id, 'items' => $items]);

        if ($sale->status !== 'paid' && (float) $sale->total > 0) {
            $data = ['method' => 'card', 'amount' => (float) $sale->total, 'reference' => 'OP-' . strtoupper(Str::random(6))];
            DB::transaction(fn () => Sales::pay($this->tenant, $sale->id, $data, Invoicing::validated($this->tenant, []), $this->owner));
        }
    }
}
