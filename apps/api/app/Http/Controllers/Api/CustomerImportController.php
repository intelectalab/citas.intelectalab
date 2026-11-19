<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\CustomerImport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerImportController extends Controller
{
    public function template()
    {
        return response()->json(CustomerImport::template());
    }

    private function analyze(Request $request): array
    {
        $tenant = $request->user()->tenant;
        abort_unless($tenant, 403);

        $validated = $request->validate([
            'file' => 'required|file|max:5120|mimes:csv,txt,xlsx',
            'mode' => 'nullable|in:update,skip',
        ], [
            'file.required' => 'Elige el archivo con tus clientes.',
            'file.max'      => 'El archivo pesa más de 5 MB. Divídelo en partes más pequeñas.',
            'file.mimes'    => 'El archivo debe ser un Excel (.xlsx) o un CSV.',
        ]);

        try {
            $rows = CustomerImport::read($validated['file']);
        } catch (\RuntimeException $e) {
            abort(422, $e->getMessage());
        }

        $analysis = CustomerImport::analyze($tenant, $rows, $validated['mode'] ?? 'skip');
        abort_if(isset($analysis['error']), 422, $analysis['error'] ?? '');

        return $analysis;
    }

    public function preview(Request $request)
    {
        $analysis = $this->analyze($request);
        $analysis['rows'] = array_map(fn ($row) => array_diff_key($row, ['data' => true]), $analysis['rows']);

        return response()->json($analysis);
    }

    public function store(Request $request)
    {
        $analysis = $this->analyze($request);
        $tenant = $request->user()->tenant;

        if ($analysis['counts']['create'] + $analysis['counts']['update'] === 0) {
            return response()->json(['message' => 'No hay clientes nuevos ni datos para actualizar en el archivo.'], 422);
        }

        $result = DB::transaction(fn () => CustomerImport::apply($tenant, $analysis));

        Audit::log($request, 'customer.imported', [
            'tenant'       => $tenant->id,
            'entity_type'  => 'customer',
            'entity_label' => $request->file('file')?->getClientOriginalName(),
            'after'        => [
                'Archivo'      => $request->file('file')?->getClientOriginalName(),
                'Registrados'  => $result['created'],
                'Actualizados' => $result['updated'],
                'Saltados'     => $analysis['counts']['skip'],
                'Con errores'  => $analysis['counts']['error'],
            ],
        ]);

        return response()->json([
            'message' => "Listo: {$result['created']} registrados y {$result['updated']} actualizados." . ($analysis['counts']['error'] ? " {$analysis['counts']['error']} filas con errores no se importaron." : ''),
            'result'  => $result + ['skipped' => $analysis['counts']['skip'], 'errors' => $analysis['counts']['error']],
        ]);
    }
}
