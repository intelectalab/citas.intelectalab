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
            'published' => PublicBooking::page($tenant)['published'],
            'widget'  => '<iframe src="' . e(PublicBooking::url($tenant)) . '?origen=web" title="Reservar en ' . e($tenant->name) . '" style="width:100%;min-height:760px;border:0;border-radius:16px" loading="lazy"></iframe>',
            'button'  => '<a href="' . e(PublicBooking::url($tenant)) . '?origen=web" target="_blank" rel="noopener" style="display:inline-block;padding:12px 22px;border-radius:12px;background:#022340;color:#fff;font-weight:700;text-decoration:none">Reservar cita</a>',
            'policies' => $this->policies($tenant),
        ];
    }

    private function policies(Tenant $tenant): array
    {
        $rule = fn (string $key) => \App\Support\AppointmentFlow::rule($tenant, $key);
        $lead = (int) $rule('booking.min_lead_minutes');
        $cancel = (int) $rule('cancel.window_hours');
        $out = [];
        $out[] = $lead > 0 ? 'Se reserva con al menos ' . ($lead % 60 === 0 ? ($lead / 60) . ' ' . ($lead === 60 ? 'hora' : 'horas') : "{$lead} minutos") . ' de anticipación.' : 'Se puede reservar para el mismo momento.';
        $out[] = 'Se puede reservar hasta ' . (int) $rule('booking.max_days_ahead') . ' días adelante.';
        $out[] = (bool) $rule('booking.auto_confirm') ? 'Las reservas se confirman solas.' : 'Tu equipo confirma cada reserva.';
        $out[] = (bool) $rule('cancel.client_allowed') ? 'El cliente puede cancelar hasta ' . $cancel . ' ' . ($cancel === 1 ? 'hora' : 'horas') . ' antes.' : 'El cliente no puede cancelar por su cuenta.';
        $times = (int) $rule('reschedule.max_times');
        if ($times > 0) {
            $out[] = 'Puede cambiar la cita hasta ' . $times . ' ' . ($times === 1 ? 'vez' : 'veces') . '.';
        }
        if (\App\Support\Penalties::enabled($tenant)) {
            $rules = \App\Support\Penalties::rules($tenant);
            $out[] = "Cancelar tarde cobra {$rules['late_cancel_percent']} % y no asistir, {$rules['no_show_percent']} %.";
        }

        return $out;
    }

    public function publish(Request $request)
    {
        $tenant = $this->tenant($request, 'settings.edit');
        $published = $request->validate(['published' => 'required|boolean'])['published'];
        $settings = $tenant->settings ?? new \App\Models\TenantSetting(['tenant_id' => $tenant->id]);
        $stored = $settings->booking_page ?? [];
        $stored['published'] = (bool) $published;
        $settings->setAttribute('booking_page', $stored);
        $settings->save();
        $tenant->setRelation('settings', $settings);

        Audit::log($request, $published ? 'booking_page.published' : 'booking_page.unpublished', ['tenant' => $tenant->id, 'entity_type' => 'tenant_settings', 'entity_id' => $tenant->id, 'entity_label' => 'Página de reservas', 'after' => ['published' => (bool) $published]]);

        return response()->json($this->payload($tenant) + ['message' => $published ? 'Tu página de reservas está publicada.' : 'Tu página de reservas quedó despublicada: quien entre verá que no está disponible.']);
    }

    public function slug(Request $request)
    {
        $tenant = $this->tenant($request, 'settings.edit');
        $slug = \Illuminate\Support\Str::lower(trim((string) $request->input('slug')));
        $request->merge(['slug' => $slug]);
        $request->validate([
            'slug' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', \Illuminate\Validation\Rule::unique('tenants', 'slug')->ignore($tenant->id)],
        ], [
            'slug.required' => 'Escribe la dirección de tu página.',
            'slug.min'      => 'La dirección debe tener al menos 3 letras.',
            'slug.regex'    => 'Usa solo letras minúsculas sin tildes, números y guiones (sin espacios), por ejemplo mi-salon.',
            'slug.unique'   => 'Esa dirección ya la usa otra empresa. Prueba con otra.',
        ]);

        $before = $tenant->slug;

        if ($before !== $slug) {
            $tenant->update(['slug' => $slug]);
            Audit::log($request, 'booking_page.slug_changed', ['tenant' => $tenant->id, 'entity_type' => 'tenant', 'entity_id' => $tenant->id, 'entity_label' => 'Página de reservas', 'before' => ['slug' => $before], 'after' => ['slug' => $slug]]);
        }

        return response()->json($this->payload($tenant->fresh(['settings', 'activeSubscription.plan'])) + ['message' => 'Dirección guardada. Los enlaces y el código QR anteriores ya no funcionan: comparte los nuevos.']);
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
