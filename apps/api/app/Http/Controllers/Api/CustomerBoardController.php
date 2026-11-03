<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\CustomerStages;
use Illuminate\Http\Request;

class CustomerBoardController extends Controller
{
    private function notReady()
    {
        return response()->json(['ready' => false, 'message' => 'Falta actualizar la base de datos para usar el tablero: ejecuta php artisan migrate.']);
    }

    private function payload(Request $request, array $stages): array
    {
        return [
            'ready'            => true,
            'stages'           => $stages,
            'default_stage_id' => CustomerStages::defaultId($stages),
            'colors'           => CustomerStages::COLORS,
            'max_columns'      => CustomerStages::MAX_COLUMNS,
            'can_move'         => $request->user()->allows('customers.edit'),
            'can_edit_columns' => $request->user()->allows('settings.edit'),
        ];
    }

    public function show(Request $request)
    {
        if (!CustomerStages::ready()) {
            return $this->notReady();
        }

        CustomerStages::refreshStale($request->user()->tenant);

        return response()->json($this->payload($request, CustomerStages::stages($request->user()->tenant)));
    }

    public function saveColumns(Request $request)
    {
        abort_unless(CustomerStages::ready(), 503, 'Falta actualizar la base de datos: ejecuta php artisan migrate.');

        $validated = $request->validate([
            'columns'               => 'required|array|min:1|max:' . CustomerStages::MAX_COLUMNS,
            'columns.*.id'          => 'nullable|uuid',
            'columns.*.name'        => 'required|string|max:40',
            'columns.*.description' => 'nullable|string|max:150',
            'columns.*.color'       => 'nullable|string|max:7',
            'moves'                 => 'sometimes|array',
            'moves.*'               => 'nullable|uuid',
        ], [], [
            'columns.*.name'        => 'el nombre de la columna',
            'columns.*.description' => 'la descripción de la columna',
        ]);

        $columns = $validated['columns'];
        ksort($columns);

        $stages = CustomerStages::save($request->user()->tenant, array_values($columns), $validated['moves'] ?? [], $request->user());

        return response()->json($this->payload($request, $stages) + ['message' => 'Columnas guardadas.']);
    }

    public function move(Request $request, string $customer)
    {
        abort_unless(CustomerStages::ready(), 503, 'Falta actualizar la base de datos: ejecuta php artisan migrate.');

        $tenant = $request->user()->tenant;
        $customer = Customer::where('tenant_id', $tenant->id)->findOrFail($customer);

        $validated = $request->validate([
            'stage_id' => ['present', 'nullable', 'string', fn ($attribute, $value, $fail) => $value === null || $value === CustomerStages::AUTO || \Illuminate\Support\Str::isUuid($value) ? null : $fail('Esa columna no es válida.')],
            'source'   => 'sometimes|in:board,detail',
        ]);

        if ($validated['stage_id'] === CustomerStages::AUTO) {
            CustomerStages::release($tenant, $customer->id);
        } else {
            CustomerStages::move($tenant, $customer->id, $validated['stage_id'], $request->user(), $validated['source'] ?? 'board');
        }

        return response()->json([
            'pipeline_stage_id' => $customer->fresh()->pipeline_stage_id,
            'stages'            => CustomerStages::stages($tenant),
        ]);
    }

    public function changes(Request $request, string $customer)
    {
        if (!CustomerStages::ready()) {
            return $this->notReady();
        }

        $tenant = $request->user()->tenant;
        $customer = Customer::where('tenant_id', $tenant->id)->findOrFail($customer);
        $stages = CustomerStages::stages($tenant);

        return response()->json($this->payload($request, $stages) + [
            'stage_id' => $customer->pipeline_stage_id,
            'manual'   => CustomerStages::automatic() ? (bool) $customer->stage_manual_at : null,
            'changes'  => CustomerStages::changes($tenant, $customer->id),
        ]);
    }
}
