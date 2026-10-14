<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Professional;
use App\Models\Status;

class ProfessionalController extends Controller
{
    public function index(Request $request)
    {
        $currentUser = $request->user();

        if (!$currentUser || !$currentUser->tenant_id) {
            return response()->json([]);
        }

        $query = Professional::where('tenant_id', $currentUser->tenant_id)
            ->with(['status', 'specialties', 'user', 'typeDocument']);

        if ($request->filled('search')) {
            $search = $request->input('search');
            $terms = explode(' ', trim($search));

            $query->where(function($q) use ($terms) {
                foreach ($terms as $term) {
                    if (empty($term)) continue;
                    
                    $q->where(function($subQ) use ($term) {
                        $subQ->where('display_name', 'like', "%{$term}%")
                             ->orWhere('license_number', 'like', "%{$term}%")
                             ->orWhere('document_number', 'like', "%{$term}%")
                             ->orWhereHas('user', function($userQuery) use ($term) {
                                 $userQuery->where('first_name', 'like', "%{$term}%")
                                           ->orWhere('last_name', 'like', "%{$term}%")
                                           ->orWhere('email', 'like', "%{$term}%");
                             });
                    });
                }
            });
        }

        if ($request->filled('status')) {
            $status = $request->input('status');
            $query->whereHas('status', function($q) use ($status) {
                $q->where('slug', $status)->orWhere('name', $status);
            });
        }

        $professionals = $query->get();

        return response()->json($professionals);
    }

    /**
     * Mensaje cuando el plan de la empresa ya no admite otro profesional u otra
     * cuenta de usuario; null si todavía hay lugar (o el plan no pone tope).
     */
    private function planLimitReached(?\App\Models\Tenant $tenant): ?string
    {
        $limits = $tenant?->activeSubscription?->plan?->limits ?? [];
        $usage = $tenant ? $tenant->usage() : [];

        foreach (['professionals' => 'profesionales', 'users' => 'cuentas de usuario además de la del dueño'] as $key => $label) {
            $limit = $limits[$key] ?? null;

            if (is_numeric($limit) && ($usage[$key] ?? 0) >= (int) $limit) {
                return "Tu plan permite hasta {$limit} {$label} y ya llegaste a ese número. Para agregar otro, cambia de plan.";
            }
        }

        return null;
    }

