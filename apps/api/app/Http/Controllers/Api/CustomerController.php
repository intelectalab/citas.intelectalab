<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerDependent;
use App\Support\Search;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{

    /**
     * Consulta base de clientes limitada al tenant del usuario autenticado.
     * Garantiza que ningún tenant pueda ver ni manipular clientes de otro.
     */
    private function tenantQuery(Request $request)
    {
        return Customer::where('tenant_id', $request->user()->tenant_id);
    }

    /**
     * Obtiene un cliente por id dentro del tenant actual o responde 404.
     */
    private function findForTenant(Request $request, string $id): Customer
    {
        return $this->tenantQuery($request)->findOrFail($id);
    }

    /**
     * Relaciones que acompañan al cliente. Los dependientes solo viajan si el
     * rubro del tenant los usa.
     */
    private function relations(Request $request): array
    {
        $relations = ['typeDocument', 'status'];

        if ($this->usesDependents($request)) {
            $relations[] = 'dependents';
        }

        return $relations;
    }

    private function usesDependents(Request $request): bool
    {
        return (bool) $request->user()->tenant?->dependents()['enabled'];
    }

    public function index(Request $request)
    {
        $query = $this->tenantQuery($request)->with($this->relations($request));

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                // Sin distinguir mayúsculas ni tildes, y también por nombre completo.
                Search::contains($q, "coalesce(first_name, '') || ' ' || coalesce(last_name, '')", $search);

                foreach (['document_number', 'phone', 'email', 'code'] as $column) {
                    Search::orContains($q, $column, $search);
                }
            });
        }

        if ($biologicalSex = $request->input('biological_sex')) {
            $query->where('biological_sex', $biologicalSex);
        }

        if ($statusId = $request->input('status_id')) {
            $query->where('status_id', $statusId);
        }

        $customers = $query->orderBy('created_at', 'desc')->paginate(10);

        return response()->json($customers);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'birth_date' => 'nullable|date',
            'biological_sex' => 'nullable|string|in:M,F,O',
            'blood_type' => 'nullable|string|max:10',
            'type_document_id' => 'nullable|uuid',
            'document_number' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'emergency_contact_name' => 'nullable|string|max:255',
            'emergency_contact_phone' => 'nullable|string|max:50',
            'status_id' => 'nullable|uuid',
            'manager_user_id' => 'nullable|uuid',
            'pipeline_stage_id' => 'nullable|uuid',
            'address' => 'nullable|string|max:255',
            'district' => 'nullable|string|max:100',
            'department' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'medical_notes' => 'nullable|string',
            // Mascotas u otros dependientes registrados junto con el cliente.
            'dependents' => 'sometimes|array|max:20',
        ] + CustomerDependent::rules('dependents.*.'));

        $dependents = $validated['dependents'] ?? [];
        unset($validated['dependents']);

        try {
            DB::beginTransaction();

            $tenantId = $request->user()->tenant_id ?? null;
            

            $validated['code'] = Customer::nextCode($tenantId);
            $validated['tenant_id'] = $tenantId;

            $customer = Customer::create($validated);

            if ($this->usesDependents($request)) {
                $kind = $request->user()->tenant->dependents()['kind'];

                foreach ($dependents as $dependent) {
                    $customer->dependents()->create($dependent + ['tenant_id' => $tenantId, 'kind' => $kind]);
                }
            }

            DB::commit();

            return response()->json([
                'message' => 'Cliente registrado con éxito',
                'customer' => $customer->load($this->relations($request))
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al registrar el cliente',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(Request $request, string $customer)
    {
        $customer = $this->findForTenant($request, $customer);

        return response()->json([
            'customer' => $customer->load($this->relations($request))
        ]);
    }

    public function update(Request $request, string $customer)
    {
        $customer = $this->findForTenant($request, $customer);

        $validated = $request->validate([
            'first_name' => 'sometimes|required|string|max:255',
            'last_name' => 'sometimes|required|string|max:255',
            'birth_date' => 'nullable|date',
            'biological_sex' => 'nullable|string|in:M,F,O',
            'blood_type' => 'nullable|string|max:10',
            'type_document_id' => 'nullable|uuid',
            'document_number' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'emergency_contact_name' => 'nullable|string|max:255',
            'emergency_contact_phone' => 'nullable|string|max:50',
            'status_id' => 'nullable|uuid',
            'manager_user_id' => 'nullable|uuid',
            'pipeline_stage_id' => 'nullable|uuid',
            'address' => 'nullable|string|max:255',
            'district' => 'nullable|string|max:100',
            'department' => 'nullable|string|max:100',
            'postal_code' => 'nullable|string|max:20',
            'medical_notes' => 'nullable|string',
        ]);

        $customer->update($validated);

        return response()->json([
            'message' => 'Cliente actualizado con éxito',
            'customer' => $customer->load($this->relations($request))
        ]);
    }


    public function destroy(Request $request, string $customer)
    {
        $customer = $this->findForTenant($request, $customer);
        $customer->delete();

        return response()->json([
            'message' => 'Cliente eliminado correctamente'
        ]);
    }
}