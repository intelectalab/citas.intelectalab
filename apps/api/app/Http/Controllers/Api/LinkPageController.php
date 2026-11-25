<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\Access;
use App\Support\Audit;
use App\Support\Channels;
use App\Support\GoogleReserve;
use App\Support\LinkPage;
use App\Support\PublicBooking;
use Illuminate\Http\Request;

class LinkPageController extends Controller
{
    private function tenant(Request $request, string $permission = 'settings.view'): Tenant
    {
        $user = $request->user();
        abort_unless($user?->tenant_id, 403);
        abort_unless($user->allows($permission), 403, Access::denial($permission, $user->tenant));
        abort_unless(PublicBooking::pageReady(), 503, 'Falta actualizar la base de datos para configurar la página de reservas (php artisan migrate).');

        return $user->tenant->loadMissing(['settings', 'activeSubscription.plan']);
    }

    private function payload(Tenant $tenant): array
    {
        return [
            'link_page'     => LinkPage::get($tenant),
            'link_page_url' => LinkPage::url($tenant),
            'channels'      => Channels::links(PublicBooking::url($tenant)),
            'channels_ready' => Channels::ready(),
            'google'        => LinkPage::google($tenant),
            'tracking'      => LinkPage::tracking($tenant),
            'open'          => PublicBooking::open($tenant),
            'visits'        => LinkPage::visits($tenant),
            'has_whatsapp'  => PublicBooking::digits(PublicBooking::page($tenant)['socials']['whatsapp'] ?? null) !== '',
        ];
    }

    public function show(Request $request)
    {
        return response()->json($this->payload($this->tenant($request)));
    }

    public function save(Request $request)
    {
        $tenant = $this->tenant($request, 'settings.edit');
        $validated = $request->validate([
            'enabled'       => 'required|boolean',
            'title'         => 'nullable|string|max:120',
            'bio'           => 'nullable|string|max:300',
            'links'         => 'array|max:' . LinkPage::MAX_LINKS,
            'links.*.label' => 'required|string|max:60',
            'links.*.url'   => ['required', 'string', 'max:500', 'regex:#^https?://#i'],
            'show_reviews'  => 'boolean',
            'show_whatsapp' => 'boolean',
            'show_location' => 'boolean',
            'show_promotions' => 'boolean',
            'show_gift_cards' => 'boolean',
        ], [
            'links.max'              => 'Puedes poner hasta ' . LinkPage::MAX_LINKS . ' enlaces propios.',
            'links.*.label.required' => 'Ponle un nombre a cada enlace, por ejemplo «Nuestra carta de servicios».',
            'links.*.url.required'   => 'Pega la dirección de cada enlace.',
            'links.*.url.regex'      => 'Cada enlace debe empezar con https://',
        ]);

        $before = LinkPage::get($tenant);
        $after = LinkPage::save($tenant, $validated);
        $this->audit($request, $tenant, 'Enlace para redes', $before, $after);

        return response()->json($this->payload($tenant));
    }

    public function saveChannels(Request $request)
    {
        $tenant = $this->tenant($request, 'settings.edit');
        $validated = $request->validate([
            'google_enabled' => 'required|boolean',
            'ga4_id'         => ['nullable', 'string', 'max:20', 'regex:/^G-[A-Za-z0-9]{4,16}$/i'],
            'meta_pixel_id'  => ['nullable', 'string', 'max:20', 'regex:/^[0-9]{6,20}$/'],
        ], [
            'ga4_id.regex'        => 'El código de Google Analytics empieza con G- seguido de letras y números, por ejemplo G-AB12CD34EF.',
            'meta_pixel_id.regex' => 'El código del Píxel de Meta tiene solo números, por ejemplo 123456789012345.',
        ]);

        $before = ['google' => LinkPage::google($tenant)['enabled'], 'tracking' => LinkPage::tracking($tenant)];
        LinkPage::saveChannels($tenant, $validated);
        $after = ['google' => LinkPage::google($tenant)['enabled'], 'tracking' => LinkPage::tracking($tenant)];
        $this->audit($request, $tenant, 'Canales de reserva', $before, $after);

        return response()->json($this->payload($tenant));
    }

    public function googleFeed(Request $request)
    {
        return response()->json(GoogleReserve::feed($this->tenant($request)));
    }

    private function audit(Request $request, Tenant $tenant, string $label, array $before, array $after): void
    {
        Audit::log($request, 'settings.changed', [
            'tenant'       => $tenant->id,
            'entity_type'  => 'tenant_settings',
            'entity_id'    => $tenant->id,
            'entity_label' => $label,
            'before'       => $before,
            'after'        => $after,
        ]);
    }
}
