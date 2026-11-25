<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerDependent;
use App\Support\Audit;
use Illuminate\Http\Request;

class CustomerDependentController extends Controller
{
    private function customer(Request $request, string $customerId): Customer
    {
        $tenant = $request->user()->tenant;

        abort_unless($tenant && $tenant->dependents()['enabled'], 403, 'Tu rubro no usa dependientes.');

        return Customer::visibleTo($request->user())->findOrFail($customerId);
    }

    public static function auditable(CustomerDependent $dependent): array
    {
        return [
            'name'           => $dependent->name,
            'species'        => $dependent->species,
            'breed'          => $dependent->breed,
            'birth_date'     => $dependent->birth_date?->format('Y-m-d'),
            'biological_sex' => ['M' => 'Masculino', 'F' => 'Femenino', 'O' => 'Otro'][$dependent->biological_sex] ?? null,
            'notes'          => $dependent->notes ? mb_strimwidth($dependent->notes, 0, 160, '…') : null,
            'alert'          => $dependent->alert ?? null,
            'weight_kg'      => $dependent->weight_kg !== null ? rtrim(rtrim(number_format((float) $dependent->weight_kg, 2, '.', ''), '0'), '.') : null,
            'color'          => $dependent->color ?? null,
            'sterilized'     => $dependent->sterilized === null ? null : ($dependent->sterilized ? 'Sí' : 'No'),
            'microchip'      => $dependent->microchip ?? null,
            'relationship'   => $dependent->relationship ?? null,
            'document_number' => $dependent->document_number ?? null,
        ];
    }

    public static function audit(Request $request, string $action, Customer $customer, CustomerDependent $dependent, array $before = [], array $after = []): void
    {
        Audit::log($request, $action, array_filter([
            'tenant'       => $customer->tenant_id,
            'entity_type'  => 'customer_dependent',
            'entity_id'    => $dependent->id,
            'entity_label' => $dependent->name,
            'before'       => $before ?: null,
            'after'        => ['customer' => trim("{$customer->first_name} {$customer->last_name}")] + $after,
        ], fn ($value) => $value !== null));
    }

    public function index(Request $request, string $customer)
    {
        $customer = $this->customer($request, $customer);

        $rows = $customer->dependents()->orderBy('name')->get();
        $stats = \App\Support\CustomerInsights::dependentStats($request->user()->tenant, $customer, $rows->pluck('id')->all());

        return response()->json($rows->map(fn (CustomerDependent $d) => $d->toArray() + ['stats' => $stats[$d->id] ?? null])->values());
    }

    public function store(Request $request, string $customer)
    {
        $customer = $this->customer($request, $customer);
        $validated = CustomerDependent::cleanAlert($request->validate(CustomerDependent::staffRules()));

        $dependent = $customer->dependents()->create($validated + [
            'tenant_id' => $customer->tenant_id,
            'kind'      => $request->user()->tenant->dependents()['kind'],
        ]);
        self::audit($request, 'customer_dependent.created', $customer, $dependent, [], array_filter(self::auditable($dependent)));

        return response()->json($dependent, 201);
    }

    public function update(Request $request, string $customer, string $dependent)
    {
        $customer = $this->customer($request, $customer);
        $dependent = $customer->dependents()->findOrFail($dependent);

        $auditBefore = self::auditable($dependent);
        $dependent->update(CustomerDependent::cleanAlert($request->validate(CustomerDependent::staffRules())));
        [$from, $to] = Audit::changed($auditBefore, self::auditable($dependent));

        if ($from || $to) {
            self::audit($request, 'customer_dependent.updated', $customer, $dependent, $from, $to);
        }

        return response()->json($dependent);
    }

    public function destroy(Request $request, string $customer, string $dependent)
    {
        $customer = $this->customer($request, $customer);
        $row = $customer->dependents()->findOrFail($dependent);
        $row->delete();
        Audit::log($request, 'customer_dependent.deleted', [
            'tenant'       => $request->user()->tenant_id,
            'entity_type'  => 'customer_dependent',
            'entity_id'    => $row->id,
            'entity_label' => $row->name,
            'before'       => ['customer' => trim("{$customer->first_name} {$customer->last_name}")],
        ]);

        return response()->json(['message' => 'Registro eliminado correctamente']);
    }
}
