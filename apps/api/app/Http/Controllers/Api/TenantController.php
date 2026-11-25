<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\SaasModule;
use App\Models\SaaSPlan;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Support\TenantProvisioner;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TenantController extends Controller
{
    public const STATUSES = ['active', 'suspended', 'inactive'];

    public function index(Request $request)
    {
        $query = Tenant::with(['subscription.plan', 'businessType', 'users'])->whereNull('deleted_at');

        if ($request->has('search') && !empty($request->input('search'))) {
            $search = $request->input('search');
            $query->where(function($q) use ($search) {
                \App\Support\Search::contains($q, 'name', (string) $search);
                \App\Support\Search::orContains($q, 'slug', (string) $search);
            });
        }

        if ($request->has('status') && !empty($request->input('status'))) {
            $request->input('status') === 'not_active'
                ? $query->where('status', '<>', 'active')
                : $query->where('status', $request->input('status'));
        }

        $tenants = $query->latest()->get();
        $ids = $tenants->pluck('id')->all();
        $appointments = $ids ? DB::table('appointments')->whereIn('tenant_id', $ids)->where('starts_at', '>=', now()->subDays(30))->where('starts_at', '<', now())->groupBy('tenant_id')->selectRaw('tenant_id, count(*) as total')->pluck('total', 'tenant_id') : collect();
        $tenants->each(function ($tenant) use ($appointments) {
            $state = \App\Support\Subscriptions::state($tenant);
            $subscription = \App\Support\Subscriptions::latest($tenant->id);
            $tenant->setAttribute('billing_state', $state['state']);
            $tenant->setAttribute('mrr', \App\Support\Subscriptions::mrr($subscription, $state));
            $tenant->setAttribute('active_users', $tenant->users->where('status', 'active')->count());
            $tenant->setAttribute('appointments_30d', (int) ($appointments[$tenant->id] ?? 0));
            $tenant->setAttribute('trial_ends_on', $state['state'] === 'trial' && $state['ends_at'] ? Carbon::parse($state['ends_at'])->setTimezone($tenant->zone())->format('Y-m-d') : null);
        });

        return response()->json($tenants);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'             => 'required|string|max:150',
            'timezone'         => 'required|string|max:100',
            'status'           => ['required', Rule::in(self::STATUSES)],
            'business_type_id' => ['required', 'uuid', Rule::exists('business_types', 'id')],
            'plan_id'          => ['required', 'uuid', Rule::exists('saas_plans', 'id')->where('status', 'active')],
            'start_trial'      => 'sometimes|boolean',
            'billing_cycle'    => 'sometimes|in:monthly,yearly',
            'legal_name'       => 'required|string|max:200',
            'tax_id'           => ['required', 'string', 'regex:/^(10|20)\d{9}$/'],
            'phone'            => 'nullable|string|max:30',
            'address'          => 'nullable|string|max:255',
            'department'       => 'nullable|string|max:100',
            'district'         => 'nullable|string|max:100',
            'branch_name'      => 'required|string|max:150',
            'admin_first_name' => 'required|string|max:100',
            'admin_last_name'  => 'required|string|max:100',
            'admin_email'      => 'required|email|max:190',
            'payment_method'   => 'nullable|in:card,manual',
            'load_sample'      => 'sometimes|boolean',
            'confirm_duplicates' => 'sometimes|boolean',
        ], [
            'tax_id.regex' => 'El RUC tiene 11 dígitos y empieza con 10 (persona) o 20 (empresa).',
        ]);

        $duplicates = [];
        $sameTax = DB::table('companies as c')->join('tenants as t', 't.id', '=', 'c.tenant_id')->whereNull('t.deleted_at')->where('c.tax_id', $validated['tax_id'])->value('t.name');
        $sameEmail = DB::table('users as u')->leftJoin('tenants as t', 't.id', '=', 'u.tenant_id')->whereRaw('lower(u.email) = ?', [mb_strtolower($validated['admin_email'])])->value('t.name');
        $sameName = DB::table('tenants')->whereNull('deleted_at')->whereRaw('lower(name) = ?', [mb_strtolower(trim($validated['name']))])->value('name');

        if ($sameTax) {
            $duplicates[] = "El RUC {$validated['tax_id']} ya lo usa «{$sameTax}».";
        }
        if ($sameEmail !== null || DB::table('users')->whereRaw('lower(email) = ?', [mb_strtolower($validated['admin_email'])])->exists()) {
            $duplicates[] = 'El correo ' . $validated['admin_email'] . ' ya tiene una cuenta' . ($sameEmail ? " en «{$sameEmail}»" : '') . '.';
        }
        if ($sameName) {
            $duplicates[] = "Ya hay una empresa llamada «{$sameName}».";
        }

        if ($duplicates && empty($validated['confirm_duplicates'])) {
            return response()->json(['message' => 'Revisa antes de crear: ' . implode(' ', $duplicates) . ' Si es correcto, marca «Crear igual».', 'code' => 'DUPLICATES', 'duplicates' => $duplicates], 409);
        }

        $password = Str::password(12, symbols: false);

        $result = TenantProvisioner::provision([
            'name'             => $validated['name'],
            'legal_name'       => $validated['legal_name'],
            'trade_name'       => $validated['name'],
            'tax_id'           => $validated['tax_id'],
            'phone'            => $validated['phone'] ?? null,
            'address'          => $validated['address'] ?? null,
            'department'       => $validated['department'] ?? null,
            'district'         => $validated['district'] ?? null,
            'business_type_id' => $validated['business_type_id'],
            'plan_id'          => $validated['plan_id'],
            'start_trial'      => $validated['start_trial'] ?? true,
            'billing_cycle'    => $validated['billing_cycle'] ?? 'monthly',
            'branch_name'      => $validated['branch_name'],
            'timezone'         => $validated['timezone'],
            'status'           => $validated['status'],
            'first_name'       => $validated['admin_first_name'],
            'last_name'        => $validated['admin_last_name'],
            'email'            => $validated['admin_email'],
            'password'         => $password,
        ]);

        $sample = null;

        if (!empty($validated['load_sample']) && \App\Support\SampleData::ready()) {
            try {
                $sample = \App\Support\SampleData::create($result['tenant']->fresh(), $result['user']);
            } catch (\Throwable $e) {
                $sample = ['error' => $e->getMessage()];
            }
        }

        $invited = \App\Support\AccountSecurity::invite($result['user'], $result['tenant'], false);

        if (!$invited && \Illuminate\Support\Facades\Schema::hasColumn('users', 'must_change_password')) {
            $result['user']->forceFill(['must_change_password' => true])->saveQuietly();
        }

        AuditEvent::record($request, 'tenant.created', $result['tenant'], null, [
            'plan_id'          => $validated['plan_id'],
            'business_type_id' => $validated['business_type_id'],
            'owner_email'      => $validated['admin_email'],
            'invited'          => $invited,
        ]);

        return response()->json([
            'message' => 'Tenant creado correctamente',
            'data' => $result['tenant'],
            'installed' => $result['installed'],
            'sample'    => $sample,
            'owner' => ['email' => $validated['admin_email'], 'invited' => $invited, 'temporary_password' => $invited ? null : $password],
        ], 201);
    }

    public function show($id)
    {
        return response()->json($this->detail(Tenant::findOrFail($id)));
    }

    public function update(Request $request, $id)
    {
        $tenant = Tenant::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:150',
            'slug' => 'sometimes|required|string|max:150|unique:tenants,slug,' . $tenant->id,
            'timezone' => 'sometimes|required|string|max:100',
            'status' => ['sometimes', 'required', Rule::in(self::STATUSES)],
        ]);

        $previousStatus = $tenant->status;

        if (($validated['status'] ?? null) === 'active' && $previousStatus !== 'active' && $tenant->delete_on) {
            throw \Illuminate\Validation\ValidationException::withMessages(['status' => 'Esta empresa tiene el borrado programado: primero anula el borrado desde su página.']);
        }

        if (isset($validated['status']) && $validated['status'] !== 'active' && $validated['status'] !== $previousStatus) {
            $reason = trim((string) $request->input('reason'));

            if (mb_strlen($reason) < 5) {
                throw \Illuminate\Validation\ValidationException::withMessages(['status' => 'Escribe el motivo (al menos 5 letras) para cambiar el estado de la empresa.']);
            }

            $validated['suspension_reason'] = mb_substr($reason, 0, 255);
        }

        [$was, $now] = \App\Support\Audit::changed($tenant->only(array_keys($validated)), $validated);
        $tenant->update($validated);
        unset($was['status'], $now['status']);

        if ($now) {
            AuditEvent::record($request, 'tenant.updated', $tenant, $was, $now);
        }

        if ($tenant->status !== $previousStatus) {
            $this->afterStatusChange($request, $tenant, $previousStatus);
        }

        return response()->json([
            'message' => 'Tenant actualizado correctamente',
            'data' => $tenant
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $tenant = \App\Support\TenantDeletion::schedule($request, Tenant::findOrFail($id));
        $deletion = \App\Support\TenantDeletion::present($tenant);
        $day = \Carbon\Carbon::parse($deletion['delete_on'])->locale('es')->isoFormat('D [de] MMMM [de] YYYY');

        return response()->json($this->detail($tenant) + [
            'message' => "La empresa quedó desactivada y sus datos se borran el {$day}. Hasta entonces puedes anular el borrado desde su página.",
        ]);
    }

    public function cancelDeletion(Request $request, $id)
    {
        $tenant = \App\Support\TenantDeletion::cancel($request, Tenant::findOrFail($id));

        return response()->json($this->detail($tenant) + [
            'message' => 'Se anuló el borrado. La empresa sigue desactivada y con el plan cancelado: reactívala y registra su pago cuando corresponda.',
        ]);
    }

    public function suspend(Request $request, $id)
    {
        $tenant = Tenant::findOrFail($id);
        $validated = $request->validate(['reason' => 'required|string|min:5|max:255'], [
            'reason.required' => 'Escribe por qué suspendes la empresa.',
            'reason.min'      => 'Explica un poco más por qué la suspendes.',
        ]);

        $previousStatus = $tenant->status;
        $tenant->update(['status' => 'suspended', 'suspension_reason' => $validated['reason'] ?? null]);
        $this->afterStatusChange($request, $tenant, $previousStatus);

        return response()->json($this->detail($tenant));
    }

    public function reactivate(Request $request, $id)
    {
        $tenant = Tenant::findOrFail($id);
        $validated = $request->validate(['reason' => 'required|string|min:5|max:255'], [
            'reason.required' => 'Escribe por qué reactivas la empresa.',
            'reason.min'      => 'Explica un poco más por qué la reactivas.',
        ]);

        if ($tenant->delete_on) {
            throw \Illuminate\Validation\ValidationException::withMessages(['reason' => 'Esta empresa tiene el borrado programado: primero anula el borrado y después reactívala.']);
        }

        $previousStatus = $tenant->status;
        $tenant->update(['status' => 'active', 'suspension_reason' => null]);
        $this->afterStatusChange($request, $tenant, $previousStatus, $validated['reason']);

        return response()->json($this->detail($tenant));
    }

    public function changePlan(Request $request, $id)
    {
        $tenant = Tenant::findOrFail($id);

        $validated = $request->validate([
            'plan_id' => ['required', 'uuid', Rule::exists('saas_plans', 'id')->where('status', 'active')],
            'billing_cycle' => 'nullable|in:monthly,yearly',
            'reactivate' => 'sometimes|boolean',
            'reason' => 'nullable|string|max:255',
        ]);

        if ($tenant->delete_on) {
            throw \Illuminate\Validation\ValidationException::withMessages(['plan_id' => 'Esta empresa tiene el borrado programado: primero anula el borrado.']);
        }

        $plan = SaaSPlan::findOrFail($validated['plan_id']);
        $exceeded = \App\Support\PlanChanges::exceeded($tenant, $plan);

        if ($exceeded) {
            $message = "No cabe en el plan {$plan->name}: " . implode('; ', array_map(fn ($row) => "{$row['label']}: usa {$row['used']} y el plan permite {$row['limit']}", $exceeded)) . '. La empresa primero tiene que ajustar lo que usa.';

            return response()->json(['message' => $message, 'code' => 'PLAN_LIMIT', 'errors' => ['plan_id' => [$message]], 'limits' => $exceeded], 422);
        }

        $subscription = Subscription::where('tenant_id', $tenant->id)->latest('starts_at')->first();
        $before = ['plan_id' => $subscription?->plan_id, 'status' => $subscription?->status, 'ends_at' => $subscription?->ends_at?->toIso8601String()];
        $reactivated = false;
        $reason = trim((string) ($validated['reason'] ?? ''));

        if (!$subscription?->isCurrent()) {
            if (!$request->boolean('reactivate')) {
                $message = 'La suscripción de esta empresa no está vigente (venció o se canceló). Cambiar el plan no la reactiva sola: registra su pago en «Cobro del plan», o elige «Reactivar sin cobrar» y escribe el motivo.';

                return response()->json(['message' => $message, 'code' => 'NEEDS_REACTIVATION', 'errors' => ['reactivate' => [$message]]], 422);
            }

            if (mb_strlen($reason) < 10) {
                throw \Illuminate\Validation\ValidationException::withMessages(['reason' => 'Escribe por qué la reactivas sin cobrar (al menos 10 letras). Queda en la bitácora de la plataforma.']);
            }

            $reactivated = true;
        }

        if (!$reactivated) {
            $subscription->update(array_filter([
                'plan_id' => $validated['plan_id'],
                'billing_cycle' => $validated['billing_cycle'] ?? null,
            ]));
        } else {
            $cycle = $validated['billing_cycle'] ?? $subscription?->billing_cycle ?? 'monthly';
            $data = [
                'plan_id' => $validated['plan_id'],
                'status' => 'active',
                'billing_cycle' => $cycle,
                'starts_at' => now(),
                'ends_at' => $cycle === 'yearly' ? now()->addYear() : now()->addMonth(),
                'trial_ends_at' => null,
                'canceled_at' => null,
            ] + (\App\Support\Cancellations::ready() ? ['cancel_at' => null] : []);

            if ($subscription) {
                $subscription->update($data);
            } else {
                $subscription = new Subscription($data + ['tenant_id' => $tenant->id]);
                $subscription->id = (string) Str::uuid();
                $subscription->save();
            }
        }

        AuditEvent::record($request, 'tenant.plan_changed', $tenant, $before, array_filter([
            'plan_id' => $validated['plan_id'],
            'status' => $subscription->status,
            'reactivated_without_payment' => $reactivated ?: null,
            'ends_at' => $reactivated ? $subscription->ends_at?->toIso8601String() : null,
            'reason' => $reactivated ? mb_substr($reason, 0, 255) : null,
        ], fn ($value) => $value !== null));
        \App\Support\Subscriptions::forget($tenant->id);
        \App\Support\SaasEvents::record($tenant->id);

        return response()->json($this->detail($tenant));
    }

    public function changeBusinessType(Request $request, $id)
    {
        $tenant = Tenant::findOrFail($id);

        $validated = $request->validate([
            'business_type_id' => ['required', 'uuid', Rule::exists('business_types', 'id')],
            'install_template' => 'sometimes|boolean',
        ]);

        $before = ['business_type_id' => $tenant->business_type_id];
        $tenant->business_type_id = $validated['business_type_id'];
        $tenant->unsetRelation('businessType');
        $tenant->fillBusinessProfile()->save();
        $tenant->settings()->updateOrCreate([], ['modules_off' => Tenant::startingModulesOff($tenant->businessType)]);
        $tenant->unsetRelation('settings');

        $installed = !empty($validated['install_template'])
            ? DB::transaction(fn () => $tenant->installBusinessTemplate() + \App\Support\BusinessTemplates::installExtras($tenant))
            : null;

        AuditEvent::record($request, 'tenant.business_type_changed', $tenant, $before, ['business_type_id' => $tenant->business_type_id, 'installed' => $installed]);

        return response()->json($this->detail($tenant) + ['installed' => $installed]);
    }

    public function updateModules(Request $request, $id)
    {
        $tenant = Tenant::findOrFail($id);
        $optional = SaasModule::where('is_core', false)->pluck('key')->all();

        $validated = $request->validate([
            'modules_off'   => 'present|array',
            'modules_off.*' => ['string', 'distinct', Rule::in($optional)],
        ], [
            'modules_off.*.in' => 'Ese módulo no existe o es un módulo base, que no se puede apagar.',
        ]);

        $before = ['modules_off' => $tenant->modules_off ?? []];
        $tenant->update(['modules_off' => array_values($validated['modules_off'])]);

        AuditEvent::record($request, 'tenant.modules_changed', $tenant, $before, ['modules_off' => $tenant->modules_off]);

        return response()->json($this->detail($tenant));
    }

    private function afterStatusChange(Request $request, Tenant $tenant, string $previousStatus, ?string $reason = null): void
    {
        if (!$tenant->isActive()) {
            DB::table('personal_access_tokens')
                ->where(fn ($q) => $q
                    ->whereIn('tokenable_id', DB::table('users')->where('tenant_id', $tenant->id)->select(DB::raw('id::text')))
                    ->orWhereIn('tokenable_id', DB::table('customers')->where('tenant_id', $tenant->id)->select(DB::raw('id::text'))))
                ->delete();
        }

        AuditEvent::record(
            $request,
            $tenant->isActive() ? 'tenant.reactivated' : 'tenant.suspended',
            $tenant,
            ['status' => $previousStatus],
            ['status' => $tenant->status, 'reason' => $reason ?? $tenant->suspension_reason],
        );
    }

    public function userSecurity(Request $request, string $tenant, string $user)
    {
        $tenant = Tenant::findOrFail($tenant);
        $target = \App\Models\User::where('tenant_id', $tenant->id)->findOrFail($user);
        abort_unless(\App\Support\AccountSecurity::ready(), 503, 'Falta actualizar la base de datos: ejecuta php artisan migrate.');
        $action = $request->validate(['action' => ['required', \Illuminate\Validation\Rule::in(['unlock', 'code_off', 'code_default'])]])['action'];
        $before = ['mfa_mode' => $target->mfa_mode, 'locked' => (bool) \App\Support\AccountSecurity::lockedMinutes($target)];

        if ($action === 'unlock') {
            \App\Support\AccountSecurity::unlock($target);
        } else {
            $target->forceFill(['mfa_mode' => $action === 'code_off' ? 'off' : null])->saveQuietly();
        }

        AuditEvent::record($request, 'tenant.user_security', $tenant, $before, ['user' => $target->email, 'mfa_mode' => $target->mfa_mode, 'locked' => (bool) \App\Support\AccountSecurity::lockedMinutes($target)]);

        return response()->json($this->detail($tenant));
    }

    public function userInvite(Request $request, string $tenant, string $user)
    {
        $tenant = Tenant::findOrFail($tenant);
        $target = \App\Models\User::where('tenant_id', $tenant->id)->findOrFail($user);
        abort_unless(\App\Support\PlatformMail::ready(), 422, 'El correo de la plataforma no está funcionando: dale una contraseña temporal.');
        abort_unless(\App\Support\AccountSecurity::invite($target, $tenant, false), 503, 'No se pudo enviar la invitación. Revisa el correo de la plataforma.');
        AuditEvent::record($request, 'tenant.user_invited', $tenant, [], ['user' => $target->email]);

        return response()->json(['message' => "Le enviamos la invitación a {$target->email}. El enlace vale " . \App\Support\AccountSecurity::INVITE_DAYS . ' días.']);
    }

    public function userPassword(Request $request, string $tenant, string $user)
    {
        $tenant = Tenant::findOrFail($tenant);
        $target = \App\Models\User::where('tenant_id', $tenant->id)->findOrFail($user);
        $password = Str::password(10, symbols: false);

        $target->forceFill(['password_hash' => \Illuminate\Support\Facades\Hash::make($password), 'must_change_password' => true] + (\App\Support\AccountSecurity::ready() ? ['failed_logins' => 0, 'locked_until' => null] : []))->saveQuietly();
        $target->tokens()->delete();

        if (\App\Support\AccountSecurity::ready()) {
            \App\Support\AccountSecurity::forgetDevices($target);
        }

        AuditEvent::record($request, 'tenant.user_password', $tenant, [], ['user' => $target->email]);

        return response()->json(['email' => $target->email, 'temporary_password' => $password]);
    }

    public function userEmail(Request $request, string $tenant, string $user)
    {
        $tenant = Tenant::findOrFail($tenant);
        $target = \App\Models\User::where('tenant_id', $tenant->id)->findOrFail($user);
        $request->merge(['email' => is_string($request->email) ? Str::lower(trim($request->email)) : $request->email]);
        $email = $request->validate([
            'email' => ['required', 'email', 'max:190', \Illuminate\Validation\Rule::unique('users', 'email')->where('tenant_id', $tenant->id)->ignore($target->id)],
        ], [
            'email.required' => 'Escribe el correo nuevo.',
            'email.email'    => 'Ese correo no es válido.',
            'email.unique'   => 'Otra cuenta de esta empresa ya usa ese correo.',
        ])['email'];

        $old = $target->email;

        if ($old !== $email) {
            $target->forceFill(['email' => $email])->saveQuietly();

            if (\App\Support\AccountSecurity::ready()) {
                \App\Support\AccountSecurity::forgetDevices($target);
            }

            AuditEvent::record($request, 'tenant.user_email', $tenant, ['email' => $old], ['email' => $email]);
            $body = '<p>Hola ' . e($target->first_name) . ':</p><p>El correo con el que entras a <strong>' . e($tenant->name) . '</strong> cambió a <strong>' . e($email) . '</strong>. Lo cambió el equipo de soporte de la plataforma.</p><p>Si no lo pediste, escríbenos.</p>';
            \App\Support\PlatformMail::trySend($email, 'Tu correo de acceso cambió', $body, $tenant);
            \App\Support\PlatformMail::trySend($old, 'Tu correo de acceso cambió', $body, $tenant);
        }

        return response()->json($this->detail($tenant));
    }

    public function detail(Tenant $tenant): array
    {
        $tenant = Tenant::with(['businessType', 'settings', 'company', 'users.roles'])->findOrFail($tenant->id);

        $subscription = Subscription::with('plan')->where('tenant_id', $tenant->id)->latest('starts_at')->first();
        $plan = $subscription?->plan;
        $isCurrent = (bool) $subscription?->isCurrent();

        $planKeys = $plan?->moduleKeys() ?? [];
        $platformOff = $tenant->modules_off ?? [];
        $tenantOff = $tenant->settings?->modules_off ?? [];
        $active = $tenant->modules();

        $modules = SaasModule::orderBy('sort_order')->get()->map(function (SaasModule $module) use ($isCurrent, $planKeys, $platformOff, $tenantOff, $active) {
            $inPlan = $module->is_core || in_array($module->key, $planKeys, true);

            $reason = match (true) {
                !$isCurrent                                  => 'no_subscription',
                !$inPlan                                     => 'plan',
                in_array($module->key, $platformOff, true)   => 'platform',
                in_array($module->key, $tenantOff, true)     => 'tenant',
                default                                      => null,
            };

            return [
                'key'          => $module->key,
                'name'         => $module->name,
                'is_core'      => $module->is_core,
                'in_plan'      => $inPlan,
                'enabled'      => in_array($module->key, $active, true),
                'off_reason'   => $reason,
                'platform_off' => in_array($module->key, $platformOff, true),
            ];
        });

        $limits = \App\Support\Addons::extend($tenant->id, $plan?->limits);
        $usage = collect($tenant->usage())->map(fn ($used, $key) => [
            'key'   => $key,
            'used'  => $used,
            'limit' => isset($limits[$key]) ? (int) $limits[$key] : null,
        ])->values();

        $invoices = $subscription
            ? $subscription->invoices()->with('status')->latest('invoice_date')->limit(24)->get()
            : collect();

        $owner = $tenant->users->firstWhere('is_owner', true);

        return [
            'id'                => $tenant->id,
            'name'              => $tenant->name,
            'slug'              => $tenant->slug,
            'code'              => $tenant->code,
            'timezone'          => $tenant->timezone,
            'status'            => $tenant->status,
            'suspension_reason' => $tenant->suspension_reason,
            'created_at'        => $tenant->created_at,
            'business_type'     => $tenant->businessType?->only(['id', 'slug', 'name', 'short_name', 'color']),
            'business_profile_outdated' => $tenant->businessType ? $tenant->outdatedProfileParts() : [],
            'business_profile_synced_at' => $tenant->business_profile_synced_at,
            'company'           => $tenant->company?->only(['legal_name', 'trade_name', 'tax_id', 'phone', 'address', 'district', 'department']),
            'subscription'      => $subscription ? [
                'id'            => $subscription->id,
                'status'        => $subscription->status,
                'is_current'    => $isCurrent,
                'billing_cycle' => $subscription->billing_cycle,
                'starts_at'     => $subscription->starts_at,
                'ends_at'       => $subscription->ends_at,
                'trial_ends_at' => $subscription->trial_ends_at,
                'canceled_at'   => $subscription->canceled_at,
                'plan'          => $plan?->only(['id', 'name', 'slug', 'price_monthly', 'price_yearly', 'currency', 'is_public_catalog']),
                'list_price'    => \App\Support\Subscriptions::price($plan, $subscription->billing_cycle ?: 'monthly'),
                'charge'        => \App\Support\Subscriptions::charge($subscription),
                'mrr'           => \App\Support\Subscriptions::mrr($subscription),
            ] : null,
            'deletion'          => \App\Support\TenantDeletion::present($tenant),
            'invoices' => $invoices->map(fn ($invoice) => [
                'id'           => $invoice->id,
                'amount'       => $invoice->amount,
                'currency'     => $invoice->currency,
                'status'       => $invoice->status?->name,
                'status_slug'  => $invoice->status?->slug,
                'reason'       => $invoice->billing_reason,
                'invoice_date' => $invoice->invoice_date,
                'paid_at'      => $invoice->paid_at,
                'period_start' => $invoice->period_start,
                'period_end'   => $invoice->period_end,
                'reference'    => $invoice->external_invoice_id,
                'notes'        => $invoice->notes,
            ]),
            'billing' => \App\Support\Subscriptions::present($tenant),
            'plan_payments' => $subscription ? \App\Support\PlanBilling::history($subscription->id) : [],
            'modules' => $modules,
            'usage'   => $usage,
            'users'   => $tenant->users->values()->map(fn ($user) => [
                'id'         => $user->id,
                'first_name' => $user->first_name,
                'last_name'  => $user->last_name,
                'email'      => $user->email,
                'status'     => $user->status,
                'is_owner'   => $user->is_owner,
                'role'       => $user->is_owner ? 'Dueño' : $user->roles->first()?->name,
                'security'   => \App\Support\AccountSecurity::present($user),
            ]),
            'settings' => [
                'vocabulary'            => $tenant->vocabulary(),
                'vocabulary_overrides'  => (object) ($tenant->settings?->vocabulary ?? []),
                'modules_off'           => $tenant->settings?->modules_off ?? [],
                'rules'                 => (object) $tenant->rules(),
                'rules_overrides'       => (object) ($tenant->settings?->rules ?? []),
                'dependents'            => $tenant->dependents(),
                'allow_customer_signup' => $tenant->settings?->allow_customer_signup ?? true,
                'brand_color'           => $tenant->settings?->brand_color,
            ],
            'customer_accounts' => DB::table('customers')->where('tenant_id', $tenant->id)->whereNull('deleted_at')->whereNotNull('password_hash')->count(),
            'can_impersonate'   => (bool) $owner,
        ];
    }
}
