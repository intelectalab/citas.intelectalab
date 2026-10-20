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

    private function tenantQuery(Request $request)
    {
        return Customer::where('tenant_id', $request->user()->tenant_id);
    }

    private function findForTenant(Request $request, string $id): Customer
    {
        return $this->tenantQuery($request)->findOrFail($id);
    }

    private function relations(Request $request): array
    {
        $relations = ['typeDocument', 'status'];

        if ($this->usesDependents($request)) {
            $relations[] = 'dependents';
        }

        return $relations;
    }

    private function withoutClinicalFields(Request $request, array $validated): array
    {
        if (empty($request->user()->tenant?->rules()['customers.clinical_fields'])) {
            unset($validated['blood_type']);
        }

        return $validated;
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

        $perPage = min(max((int) $request->input('per_page', 10), 1), 500);

        $customers = $query->orderBy('created_at', 'desc')->paginate($perPage);

        if ($tenant = $request->user()->tenant) {
            $states = \App\Support\OnlineBooking::states($tenant, $customers->getCollection()->pluck('id')->all());
            $customers->getCollection()->each(fn (Customer $c) => $c->setAttribute('online_booking', $states[$c->id] ?? null));
        }

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
            'dependents' => 'sometimes|array|max:20',
        ] + CustomerDependent::rules('dependents.*.'));

        $dependents = $validated['dependents'] ?? [];
        unset($validated['dependents']);
        $validated = $this->withoutClinicalFields($request, $validated);

        try {
            DB::beginTransaction();

            $tenantId = $request->user()->tenant_id ?? null;
            

            $validated['code'] = Customer::nextCode($tenantId);
            $validated['tenant_id'] = $tenantId;

            $customer = Customer::create($validated);

            $customer->syncPortalPassword();

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
        $tenant = $request->user()->tenant;

        $customer->setAttribute('online_booking', $tenant ? \App\Support\OnlineBooking::state($tenant, $customer->id) : null);

        return response()->json([
            'customer' => $customer->load($this->relations($request)),
            'can_forgive_booking' => $request->user()->allows('customers.forgive') && \App\Support\OnlineBooking::canForgive(),
            'consents' => \App\Support\Messaging::ready() ? MessageController::consentsOf($customer->id) : null,
        ]);
    }

    public function forgiveBooking(Request $request, string $customer)
    {
        abort_unless($request->user()->allows('customers.forgive'), 403, 'Tu cuenta no tiene permiso para perdonar inasistencias.');
        abort_unless(\App\Support\OnlineBooking::canForgive(), 503, 'Falta actualizar la base de datos: carga init.sql, seed.sql y seed_demo.sql.');

        $customer = $this->findForTenant($request, $customer);
        \Illuminate\Support\Facades\DB::table('customers')->where('id', $customer->id)->update(['booking_strikes_reset_at' => now()]);

        return response()->json(['online_booking' => null]);
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

        $customer->update($this->withoutClinicalFields($request, $validated));

        $customer->syncPortalPassword($customer->wasChanged('document_number'));

        return response()->json([
            'message' => 'Cliente actualizado con éxito',
            'customer' => $customer->load($this->relations($request))
        ]);
    }


    public function portalAccess(Request $request, string $customer)
    {
        $customer = $this->findForTenant($request, $customer);
        $validated = $request->validate(['enabled' => 'required|boolean']);

        $turnedOn = $validated['enabled'] && $customer->portal_enabled !== true;
        $customer->forceFill(['portal_enabled' => $validated['enabled']])->save();

        if (!$validated['enabled']) {
            $customer->tokens()->delete();
        } else {
            $customer->syncPortalPassword();

            if ($turnedOn && $request->user()->tenant) {
                \App\Support\Messaging::portalAccess($request->user()->tenant, $customer, $request->user());
            }
        }

        return response()->json([
            'message'  => $validated['enabled'] ? 'Acceso al portal encendido' : 'Acceso al portal apagado',
            'customer' => $customer->load($this->relations($request)),
        ]);
    }

    public function resetPortalPassword(Request $request, string $customer)
    {
        $customer = $this->findForTenant($request, $customer);

        if (!$customer->resetPortalPassword()) {
            return response()->json([
                'message' => 'Para darle acceso al portal, registra su correo y su número de documento.',
                'code'    => 'MISSING_DATA',
            ], 422);
        }

        $customer->tokens()->delete();

        if ($request->user()->tenant) {
            \App\Support\Messaging::portalAccess($request->user()->tenant, $customer, $request->user());
        }

        return response()->json([
            'message'  => 'Su contraseña volvió a ser su número de documento.',
            'customer' => $customer->load($this->relations($request)),
        ]);
    }

    public function destroy(Request $request, string $customer)
    {
        $customer = $this->findForTenant($request, $customer);
        $customer->tokens()->delete();
        $customer->delete();

        return response()->json([
            'message' => 'Cliente eliminado correctamente'
        ]);
    }
}