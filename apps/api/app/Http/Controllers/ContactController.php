<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    public function index(Request $request)
{
    $currentUser = $request->user();

    $query = Customer::where('tenant_id', $currentUser->tenant_id);

    if ($request->filled('search')) {
        $search = $request->search;
        $query->where(function($q) use ($search) {
            $q->where('first_name', 'like', "%{$search}%")
              ->orWhere('last_name', 'like', "%{$search}%")
              ->orWhere('email', 'like', "%{$search}%")
              ->orWhere('document_number', 'like', "%{$search}%");
        });
    }

    if ($request->filled('owner_id')) {
        $query->where('manager_user_id', $request->owner_id);
    }

    if ($request->filled('lead_status')) {
        $query->where('pipeline_stage_id', $request->lead_status);
    }

    if ($request->filled('created_at')) {
        $query->whereDate('created_at', $request->created_at);
    }

   return response()->json($query->orderBy('created_at', 'desc')->get());
}

    // Crear un nuevo contacto
    public function store(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'type_document_id'  => 'nullable|uuid|exists:type_documents,id',
            'document_number'   => 'nullable|string|max:30',
            'first_name'        => 'required|string|max:250',
            'last_name'         => 'required|string|max:250',
            'email'             => 'nullable|email|max:250',
            'phone'             => 'nullable|string|max:50',
            'manager_user_id'   => 'nullable|uuid|exists:users,id',
            'pipeline_stage_id' => 'nullable|uuid|exists:pipeline_stages,id',
            'address'           => 'nullable|string|max:255',
            'district'          => 'nullable|string|max:100',
            'department'        => 'nullable|string|max:100',
            'postal_code'       => 'nullable|string|max:20',
        ]);

        $contact = Customer::create([
            'tenant_id'         => $user->tenant_id,
            'type_document_id'  => $validated['type_document_id'] ?? null,
            'document_number'   => $validated['document_number'] ?? null,
            'first_name'        => $validated['first_name'],
            'last_name'         => $validated['last_name'],
            'email'             => $validated['email'] ?? null,
            'phone'             => $validated['phone'] ?? null,
            'manager_user_id'   => $validated['manager_user_id'] ?? null,
            'pipeline_stage_id' => $validated['pipeline_stage_id'] ?? null,
            'address'           => $validated['address'] ?? null,
            'district'          => $validated['district'] ?? null,
            'department'        => $validated['department'] ?? null,
            'postal_code'       => $validated['postal_code'] ?? null,
        ]);

        return response()->json([
            'message' => 'Contacto creado con éxito',
            'contact' => $contact->load(['typeDocument', 'managerUser', 'pipelineStage'])
        ], 201);
    }

    // Actualizar un contacto existente
    public function update(Request $request, $id)
    {
        $user = $request->user();

        $contact = Customer::where('tenant_id', $user->tenant_id)->where('id', $id)->firstOrFail();

        $validated = $request->validate([
            'type_document_id'  => 'nullable|uuid|exists:type_documents,id',
            'document_number'   => 'nullable|string|max:30',
            'first_name'        => 'required|string|max:250',
            'last_name'         => 'required|string|max:250',
            'email'             => 'nullable|email|max:250',
            'phone'             => 'nullable|string|max:50',
            'manager_user_id'   => 'nullable|uuid|exists:users,id',
            'pipeline_stage_id' => 'nullable|uuid|exists:pipeline_stages,id',
            'address'           => 'nullable|string|max:255',
            'district'          => 'nullable|string|max:100',
            'department'        => 'nullable|string|max:100',
            'postal_code'       => 'nullable|string|max:20',
        ]);

        $contact->update($validated);

        return response()->json([
            'message' => 'Contacto actualizado con éxito',
            'contact' => $contact->load(['typeDocument', 'managerUser', 'pipelineStage'])
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $user = $request->user();

        $contact = Customer::where('tenant_id', $user->tenant_id)->where('id', $id)->firstOrFail();
        $contact->delete();

        return response()->json([
            'message' => 'Contacto eliminado con éxito'
        ]);
    }
}