<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Professional;
use App\Models\Tenant;
use App\Support\ApiKeys;
use App\Support\Audit;
use App\Support\CalendarFeeds;
use App\Support\Person;
use App\Support\Webhooks;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ApiIntegrationController extends Controller
{
    private function tenant(Request $request): Tenant
    {
        $tenant = $request->user()->tenant;
        abort_unless($tenant, 403);
        abort_unless(ApiKeys::ready(), 409, ApiKeys::NOT_READY);

        return $tenant;
    }

    private function users(array $ids): array
    {
        $ids = array_values(array_filter(array_unique($ids)));

        return $ids ? \App\Models\User::whereIn('id', $ids)->get()->mapWithKeys(fn ($u) => [$u->id => Person::name($u)])->all() : [];
    }

    private function audit(Request $request, Tenant $tenant, string $action, string $type, string $id, string $label, array $after = [], array $before = []): void
    {
        Audit::log($request, $action, ['tenant' => $tenant, 'entity_type' => $type, 'entity_id' => $id, 'entity_label' => $label, 'after' => $after ?: null, 'before' => $before ?: null]);
    }

    public function show(Request $request)
    {
        if (!ApiKeys::ready()) {
            return response()->json(['ready' => false, 'message' => ApiKeys::NOT_READY]);
        }

        $tenant = $this->tenant($request);
        $keys = DB::table('api_keys')->where('tenant_id', $tenant->id)->orderByRaw('revoked_at is not null')->orderByDesc('created_at')->get();
        $endpoints = DB::table('webhook_endpoints')->where('tenant_id', $tenant->id)->orderBy('created_at')->get();
        $last = DB::table('webhook_deliveries')->where('tenant_id', $tenant->id)
            ->selectRaw('distinct on (webhook_id) webhook_id, status, created_at')
            ->orderBy('webhook_id')->orderByDesc('created_at')->get()
            ->mapWithKeys(fn ($row) => [$row->webhook_id => ['status' => $row->status, 'status_label' => Webhooks::STATUS[$row->status] ?? $row->status, 'at' => \Illuminate\Support\Carbon::parse($row->created_at)->toIso8601String()]])
            ->all();
        $users = $this->users($keys->pluck('created_by')->merge($keys->pluck('revoked_by'))->all());

        return response()->json([
            'ready'        => true,
            'base_url'     => rtrim((string) config('app.url'), '/') . '/api/v1',
            'per_minute'   => ApiKeys::PER_MINUTE,
            'resources'    => collect(ApiKeys::RESOURCES)->map(fn ($r, $key) => ['key' => $key] + $r)->values(),
            'levels'       => ApiKeys::LEVELS,
            'events'       => collect(Webhooks::EVENTS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'keys'         => $keys->map(fn ($key) => ApiKeys::present($key, $users))->values(),
            'webhooks'     => $endpoints->map(fn ($endpoint) => Webhooks::presentEndpoint($endpoint, $last))->values(),
            'max_attempts' => Webhooks::MAX_ATTEMPTS,
        ]);
    }

    public function storeKey(Request $request)
    {
        $tenant = $this->tenant($request);

        $validated = $request->validate([
            'name'   => 'required|string|max:120',
            'scopes' => 'required|array',
            'scopes.*' => ['string', Rule::in(array_keys(ApiKeys::LEVELS))],
        ], ['name.required' => 'Ponle un nombre a la clave, por ejemplo «Página web».']);

        $scopes = ApiKeys::cleanScopes($validated['scopes']);

        if (!$scopes) {
            throw ValidationException::withMessages(['scopes' => 'Dale acceso a la clave a por lo menos una cosa.']);
        }

        $plain = ApiKeys::generate();
        $id = (string) Str::uuid();

        DB::table('api_keys')->insert([
            'id' => $id, 'tenant_id' => $tenant->id, 'name' => trim($validated['name']), 'prefix' => substr($plain, 0, 12),
            'key_hash' => ApiKeys::hash($plain), 'scopes' => json_encode($scopes), 'created_by' => $request->user()->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->audit($request, $tenant, 'api_key.created', 'api_key', $id, trim($validated['name']), ['scopes' => $scopes]);
        $key = DB::table('api_keys')->where('id', $id)->first();

        return response()->json([
            'message' => 'Clave creada. Cópiala ahora: no se volverá a mostrar.',
            'plain'   => $plain,
            'key'     => ApiKeys::present($key, $this->users([$key->created_by])),
        ], 201);
    }

    public function revokeKey(Request $request, string $key)
    {
        $tenant = $this->tenant($request);
        $row = DB::table('api_keys')->where('tenant_id', $tenant->id)->where('id', $key)->first();
        abort_unless($row, 404, 'No encontramos esa clave.');

        if (!$row->revoked_at) {
            DB::table('api_keys')->where('id', $row->id)->update(['revoked_at' => now(), 'revoked_by' => $request->user()->id, 'updated_at' => now()]);
            $this->audit($request, $tenant, 'api_key.revoked', 'api_key', $row->id, $row->name);
        }

        $row = DB::table('api_keys')->where('id', $row->id)->first();

        return response()->json(['message' => 'Clave revocada: ya no funciona.', 'key' => ApiKeys::present($row, $this->users([$row->created_by, $row->revoked_by]))]);
    }

    private function webhookRules(): array
    {
        return [
            'url'         => 'required|string|max:500',
            'description' => 'nullable|string|max:150',
            'events'      => 'required|array|min:1',
            'events.*'    => ['string', Rule::in(array_keys(Webhooks::EVENTS))],
            'active'      => 'sometimes|boolean',
        ];
    }

    private function checkUrl(string $url): string
    {
        $url = trim($url);

        if ($problem = Webhooks::urlProblem($url)) {
            throw ValidationException::withMessages(['url' => $problem]);
        }

        return $url;
    }

    private function findWebhook(Tenant $tenant, string $id): object
    {
        $row = DB::table('webhook_endpoints')->where('tenant_id', $tenant->id)->where('id', $id)->first();
        abort_unless($row, 404, 'No encontramos ese webhook.');

        return $row;
    }

    public function storeWebhook(Request $request)
    {
        $tenant = $this->tenant($request);
        $validated = $request->validate($this->webhookRules(), ['events.required' => 'Elige por lo menos un aviso.', 'url.required' => 'Escribe la dirección que recibirá los avisos.']);
        $url = $this->checkUrl($validated['url']);
        $secret = Webhooks::newSecret();
        $id = (string) Str::uuid();

        DB::table('webhook_endpoints')->insert([
            'id' => $id, 'tenant_id' => $tenant->id, 'url' => $url, 'description' => trim((string) ($validated['description'] ?? '')) ?: null,
            'events' => json_encode(array_values(array_unique($validated['events']))), 'secret' => Crypt::encryptString($secret),
            'active' => $validated['active'] ?? true, 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->audit($request, $tenant, 'webhook.created', 'webhook', $id, $url, ['url' => $url, 'events' => $validated['events']]);

        return response()->json([
            'message' => 'Webhook registrado. Guarda la clave secreta: no se volverá a mostrar.',
            'secret'  => $secret,
            'webhook' => Webhooks::presentEndpoint(DB::table('webhook_endpoints')->where('id', $id)->first()),
        ], 201);
    }

    public function updateWebhook(Request $request, string $webhook)
    {
        $tenant = $this->tenant($request);
        $row = $this->findWebhook($tenant, $webhook);
        $validated = $request->validate($this->webhookRules(), ['events.required' => 'Elige por lo menos un aviso.']);
        $url = $this->checkUrl($validated['url']);
        $events = array_values(array_unique($validated['events']));

        DB::table('webhook_endpoints')->where('id', $row->id)->update([
            'url' => $url, 'description' => trim((string) ($validated['description'] ?? '')) ?: null, 'events' => json_encode($events),
            'active' => $validated['active'] ?? (bool) $row->active, 'updated_at' => now(),
        ]);

        $this->audit($request, $tenant, 'webhook.updated', 'webhook', $row->id, $url,
            ['url' => $url, 'events' => $events, 'active' => $validated['active'] ?? (bool) $row->active],
            ['url' => $row->url, 'events' => json_decode($row->events, true), 'active' => (bool) $row->active]);

        return response()->json(['message' => 'Webhook guardado.', 'webhook' => Webhooks::presentEndpoint(DB::table('webhook_endpoints')->where('id', $row->id)->first())]);
    }

    public function rotateSecret(Request $request, string $webhook)
    {
        $tenant = $this->tenant($request);
        $row = $this->findWebhook($tenant, $webhook);
        $secret = Webhooks::newSecret();

        DB::table('webhook_endpoints')->where('id', $row->id)->update(['secret' => Crypt::encryptString($secret), 'updated_at' => now()]);
        $this->audit($request, $tenant, 'webhook.secret_changed', 'webhook', $row->id, $row->url);

        return response()->json(['message' => 'Clave secreta cambiada. Actualízala en el sistema que recibe los avisos.', 'secret' => $secret]);
    }

    public function destroyWebhook(Request $request, string $webhook)
    {
        $tenant = $this->tenant($request);
        $row = $this->findWebhook($tenant, $webhook);

        DB::table('webhook_endpoints')->where('id', $row->id)->delete();
        $this->audit($request, $tenant, 'webhook.deleted', 'webhook', $row->id, $row->url, [], ['url' => $row->url]);

        return response()->json(['message' => 'Webhook eliminado.']);
    }

    public function testWebhook(Request $request, string $webhook)
    {
        $tenant = $this->tenant($request);
        $ok = Webhooks::test($this->findWebhook($tenant, $webhook));

        return response()->json(['ok' => (bool) $ok, 'message' => $ok ? 'La prueba llegó bien.' : 'La prueba no llegó. Revisa el detalle en la lista de envíos.']);
    }

    public function deliveries(Request $request, string $webhook)
    {
        $tenant = $this->tenant($request);
        $row = $this->findWebhook($tenant, $webhook);

        $rows = DB::table('webhook_deliveries')->where('tenant_id', $tenant->id)->where('webhook_id', $row->id)
            ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('created_at')->limit(200)->get();

        return response()->json(['webhook' => Webhooks::presentEndpoint($row), 'deliveries' => $rows->map(fn ($d) => Webhooks::presentDelivery($d))->values()]);
    }

    public function resend(Request $request, string $webhook, string $delivery)
    {
        $tenant = $this->tenant($request);
        $row = $this->findWebhook($tenant, $webhook);
        $found = DB::table('webhook_deliveries')->where('tenant_id', $tenant->id)->where('webhook_id', $row->id)->where('id', $delivery)->first();
        abort_unless($found, 404, 'No encontramos ese envío.');

        $ok = Webhooks::resend($found);
        $this->audit($request, $tenant, 'webhook.resent', 'webhook', $row->id, $row->url, ['event' => $found->event, 'ok' => (bool) $ok]);

        return response()->json([
            'ok'       => (bool) $ok,
            'message'  => $ok ? 'Se reenvió y llegó bien.' : 'Se reenvió, pero no llegó. Se volverá a intentar solo.',
            'delivery' => Webhooks::presentDelivery(DB::table('webhook_deliveries')->where('id', $found->id)->first()),
        ]);
    }

    public function feeds(Request $request)
    {
        $tenant = $this->tenant($request);
        $feeds = DB::table('calendar_feeds')->where('tenant_id', $tenant->id)->get();
        $byPro = $feeds->whereNotNull('professional_id')->keyBy('professional_id');
        $byBranch = $feeds->whereNotNull('branch_id')->keyBy('branch_id');

        return response()->json([
            'details'       => CalendarFeeds::DETAILS,
            'professionals' => Professional::where('tenant_id', $tenant->id)->with('status')->orderBy('display_name')->get()
                ->reject(fn ($p) => $p->status?->slug === 'inactive')
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->display_name, 'feed' => ($f = $byPro->get($p->id)) ? CalendarFeeds::present($f) : null])->values(),
            'branches'      => Branch::where('tenant_id', $tenant->id)->where('status', 'active')->orderBy('name')->get()
                ->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'feed' => ($f = $byBranch->get($b->id)) ? CalendarFeeds::present($f) : null])->values(),
        ]);
    }

    public function storeFeed(Request $request)
    {
        $tenant = $this->tenant($request);

        $validated = $request->validate([
            'professional_id' => 'required_without:branch_id|nullable|uuid',
            'branch_id'       => 'required_without:professional_id|nullable|uuid',
            'detail'          => ['nullable', Rule::in(array_keys(CalendarFeeds::DETAILS))],
        ]);

        $column = !empty($validated['professional_id']) ? 'professional_id' : 'branch_id';
        $ownerId = $validated[$column];
        $label = $column === 'professional_id'
            ? Professional::where('tenant_id', $tenant->id)->findOrFail($ownerId)->display_name
            : Branch::where('tenant_id', $tenant->id)->findOrFail($ownerId)->name;
        $existing = DB::table('calendar_feeds')->where('tenant_id', $tenant->id)->where($column, $ownerId)->first();
        $token = CalendarFeeds::newToken();

        if ($existing) {
            DB::table('calendar_feeds')->where('id', $existing->id)->update(['token' => $token, 'detail' => $validated['detail'] ?? $existing->detail, 'last_accessed_at' => null, 'updated_at' => now()]);
            $id = $existing->id;
            $this->audit($request, $tenant, 'calendar_feed.regenerated', 'calendar_feed', $id, $label);
        } else {
            $id = (string) Str::uuid();
            DB::table('calendar_feeds')->insert([
                'id' => $id, 'tenant_id' => $tenant->id, $column => $ownerId, 'token' => $token, 'detail' => $validated['detail'] ?? 'service',
                'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit($request, $tenant, 'calendar_feed.created', 'calendar_feed', $id, $label);
        }

        return response()->json([
            'message' => $existing ? 'Enlace nuevo listo. El anterior dejó de funcionar: vuelve a suscribirte con este.' : 'Enlace listo para suscribirte.',
            'feed'    => CalendarFeeds::present(DB::table('calendar_feeds')->where('id', $id)->first()),
        ], $existing ? 200 : 201);
    }

    public function updateFeed(Request $request, string $feed)
    {
        $tenant = $this->tenant($request);
        $row = DB::table('calendar_feeds')->where('tenant_id', $tenant->id)->where('id', $feed)->first();
        abort_unless($row, 404, 'No encontramos ese enlace.');
        $validated = $request->validate(['detail' => ['required', Rule::in(array_keys(CalendarFeeds::DETAILS))]]);

        DB::table('calendar_feeds')->where('id', $row->id)->update(['detail' => $validated['detail'], 'updated_at' => now()]);
        $this->audit($request, $tenant, 'calendar_feed.updated', 'calendar_feed', $row->id, $row->id, ['detail' => $validated['detail']], ['detail' => $row->detail]);

        return response()->json(['message' => 'Guardado.', 'feed' => CalendarFeeds::present(DB::table('calendar_feeds')->where('id', $row->id)->first())]);
    }

    public function destroyFeed(Request $request, string $feed)
    {
        $tenant = $this->tenant($request);
        $row = DB::table('calendar_feeds')->where('tenant_id', $tenant->id)->where('id', $feed)->first();
        abort_unless($row, 404, 'No encontramos ese enlace.');

        DB::table('calendar_feeds')->where('id', $row->id)->delete();
        $this->audit($request, $tenant, 'calendar_feed.deleted', 'calendar_feed', $row->id, $row->id);

        return response()->json(['message' => 'Enlace quitado: el calendario suscrito ya no recibirá citas.']);
    }

    public function calendar(Request $request, string $token)
    {
        abort_unless(ApiKeys::ready() && preg_match('/^[A-Za-z0-9]{48}$/', $token), 404);
        $feed = DB::table('calendar_feeds')->where('token', $token)->first();
        abort_unless($feed, 404, 'Este enlace de calendario ya no existe.');
        $tenant = Tenant::with('settings')->find($feed->tenant_id);
        abort_unless($tenant && $tenant->isActive() && $tenant->hasModule('api'), 404, 'Este enlace de calendario ya no está disponible.');

        DB::table('calendar_feeds')->where('id', $feed->id)->update(['last_accessed_at' => now()]);

        return response(CalendarFeeds::render($feed, $tenant), 200, [
            'Content-Type'        => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="citas.ics"',
            'Cache-Control'       => 'private, max-age=300',
        ]);
    }
}
