<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PatientController extends Controller
{

    /**
     * Consulta base de pacientes limitada al tenant del usuario autenticado.
     * Garantiza que ningún tenant pueda ver ni manipular pacientes de otro.
     */
    private function tenantQuery(Request $request)
    {
        return Patient::where('tenant_id', $request->user()->tenant_id);
    }

    /**
     * Obtiene un paciente por id dentro del tenant actual o responde 404.
     */
    private function findForTenant(Request $request, string $id): Patient
    {
        return $this->tenantQuery($request)->findOrFail($id);
    }

    public function index(Request $request)
    {
        $query = $this->tenantQuery($request)->with(['typeDocument', 'status']);

        if ($search = $request->input('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('document_number', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('code', 'like', "%{$search}%");
            });
        }

        if ($biologicalSex = $request->input('biological_sex')) {
            $query->where('biological_sex', $biologicalSex);
        }

        if ($statusId = $request->input('status_id')) {
            $query->where('status_id', $statusId);
        }

        $patients = $query->orderBy('created_at', 'desc')->paginate(10);

        return response()->json($patients);
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
        ]);

        try {
            DB::beginTransaction();

            $tenantId = $request->user()->tenant_id ?? null;
            
            $lastPatient = Patient::where('tenant_id', $tenantId)
                ->orderBy('created_at', 'desc')
                ->first();

            $nextNumber = 1;
            if ($lastPatient && preg_match('/PAC-(\d+)/', $lastPatient->code, $matches)) {
                $nextNumber = intval($matches[1]) + 1;
            }
            
            $validated['code'] = 'PAC-' . str_pad($nextNumber, 4, '0', STR_PAD_LEFT);
            $validated['tenant_id'] = $tenantId;

            $patient = Patient::create($validated);

            DB::commit();

            return response()->json([
                'message' => 'Paciente registrado con éxito',
                'patient' => $patient->load(['typeDocument', 'status'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'message' => 'Error al registrar el paciente',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(Request $request, string $patient)
    {
        $patient = $this->findForTenant($request, $patient);

        return response()->json([
            'patient' => $patient->load(['typeDocument', 'status'])
        ]);
    }

    public function update(Request $request, string $patient)
    {
        $patient = $this->findForTenant($request, $patient);

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

        $patient->update($validated);

        return response()->json([
            'message' => 'Paciente actualizado con éxito',
            'patient' => $patient->load(['typeDocument', 'status'])
        ]);
    }


    public function destroy(Request $request, string $patient)
    {
        $patient = $this->findForTenant($request, $patient);
        $patient->delete();

        return response()->json([
            'message' => 'Paciente eliminado correctamente'
        ]);
    }
}