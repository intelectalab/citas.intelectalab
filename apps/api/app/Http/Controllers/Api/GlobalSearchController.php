<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Professional;
use App\Models\Service;
use App\Models\User;
use App\Support\Access;
use App\Support\AppointmentFlow;
use App\Support\Search;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GlobalSearchController extends Controller
{
    private const PER_GROUP = 5;

    public function search(Request $request)
    {
        $user = $request->user();
        $tenant = $user->tenant;
        abort_unless($tenant, 403, 'Esta cuenta no pertenece a una empresa.');

        $validated = $request->validate(['q' => 'required|string|min:2|max:100']);
        $term = trim($validated['q']);
        $groups = [];
        $timezone = $tenant->zone();
        $own = $this->ownProfessionalId($user);
        $limit = Access::branchLimit($user);

        if ($user->allows('customers.view') && $tenant->hasModule('crm')) {
            $rows = Customer::where('tenant_id', $tenant->id)
                ->where(function ($q) use ($term, $tenant) {
                    Search::contains($q, "coalesce(first_name, '') || ' ' || coalesce(last_name, '')", $term);
                    foreach (['document_number', 'phone', 'email', 'code'] as $column) {
                        Search::orContains($q, $column, $term);
                    }
                    foreach (\App\Support\Phones::searchVariants($term, \App\Support\Phones::prefixFor($tenant)) as $digits) {
                        $q->orWhereRaw("regexp_replace(coalesce(phone, ''), '[^0-9]', '', 'g') like ?", ['%' . $digits . '%']);
                    }
                })
                ->when($own, fn ($q, $id) => $q->whereExists(fn ($e) => $e->select(DB::raw(1))->from('appointments')->whereColumn('appointments.customer_id', 'customers.id')->where('appointments.professional_id', $id)))
                ->orderBy('first_name')
                ->limit(self::PER_GROUP)
                ->get(['id', 'code', 'first_name', 'last_name', 'phone']);

            $groups[] = ['key' => 'customers', 'items' => $rows->map(fn (Customer $c) => [
                'id'       => $c->id,
                'title'    => \App\Support\Person::name($c),
                'subtitle' => trim($c->code . ($c->phone ? " · {$c->phone}" : '')),
                'url'      => "/customers/{$c->id}",
            ])->all()];
        }

        if ($user->allows('agenda.view') && $tenant->hasModule('agenda')) {
            $rows = Appointment::where('tenant_id', $tenant->id)
                ->when($own, fn ($q, $id) => $q->where('professional_id', $id))
                ->when($limit, fn ($q, $id) => $q->where('branch_id', $id))
                ->where(function ($q) use ($term) {
                    Search::contains($q, 'code', $term)
                        ->orWhereHas('customer', fn ($c) => $c->where(fn ($c) => Search::contains($c, "coalesce(first_name, '') || ' ' || coalesce(last_name, '')", $term)));
                })
                ->with(['customer:id,first_name,last_name', 'service:id,name'])
                ->orderByRaw('abs(extract(epoch from (starts_at - now())))')
                ->limit(self::PER_GROUP)
                ->get();

            $groups[] = ['key' => 'appointments', 'items' => $rows->map(fn (Appointment $a) => [
                'id'       => $a->id,
                'title'    => $a->code . ' · ' . ($a->customer ? \App\Support\Person::name($a->customer) : ''),
                'subtitle' => $a->starts_at->copy()->setTimezone($timezone)->format('d/m/Y H:i') . ' · ' . ($a->service?->name ?? '') . ' · ' . AppointmentFlow::name($tenant, $a->status),
                'url'      => "/agenda?cita={$a->id}",
            ])->all()];
        }

        if ($user->allows('sales.view') && $tenant->hasModule('pos')) {
            $rows = DB::table('sales as s')
                ->leftJoin('customers as c', 'c.id', '=', 's.customer_id')
                ->where('s.tenant_id', $tenant->id)
                ->when($limit, fn ($q, $id) => $q->where('s.branch_id', $id))
                ->where(function ($q) use ($term) {
                    Search::contains($q, 's.code', $term);
                    Search::orContains($q, "coalesce(c.first_name, '') || ' ' || coalesce(c.last_name, '')", $term);
                })
                ->orderByDesc('s.created_at')
                ->limit(self::PER_GROUP)
                ->get(['s.id', 's.code', 's.total', 's.currency', 's.created_at', 'c.first_name', 'c.last_name']);

            $groups[] = ['key' => 'sales', 'items' => $rows->map(fn ($s) => [
                'id'       => $s->id,
                'title'    => $s->code . ($s->first_name ? ' · ' . \App\Support\Person::name($s) : ''),
                'subtitle' => \Illuminate\Support\Carbon::parse($s->created_at)->setTimezone($timezone)->format('d/m/Y') . ' · ' . ($s->currency ?? '') . ' ' . number_format((float) $s->total, 2),
                'url'      => "/sales?venta={$s->id}",
            ])->all()];
        }

        if ($user->allows('services.view') && $tenant->hasModule('agenda')) {
            $rows = Service::where('tenant_id', $tenant->id)
                ->where(fn ($q) => Search::contains($q, 'name', $term)->orWhere(fn ($q) => Search::contains($q, "coalesce(code, '')", $term)))
                ->with('status')
                ->orderBy('name')
                ->limit(self::PER_GROUP)
                ->get();

            $groups[] = ['key' => 'services', 'items' => $rows->map(fn (Service $s) => [
                'id'       => $s->id,
                'title'    => $s->name,
                'subtitle' => trim(($s->code ? "{$s->code} · " : '') . "{$s->duration_minutes} min" . ($s->status?->slug === 'inactive' ? ' · Inactivo' : '')),
                'url'      => '/services?buscar=' . rawurlencode($s->name),
            ])->all()];
        }

        if ($user->allows('professionals.view') && $tenant->hasModule('agenda')) {
            $rows = Professional::where('tenant_id', $tenant->id)
                ->when($own, fn ($q, $id) => $q->where('id', $id))
                ->when($limit, fn ($q, $id) => $q->whereIn('id', Access::professionalsAt($id)))
                ->where(fn ($q) => Search::contains($q, 'display_name', $term))
                ->orderBy('display_name')
                ->limit(self::PER_GROUP)
                ->get(['id', 'display_name']);

            $groups[] = ['key' => 'professionals', 'items' => $rows->map(fn (Professional $p) => [
                'id'       => $p->id,
                'title'    => $p->display_name,
                'subtitle' => '',
                'url'      => "/professionals/{$p->id}",
            ])->all()];
        }

        return response()->json([
            'query'  => $term,
            'groups' => array_values(array_filter($groups, fn (array $group) => $group['items'] !== [])),
        ]);
    }

    private function ownProfessionalId(User $user): ?string
    {
        if ($user->isTenantAdmin() || !$user->roles()->where('slug', 'professional')->exists()) {
            return null;
        }

        return Professional::where('tenant_id', $user->tenant_id)->where('user_id', $user->id)->value('id')
            ?? '00000000-0000-0000-0000-000000000000';
    }
}
