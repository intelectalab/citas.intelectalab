<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\CustomerDependent;
use App\Models\Tenant;
use App\Models\TenantSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class PublicBooking
{
    public const UNAVAILABLE = 'Esta página de reservas no está disponible por ahora. Comunícate con la empresa.';

    public const SOCIALS = ['instagram', 'facebook', 'tiktok', 'whatsapp'];

    private static ?bool $professionalsFlag = null;

    public static function professionalsFlag(): bool
    {
        return self::$professionalsFlag ??= Schema::hasColumn('professionals', 'online_booking');
    }

    public static function pageReady(): bool
    {
        return Schema::hasColumn('tenant_settings', 'booking_page');
    }

    public static function tenant(string $slug): ?Tenant
    {
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/', $slug)) {
            return null;
        }

        return Tenant::with(['settings', 'activeSubscription.plan'])->where('slug', $slug)->where('status', 'active')->first();
    }

    public static function open(Tenant $tenant): bool
    {
        return $tenant->hasModule('booking')
            && (bool) AppointmentFlow::rule($tenant, 'booking.enabled')
            && (bool) (self::pageReady() ? ($tenant->settings?->booking_page['published'] ?? true) : true)
            && !Subscriptions::lockedFor($tenant);
    }

    public static function url(Tenant $tenant): string
    {
        return rtrim((string) config('app.frontend_url'), '/') . '/reservar/' . $tenant->slug;
    }

    public static function page(Tenant $tenant): array
    {
        $stored = self::pageReady() ? ($tenant->settings?->booking_page ?? []) : [];
        $socials = [];

        foreach (self::SOCIALS as $key) {
            $value = trim((string) ($stored['socials'][$key] ?? ''));
            $socials[$key] = $value !== '' ? $value : null;
        }

        return [
            'title'         => trim((string) ($stored['title'] ?? '')) ?: $tenant->name,
            'description'   => trim((string) ($stored['description'] ?? '')) ?: null,
            'cover_url'     => self::coverPath($tenant->id) ? rtrim((string) config('app.url'), '/') . '/api/public/booking/' . $tenant->slug . '/cover?v=' . ($stored['cover_version'] ?? 0) : null,
            'socials'       => $socials,
            'show_policies' => (bool) ($stored['show_policies'] ?? true),
            'published'     => (bool) ($stored['published'] ?? true),
        ];
    }

    public static function savePage(Tenant $tenant, array $values): array
    {
        $settings = $tenant->settings ?? new TenantSetting(['tenant_id' => $tenant->id]);
        $stored = $settings->booking_page ?? [];

        $stored['title'] = trim((string) ($values['title'] ?? '')) ?: null;
        $stored['description'] = trim((string) ($values['description'] ?? '')) ?: null;
        $stored['show_policies'] = (bool) ($values['show_policies'] ?? true);
        $stored['socials'] = collect(self::SOCIALS)->mapWithKeys(fn ($key) => [$key => trim((string) ($values['socials'][$key] ?? '')) ?: null])->all();

        $settings->setAttribute('booking_page', $stored);
        $settings->save();
        $tenant->setRelation('settings', $settings);

        return self::page($tenant);
    }

    public static function coverPath(string $tenantId): ?string
    {
        foreach (Storage::disk('local')->files("branding/{$tenantId}") as $file) {
            if (str_starts_with(basename($file), 'booking-cover.')) {
                return $file;
            }
        }

        return null;
    }

    public static function storeCover(Tenant $tenant, UploadedFile $file): void
    {
        self::removeCover($tenant, false);
        $extension = strtolower($file->extension() ?: 'jpg');
        Storage::disk('local')->putFileAs("branding/{$tenant->id}", $file, "booking-cover.{$extension}");
        self::bumpCover($tenant);
    }

    public static function removeCover(Tenant $tenant, bool $bump = true): void
    {
        if ($path = self::coverPath($tenant->id)) {
            Storage::disk('local')->delete($path);
        }

        if ($bump) {
            self::bumpCover($tenant);
        }
    }

    private static function bumpCover(Tenant $tenant): void
    {
        $settings = $tenant->settings ?? new TenantSetting(['tenant_id' => $tenant->id]);
        $stored = $settings->booking_page ?? [];
        $stored['cover_version'] = now()->timestamp;
        $settings->setAttribute('booking_page', $stored);
        $settings->save();
        $tenant->setRelation('settings', $settings);
    }

    public static function digits(?string $value): string
    {
        return preg_replace('/\D+/', '', (string) $value) ?? '';
    }

    public static function findCustomer(Tenant $tenant, array $data): ?Customer
    {
        $document = trim((string) ($data['document_number'] ?? ''));
        $phone = self::digits($data['phone'] ?? null);

        if ($document !== '') {
            $found = Customer::where('tenant_id', $tenant->id)
                ->whereRaw('upper(trim(document_number)) = ?', [mb_strtoupper($document)])
                ->orderBy('created_at')
                ->first();

            if ($found) {
                return $found;
            }
        }

        if (strlen($phone) >= 6) {
            $tail = substr($phone, -9);

            return Customer::where('tenant_id', $tenant->id)
                ->whereNotNull('phone')
                ->whereRaw("right(regexp_replace(phone, '\\D', '', 'g'), 9) = ?", [$tail])
                ->orderBy('created_at')
                ->first();
        }

        return null;
    }

    public static function differences(Customer $customer, array $data): ?string
    {
        $notes = [];
        $phone = self::digits($data['phone'] ?? null);
        $document = trim((string) ($data['document_number'] ?? ''));
        $email = mb_strtolower(trim((string) ($data['email'] ?? '')));
        $name = trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? ''));

        if ($phone !== '' && substr(self::digits($customer->phone), -9) !== substr($phone, -9)) {
            $notes[] = "el celular {$data['phone']}";
        }

        if ($document !== '' && mb_strtoupper(trim((string) $customer->document_number)) !== mb_strtoupper($document)) {
            $notes[] = "el documento {$document}";
        }

        if ($email !== '' && mb_strtolower(trim((string) $customer->email)) !== $email) {
            $notes[] = "el correo {$email}";
        }

        if ($name !== '' && mb_strtolower(Person::name($customer)) !== mb_strtolower($name)) {
            $notes[] = "el nombre {$name}";
        }

        return $notes ? 'Reservó en la página pública con ' . implode(', ', $notes) . '. Revisa si es la misma persona.' : null;
    }

    public static function createCustomer(Tenant $tenant, array $data): Customer
    {
        $customer = Customer::create([
            'tenant_id'        => $tenant->id,
            'code'             => Customer::nextCode($tenant->id),
            'first_name'       => trim($data['first_name']),
            'last_name'        => trim($data['last_name']),
            'type_document_id' => $data['type_document_id'] ?? null,
            'document_number'  => trim((string) ($data['document_number'] ?? '')) ?: null,
            'phone'            => Phones::normalize($data['phone'] ?? null, Phones::prefixFor($tenant)) ?? (trim((string) ($data['phone'] ?? '')) ?: null),
            'email'            => trim((string) ($data['email'] ?? '')) ?: null,
        ]);

        $customer->syncPortalPassword();

        return $customer;
    }

    public static function dependentFor(Tenant $tenant, Customer $customer, array $data): ?string
    {
        $config = $tenant->dependents();
        $name = trim((string) ($data['name'] ?? ''));

        if (!$config['enabled'] || $name === '') {
            return null;
        }

        $existing = CustomerDependent::where('tenant_id', $tenant->id)
            ->where('customer_id', $customer->id)
            ->where('status', 'active')
            ->whereRaw('lower(trim(name)) = ?', [mb_strtolower($name)])
            ->first();

        if ($existing) {
            return $existing->id;
        }

        return CustomerDependent::create([
            'tenant_id'   => $tenant->id,
            'customer_id' => $customer->id,
            'name'        => $name,
            'kind'        => $config['kind'],
            'species'     => trim((string) ($data['species'] ?? '')) ?: null,
            'status'      => 'active',
        ])->id;
    }
}
