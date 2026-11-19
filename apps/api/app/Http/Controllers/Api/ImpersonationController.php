<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Support\Notifier;
use App\Support\SupportDesk;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ImpersonationController extends Controller
{
    public const TOKEN_PREFIX = 'impersonation:';
    public const HOURS = 2;

    public function start(Request $request, string $tenant)
    {
        $tenant = Tenant::findOrFail($tenant);
        $validated = $request->validate([
            'reason'    => 'required|string|min:5|max:500',
            'ticket_id' => 'nullable|uuid',
        ], [
            'reason.required' => 'Escribe para qué entras a la empresa. Se guarda en su auditoría y la empresa lo ve.',
            'reason.min'      => 'Explica un poco más para qué entras.',
        ]);
        $ticket = SupportDesk::assertConsent($tenant, $validated['ticket_id'] ?? null);

        $owner = $tenant->users()
            ->where('status', 'active')
            ->orderByDesc('is_owner')
            ->orderBy('created_at')
            ->first();

        if (!$owner || !$owner->is_owner) {
            return response()->json([
                'message' => 'La empresa no tiene una cuenta de dueño activa para ingresar.',
            ], 422);
        }

        $token = $owner->createToken(
            self::TOKEN_PREFIX . $request->user()->id,
            ['*'],
            now()->addHours((int) (PlatformSetting::value('impersonation_hours') ?: self::HOURS)),
        );

        $reason = trim($validated['reason']);

        AuditEvent::record($request, 'impersonation.started', $tenant, null, array_filter([
            'as_user_id' => $owner->id,
            'expires_at' => $token->accessToken->expires_at,
            'reason'     => $reason,
            'ticket'     => $ticket?->code,
        ]));

        if (Schema::hasTable('saas_support_sessions')) {
            DB::table('saas_support_sessions')->insert([
                'id'               => (string) Str::uuid(),
                'tenant_id'        => $tenant->id,
                'platform_user_id' => $request->user()->id,
                'as_user_id'       => $owner->id,
                'token_id'         => $token->accessToken->id,
                'reason'           => $reason,
                'ticket_id'        => $ticket?->id,
                'started_at'       => now(),
                'expires_at'       => $token->accessToken->expires_at,
            ]);
        }

        $who = trim($request->user()->first_name . ' ' . $request->user()->last_name) ?: 'Soporte';

        try {
            foreach (Notifier::adminIds($tenant->id) as $userId) {
                Notifier::send($tenant->id, $userId, 'support.access', 'Soporte entró a tu cuenta', "{$who}: {$reason}", '/audit', 'tenant', $tenant->id, 'support-access:' . $token->accessToken->id . ':' . $userId);
            }
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'access_token' => $token->plainTextToken,
            'token_type'   => 'Bearer',
            'expires_at'   => $token->accessToken->expires_at,
            'tenant'       => ['id' => $tenant->id, 'name' => $tenant->name],
        ]);
    }

    public function stop(Request $request)
    {
        $token = $request->user()->currentAccessToken();

        abort_unless(str_starts_with((string) $token->name, self::TOKEN_PREFIX), 422, 'Esta no es una sesión de soporte.');

        $tenant = $request->user()->tenant;

        if (Schema::hasTable('saas_support_sessions')) {
            DB::table('saas_support_sessions')->where('token_id', $token->id)->whereNull('ended_at')->update(['ended_at' => now()]);
        }

        $token->delete();

        $platformUser = \App\Models\User::find(substr($token->name, strlen(self::TOKEN_PREFIX)));

        \App\Support\Audit::log($request, 'impersonation.ended', [
            'actor'        => $platformUser,
            'impersonator' => null,
            'tenant'       => $tenant,
            'entity_type'  => 'tenant',
            'entity_id'    => $tenant?->id,
            'entity_label' => $tenant?->name,
        ]);

        return response()->json(['message' => 'Sesión de soporte cerrada.']);
    }
}
