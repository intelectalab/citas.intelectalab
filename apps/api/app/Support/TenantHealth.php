<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TenantHealth
{
    public const IDLE_DAYS = 7;

    public const TRIAL_ALERT_DAYS = 3;

    public const LOW_USE = 5;

    public const SIGNALS = [
        'overdue'    => ['label' => 'Pago vencido', 'points' => 35],
        'trial_soon' => ['label' => 'Prueba por vencer', 'points' => 20],
        'no_data'    => ['label' => 'Sin datos cargados', 'points' => 15],
        'idle'       => ['label' => 'Sin ingresar', 'points' => 20],
        'low_use'    => ['label' => 'Uso bajo', 'points' => 15],
        'failed'     => ['label' => 'Mensajes que fallaron', 'points' => 5],
        'leaving'    => ['label' => 'Pidió la baja', 'points' => 30],
        'near'       => ['label' => 'Cerca del tope', 'points' => 0],
        'whatsapp'   => ['label' => 'WhatsApp sobre el 85 %', 'points' => 0],
    ];

    public const WHATSAPP_ALERT = 85;

    public const UPSELL = ['near', 'whatsapp'];

    public static function report(): array
    {
        $zone = PlatformReports::ZONE;
        $today = now($zone)->startOfDay();
        $from = $today->copy()->subDays(29);
        $reports = new PlatformReports($from, $today->copy()->addDay(), $from->copy()->subDays(30), $from->copy());
        $usage = $reports->usage();
        $customers = DB::table('customers')->groupBy('tenant_id')->selectRaw('tenant_id, count(*) as total')->pluck('total', 'tenant_id');
        $appointments = DB::table('appointments')->groupBy('tenant_id')->selectRaw('tenant_id, count(*) as total')->pluck('total', 'tenant_id');
        $failed = DB::table('customer_messages')->where('status', 'failed')->where('created_at', '>=', $from->copy()->utc()->toIso8601String())
            ->groupBy('tenant_id')->selectRaw('tenant_id, count(*) as total')->pluck('total', 'tenant_id');
        $leaving = Cancellations::ready() ? DB::table('saas_cancellations')->where('status', 'scheduled')->pluck('tenant_id')->flip() : collect();
        $loginReady = $usage['login_ready'];
        $tenants = \App\Models\Tenant::with(['settings', 'activeSubscription.plan'])->whereIn('id', collect($usage['rows'])->pluck('id'))->get()->keyBy('id');

        $rows = collect($usage['rows'])->filter(fn ($r) => !in_array($r['status'], ['canceled', 'suspended'], true))->map(function ($r) use ($customers, $appointments, $failed, $leaving, $loginReady, $tenants) {
            $state = Subscriptions::state($r['id']);
            $trialLeft = $r['status'] === 'trial' && $state['ends_at'] ? max(0, (int) ceil(now()->diffInSeconds($state['ends_at'], false) / 86400)) : null;
            $loaded = (int) ($customers[$r['id']] ?? 0) + (int) ($appointments[$r['id']] ?? 0);
            $daysAway = $r['last_login_at'] ? (int) floor(Carbon::parse($r['last_login_at'])->diffInDays(now())) : null;
            $signals = [];

            if (in_array($r['status'], ['grace', 'locked', 'none'], true)) {
                $signals[] = 'overdue';
            }
            if ($trialLeft !== null && $trialLeft <= self::TRIAL_ALERT_DAYS) {
                $signals[] = 'trial_soon';
            }
            if ($loaded === 0) {
                $signals[] = 'no_data';
            }
            if ($loginReady && ($daysAway === null || $daysAway >= self::IDLE_DAYS)) {
                $signals[] = 'idle';
            }
            if ($loaded > 0 && $r['appointments'] < self::LOW_USE) {
                $signals[] = 'low_use';
            }
            if ((int) ($failed[$r['id']] ?? 0) > 0) {
                $signals[] = 'failed';
            }
            if ($leaving->has($r['id'])) {
                $signals[] = 'leaving';
            }
            if ($r['near']) {
                $signals[] = 'near';
            }

            $whatsapp = null;
            $tenant = $tenants[$r['id']] ?? null;

            if ($tenant) {
                try {
                    $limit = MessageQuota::limit($tenant, 'whatsapp');
                    $whatsapp = $limit && $limit > 0 ? (int) floor(MessageQuota::used($tenant, 'whatsapp') * 100 / $limit) : null;
                } catch (\Throwable $e) {
                    $whatsapp = null;
                }
            }

            if ($whatsapp !== null && $whatsapp >= self::WHATSAPP_ALERT) {
                $signals[] = 'whatsapp';
            }

            $score = min(100, array_sum(array_map(fn ($s) => self::SIGNALS[$s]['points'], $signals)));

            return [
                'id'            => $r['id'],
                'name'          => $r['name'],
                'plan'          => $r['plan'],
                'status'        => $r['status'],
                'status_label'  => $r['status_label'],
                'score'         => $score,
                'level'         => $score >= 50 ? 'high' : ($score >= 20 ? 'medium' : 'low'),
                'signals'       => array_map(fn ($s) => ['key' => $s, 'label' => self::SIGNALS[$s]['label']], $signals),
                'alert'         => in_array('trial_soon', $signals, true) && $loaded === 0,
                'trial_days_left' => $trialLeft,
                'days_away'     => $daysAway,
                'last_login_at' => $r['last_login_at'],
                'appointments'  => $r['appointments'],
                'customers'     => (int) ($customers[$r['id']] ?? 0),
                'failed'        => (int) ($failed[$r['id']] ?? 0),
                'top'           => $r['top'],
                'whatsapp_percent' => $whatsapp,
                'upsell'        => (bool) array_intersect($signals, self::UPSELL) && !in_array('overdue', $signals, true) && !in_array('leaving', $signals, true),
            ];
        })->sortByDesc('score')->values();

        return [
            'summary' => [
                'high'   => $rows->where('level', 'high')->count(),
                'medium' => $rows->where('level', 'medium')->count(),
                'low'    => $rows->where('level', 'low')->count(),
                'alerts' => $rows->where('alert', true)->count(),
            ],
            'alerts'      => $rows->where('alert', true)->values()->all(),
            'upsell'      => $rows->where('upsell', true)->values()->all(),
            'whatsapp_alert' => self::WHATSAPP_ALERT,
            'rows'        => $rows->all(),
            'signals'     => collect(self::SIGNALS)->map(fn ($s, $key) => ['key' => $key, 'label' => $s['label']])->values()->all(),
            'idle_days'   => self::IDLE_DAYS,
            'trial_days'  => self::TRIAL_ALERT_DAYS,
            'low_use'     => self::LOW_USE,
            'login_ready' => $loginReady,
        ];
    }
}
