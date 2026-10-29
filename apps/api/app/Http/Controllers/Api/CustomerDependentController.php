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

        return Customer::where('tenant_id', $tenant->id)->findOrFail($customerId);
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

        return response()->json($customer->dependents()->orderBy('name')->get());
    }

    public function store(Request $request, string $customer)
    {
        $customer = $this->customer($request, $customer);
        $validated = $request->validate(CustomerDependent::rules());

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
        $dependent->update($request->validate(CustomerDependent::rules()));
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
