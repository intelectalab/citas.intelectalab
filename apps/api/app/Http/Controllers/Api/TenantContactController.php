<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TenantContact;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class TenantContactController extends Controller
{
    public function index(Request $request)
    {
        $query = TenantContact::with('tenant');

        if ($request->has('tenant_id') && !empty($request->query('tenant_id'))) {
            $query->where('tenant_id', $request->query('tenant_id'));
        }

        if ($request->has('search')) {
            $search = $request->query('search');
            $query->where(function($q) use ($search) {
                $q->where('first_name', 'like', "%{$search}%")
                  ->orWhere('last_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $query->latest();

        return response()->json($query->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'tenant_id'  => 'required|uuid|exists:tenants,id', 
            'first_name' => 'required|string|max:250',
            'last_name'  => 'required|string|max:250',
            'email'      => 'required|email|max:250',
            'phone'      => 'nullable|string|max:50',
            'position'   => 'nullable|string|max:150',
        ]);

        $contact = TenantContact::create($validated);

        return response()->json([
            'message' => 'Contacto registrado exitosamente para la empresa',
            'data'    => $contact
        ], Response::HTTP_CREATED);
    }

    public function show(TenantContact $tenantContact)
    {
        return response()->json($tenantContact->load('tenant'));
    }

    public function update(Request $request, TenantContact $tenantContact)
    {
        $validated = $request->validate([
            'tenant_id'  => 'required|uuid|exists:tenants,id',
            'first_name' => 'required|string|max:250',
            'last_name'  => 'required|string|max:250',
            'email'      => 'required|email|max:250',
            'phone'      => 'nullable|string|max:50',
            'position'   => 'nullable|string|max:150',
        ]);

        $tenantContact->update($validated);

        return response()->json([
            'message' => 'Contacto actualizado exitosamente',
            'data'    => $tenantContact
        ]);
    }

    public function destroy(TenantContact $tenantContact)
    {
        $tenantContact->delete();

        return response()->json([
            'message' => 'Contacto eliminado exitosamente'
        ], Response::HTTP_OK);
    }
}