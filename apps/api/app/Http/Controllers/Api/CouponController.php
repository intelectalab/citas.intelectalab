<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Coupons;
use App\Support\Sales;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CouponController extends Controller
{
    private function tenantId(Request $request): string
    {
        $user = $request->user();
        abort_unless($user->tenant_id, 403);
        abort_unless(Sales::ready(), 503, Sales::NOT_READY);

        return $user->tenant_id;
    }

    private function timezone(Request $request): string
    {
        return $request->user()->tenant->timezone ?? 'America/Lima';
    }

    private function present(object $coupon, string $timezone): array
    {
        $day = fn ($value) => $value ? Carbon::parse($value)->setTimezone($timezone)->toDateString() : null;

        return [
            'id'             => $coupon->id,
            'code'           => $coupon->code,
            'description'    => $coupon->description,
            'discount_type'  => $coupon->discount_type,
            'discount_value' => (float) $coupon->discount_value,
            'min_purchase'   => (float) $coupon->min_purchase,
            'max_uses'       => $coupon->max_uses,
            'used_count'     => (int) $coupon->used_count,
            'starts_on'      => $day($coupon->starts_at),
            'ends_on'        => $day($coupon->ends_at),
            'status'         => $coupon->status,
            'state'          => Coupons::state($coupon),
            'created_at'     => Carbon::parse($coupon->created_at)->toIso8601String(),
        ];
    }

    private function validated(Request $request, string $tenantId, ?string $ignoreId = null): array
    {
        $request->merge(['code' => mb_strtoupper(trim((string) $request->input('code')))]);

        $validated = $request->validate([
            'code'           => ['required', 'string', 'max:40', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('coupons', 'code')->where('tenant_id', $tenantId)->ignore($ignoreId)],
            'description'    => 'nullable|string|max:255',
            'discount_type'  => ['required', Rule::in([Coupons::PERCENT, Coupons::AMOUNT])],
            'discount_value' => ['required', 'numeric', 'min:0.01', $request->input('discount_type') === Coupons::PERCENT ? 'max:99' : 'max:99999'],
            'min_purchase'   => 'nullable|numeric|min:0|max:9999999',
            'max_uses'       => 'nullable|integer|min:1|max:1000000',
            'starts_on'      => 'nullable|date_format:Y-m-d',
            'ends_on'        => 'nullable|date_format:Y-m-d|after_or_equal:starts_on',
            'status'         => ['nullable', Rule::in(['active', 'inactive'])],
        ], [
            'code.required'           => 'Escribe el código del cupón.',
            'code.regex'              => 'El código solo lleva letras, números y guiones, sin espacios.',
            'code.unique'             => 'Ya tienes un cupón con ese código.',
            'discount_value.required' => 'Escribe cuánto descuenta.',
            'discount_value.numeric'  => 'Escribe el descuento como un número.',
            'discount_value.min'      => 'El descuento debe ser mayor que cero.',
            'discount_value.max'      => $request->input('discount_type') === Coupons::PERCENT ? 'Un cupón descuenta como máximo 99 %.' : 'Ese descuento es demasiado alto.',
            'max_uses.integer'        => 'Escribe los usos como un número entero.',
            'max_uses.min'            => 'Los usos deben ser al menos 1. Déjalo vacío para no limitarlos.',
            'ends_on.after_or_equal'  => 'La vigencia no puede terminar antes de empezar.',
        ]);

        $timezone = $this->timezone($request);

        return [
            'code'           => $validated['code'],
            'description'    => trim((string) ($validated['description'] ?? '')) ?: null,
            'discount_type'  => $validated['discount_type'],
            'discount_value' => round((float) $validated['discount_value'], 2),
            'min_purchase'   => round((float) ($validated['min_purchase'] ?? 0), 2),
            'max_uses'       => $validated['max_uses'] ?? null,
            'starts_at'      => !empty($validated['starts_on']) ? Carbon::parse($validated['starts_on'], $timezone)->startOfDay()->utc() : null,
            'ends_at'        => !empty($validated['ends_on']) ? Carbon::parse($validated['ends_on'], $timezone)->endOfDay()->utc() : null,
            'status'         => $validated['status'] ?? 'active',
            'updated_at'     => now(),
        ];
    }

    public function index(Request $request)
    {
        $tenantId = $this->tenantId($request);
        $timezone = $this->timezone($request);

        return response()->json([
            'coupons' => DB::table('coupons')->where('tenant_id', $tenantId)->orderByDesc('created_at')->get()->map(fn ($c) => $this->present($c, $timezone))->values(),
        ]);
    }

    public function store(Request $request)
    {
        $tenantId = $this->tenantId($request);
        $id = (string) Str::uuid();

        DB::table('coupons')->insert($this->validated($request, $tenantId) + ['id' => $id, 'tenant_id' => $tenantId, 'created_at' => now()]);

        return response()->json($this->present(DB::table('coupons')->where('id', $id)->first(), $this->timezone($request)), 201);
    }

    public function update(Request $request, string $coupon)
    {
        $tenantId = $this->tenantId($request);
        $row = DB::table('coupons')->where('tenant_id', $tenantId)->where('id', $coupon)->first();
        abort_unless($row, 404);

        DB::table('coupons')->where('id', $row->id)->update($this->validated($request, $tenantId, $row->id));

        return response()->json($this->present(DB::table('coupons')->where('id', $row->id)->first(), $this->timezone($request)));
    }

    public function destroy(Request $request, string $coupon)
    {
        $tenantId = $this->tenantId($request);
        $row = DB::table('coupons')->where('tenant_id', $tenantId)->where('id', $coupon)->first();
        abort_unless($row, 404);

        if (DB::table('coupon_redemptions')->where('coupon_id', $row->id)->exists()) {
            DB::table('coupons')->where('id', $row->id)->update(['status' => 'inactive', 'updated_at' => now()]);

            return response()->json(['deactivated' => true]);
        }

        DB::table('coupons')->where('id', $row->id)->delete();

        return response()->json(['deactivated' => false]);
    }
}
