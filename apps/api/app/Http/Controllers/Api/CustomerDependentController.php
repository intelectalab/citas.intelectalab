<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerDependent;
use Illuminate\Http\Request;

/**
 * Dependientes de un cliente (mascotas, hijos). Solo responde a los tenants
 * cuyo rubro los activa, y siempre dentro del tenant del usuario.
 */
class CustomerDependentController extends Controller
{
    private function customer(Request $request, string $customerId): Customer
    {
        $tenant = $request->user()->tenant;

        abort_unless($tenant && $tenant->dependents()['enabled'], 403, 'Tu rubro no usa dependientes.');

        return Customer::where('tenant_id', $tenant->id)->findOrFail($customerId);
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

        return response()->json($dependent, 201);
    }

    public function update(Request $request, string $customer, string $dependent)
    {
        $customer = $this->customer($request, $customer);
        $dependent = $customer->dependents()->findOrFail($dependent);

        $dependent->update($request->validate(CustomerDependent::rules()));

        return response()->json($dependent);
    }

    public function destroy(Request $request, string $customer, string $dependent)
    {
        $customer = $this->customer($request, $customer);
        $customer->dependents()->findOrFail($dependent)->delete();

        return response()->json(['message' => 'Registro eliminado correctamente']);
    }
}
