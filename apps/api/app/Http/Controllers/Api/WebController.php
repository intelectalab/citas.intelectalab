<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SaaSPlan;
use App\Support\Currency;
use App\Support\PlanCoupons;
use App\Support\RichText;
use App\Support\WebLeads;
use App\Support\WebPosts;
use App\Support\WebSite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class WebController extends Controller
{
    public function site()
    {
        return response()->json([
            'site'           => WebSite::publicPayload(),
            'plans'          => WebSite::plans(),
            'business_types' => WebSite::businessTypes(),
            'posts'          => WebPosts::ready() ? WebPosts::published(3) : [],
            'addons'         => WebSite::addons(),
            'rubro_groups'   => collect(\App\Support\WebRubros::GROUPS)->map(fn ($slugs, $name) => ['name' => $name, 'benefits' => \App\Support\WebRubros::GROUP_BENEFITS[$name] ?? []])->values(),
            'heard_from'     => collect(WebLeads::HEARD_FROM)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
        ]);
    }

    public function businessType(string $slug)
    {
        $type = collect(WebSite::businessTypes(true))->firstWhere('slug', $slug);
        abort_unless($type, 404, 'No encontramos ese rubro.');

        return response()->json([
            'business_type' => $type,
            'testimonials'  => collect(WebSite::settings()['testimonials'])->where('rubro', $slug)->values(),
            'others'        => collect(WebSite::businessTypes())->where('slug', '!=', $slug)->values(),
            'posts'         => WebPosts::ready() ? WebPosts::published(3, $slug) : [],
        ]);
    }

    public function posts(Request $request)
    {
        if (!WebPosts::ready()) {
            return response()->json(['items' => [], 'business_types' => WebSite::businessTypes()]);
        }

        $rubro = $request->query('rubro');

        return response()->json([
            'items'          => WebPosts::published(60, is_string($rubro) && $rubro !== '' ? $rubro : null),
            'business_types' => WebSite::businessTypes(),
        ]);
    }

    public function post(string $slug)
    {
        abort_unless(WebPosts::ready(), 404);
        $post = DB::table('web_posts')->where('slug', $slug)->where('status', 'published')->where('published_at', '<=', now())->first();
        abort_unless($post, 404, 'No encontramos ese artículo.');

        return response()->json([
            'post'    => WebPosts::present($post, true),
            'related' => collect(WebPosts::published(4, $post->business_type))->where('slug', '!=', $slug)->take(3)->values(),
        ]);
    }

    public function cover(string $post)
    {
        abort_unless(WebPosts::ready(), 404);
        abort_unless(\Illuminate\Support\Str::isUuid($post), 404);
        $path = DB::table('web_posts')->where('id', $post)->value('cover_path');
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return response()->file(Storage::disk('local')->path($path), ['Cache-Control' => 'public, max-age=86400']);
    }

    public function sitemap()
    {
        return response()->json([
            'plans_updated_at' => DB::table('saas_plans')->where('status', 'active')->where('is_public_catalog', true)->max('updated_at'),
            'business_types'   => collect(WebSite::businessTypes())->map(fn ($type) => ['slug' => $type['slug'], 'updated_at' => $type['updated_at']])->values(),
            'posts'            => WebPosts::ready()
                ? DB::table('web_posts')->where('status', 'published')->where('published_at', '<=', now())->orderByDesc('published_at')->get(['slug', 'title', 'excerpt', 'seo_description', 'published_at', 'updated_at'])
                : [],
            'legal_updated_at' => WebSite::settings()['legal_updated_at'],
        ]);
    }

    public function legal(string $page)
    {
        abort_unless(array_key_exists($page, WebSite::LEGAL), 404);
        $site = WebSite::settings();

        return response()->json([
            'title'      => WebSite::LEGAL[$page],
            'html'       => RichText::clean(WebSite::fill((string) ($site['legal'][$page] ?? ''))),
            'updated_at' => $site['legal_updated_at'],
        ]);
    }

    public function visit(Request $request)
    {
        $values = $request->validate([
            'page'          => ['required', Rule::in(WebLeads::PAGES)],
            'business_type' => 'nullable|string|max:50|alpha_dash',
            'utm_source'    => 'nullable|string|max:80',
            'referrer'      => 'nullable|string|max:500',
            'post'          => ['nullable', 'string', 'max:90', 'regex:/^[a-z0-9-]+$/'],
        ]);

        WebLeads::recordVisit($values['page'], WebLeads::sourceOf($values['utm_source'] ?? null, $values['referrer'] ?? null, $request->getHost()), $values['business_type'] ?? null);

        if ($values['page'] === 'post' && !empty($values['post'])) {
            WebPosts::countView($values['post']);
        }

        return response()->noContent();
    }

    public function lead(Request $request)
    {
        abort_unless(WebLeads::ready(), 503, WebLeads::NOT_READY);
        abort_unless(WebSite::settings()['contact_form'], 404);

        $values = $request->validate([
            'name'          => 'required|string|min:2|max:150',
            'phone'         => 'required|string|min:6|max:30',
            'email'         => 'nullable|email|max:190',
            'business'      => 'nullable|string|max:200',
            'business_type' => 'nullable|string|max:50|exists:business_types,slug',
            'professionals' => 'nullable|integer|min:1|max:1000',
            'consent'       => 'accepted',
            'utm'           => 'nullable|array',
            'website'       => 'nullable|max:0',
        ], [
            'name.required'  => 'Escribe tu nombre.',
            'phone.required' => 'Escribe tu celular para que te llamemos.',
            'consent.accepted' => 'Para llamarte necesitamos que aceptes la política de privacidad.',
            'website.max'    => 'No pudimos enviar tus datos.',
        ]);

        WebLeads::create([
            'name'          => trim($values['name']),
            'phone'         => trim($values['phone']),
            'email'         => $values['email'] ?? null,
            'business'      => $values['business'] ?? null,
            'business_type' => $values['business_type'] ?? null,
            'professionals' => $values['professionals'] ?? null,
            'source'        => 'contacto',
            'utm'           => $values['utm'] ?? [],
            'note'          => 'Pidió que lo llamen desde el sitio web.',
        ]);

        return response()->json(['message' => '¡Gracias! Te llamaremos pronto.'], 201);
    }

    public function coupon(Request $request)
    {
        $values = $request->validate([
            'code'    => 'required|string|max:30',
            'plan_id' => 'nullable|uuid',
            'cycle'   => 'nullable|in:monthly,yearly',
        ], ['code.required' => 'Escribe el cupón.']);

        abort_unless(PlanCoupons::ready(), 503, PlanCoupons::NOT_READY);
        $coupon = PlanCoupons::check($values['code'], $values['plan_id'] ?? null, 'code');
        $plan = !empty($values['plan_id']) ? SaaSPlan::find($values['plan_id']) : null;
        $cycle = $values['cycle'] ?? 'monthly';
        $preview = null;

        if ($plan) {
            $price = $cycle === 'yearly' ? (float) $plan->price_yearly : (float) $plan->price_monthly;
            $redemption = (object) ['percent_off' => $coupon->percent_off, 'amount_off' => $coupon->amount_off, 'cycles_left' => $coupon->months];
            $preview = ['price' => round($price, 2), 'first_charge' => round($price - PlanCoupons::discount($redemption, $price, $cycle), 2), 'currency' => $plan->currency, 'symbol' => Currency::present($plan->currency)['symbol']];
        }

        return response()->json(['code' => $coupon->code, 'text' => PlanCoupons::text($coupon), 'preview' => $preview]);
    }
}
