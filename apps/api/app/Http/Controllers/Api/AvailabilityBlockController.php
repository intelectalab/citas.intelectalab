<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AvailabilityBlock;
use App\Models\Branch;
use App\Models\Professional;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Bloqueos de agenda: ausencias de un profesional o cierres de una sede.
 * Las horas se reciben y se devuelven en la zona horaria de la empresa.
 */
class AvailabilityBlockController extends Controller
{
    private const LOCAL_FORMAT = 'Y-m-d\TH:i';

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->user()->tenant;
        abort_unless($tenant, 403);

        return $tenant;
    }

    public function index(Request $request)
    {
        $tenant = $this->tenant($request);

        $request->validate([
            'professional_id' => 'nullable|uuid',
            'branch_id'       => 'nullable|uuid',
            'from'            => 'nullable|date',
            'to'              => 'nullable|date',
        ]);

        $timezone = $tenant->timezone ?: 'America/Lima';

        // Sin rango, solo los que aún no terminan.
        $from = $request->filled('from') ? Carbon::parse($request->input('from'), $timezone) : now();

        $blocks = AvailabilityBlock::where('tenant_id', $tenant->id)
            ->when($request->filled('professional_id'), fn ($q) => $q->where('professional_id', $request->input('professional_id')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->input('branch_id')))
            ->where('ends_at', '>', $from->toIso8601String())
            ->when($request->filled('to'), fn ($q) => $q->where('starts_at', '<', Carbon::parse($request->input('to'), $timezone)->toIso8601String()))
            ->orderBy('starts_at')
            ->get();

        return response()->json($blocks->map(fn (AvailabilityBlock $block) => $this->present($block, $timezone)));
    }

    public function store(Request $request)
    {
        $tenant = $this->tenant($request);
        $timezone = $tenant->timezone ?: 'America/Lima';

        $validated = $request->validate([
            'professional_id' => 'nullable|uuid|required_without:branch_id',
            'branch_id'       => 'nullable|uuid|required_without:professional_id',
            'starts_at'       => 'required|date_format:' . self::LOCAL_FORMAT,
            'ends_at'         => 'required|date_format:' . self::LOCAL_FORMAT,
            'reason'          => 'required|string|max:255',
        ]);

        if (!empty($validated['professional_id'])) {
            Professional::where('tenant_id', $tenant->id)->findOrFail($validated['professional_id']);
        }
        if (!empty($validated['branch_id'])) {
            Branch::where('tenant_id', $tenant->id)->findOrFail($validated['branch_id']);
        }

        $startsAt = Carbon::createFromFormat(self::LOCAL_FORMAT, $validated['starts_at'], $timezone)->startOfMinute();
        $endsAt = Carbon::createFromFormat(self::LOCAL_FORMAT, $validated['ends_at'], $timezone)->startOfMinute();

        if ($endsAt->lte($startsAt)) {
            throw ValidationException::withMessages(['ends_at' => 'La fecha de fin debe ser posterior a la de inicio.']);
        }

        $block = AvailabilityBlock::create([
            'tenant_id'       => $tenant->id,
            'professional_id' => $validated['professional_id'] ?? null,
            'branch_id'       => $validated['branch_id'] ?? null,
            'starts_at'       => $startsAt->toIso8601String(),
            'ends_at'         => $endsAt->toIso8601String(),
            'reason'          => trim($validated['reason']),
        ]);

        return response()->json($this->present($block->fresh(), $timezone), 201);
    }

    public function destroy(Request $request, string $id)
    {
        AvailabilityBlock::where('tenant_id', $this->tenant($request)->id)->findOrFail($id)->delete();

        return response()->json(['message' => 'Bloqueo eliminado correctamente']);
    }

    private function present(AvailabilityBlock $block, string $timezone): array
    {
        $startsAt = Carbon::parse($block->starts_at)->setTimezone($timezone);
        $endsAt = Carbon::parse($block->ends_at)->setTimezone($timezone);

        return [
            'id'              => $block->id,
            'professional_id' => $block->professional_id,
            'branch_id'       => $block->branch_id,
            'reason'          => $block->reason,
            'starts_at'       => $startsAt->format(self::LOCAL_FORMAT),
            'ends_at'         => $endsAt->format(self::LOCAL_FORMAT),
        ];
    }
}
