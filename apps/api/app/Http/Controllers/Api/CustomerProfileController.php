<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Tenant;
use App\Support\Audit;
use App\Support\Currency;
use App\Support\CustomerInsights;
use App\Support\CustomerProfile;
use App\Support\Loyalty;
use App\Support\Person;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CustomerProfileController extends Controller
{
    private function tenant(Request $request): Tenant
    {
        $tenant = $request->user()->tenant;
        abort_unless($tenant, 403);

        return $tenant;
    }

    private function customer(Request $request, string $id): Customer
    {
        $this->tenant($request);

        return Customer::visibleTo($request->user())->findOrFail($id);
    }

    public function show(Request $request, string $customer)
    {
        $tenant = $this->tenant($request);
        $row = $this->customer($request, $customer);
        $user = $request->user();
        $canMoney = $user->allows('sales.view');
        $loyalty = Loyalty::enabled($tenant);
        $points = $loyalty ? Loyalty::customerPoints($tenant->id, $row->id) : null;

        return response()->json([
            'currency'        => Currency::present(Currency::of($tenant)),
            'money'           => $canMoney ? CustomerInsights::money($tenant, $row, CustomerProfile::money($tenant, $row->id)) : null,
            'points'          => $points,
            'credit'          => $canMoney ? \App\Support\CustomerCredit::summary($tenant->id, $row->id) : null,
            'points_value'    => $loyalty ? round($points * Loyalty::rules($tenant)['point_value'], 2) : null,
            'preferences'     => CustomerInsights::preferences($tenant, $row),
            'recalls'         => $user->allows('recalls.view') && $tenant->hasModule('agenda') ? CustomerInsights::recalls($tenant, $row) : null,
            'memberships'     => \App\Support\Memberships::enabled($tenant) && $user->allows('memberships.view') ? CustomerInsights::memberships($tenant, $row) : null,
            'quotes'          => \App\Support\Quotes::enabled($tenant) && $user->allows('quotes.view') ? CustomerInsights::quotes($tenant, $row, $user) : null,
            'loyalty'         => $loyalty && $user->allows('loyalty.view') ? CustomerInsights::loyalty($tenant, $row, $user) : null,
            'messages'        => $user->allows('messages.view') ? CustomerInsights::messages($tenant, $row) : null,
            'segments'        => CustomerProfile::segments($tenant, $row->id),
            'tags'            => CustomerProfile::tagsOf($row->id),
            'tags_ready'      => CustomerProfile::tagsReady(),
            'purchases'       => $canMoney ? CustomerProfile::purchases($tenant, $row->id) : null,
            'notes_ready'     => CustomerProfile::notesReady(),
            'consent_history' => CustomerProfile::consentHistory($tenant, $row->id),
            'consent_history_ready' => CustomerProfile::consentEventsReady(),
            'fields_ready'    => CustomerProfile::fieldsReady(),
            'can'             => [
                'private_notes' => $user->allows('customers.notes'),
                'write_notes'   => $user->allows('customers.edit') || $user->allows('customers.notes'),
                'tags'          => $user->allows('customers.edit'),
            ],
        ]);
    }

    public function notes(Request $request, string $customer)
    {
        abort_unless(CustomerProfile::notesReady(), 503, CustomerProfile::NOT_READY);
        $row = $this->customer($request, $customer);

        return response()->json(['notes' => CustomerProfile::notes($this->tenant($request), $row->id, $request->user())]);
    }

    public function storeNote(Request $request, string $customer)
    {
        abort_unless(CustomerProfile::notesReady(), 503, CustomerProfile::NOT_READY);
        $tenant = $this->tenant($request);
        $row = $this->customer($request, $customer);
        $validated = $request->validate([
            'body'       => 'required|string|max:4000',
            'is_private' => 'boolean',
        ], ['body.required' => 'Escribe la nota.', 'body.max' => 'La nota es muy larga: hasta 4000 letras.']);

        $private = (bool) ($validated['is_private'] ?? false);
        $user = $request->user();
        abort_unless($private ? $user->allows('customers.notes') : $user->allows('customers.edit'), 403, $private ? 'Tu cuenta no tiene permiso para escribir notas privadas.' : 'Tu cuenta no tiene permiso para escribir notas.');

        DB::table('customer_notes')->insert([
            'id'          => (string) Str::uuid(),
            'tenant_id'   => $tenant->id,
            'customer_id' => $row->id,
            'body'        => trim($validated['body']),
            'is_private'  => $private,
            'author_id'   => $user->id,
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        if ($private) {
            Audit::log($request, 'customer.private_note_added', ['tenant' => $tenant->id, 'entity_type' => 'customer', 'entity_id' => $row->id, 'entity_label' => Person::name($row)]);
        }

        return response()->json(['message' => 'Nota guardada.', 'notes' => CustomerProfile::notes($tenant, $row->id, $user)], 201);
    }

    public function destroyNote(Request $request, string $customer, string $note)
    {
        abort_unless(CustomerProfile::notesReady(), 503, CustomerProfile::NOT_READY);
        $tenant = $this->tenant($request);
        $row = $this->customer($request, $customer);
        $item = DB::table('customer_notes')->where('tenant_id', $tenant->id)->where('customer_id', $row->id)->where('id', $note)->whereNull('deleted_at')->first();
        abort_unless($item, 404, 'Esa nota ya no existe.');

        $user = $request->user();
        $allowed = $item->author_id === $user->id || $user->isTenantAdmin();
        abort_unless($allowed && (!$item->is_private || $user->allows('customers.notes')), 403, 'Solo quien escribió la nota o un administrador puede borrarla.');

        DB::table('customer_notes')->where('id', $item->id)->update(['deleted_at' => now(), 'updated_at' => now()]);

        return response()->json(['message' => 'Nota borrada.', 'notes' => CustomerProfile::notes($tenant, $row->id, $user)]);
    }

    public function tags(Request $request)
    {
        $tenant = $this->tenant($request);

        return response()->json(['ready' => CustomerProfile::tagsReady(), 'tags' => CustomerProfile::tags($tenant->id), 'colors' => CustomerProfile::TAG_COLORS]);
    }

    private function tagRules(Tenant $tenant, ?string $exceptId = null): array
    {
        return [
            'name'  => ['required', 'string', 'max:40', Rule::unique('customer_tags', 'name')->where('tenant_id', $tenant->id)->ignore($exceptId)],
            'color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ];
    }

    private const TAG_MESSAGES = [
        'name.required' => 'Escribe el nombre de la etiqueta.',
        'name.unique'   => 'Ya tienes una etiqueta con ese nombre.',
        'name.max'      => 'El nombre de la etiqueta debe ser corto: hasta 40 letras.',
        'color.regex'   => 'Elige uno de los colores.',
    ];

    public function storeTag(Request $request)
    {
        abort_unless(CustomerProfile::tagsReady(), 503, CustomerProfile::NOT_READY);
        $tenant = $this->tenant($request);
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $validated = $request->validate($this->tagRules($tenant), self::TAG_MESSAGES);
        $id = (string) Str::uuid();

        DB::table('customer_tags')->insert([
            'id'         => $id,
            'tenant_id'  => $tenant->id,
            'name'       => $validated['name'],
            'color'      => $validated['color'] ?? CustomerProfile::TAG_COLORS[0],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Audit::log($request, 'customer_tag.created', ['tenant' => $tenant->id, 'entity_type' => 'customer_tag', 'entity_id' => $id, 'entity_label' => $validated['name']]);

        return response()->json(['tag' => ['id' => $id, 'name' => $validated['name'], 'color' => $validated['color'] ?? CustomerProfile::TAG_COLORS[0]], 'tags' => CustomerProfile::tags($tenant->id)], 201);
    }

    public function updateTag(Request $request, string $tag)
    {
        abort_unless(CustomerProfile::tagsReady(), 503, CustomerProfile::NOT_READY);
        $tenant = $this->tenant($request);
        $row = DB::table('customer_tags')->where('tenant_id', $tenant->id)->where('id', $tag)->first();
        abort_unless($row, 404, 'Esa etiqueta ya no existe.');
        $request->merge(['name' => trim((string) $request->input('name'))]);
        $validated = $request->validate($this->tagRules($tenant, $row->id), self::TAG_MESSAGES);

        DB::table('customer_tags')->where('id', $row->id)->update(['name' => $validated['name'], 'color' => $validated['color'] ?? $row->color, 'updated_at' => now()]);

        [$from, $to] = Audit::changed(['name' => $row->name, 'color' => $row->color], ['name' => $validated['name'], 'color' => $validated['color'] ?? $row->color]);

        if ($from || $to) {
            Audit::log($request, 'customer_tag.updated', ['tenant' => $tenant->id, 'entity_type' => 'customer_tag', 'entity_id' => $row->id, 'entity_label' => $validated['name'], 'before' => $from, 'after' => $to]);
        }

        return response()->json(['tags' => CustomerProfile::tags($tenant->id)]);
    }

    public function destroyTag(Request $request, string $tag)
    {
        abort_unless(CustomerProfile::tagsReady(), 503, CustomerProfile::NOT_READY);
        $tenant = $this->tenant($request);
        $row = DB::table('customer_tags')->where('tenant_id', $tenant->id)->where('id', $tag)->first();
        abort_unless($row, 404, 'Esa etiqueta ya no existe.');

        DB::transaction(function () use ($row) {
            DB::table('customer_tag_assignments')->where('customer_tag_id', $row->id)->delete();
            DB::table('customer_tags')->where('id', $row->id)->delete();
        });

        Audit::log($request, 'customer_tag.deleted', ['tenant' => $tenant->id, 'entity_type' => 'customer_tag', 'entity_id' => $row->id, 'entity_label' => $row->name]);

        return response()->json(['tags' => CustomerProfile::tags($tenant->id)]);
    }

    public function assignTags(Request $request, string $customer)
    {
        abort_unless(CustomerProfile::tagsReady(), 503, CustomerProfile::NOT_READY);
        $tenant = $this->tenant($request);
        $row = $this->customer($request, $customer);
        $validated = $request->validate([
            'tag_ids'   => 'present|array|max:30',
            'tag_ids.*' => ['uuid', Rule::exists('customer_tags', 'id')->where('tenant_id', $tenant->id)],
        ], ['tag_ids.*.exists' => 'Una de las etiquetas ya no existe.']);

        $before = collect(CustomerProfile::tagsOf($row->id))->pluck('name')->all();
        $ids = array_values(array_unique($validated['tag_ids']));

        DB::transaction(function () use ($tenant, $row, $ids) {
            DB::table('customer_tag_assignments')->where('customer_id', $row->id)->whereNotIn('customer_tag_id', $ids ?: ['00000000-0000-0000-0000-000000000000'])->delete();
            DB::table('customer_tag_assignments')->insertOrIgnore(array_map(fn ($id) => ['tenant_id' => $tenant->id, 'customer_id' => $row->id, 'customer_tag_id' => $id], $ids));
        });

        $tags = CustomerProfile::tagsOf($row->id);
        $after = collect($tags)->pluck('name')->all();

        if ($before !== $after) {
            Audit::log($request, 'customer.tags_changed', [
                'tenant' => $tenant->id, 'entity_type' => 'customer', 'entity_id' => $row->id, 'entity_label' => Person::name($row),
                'before' => ['Etiquetas' => implode(', ', $before) ?: 'Ninguna'],
                'after'  => ['Etiquetas' => implode(', ', $after) ?: 'Ninguna'],
            ]);
        }

        return response()->json(['tags' => $tags]);
    }
}
