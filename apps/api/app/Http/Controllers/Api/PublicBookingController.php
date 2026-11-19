<?php

namespace App\Http\Controllers\Api;

use App\Models\Tenant;
use App\Support\AppointmentFlow;
use App\Support\Branding;
use App\Support\OnlineBooking;
use App\Support\PortalLook;
use App\Support\PublicBooking;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class PublicBookingController extends PortalController
{
    protected function tenant(Request $request): Tenant
    {
        $tenant = PublicBooking::tenant((string) $request->route('slug'));
        abort_unless($tenant, 404, 'No encontramos esta página de reservas. Revisa el enlace.');
        abort_unless(PublicBooking::open($tenant), 403, PublicBooking::UNAVAILABLE);

        return $tenant;
    }

    public function show(Request $request, string $slug)
    {
        $tenant = PublicBooking::tenant($slug);
        abort_unless($tenant, 404, 'No encontramos esta página de reservas. Revisa el enlace.');

        $brand = Branding::present($tenant);
        $open = PublicBooking::open($tenant);
        $dependents = $tenant->dependents();
        $policies = $this->policies($tenant);

        return response()->json([
            'name'       => $tenant->name,
            'code'       => $tenant->code,
            'open'       => $open,
            'message'    => $open ? null : PublicBooking::UNAVAILABLE,
            'brand'      => ['primary' => $brand['primary'], 'accent' => $brand['accent'], 'logo' => $brand['logo_url']],
            'page'       => PublicBooking::page($tenant),
            'vocabulary' => $tenant->vocabulary(),
            'currency'   => \App\Support\Currency::present(\App\Support\Currency::of($tenant)),
            'currencies' => \App\Support\Currency::catalog(),
            'contact'    => PortalLook::forPortal($tenant)['contact'],
            'privacy'    => PortalLook::privacyHtml($tenant),
            'portal_signup' => $tenant->settings?->allow_customer_signup !== false,
            'document_types' => DB::table('type_documents')->where('status', 1)->orderBy('name')->get(['id', 'name', 'value', 'length', 'minimum']),
            'policies'   => array_merge($policies, ['booking_enabled' => $open]),
            'dependents' => ['enabled' => $dependents['enabled'], 'required' => $dependents['required'], 'items' => []],
            'classes'    => $open && \App\Support\ClassSessions::enabled($tenant),
        ]);
    }

    public function cover(string $slug)
    {
        $tenant = PublicBooking::tenant($slug);
        abort_unless($tenant && ($path = PublicBooking::coverPath($tenant->id)), 404);

        return response()->file(Storage::disk('local')->path($path), ['Cache-Control' => 'public, max-age=86400']);
    }

    public function publicCatalog(Request $request)
    {
        return $this->catalog($request);
    }

    public function publicAvailability(Request $request)
    {
        $request->request->remove('ignore_id');
        $request->query->remove('ignore_id');

        return $this->portalAvailability($request);
    }

    public function publicBook(Request $request)
    {
        $tenant = $this->tenant($request);
        $dependents = $tenant->dependents();

        $validated = $request->validate([
            'service_id'       => 'required|uuid',
            'branch_id'        => 'required|uuid',
            'professional_id'  => 'nullable|uuid',
            'starts_at'        => 'required|date_format:' . self::LOCAL_FORMAT,
            'notes'            => 'nullable|string|max:1000',
            'first_name'       => 'required|string|max:100',
            'last_name'        => 'required|string|max:100',
            'type_document_id' => 'nullable|uuid|exists:type_documents,id',
            'document_number'  => 'required|string|min:6|max:20|regex:/^[A-Za-z0-9-]+$/',
            'phone'            => 'required|string|min:6|max:30|regex:/^[0-9+\s()-]+$/',
            'email'            => 'required|email|max:190',
            'dependent_name'   => ($dependents['enabled'] && $dependents['required'] ? 'required' : 'nullable') . '|string|max:150',
            'dependent_species' => 'nullable|string|max:80',
            'accept_privacy'   => 'accepted',
            'marketing'        => 'nullable|boolean',
            'request_key'      => 'required|uuid',
            'website'          => 'nullable|string|max:0',
        ], [
            'accept_privacy.accepted' => 'Para reservar, acepta la política de privacidad.',
            'website.max'             => 'No se pudo completar la reserva.',
            'document_number.regex'   => 'Escribe el documento solo con letras y números.',
            'phone.regex'             => 'Escribe el celular solo con números.',
            'dependent_name.required' => 'Indica el nombre de tu ' . mb_strtolower($tenant->vocabulary()['dependent']) . '.',
        ]);

        $cacheKey = "public-booking:{$tenant->id}:{$validated['request_key']}";

        if ($previous = Cache::get($cacheKey)) {
            return response()->json($previous['body'], $previous['status']);
        }

        $lock = Cache::lock($cacheKey . ':lock', 20);

        if (!$lock->get()) {
            return response()->json(['message' => 'Estamos registrando tu reserva. Espera unos segundos.', 'code' => 'IN_PROGRESS'], 409);
        }

        try {
            $response = $this->reserve($request, $tenant, $validated);

            if ($response->getStatusCode() < 400) {
                Cache::put($cacheKey, ['status' => $response->getStatusCode(), 'body' => $response->getData(true)], now()->addMinutes(30));
            }

            return $response;
        } finally {
            $lock->release();
        }
    }

    private function reserve(Request $request, Tenant $tenant, array $validated): JsonResponse
    {
        $existing = PublicBooking::findCustomer($tenant, $validated);

        if ($existing) {
            $strikes = OnlineBooking::state($tenant, $existing->id);

            if ($strikes && $strikes['blocked']) {
                return response()->json(['message' => OnlineBooking::blockedMessage($tenant, $strikes), 'code' => 'BOOKING_BLOCKED'], 403);
            }
        }

        DB::beginTransaction();

        try {
            $customer = $existing ?? PublicBooking::createCustomer($tenant, $validated);
            $request->setUserResolver(fn () => $customer);
            $note = $existing ? PublicBooking::differences($existing, $validated) : null;

            if (!$existing || !self::privacyAccepted($customer->id)) {
                self::acceptPrivacy($tenant->id, $customer->id, 'public_booking');
            }

            if (!empty($validated['marketing']) && \App\Support\Messaging::ready()) {
                MessageController::saveConsents($tenant->id, $customer->id, ['email' => true], 'public_booking');
            }

            $response = $this->placeBooking(
                $tenant,
                $customer,
                ['service_id' => $validated['service_id'], 'branch_id' => $validated['branch_id'], 'professional_id' => $validated['professional_id'] ?? null, 'starts_at' => $validated['starts_at'], 'notes' => $validated['notes'] ?? null],
                $note,
                $tenant->dependents()['enabled'] && trim((string) ($validated['dependent_name'] ?? '')) !== ''
                    ? ['name' => $validated['dependent_name'], 'species' => $validated['dependent_species'] ?? null]
                    : null,
            );

            if ($response->getStatusCode() >= 400) {
                DB::rollBack();

                return $response;
            }

            if (!$existing) {
                \App\Support\Audit::log($request, 'customer.created', [
                    'tenant'       => $tenant->id,
                    'entity_type'  => 'customer',
                    'entity_id'    => $customer->id,
                    'entity_label' => trim($customer->code . ' · ' . \App\Support\Person::name($customer)),
                    'after'        => ['code' => $customer->code, 'origen' => 'Página pública de reservas'],
                ]);
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        $data = $response->getData(true);

        return response()->json($data + [
            'portal' => [
                'email'     => $customer->email,
                'first_use' => (bool) $customer->must_change_password,
            ],
        ], $response->getStatusCode());
    }
}