    public function store(Request $request)
    {
        $currentUser = $request->user();

        if (!$currentUser || !$currentUser->tenant_id) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        // Cada profesional nuevo ocupa un lugar de profesional y una cuenta de usuario del plan.
        if ($blocked = $this->planLimitReached($currentUser->tenant)) {
            return response()->json(['message' => $blocked, 'code' => 'PLAN_LIMIT'], 422);
        }

        $request->merge([
            'type_document_id' => $request->type_document_id ?: null,
            'status_id' => $request->status_id ?: null,
            'document_number' => $request->document_number ?: null,
            'license_number' => $request->license_number ?: null,
            'phone' => $request->phone ?: null,
        ]);

        $validated = $request->validate([
            'display_name' => 'required|string|max:150',
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'email' => 'required|email|max:150|unique:users,email',
            'type_document_id' => 'nullable|exists:type_documents,id',
            'document_number' => 'nullable|string|max:30',
            'license_number' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:30',
            'status_id' => 'nullable|exists:statuses,id',
            'specialties' => 'nullable|array',
            'specialties.*' => 'exists:specialties,id',
        ]);

        $rawPassword = $validated['document_number'] ?? \Illuminate\Support\Str::random(8);

        $user = \App\Models\User::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $currentUser->tenant_id,
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'email' => $validated['email'],
            'password_hash' => \Illuminate\Support\Facades\Hash::make($rawPassword),
            'status' => 'active',
        ]);

        // Sin este rol la cuenta vería las citas de todos: se crea si la empresa aún no lo tiene,
        // con sus permisos de fábrica.
        \App\Support\Access::ensureRoles($currentUser->tenant);
        $roleProfessional = \App\Models\Role::firstOrCreate(
            ['tenant_id' => $currentUser->tenant_id, 'slug' => 'professional'],
            [
                'id'          => (string) \Illuminate\Support\Str::uuid(),
                'name'        => 'Profesional',
                'description' => 'Atiende sus propias citas',
                'is_system'   => true,
            ]
        );

        if ($roleProfessional) {
            \Illuminate\Support\Facades\DB::table('user_roles')->insert([
                'tenant_id' => $currentUser->tenant_id,
                'user_id' => $user->id,
                'role_id' => $roleProfessional->id,
            ]);
        }

        $statusId = $validated['status_id'] ?? \App\Models\Status::whereHas('module', function($q) {
            $q->where('name', 'professionals');
        })->where('slug', 'active')->value('id');

        $professional = Professional::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'tenant_id' => $currentUser->tenant_id,
            'user_id' => $user->id,
            'type_document_id' => $validated['type_document_id'] ?? null,
            'document_number' => $validated['document_number'] ?? null,
            'display_name' => $validated['display_name'],
            'license_number' => $validated['license_number'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'status_id' => $statusId,
        ]);

        if (isset($validated['specialties'])) {
            $syncData = [];
            foreach ($validated['specialties'] as $specialtyId) {
                $syncData[$specialtyId] = ['tenant_id' => $currentUser->tenant_id];
            }
            $professional->specialties()->sync($syncData);
        }

        return response()->json([
            'professional' => $professional->load(['status', 'specialties', 'user', 'typeDocument']),
            'temporary_password' => $rawPassword,
        ], 201);
    }

    public function show(Request $request, $id)
    {
        $currentUser = $request->user();

        $professional = Professional::where('tenant_id', $currentUser->tenant_id)
            ->with(['status', 'specialties', 'user', 'schedules', 'services', 'typeDocument'])
            ->findOrFail($id);

        return response()->json($professional);
    }

    public function update(Request $request, $id)
    {
        $currentUser = $request->user();

        $professional = Professional::where('tenant_id', $currentUser->tenant_id)
            ->with('user')
            ->findOrFail($id);

        $request->merge([
            'type_document_id' => $request->type_document_id ?: null,
            'status_id' => $request->status_id ?: null,
            'document_number' => $request->document_number ?: null,
            'license_number' => $request->license_number ?: null,
            'phone' => $request->phone ?: null,
        ]);

        $validated = $request->validate([
            'display_name' => 'sometimes|required|string|max:150',
            'first_name' => 'sometimes|required|string|max:100',
            'last_name' => 'sometimes|required|string|max:100',
            'email' => 'sometimes|required|email|max:150|unique:users,email,' . optional($professional->user)->id,
            'type_document_id' => 'nullable|exists:type_documents,id',
            'document_number' => 'nullable|string|max:30',
            'license_number' => 'nullable|string|max:50',
            'phone' => 'nullable|string|max:30',
            'status_id' => 'nullable|exists:statuses,id',
            'specialties' => 'nullable|array',
            'specialties.*' => 'exists:specialties,id',
        ]);

        // 1. Actualizar usuario
        if ($professional->user) {
            $userData = [];
            if (isset($validated['first_name'])) $userData['first_name'] = $validated['first_name'];
            if (isset($validated['last_name'])) $userData['last_name'] = $validated['last_name'];
            if (isset($validated['email'])) $userData['email'] = $validated['email'];
            
            if (!empty($userData)) {
                $professional->user->update($userData);
            }
        }

        // 2. Actualizar profesional
        $professional->update([
            'display_name' => $validated['display_name'] ?? $professional->display_name,
            'type_document_id' => array_key_exists('type_document_id', $validated) ? $validated['type_document_id'] : $professional->type_document_id,
            'document_number' => array_key_exists('document_number', $validated) ? $validated['document_number'] : $professional->document_number,
            'license_number' => array_key_exists('license_number', $validated) ? $validated['license_number'] : $professional->license_number,
            'phone' => array_key_exists('phone', $validated) ? $validated['phone'] : $professional->phone,
            'status_id' => $validated['status_id'] ?? $professional->status_id,
        ]);

        // 3. Sincronizar especialidades
        if (isset($validated['specialties'])) {
            $syncData = [];
            foreach ($validated['specialties'] as $specialtyId) {
                $syncData[$specialtyId] = ['tenant_id' => $currentUser->tenant_id];
            }
            $professional->specialties()->sync($syncData);
        }

        return response()->json($professional->load(['status', 'specialties', 'user', 'typeDocument']));
    }

    public function destroy(Request $request, $id)
    {
        $currentUser = $request->user();

        $professional = Professional::where('tenant_id', $currentUser->tenant_id)
            ->findOrFail($id);

        $professional->delete();

        return response()->json(['message' => 'Professional deleted successfully']);
    }
}