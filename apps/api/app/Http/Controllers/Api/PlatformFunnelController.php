<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\WebLeads;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PlatformFunnelController extends Controller
{
    public const EXPIRING_DAYS = 3;

    public function extras(Request $request)
    {
        $values = $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);
        $zone = 'America/Lima';
        $start = isset($values['from']) ? Carbon::parse($values['from'], $zone)->startOfDay() : now($zone)->subDays(29)->startOfDay();
        $end = isset($values['to']) ? Carbon::parse($values['to'], $zone)->endOfDay() : now($zone)->endOfDay();

        $expiring = DB::table('subscriptions as s')
            ->join('tenants as t', 't.id', '=', 's.tenant_id')
            ->join('saas_plans as p', 'p.id', '=', 's.plan_id')
            ->whereNull('t.deleted_at')
            ->where('s.status', 'trialing')
            ->whereNotNull('s.trial_ends_at')
            ->whereBetween('s.trial_ends_at', [now(), now()->addDays(self::EXPIRING_DAYS)->endOfDay()])
            ->when(Schema::hasColumn('subscriptions', 'card_status'), fn ($q) => $q->where(fn ($w) => $w->whereNull('s.card_status')->orWhere('s.card_status', '!=', 'authorized')))
            ->orderBy('s.trial_ends_at')
            ->limit(100)
            ->get(['t.id', 't.name', 's.trial_ends_at', 'p.name as plan']);

        $owners = $expiring->isEmpty() ? collect() : DB::table('users')->whereIn('tenant_id', $expiring->pluck('id'))->where('is_owner', true)->get(['tenant_id', 'first_name', 'last_name', 'email', 'phone'])->keyBy('tenant_id');
        $contacts = $expiring->isEmpty() ? collect() : DB::table('tenant_contacts')->whereIn('tenant_id', $expiring->pluck('id'))->orderBy('created_at')->get(['tenant_id', 'phone'])->groupBy('tenant_id');

        $leads = WebLeads::ready() ? DB::table('web_leads')->whereBetween('created_at', [$start->copy()->utc(), $end->copy()->utc()]) : null;

        return response()->json([
            'days'     => self::EXPIRING_DAYS,
            'expiring' => $expiring->map(function ($row) use ($owners, $contacts, $zone) {
                $owner = $owners[$row->id] ?? null;
                $phone = $owner?->phone ?: ($contacts[$row->id] ?? collect())->pluck('phone')->filter()->first();
                $ends = Carbon::parse($row->trial_ends_at);

                return [
                    'tenant_id' => $row->id,
                    'name'      => $row->name,
                    'plan'      => $row->plan,
                    'ends_on'   => $ends->copy()->setTimezone($zone)->format('Y-m-d'),
                    'days_left' => max(0, (int) ceil(now()->diffInSeconds($ends, false) / 86400)),
                    'owner'     => $owner ? trim("{$owner->first_name} {$owner->last_name}") : null,
                    'email'     => $owner?->email,
                    'phone'     => $phone,
                ];
            })->values(),
            'chatbot'  => $leads ? (clone $leads)->where('source', 'chatbot')->count() : 0,
            'coupon'   => $leads && Schema::hasColumn('web_leads', 'coupon') ? (clone $leads)->whereNotNull('coupon')->where('coupon', '!=', '')->count() : 0,
        ]);
    }
}
