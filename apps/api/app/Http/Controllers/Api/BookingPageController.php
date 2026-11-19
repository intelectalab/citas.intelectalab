<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\Access;
use App\Support\Audit;
use App\Support\PublicBooking;
use Illuminate\Http\Request;

class BookingPageController extends Controller
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
            'url'     => PublicBooking::url($tenant),
            'slug'    => $tenant->slug,
            'open'    => PublicBooking::open($tenant),
            'module'  => $tenant->hasModule('booking'),
            'enabled' => (bool) \App\Support\AppointmentFlow::rule($tenant, 'booking.enabled'),
            'page'    => PublicBooking::page($tenant),
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
            'title'              => 'nullable|string|max:120',
            'description'        => 'nullable|string|max:600',
            'show_policies'      => 'boolean',
            'socials'            => 'array',
            'socials.instagram'  => 'nullable|string|max:200',
            'socials.facebook'   => 'nullable|string|max:200',
            'socials.tiktok'     => 'nullable|string|max:200',
            'socials.whatsapp'   => 'nullable|string|max:30|regex:/^[0-9+\s()-]*$/',
        ], [
            'socials.whatsapp.regex' => 'Escribe el WhatsApp solo con números, por ejemplo 987654321.',
        ]);

        $before = PublicBooking::page($tenant);
        $after = PublicBooking::savePage($tenant, $validated);

        Audit::log($request, 'settings.changed', [
            'tenant'       => $tenant->id,
            'entity_type'  => 'tenant_settings',
            'entity_id'    => $tenant->id,
            'entity_label' => 'Página de reservas',
            'before'       => array_diff_key($before, ['cover_url' => true]),
            'after'        => array_diff_key($after, ['cover_url' => true]),
        ]);

        return response()->json($this->payload($tenant));
    }

    public function uploadCover(Request $request)
    {
        $tenant = $this->tenant($request, 'settings.edit');
        $validated = $request->validate([
            'cover' => 'required|file|mimes:png,jpg,jpeg,webp|max:3072',
        ], [
            'cover.required' => 'Elige la imagen de portada.',
            'cover.mimes'    => 'La portada debe ser una imagen PNG, JPG o WEBP.',
            'cover.max'      => 'La portada pesa más de 3 MB. Usa una imagen más liviana.',
        ]);

        PublicBooking::storeCover($tenant, $validated['cover']);

        return response()->json($this->payload($tenant));
    }

    public function removeCover(Request $request)
    {
        $tenant = $this->tenant($request, 'settings.edit');
        PublicBooking::removeCover($tenant);

        return response()->json($this->payload($tenant));
    }
}
