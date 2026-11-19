<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Support\Audit;
use App\Support\PlanCoupons;
use App\Support\RichText;
use App\Support\WebLeads;
use App\Support\WebPosts;
use App\Support\WebSite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlatformWebController extends Controller
{
    private function plans(): array
    {
        return DB::table('saas_plans')->orderBy('presentation_order')->get(['id', 'name'])->map(fn ($p) => ['value' => $p->id, 'label' => $p->name])->all();
    }

    private function couponRow(object $row, array $plans): array
    {
        $ids = PlanCoupons::planIds($row);
        $state = !$row->active ? 'inactive' : (PlanCoupons::problem($row) ? ($row->max_uses !== null && $row->used >= $row->max_uses ? 'exhausted' : 'expired') : 'active');

        return [
            'id'          => $row->id,
            'code'        => $row->code,
            'description' => $row->description,
            'kind'        => $row->percent_off !== null ? 'percent' : 'amount',
            'value'       => (float) ($row->percent_off ?? $row->amount_off),
            'months'      => (int) $row->months,
            'max_uses'    => $row->max_uses !== null ? (int) $row->max_uses : null,
            'used'        => (int) $row->used,
            'valid_until' => $row->valid_until,
            'active'      => (bool) $row->active,
            'plan_ids'    => $ids,
            'plan_names'  => collect($plans)->whereIn('value', $ids)->pluck('label')->values(),
            'state'       => $state,
            'text'        => PlanCoupons::text($row),
            'created_at'  => $row->created_at,
        ];
    }

    public function coupons()
    {
        $plans = $this->plans();

        if (!PlanCoupons::ready()) {
            return response()->json(['ready' => false, 'message' => PlanCoupons::NOT_READY, 'items' => [], 'plans' => $plans]);
        }

        $tenants = DB::table('saas_coupon_redemptions as r')->join('tenants as t', 't.id', '=', 'r.tenant_id')
            ->orderByDesc('r.created_at')->get(['r.coupon_id', 't.id', 't.name', 'r.created_at', 'r.cycles_left'])->groupBy('coupon_id');

        return response()->json([
            'ready' => true,
            'plans' => $plans,
            'items' => DB::table('saas_coupons')->orderByDesc('created_at')->get()->map(fn ($row) => $this->couponRow($row, $plans) + [
                'tenants' => ($tenants[$row->id] ?? collect())->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'at' => $t->created_at, 'cycles_left' => (int) $t->cycles_left])->values(),
            ])->values(),
        ]);
    }

    private function couponValues(Request $request, ?string $id = null): array
    {
        $request->merge(['code' => PlanCoupons::normalize($request->input('code'))]);
        $values = $request->validate([
            'code'        => ['required', 'regex:/^[A-Z0-9_-]{4,30}$/', Rule::unique('saas_coupons', 'code')->ignore($id)],
            'description' => 'nullable|string|max:200',
            'kind'        => 'required|in:percent,amount',
            'value'       => 'required|numeric|min:0.01' . ($request->input('kind') === 'percent' ? '|max:100' : '|max:99999'),
            'months'      => 'required|integer|min:1|max:36',
            'max_uses'    => 'nullable|integer|min:1|max:1000000',
            'valid_until' => 'nullable|date_format:Y-m-d',
            'active'      => 'boolean',
            'plan_ids'    => 'array',
            'plan_ids.*'  => 'uuid|exists:saas_plans,id',
        ], [
            'code.regex'   => 'El código lleva de 4 a 30 letras o números, sin espacios ni tildes.',
            'code.unique'  => 'Ya existe un cupón con ese código.',
            'value.max'    => 'El porcentaje va de 1 a 100.',
            'value.min'    => 'Escribe cuánto descuenta.',
            'months.max'   => 'Un cupón vale como mucho 36 meses.',
        ]);

        return [
            'code'        => $values['code'],
            'description' => $values['description'] ?? null,
            'percent_off' => $values['kind'] === 'percent' ? $values['value'] : null,
            'amount_off'  => $values['kind'] === 'amount' ? $values['value'] : null,
            'months'      => $values['months'],
            'max_uses'    => $values['max_uses'] ?? null,
            'valid_until' => $values['valid_until'] ?? null,
            'active'      => $values['active'] ?? true,
            'plan_ids'    => json_encode(array_values(array_unique($values['plan_ids'] ?? []))),
        ];
    }

    public function storeCoupon(Request $request)
    {
        abort_unless(PlanCoupons::ready(), 503, PlanCoupons::NOT_READY);
        $values = $this->couponValues($request);
        $id = (string) Str::uuid();
        DB::table('saas_coupons')->insert($values + ['id' => $id, 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
        $row = DB::table('saas_coupons')->where('id', $id)->first();
        Audit::log($request, 'plan_coupon.created', ['entity_type' => 'plan_coupon', 'entity_id' => $id, 'entity_label' => $row->code, 'after' => ['code' => $row->code, 'text' => PlanCoupons::text($row)]]);

        return response()->json(['item' => $this->couponRow($row, $this->plans())], 201);
    }

    public function updateCoupon(Request $request, string $coupon)
    {
        abort_unless(PlanCoupons::ready(), 503, PlanCoupons::NOT_READY);
        $before = DB::table('saas_coupons')->where('id', $coupon)->first();
        abort_unless($before, 404);
        $values = $this->couponValues($request, $coupon);

        if ($values['max_uses'] !== null && $values['max_uses'] < (int) $before->used) {
            throw ValidationException::withMessages(['max_uses' => "Ya se usó {$before->used} veces: los usos máximos no pueden ser menos."]);
        }

        if ((int) $before->used > 0 && $values['code'] !== $before->code) {
            throw ValidationException::withMessages(['code' => 'Este cupón ya se usó: no se le puede cambiar el código.']);
        }

        DB::table('saas_coupons')->where('id', $coupon)->update($values + ['updated_at' => now()]);
        $row = DB::table('saas_coupons')->where('id', $coupon)->first();
        Audit::log($request, 'plan_coupon.updated', ['entity_type' => 'plan_coupon', 'entity_id' => $coupon, 'entity_label' => $row->code, 'before' => ['text' => PlanCoupons::text($before), 'active' => (bool) $before->active, 'max_uses' => $before->max_uses, 'valid_until' => $before->valid_until], 'after' => ['text' => PlanCoupons::text($row), 'active' => (bool) $row->active, 'max_uses' => $row->max_uses, 'valid_until' => $row->valid_until]]);

        return response()->json(['item' => $this->couponRow($row, $this->plans())]);
    }

    public function destroyCoupon(Request $request, string $coupon)
    {
        abort_unless(PlanCoupons::ready(), 503, PlanCoupons::NOT_READY);
        $row = DB::table('saas_coupons')->where('id', $coupon)->first();
        abort_unless($row, 404);
        abort_if((int) $row->used > 0 || DB::table('saas_coupon_redemptions')->where('coupon_id', $coupon)->exists(), 422, 'Este cupón ya se usó y no se puede eliminar. Puedes desactivarlo para que nadie más lo use.');
        DB::table('saas_coupons')->where('id', $coupon)->delete();
        Audit::log($request, 'plan_coupon.deleted', ['entity_type' => 'plan_coupon', 'entity_id' => $coupon, 'entity_label' => $row->code]);

        return response()->noContent();
    }

    public function funnel(Request $request)
    {
        if (!WebLeads::ready()) {
            return response()->json(['ready' => false, 'message' => WebLeads::NOT_READY]);
        }

        $values = $request->validate(['from' => 'nullable|date_format:Y-m-d', 'to' => 'nullable|date_format:Y-m-d']);

        return response()->json(['ready' => true] + WebLeads::funnel($values['from'] ?? null, $values['to'] ?? null));
    }

    private function options(): array
    {
        $pairs = fn (array $list) => collect($list)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values();

        return [
            'stages'         => $pairs(WebLeads::STAGES),
            'sources'        => $pairs(WebLeads::SOURCES),
            'business_types' => DB::table('business_types')->orderBy('sort_order')->get(['slug', 'name'])->map(fn ($b) => ['value' => $b->slug, 'label' => $b->name]),
        ];
    }

    public function leads()
    {
        if (!WebLeads::ready()) {
            return response()->json(['ready' => false, 'message' => WebLeads::NOT_READY, 'items' => []] + $this->options());
        }

        $names = DB::table('business_types')->pluck('name', 'slug')->all();
        $notes = DB::table('web_lead_events')->selectRaw('lead_id, count(*) as total')->where('kind', 'note')->groupBy('lead_id')->pluck('total', 'lead_id');

        return response()->json([
            'ready' => true,
            'items' => DB::table('web_leads as l')->leftJoin('tenants as t', 't.id', '=', 'l.tenant_id')->orderByDesc('l.created_at')->limit(1000)
                ->get(['l.*', 't.name as tenant_name'])->map(fn ($lead) => WebLeads::present($lead, $names) + ['notes' => (int) ($notes[$lead->id] ?? 0)])->values(),
        ] + $this->options());
    }

    public function lead(string $lead)
    {
        abort_unless(WebLeads::ready(), 503, WebLeads::NOT_READY);
        $row = DB::table('web_leads as l')->leftJoin('tenants as t', 't.id', '=', 'l.tenant_id')->where('l.id', $lead)->first(['l.*', 't.name as tenant_name']);
        abort_unless($row, 404);

        return response()->json([
            'lead'   => WebLeads::present($row, DB::table('business_types')->pluck('name', 'slug')->all()),
            'events' => DB::table('web_lead_events')->where('lead_id', $lead)->orderByDesc('created_at')->get()->map(fn ($e) => [
                'id' => $e->id, 'kind' => $e->kind, 'from' => $e->from_stage, 'to' => $e->to_stage,
                'from_label' => $e->from_stage ? (WebLeads::STAGES[$e->from_stage] ?? $e->from_stage) : null,
                'to_label' => $e->to_stage ? (WebLeads::STAGES[$e->to_stage] ?? $e->to_stage) : null,
                'body' => $e->body, 'user_name' => $e->user_name, 'at' => $e->created_at,
            ])->values(),
        ]);
    }

    public function storeLead(Request $request)
    {
        abort_unless(WebLeads::ready(), 503, WebLeads::NOT_READY);
        $values = $request->validate([
            'name'          => 'required|string|min:2|max:150',
            'phone'         => 'nullable|string|max:30|required_without:email',
            'email'         => 'nullable|email|max:190',
            'business'      => 'nullable|string|max:200',
            'business_type' => 'nullable|string|exists:business_types,slug',
            'professionals' => 'nullable|integer|min:1|max:1000',
            'note'          => 'nullable|string|max:2000',
        ], ['phone.required_without' => 'Escribe al menos el celular o el correo.']);

        $id = WebLeads::create($values + ['source' => 'manual', 'stage' => 'new'], $request->user());
        Audit::log($request, 'web.lead_created', ['entity_type' => 'web_lead', 'entity_id' => $id, 'entity_label' => $values['name']]);

        return $this->lead($id)->setStatusCode(201);
    }

    public function stage(Request $request, string $lead)
    {
        abort_unless(WebLeads::ready(), 503, WebLeads::NOT_READY);
        $row = DB::table('web_leads')->where('id', $lead)->first();
        abort_unless($row, 404);
        $values = $request->validate([
            'stage'  => ['required', Rule::in(array_keys(WebLeads::STAGES))],
            'reason' => 'nullable|string|max:200|required_if:stage,lost',
        ], ['reason.required_if' => 'Escribe por qué se perdió.']);

        if ($values['stage'] === $row->stage) {
            return $this->lead($lead);
        }

        if (in_array($values['stage'], ['trial', 'paid'], true) && !$row->tenant_id) {
            throw ValidationException::withMessages(['stage' => 'Pasa solo a «En prueba» o «Pagó» cuando se registra: así el embudo cuadra con las empresas reales.']);
        }

        if ($row->paid_at && $values['stage'] !== 'paid') {
            throw ValidationException::withMessages(['stage' => 'Este interesado ya pagó su plan: su etapa no se cambia a mano.']);
        }

        DB::table('web_leads')->where('id', $lead)->update(['stage' => $values['stage'], 'lost_reason' => $values['stage'] === 'lost' ? $values['reason'] : null, 'updated_at' => now()]);
        WebLeads::event($lead, 'stage', $row->stage, $values['stage'], $values['reason'] ?? null, $request->user());
        Audit::log($request, 'web.lead_stage', ['entity_type' => 'web_lead', 'entity_id' => $lead, 'entity_label' => $row->name, 'before' => ['stage' => WebLeads::STAGES[$row->stage] ?? $row->stage], 'after' => ['stage' => WebLeads::STAGES[$values['stage']]]]);

        return $this->lead($lead);
    }

    public function note(Request $request, string $lead)
    {
        abort_unless(WebLeads::ready(), 503, WebLeads::NOT_READY);
        $row = DB::table('web_leads')->where('id', $lead)->first();
        abort_unless($row, 404);
        $values = $request->validate(['body' => 'required|string|min:2|max:2000'], ['body.required' => 'Escribe la nota.']);

        WebLeads::event($lead, 'note', null, null, trim($values['body']), $request->user());
        DB::table('web_leads')->where('id', $lead)->update(['updated_at' => now()]);
        Audit::log($request, 'web.lead_note', ['entity_type' => 'web_lead', 'entity_id' => $lead, 'entity_label' => $row->name]);

        return $this->lead($lead);
    }

    public function settings()
    {
        return response()->json(['values' => WebSite::settings(), 'defaults' => WebSite::defaults(), 'legal_pages' => WebSite::LEGAL]);
    }

    public function updateSettings(Request $request)
    {
        $values = $request->validate([
            'url'              => 'nullable|url|max:190',
            'phone'            => 'nullable|string|max:30',
            'whatsapp'         => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ]{8,20}$/'],
            'whatsapp_message' => 'nullable|string|max:300',
            'email'            => 'nullable|email|max:190',
            'seo_title'        => 'nullable|string|max:70',
            'seo_description'  => 'nullable|string|max:' . WebPosts::SEO_DESCRIPTION_MAX,
            'rubros_title'     => 'required|string|max:120',
            'rubros_text'      => 'nullable|string|max:300',
            'pricing_title'    => 'required|string|max:120',
            'pricing_text'     => 'nullable|string|max:300',
            'cta_title'        => 'required|string|max:120',
            'cta_text'         => 'nullable|string|max:300',
            'contact_form'     => 'boolean',
            'faq'              => 'array|max:12',
            'faq.*.q'          => 'required|string|max:200',
            'faq.*.a'          => 'required|string|max:1000',
            'ga4_id'           => ['nullable', 'regex:/^G-[A-Z0-9]{4,20}$/'],
            'meta_pixel_id'    => ['nullable', 'regex:/^[0-9]{6,20}$/'],
            'cookie_banner'    => 'boolean',
            'cookie_text'      => 'required|string|max:400',
            'legal'            => 'array',
            'legal.terminos'   => 'nullable|string|max:60000',
            'legal.privacidad' => 'nullable|string|max:60000',
            'legal.cookies'    => 'nullable|string|max:60000',
        ], [
            'url.url'            => 'Escribe la dirección completa, por ejemplo https://www.tusitio.com.',
            'whatsapp.regex'     => 'Escribe el WhatsApp solo con números, con el código del país (ej. +51 999 888 777).',
            'ga4_id.regex'       => 'El código de Google Analytics empieza con G- (ej. G-AB12CD34EF).',
            'meta_pixel_id.regex' => 'El número del píxel de Meta lleva solo números.',
            'seo_description.max' => 'La descripción para buscadores no debe pasar de ' . WebPosts::SEO_DESCRIPTION_MAX . ' caracteres.',
            'faq.*.q.required'   => 'Cada pregunta frecuente necesita su pregunta.',
            'faq.*.a.required'   => 'Cada pregunta frecuente necesita su respuesta.',
        ]);

        $before = WebSite::settings();
        $next = array_merge($before, $values);
        $next['url'] = $next['url'] ? rtrim($next['url'], '/') : null;
        $next['legal'] = array_merge($before['legal'], collect($values['legal'] ?? [])->map(fn ($html) => RichText::normalize((string) $html))->all());

        if ($next['legal'] !== $before['legal']) {
            $next['legal_updated_at'] = now()->toIso8601String();
        }

        PlatformSetting::put(['site' => $next]);
        $changed = collect(array_keys($next))->filter(fn ($key) => ($before[$key] ?? null) !== ($next[$key] ?? null) && $key !== 'legal_updated_at')->values();
        Audit::log($request, 'web.settings_changed', ['after' => ['campos' => $changed->implode(', ')]]);

        return $this->settings();
    }

    private function postRow(object $post): array
    {
        return WebPosts::present($post, true) + ['seo_warnings' => WebPosts::seoWarnings($post)];
    }

    public function posts()
    {
        if (!WebPosts::ready()) {
            return response()->json(['ready' => false, 'message' => WebPosts::NOT_READY, 'items' => [], 'business_types' => []]);
        }

        return response()->json([
            'ready'          => true,
            'items'          => DB::table('web_posts')->orderByDesc('updated_at')->get()->map(fn ($post) => $this->postRow($post))->values(),
            'business_types' => DB::table('business_types')->orderBy('sort_order')->get(['slug', 'name'])->map(fn ($b) => ['value' => $b->slug, 'label' => $b->name]),
        ]);
    }

    private function postValues(Request $request, ?string $id = null): array
    {
        if (!$request->filled('slug') && $request->filled('title')) {
            $request->merge(['slug' => WebPosts::slug((string) $request->input('title'))]);
        } else {
            $request->merge(['slug' => WebPosts::slug((string) $request->input('slug'))]);
        }

        $values = $request->validate([
            'title'           => 'required|string|min:5|max:160',
            'slug'            => ['required', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', 'max:80', Rule::unique('web_posts', 'slug')->ignore($id)],
            'excerpt'         => 'nullable|string|max:300',
            'body'            => 'required|string|max:200000',
            'business_type'   => 'nullable|string|exists:business_types,slug',
            'seo_title'       => 'nullable|string|max:120',
            'seo_description' => 'nullable|string|max:300',
            'author'          => 'nullable|string|max:150',
            'status'          => 'required|in:draft,published',
        ], [
            'title.required' => 'Escribe el título del artículo.',
            'body.required'  => 'Escribe el contenido del artículo.',
            'slug.unique'    => 'Ya hay un artículo con esa dirección. Cámbiala un poco.',
            'slug.regex'     => 'La dirección lleva solo letras sin tildes, números y guiones.',
        ]);

        $values['body'] = RichText::normalize($values['body']);

        return $values;
    }

    public function storePost(Request $request)
    {
        abort_unless(WebPosts::ready(), 503, WebPosts::NOT_READY);
        $values = $this->postValues($request);
        $id = (string) Str::uuid();
        DB::table('web_posts')->insert($values + [
            'id' => $id, 'published_at' => $values['status'] === 'published' ? now() : null,
            'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Audit::log($request, $values['status'] === 'published' ? 'web.post_published' : 'web.post_created', ['entity_type' => 'web_post', 'entity_id' => $id, 'entity_label' => $values['title']]);

        return response()->json(['item' => $this->postRow(DB::table('web_posts')->where('id', $id)->first())], 201);
    }

    public function updatePost(Request $request, string $post)
    {
        abort_unless(WebPosts::ready(), 503, WebPosts::NOT_READY);
        $before = DB::table('web_posts')->where('id', $post)->first();
        abort_unless($before, 404);
        $values = $this->postValues($request, $post);
        $values['published_at'] = $values['status'] === 'published' ? ($before->published_at ?? now()) : $before->published_at;
        DB::table('web_posts')->where('id', $post)->update($values + ['updated_at' => now()]);

        $action = $before->status !== $values['status'] ? ($values['status'] === 'published' ? 'web.post_published' : 'web.post_unpublished') : 'web.post_updated';
        Audit::log($request, $action, ['entity_type' => 'web_post', 'entity_id' => $post, 'entity_label' => $values['title']]);

        return response()->json(['item' => $this->postRow(DB::table('web_posts')->where('id', $post)->first())]);
    }

    public function destroyPost(Request $request, string $post)
    {
        abort_unless(WebPosts::ready(), 503, WebPosts::NOT_READY);
        $row = DB::table('web_posts')->where('id', $post)->first();
        abort_unless($row, 404);

        if ($row->cover_path) {
            Storage::disk('local')->delete($row->cover_path);
        }

        DB::table('web_posts')->where('id', $post)->delete();
        Audit::log($request, 'web.post_deleted', ['entity_type' => 'web_post', 'entity_id' => $post, 'entity_label' => $row->title]);

        return response()->noContent();
    }

    public function uploadCover(Request $request, string $post)
    {
        abort_unless(WebPosts::ready(), 503, WebPosts::NOT_READY);
        $row = DB::table('web_posts')->where('id', $post)->first();
        abort_unless($row, 404);
        $file = $request->validate(['file' => 'required|file|mimes:png,jpg,jpeg,webp|max:3072'], [
            'file.required' => 'Elige la imagen.',
            'file.mimes'    => 'La portada debe ser PNG, JPG o WEBP.',
            'file.max'      => 'La portada pesa más de 3 MB. Usa una más liviana.',
        ])['file'];
        $size = @getimagesize($file->getRealPath());
        abort_unless($size, 422, 'Ese archivo no es una imagen válida.');
        abort_if($size[0] < 600 || $size[1] < 300, 422, "La portada es muy pequeña ({$size[0]}×{$size[1]} px): usa una de al menos 1200×630 px para que se vea nítida al compartirla.");

        if ($row->cover_path) {
            Storage::disk('local')->delete($row->cover_path);
        }

        $path = $file->storeAs('web-posts', $post . '-' . Str::random(6) . '.' . strtolower($file->getClientOriginalExtension() ?: 'jpg'), 'local');
        DB::table('web_posts')->where('id', $post)->update(['cover_path' => $path, 'updated_at' => now()]);
        Audit::log($request, 'web.post_updated', ['entity_type' => 'web_post', 'entity_id' => $post, 'entity_label' => $row->title, 'after' => ['portada' => 'nueva']]);

        return response()->json(['item' => $this->postRow(DB::table('web_posts')->where('id', $post)->first())]);
    }

    public function removeCover(Request $request, string $post)
    {
        abort_unless(WebPosts::ready(), 503, WebPosts::NOT_READY);
        $row = DB::table('web_posts')->where('id', $post)->first();
        abort_unless($row, 404);

        if ($row->cover_path) {
            Storage::disk('local')->delete($row->cover_path);
        }

        DB::table('web_posts')->where('id', $post)->update(['cover_path' => null, 'updated_at' => now()]);

        return response()->json(['item' => $this->postRow(DB::table('web_posts')->where('id', $post)->first())]);
    }
}
