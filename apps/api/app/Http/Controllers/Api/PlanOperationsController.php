<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Models\SubscriptionInvoice;
use App\Support\Access;
use App\Support\Audit;
use App\Support\DataExports;
use App\Support\ErrorLogs;
use App\Support\PlanInvoices;
use App\Support\Rls;
use App\Support\WebLeads;
use App\Support\WebSite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PlanOperationsController extends Controller
{
    public const HELP_NOT_READY = 'Falta actualizar la base de datos para administrar los artículos de ayuda: ejecuta php artisan migrate.';

    public function download(string $export)
    {
        return DataExports::download($export);
    }

    public function clientError(Request $request)
    {
        $values = $request->validate([
            'message' => 'required|string|max:2000',
            'kind'    => 'nullable|string|max:190',
            'file'    => 'nullable|string|max:500',
            'line'    => 'nullable|integer|min:0',
            'url'     => 'nullable|string|max:500',
            'stack'   => 'nullable|string|max:5000',
        ]);

        ErrorLogs::captureWeb($values, $request);

        return response()->noContent();
    }

    public function chatLead(Request $request)
    {
        abort_unless(WebLeads::ready(), 503, WebLeads::NOT_READY);
        abort_unless(WebSite::settings()['chat_enabled'], 404);

        $values = $request->validate([
            'name'          => 'required|string|min:2|max:150',
            'phone'         => 'required|string|min:6|max:30',
            'email'         => 'nullable|email|max:190',
            'business_type' => 'nullable|string|max:50|exists:business_types,slug',
            'professionals' => 'nullable|integer|min:1|max:1000',
            'message'       => 'nullable|string|max:1000',
            'plan'          => 'nullable|string|max:80',
            'person'        => 'boolean',
            'consent'       => 'accepted',
            'utm'           => 'nullable|array',
            'website'       => 'nullable|max:0',
            'transcript'    => 'nullable|array|max:60',
            'transcript.*.from' => 'required|in:bot,user',
            'transcript.*.text' => 'required|string|max:600',
        ], [
            'name.required'    => 'Escribe tu nombre.',
            'phone.required'   => 'Escribe tu WhatsApp para que un asesor te escriba.',
            'consent.accepted' => 'Para contactarte necesitamos que aceptes la política de privacidad.',
            'website.max'      => 'No pudimos enviar tus datos.',
        ]);

        $note = collect([
            ($values['person'] ?? false) ? 'Pidió hablar con una persona desde el chat del sitio.' : 'Dejó sus datos en el chat del sitio.',
            !empty($values['plan']) ? "Plan sugerido por el chat: {$values['plan']}." : null,
            !empty($values['message']) ? 'Mensaje: ' . trim(strip_tags($values['message'])) : null,
        ])->filter()->implode(' ');

        $leadId = WebLeads::create([
            'name'          => trim($values['name']),
            'phone'         => trim($values['phone']),
            'email'         => $values['email'] ?? null,
            'business_type' => $values['business_type'] ?? null,
            'professionals' => $values['professionals'] ?? null,
            'source'        => 'chatbot',
            'utm'           => $values['utm'] ?? [],
            'note'          => mb_substr($note, 0, 1000),
        ]);

        if (!empty($values['transcript'])) {
            $lines = collect($values['transcript'])->map(fn ($m) => ($m['from'] === 'user' ? 'Visitante: ' : 'Asistente: ') . trim(strip_tags((string) $m['text'])))->implode("
");
            \App\Support\Rls::bypass(fn () => WebLeads::event($leadId, 'chat', null, null, mb_substr($lines, 0, 20000)));
        }

        return response()->json(['message' => '¡Gracias! Un asesor te escribirá pronto por WhatsApp.'], 201);
    }

    public function invoicing()
    {
        abort_unless(PlanInvoices::ready(), 503, PlanInvoices::NOT_READY);
        $config = PlanInvoices::config();
        $row = PlanInvoices::integration();

        return response()->json($config + [
            'mode'        => $row && $row->mode !== 'simulated' ? 'live' : 'simulated',
            'has_token'   => (bool) ($row && $row->credentials),
            'defaults'    => ['ruc' => PlatformSetting::value('legal_tax_id'), 'legal_name' => PlatformSetting::value('legal_name'), 'address' => PlatformSetting::value('legal_address')],
            'pending'     => Rls::bypass(fn () => SubscriptionInvoice::whereNotNull('doc_type')->where('sunat_status', 'pending')->count()),
            'rejected'    => Rls::bypass(fn () => SubscriptionInvoice::whereNotNull('doc_type')->where('sunat_status', 'rejected')->count()),
        ]);
    }

    public function saveInvoicing(Request $request)
    {
        abort_unless(PlanInvoices::ready(), 503, PlanInvoices::NOT_READY);
        $values = $request->validate([
            'mode'           => 'required|in:simulated,live',
            'ruc'            => ['nullable', 'regex:/^(10|15|17|20)\d{9}$/'],
            'legal_name'     => 'nullable|string|max:200',
            'address'        => 'nullable|string|max:255',
            'route'          => ['nullable', 'url', 'max:255', function ($attribute, $value, $fail) {
                if ($value && !\App\Support\Billing\NubefactInvoiceProvider::validRoute($value)) {
                    $fail('La ruta debe ser la de tu cuenta de Nubefact (https://...nubefact.com/...).');
                }
            }],
            'token'          => 'nullable|string|max:200',
            'series'         => 'nullable|array',
            'series.boleta'  => ['nullable', 'regex:/^B[A-Z0-9]{3}$/'],
            'series.factura' => ['nullable', 'regex:/^F[A-Z0-9]{3}$/'],
        ], [
            'ruc.regex'            => 'El RUC tiene 11 dígitos y empieza con 10, 15, 17 o 20.',
            'series.boleta.regex'  => 'La serie de boletas empieza con B y tiene 4 caracteres (ej. B001).',
            'series.factura.regex' => 'La serie de facturas empieza con F y tiene 4 caracteres (ej. F001).',
        ]);

        $config = PlanInvoices::save($values);
        Audit::log($request, 'platform.plan_invoicing', ['entity_type' => 'platform_integration', 'after' => ['modo' => $values['mode'] === 'live' ? 'Emite de verdad' : 'Simulación', 'ruc' => $config['ruc']]]);

        return $this->invoicing();
    }

    public function resendDocument(Request $request, string $invoice)
    {
        abort_unless(PlanInvoices::ready(), 503, PlanInvoices::NOT_READY);
        $row = Rls::bypass(fn () => SubscriptionInvoice::findOrFail($invoice));
        $result = $row->doc_type ? PlanInvoices::deliver($row) : PlanInvoices::issue($row);
        Audit::log($request, 'platform.plan_document_sent', ['tenant' => $row->tenant_id, 'entity_type' => 'subscription_invoice', 'entity_id' => $row->id, 'entity_label' => $result ? PlanInvoices::label($result) : null]);

        return response()->json(['document' => $result ? PlanInvoices::present($result) : null, 'message' => $result ? 'Listo: ' . PlanInvoices::stateLabel($result) . '.' : 'Este pago no lleva comprobante.']);
    }

    public function errors(Request $request)
    {
        abort_unless(ErrorLogs::ready(), 503, 'Falta actualizar la base de datos para ver los errores: ejecuta php artisan migrate.');

        return response()->json(ErrorLogs::list($request->only(['state', 'source', 'q'])) + ['settings' => $this->presentErrorSettings()]);
    }

    public function error(string $error)
    {
        abort_unless(ErrorLogs::ready(), 503);
        $row = Rls::bypass(fn () => DB::table('error_logs as e')->leftJoin('tenants as t', 't.id', '=', 'e.tenant_id')->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->where('e.id', $error)->first(['e.*', 't.name as tenant', 'u.email as user_email']));
        abort_unless($row, 404);
        $row->sample = json_decode($row->sample ?? '{}', true);

        return response()->json($row);
    }

    public function resolveError(Request $request, string $error)
    {
        abort_unless(ErrorLogs::ready(), 503);
        $resolved = $request->validate(['resolved' => 'required|boolean'])['resolved'];
        Rls::bypass(fn () => DB::table('error_logs')->where('id', $error)->update(['resolved_at' => $resolved ? now() : null, 'resolved_by' => $resolved ? $request->user()->id : null]));

        return $this->error($error);
    }

    private function presentErrorSettings(): array
    {
        $config = ErrorLogs::config();

        return ['notify' => (bool) $config['notify'], 'notify_every_minutes' => (int) $config['notify_every_minutes'], 'sentry_enabled' => (bool) $config['sentry_enabled'], 'has_sentry_dsn' => !empty($config['sentry_dsn'])];
    }

    public function errorSettings()
    {
        return response()->json($this->presentErrorSettings());
    }

    public function saveErrorSettings(Request $request)
    {
        $values = $request->validate([
            'notify'               => 'required|boolean',
            'notify_every_minutes' => 'required|integer|min:5|max:1440',
            'sentry_enabled'       => 'required|boolean',
            'sentry_dsn'           => ['nullable', 'string', 'max:300', function ($attribute, $value, $fail) {
                if ($value && !ErrorLogs::sentryEndpoint($value)) {
                    $fail('Copia el DSN tal como lo da Sentry, por ejemplo https://clave@o123.ingest.sentry.io/456.');
                }
            }],
        ]);

        $current = ErrorLogs::config();
        $next = array_merge($current, array_intersect_key($values, ErrorLogs::DEFAULTS));

        if (!array_key_exists('sentry_dsn', $values) || $values['sentry_dsn'] === null) {
            $next['sentry_dsn'] = $current['sentry_dsn'];
        }

        if ($next['sentry_enabled'] && !$next['sentry_dsn']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['sentry_dsn' => 'Para enviar a Sentry pega primero el DSN de tu proyecto.']);
        }

        PlatformSetting::put(['error_monitoring' => $next]);
        Audit::log($request, 'platform.error_settings', ['after' => ['aviso' => $next['notify'] ? 'Sí' : 'No', 'sentry' => $next['sentry_enabled'] ? 'Sí' : 'No']]);

        return response()->json($this->presentErrorSettings());
    }

    private function helpReady(): bool
    {
        return Schema::hasTable('help_articles');
    }

    private function articleRules(?string $id = null): array
    {
        return [
            'title'          => 'required|string|max:150',
            'category'       => 'required|string|max:80',
            'summary'        => 'nullable|string|max:255',
            'body'           => 'required|string|max:20000',
            'permission'     => ['nullable', 'string', 'max:80', Rule::in(Access::all())],
            'link'           => ['nullable', 'string', 'max:255', 'regex:#^/(?!/)\S*$#'],
            'replaces_topic' => 'nullable|string|max:120',
            'is_active'      => 'boolean',
            'sort_order'     => 'nullable|integer|min:0|max:9999',
        ];
    }

    private function articleMessages(): array
    {
        return [
            'title.required'    => 'Escribe el título del artículo.',
            'category.required' => 'Elige o escribe la categoría.',
            'body.required'     => 'Escribe el contenido del artículo.',
            'link.regex'        => 'La pantalla se escribe como una dirección del sistema, por ejemplo /agenda.',
            'permission.in'     => 'Ese permiso no existe.',
        ];
    }

    private function presentArticle(object $row): array
    {
        return [
            'id'             => $row->id,
            'slug'           => $row->slug,
            'title'          => $row->title,
            'category'       => $row->category,
            'summary'        => $row->summary,
            'body'           => $row->body,
            'permission'     => $row->permission,
            'link'           => $row->link,
            'replaces_topic' => $row->replaces_topic,
            'is_active'      => (bool) $row->is_active,
            'sort_order'     => (int) $row->sort_order,
            'updated_at'     => $row->updated_at,
        ];
    }

    public function helpArticles()
    {
        abort_unless($this->helpReady(), 503, self::HELP_NOT_READY);

        return response()->json([
            'items'       => DB::table('help_articles')->orderBy('category')->orderBy('sort_order')->orderBy('title')->get()->map(fn ($row) => $this->presentArticle($row))->values(),
            'permissions' => collect(Access::CATALOG)->flatMap(fn ($definition, $key) => collect($definition['actions'])->map(fn ($label, $action) => ['value' => "{$key}.{$action}", 'label' => "{$definition['label']}: {$label}"])->values())->values(),
        ]);
    }

    public function storeHelpArticle(Request $request)
    {
        abort_unless($this->helpReady(), 503, self::HELP_NOT_READY);
        $values = $request->validate($this->articleRules(), $this->articleMessages());
        $values['body'] = \App\Support\RichText::normalize($values['body']);
        $slug = Str::slug($values['title']) ?: 'articulo';
        $values['slug'] = DB::table('help_articles')->where('slug', $slug)->exists() ? $slug . '-' . Str::lower(Str::random(4)) : $slug;
        $id = (string) Str::uuid();
        DB::table('help_articles')->insert($values + ['id' => $id, 'is_active' => $values['is_active'] ?? true, 'sort_order' => $values['sort_order'] ?? 0, 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        Audit::log($request, 'platform.help_article_created', ['entity_type' => 'help_article', 'entity_id' => $id, 'entity_label' => $values['title']]);

        return response()->json($this->presentArticle(DB::table('help_articles')->where('id', $id)->first()), 201);
    }

    public function updateHelpArticle(Request $request, string $article)
    {
        abort_unless($this->helpReady(), 503, self::HELP_NOT_READY);
        $row = DB::table('help_articles')->where('id', $article)->first();
        abort_unless($row, 404);
        $rules = collect($this->articleRules())->map(fn ($rule) => is_array($rule) ? array_merge(['sometimes'], array_values(array_diff($rule, ['required']))) : 'sometimes|' . str_replace('required|', '', $rule))->all();
        $values = $request->validate($rules, $this->articleMessages());

        if (isset($values['body'])) {
            $values['body'] = \App\Support\RichText::normalize($values['body']);
        }

        DB::table('help_articles')->where('id', $article)->update($values + ['updated_at' => now()]);
        Audit::log($request, array_key_exists('is_active', $values) && count($values) === 1 ? ($values['is_active'] ? 'platform.help_article_enabled' : 'platform.help_article_disabled') : 'platform.help_article_updated', ['entity_type' => 'help_article', 'entity_id' => $article, 'entity_label' => $values['title'] ?? $row->title]);

        return response()->json($this->presentArticle(DB::table('help_articles')->where('id', $article)->first()));
    }

    public function publishedHelp(Request $request)
    {
        if (!$this->helpReady()) {
            return response()->json(['items' => [], 'hidden' => [], 'ready' => false]);
        }

        $user = $request->user();
        $rows = DB::table('help_articles')->get();

        return response()->json([
            'ready'  => true,
            'items'  => $rows->where('is_active', true)->filter(fn ($row) => !$row->permission || Access::allows($user, $row->permission))
                ->sortBy([['category', 'asc'], ['sort_order', 'asc'], ['title', 'asc']])->map(fn ($row) => $this->presentArticle($row))->values(),
            'hidden' => $rows->whereNotNull('replaces_topic')->pluck('replaces_topic')->unique()->values(),
        ]);
    }
}
