<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Professional;
use App\Support\Documents;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProfessionalSignatureController extends Controller
{
    private function professional(Request $request, string $id): Professional
    {
        return Professional::where('tenant_id', $request->user()->tenant_id)->findOrFail($id);
    }

    public function show(Request $request, string $professional)
    {
        $path = Documents::professionalSignaturePath($this->professional($request, $professional));
        abort_unless($path, 404);

        return response()->file(Storage::disk('local')->path($path), ['Cache-Control' => 'private, no-store']);
    }

    public function store(Request $request, string $professional)
    {
        $professional = $this->professional($request, $professional);
        Documents::storeProfessionalSignature($request, $professional, BrandingController::validateSignature($request));

        return response()->json(['signature_url' => $professional->signature_url]);
    }

    public function destroy(Request $request, string $professional)
    {
        Documents::removeProfessionalSignature($request, $this->professional($request, $professional));

        return response()->json(['signature_url' => null]);
    }
}
