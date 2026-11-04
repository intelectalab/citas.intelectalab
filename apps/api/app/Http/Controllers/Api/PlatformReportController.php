<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\PlanTransfers;
use App\Support\PlatformReports;
use App\Support\Reports;
use App\Support\Subscriptions;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlatformReportController extends Controller
{
    public function show(Request $request)
    {
        $validated = $request->validate([
            'tab'    => ['sometimes', Rule::in(array_keys(PlatformReports::TABS))],
            'period' => ['sometimes', Rule::in(array_keys(Reports::PERIODS))],
            'from'   => 'required_if:period,custom|nullable|date_format:Y-m-d',
            'to'     => 'required_if:period,custom|nullable|date_format:Y-m-d',
        ], [
            'from.required_if' => 'Elige desde qué día.',
            'to.required_if'   => 'Elige hasta qué día.',
        ]);

        $tab = $validated['tab'] ?? 'income';
        $period = $validated['period'] ?? '30';

        if ($period === 'custom') {
            if ($validated['to'] < $validated['from']) {
                throw ValidationException::withMessages(['to' => 'La fecha final no puede ser anterior a la inicial.']);
            }
            if (Carbon::parse($validated['from'])->diffInDays(Carbon::parse($validated['to'])) >= Reports::MAX_DAYS) {
                throw ValidationException::withMessages(['to' => 'Elige un periodo de hasta un año.']);
            }
        }

        $range = Reports::range(PlatformReports::ZONE, $period, $validated['from'] ?? null, $validated['to'] ?? null);
        $reports = new PlatformReports($range['from'], $range['to'], $range['prev_from'], $range['prev_to']);
        $day = fn (Carbon $date) => $date->format('Y-m-d');

        return response()->json([
            'tab'      => $tab,
            'tabs'     => collect(PlatformReports::TABS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'periods'  => collect(Reports::PERIODS)->map(fn ($label, $key) => ['value' => (string) $key, 'label' => $label])->values(),
            'range'    => [
                'from'      => $day($range['from']),
                'to'        => $day($range['to']->copy()->subDay()),
                'prev_from' => $day($range['prev_from']),
                'prev_to'   => $day($range['prev_to']->copy()->subDay()),
            ],
            'currency' => $reports->currency(),
            'ready'    => ['payments' => Subscriptions::ready(), 'transfers' => PlanTransfers::ready()],
            'data'     => match ($tab) {
                'tenants' => $reports->tenantsTab(),
                'usage'   => $reports->usage(),
                default   => $reports->income(),
            },
        ]);
    }

    public function summary()
    {
        $reports = new PlatformReports();

        return response()->json($reports->summary() + ['ready' => ['payments' => Subscriptions::ready(), 'transfers' => PlanTransfers::ready()]]);
    }
}
