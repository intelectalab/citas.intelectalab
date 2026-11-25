<?php

namespace App\Support;

use App\Http\Controllers\Api\DataExportController;
use App\Http\Controllers\Api\ReportController;
use App\Models\AuditEvent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ReportExports
{
    public const KIND = 'report';

    public const QUEUE_AFTER = 2000;

    public const NOT_READY = 'Falta actualizar la base de datos para descargar el reporte completo: ejecuta php artisan migrate.';

    private const WEEKDAYS = [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 7 => 'Domingo'];

    public static function ready(): bool
    {
        return DataExports::ready() && Schema::hasColumn('data_exports', 'kind');
    }

    public static function queueAfter(): int
    {
        return (int) config('reports.queue_after', self::QUEUE_AFTER);
    }

    public static function request(User $user, array $filters, ?Request $request = null): array
    {
        abort_unless(self::ready(), 503, self::NOT_READY);
        $tenant = $user->tenant;
        abort_unless($tenant instanceof Tenant, 403);

        $ownOnly = !$user->allows('reports.view') || Access::scopeOf($user) === 'own';
        $tab = $ownOnly ? 'mine' : ($filters['tab'] ?? 'sales');
        abort_if(Reports::TABS[$tab]['advanced'] && !$tenant->hasModule('reports_adv'), 403, 'Esta pestaña viene con el módulo Analítica avanzada.');

        $limit = $ownOnly ? null : Access::ownBranchLimit($user);
        $branchId = $limit ?: ($filters['branch_id'] ?? null);
        $period = $filters['period'] ?? '30';

        if ($period === 'custom') {
            if (($filters['to'] ?? '') < ($filters['from'] ?? '')) {
                throw ValidationException::withMessages(['to' => 'La fecha final no puede ser anterior a la inicial.']);
            }
            if (Carbon::parse($filters['from'])->diffInDays(Carbon::parse($filters['to'])) >= Reports::MAX_DAYS) {
                throw ValidationException::withMessages(['to' => 'Elige un periodo de hasta un año.']);
            }
        }

        $busy = DB::table('data_exports')->where('tenant_id', $tenant->id)->where('kind', self::KIND)->where('requested_by', $user->id)
            ->whereIn('status', ['queued', 'running'])->where('created_at', '>', now()->subHour())->exists();

        if ($busy) {
            throw ValidationException::withMessages(['export' => 'Ya estamos preparando otro reporte tuyo. Te avisaremos en la campana cuando esté listo.']);
        }

        $params = ['tab' => $tab, 'period' => $period, 'from' => $filters['from'] ?? null, 'to' => $filters['to'] ?? null, 'branch_id' => $branchId];
        $range = Reports::range($tenant, $period, $params['from'], $params['to']);
        $size = self::size($tenant, $branchId, $range['from'], $range['to']);
        $queued = $size > self::queueAfter();
        $title = 'Reporte de ' . mb_strtolower(self::tabLabel($tenant, $tab)) . ' · ' . self::rangeText($range['from'], $range['to']);

        $id = (string) Str::uuid();
        DB::table('data_exports')->insert([
            'id'           => $id,
            'tenant_id'    => $tenant->id,
            'requested_by' => $user->id,
            'status'       => 'queued',
            'kind'         => self::KIND,
            'title'        => mb_substr($title, 0, 150),
            'params'       => json_encode($params),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        AuditEvent::create([
            'tenant_id'     => $tenant->id,
            'actor_user_id' => $user->id,
            'action'        => DataExportController::ACTION,
            'entity_type'   => 'reports',
            'ip_address'    => $request?->ip(),
            'user_agent'    => mb_substr((string) $request?->userAgent(), 0, 500),
            'after_data'    => [
                'title'     => $title,
                'format'    => 'xlsx',
                'scope'     => 'filtered',
                'rows'      => $size,
                'filters'   => array_values(array_filter([self::rangeText($range['from'], $range['to']), $branchId ? 'Sede: ' . DB::table('branches')->where('id', $branchId)->value('name') : 'Todas las sedes', $queued ? 'Preparado en cola' : null])),
                'export_id' => $id,
            ],
        ]);

        if (!$queued || config('queue.default') === 'sync' || app()->runningInConsole()) {
            DataExports::run($id);
        } else {
            dispatch(fn () => DataExports::run($id))->afterResponse();
        }

        return ['queued' => $queued, 'export' => self::present(DB::table('data_exports')->where('id', $id)->first())];
    }

    public static function size(Tenant $tenant, ?string $branchId, Carbon $from, Carbon $to): int
    {
        $start = $from->copy()->utc()->toIso8601String();
        $end = $to->copy()->utc()->toIso8601String();

        $appointments = DB::table('appointments')->where('tenant_id', $tenant->id)
            ->when($branchId, fn ($q, $id) => $q->where('branch_id', $id))
            ->where('starts_at', '>=', $start)->where('starts_at', '<', $end)->count();
        $sales = DB::table('sales')->where('tenant_id', $tenant->id)
            ->when($branchId, fn ($q, $id) => $q->where('branch_id', $id))
            ->where('created_at', '>=', $start)->where('created_at', '<', $end)->count();

        return $appointments + $sales;
    }

    public static function run(object $row): void
    {
        Rls::asTenant($row->tenant_id, function () use ($row) {
            DB::table('data_exports')->where('id', $row->id)->update(['status' => 'running', 'started_at' => now(), 'updated_at' => now()]);

            try {
                $tenant = Tenant::findOrFail($row->tenant_id);
                $user = User::where('tenant_id', $tenant->id)->findOrFail($row->requested_by);
                $params = json_decode((string) $row->params, true) ?: [];
                $payload = (new ReportController())->build($user, array_filter($params, fn ($value) => $value !== null));
                $sheets = self::sheets($tenant, $user, $payload);
                $path = "exports/{$tenant->id}/{$row->id}.xlsx";
                Storage::disk('local')->put($path, DataExports::xlsx($sheets));
                $rows = array_sum(array_map(fn ($sheet) => count($sheet['rows']), $sheets));

                DB::table('data_exports')->where('id', $row->id)->update([
                    'status'      => 'ready',
                    'file_path'   => $path,
                    'file_size'   => Storage::disk('local')->size($path),
                    'rows'        => $rows,
                    'finished_at' => now(),
                    'expires_at'  => now()->addDays(DataExports::DAYS),
                    'updated_at'  => now(),
                ]);

                Notifier::send($tenant->id, $user->id, 'report.export_ready', 'Tu reporte está listo', ($row->title ?: 'El reporte') . '. Descárgalo en Reportes → Tus descargas. El enlace sirve por ' . DataExports::DAYS . ' días.', '/reports#descargas', 'data_export', $row->id, "export:{$row->id}");
            } catch (\Throwable $e) {
                report($e);
                DB::table('data_exports')->where('id', $row->id)->update(['status' => 'failed', 'error' => 'No se pudo preparar el reporte. Inténtalo de nuevo.', 'finished_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    public static function present(object $row): array
    {
        return DataExports::present($row) + ['title' => $row->title ?? null];
    }

    public static function listFor(User $user): array
    {
        if (!self::ready()) {
            return [];
        }

        return DB::table('data_exports')->where('tenant_id', $user->tenant_id)->where('kind', self::KIND)->where('requested_by', $user->id)
            ->orderByDesc('created_at')->limit(6)->get()->map(fn ($row) => self::present($row))->values()->all();
    }

    public static function fileName(object $row): string
    {
        $company = Rls::bypass(fn () => DB::table('tenants')->where('id', $row->tenant_id)->value('name')) ?? 'empresa';
        $params = json_decode((string) ($row->params ?? ''), true) ?: [];

        return 'Reporte ' . Str::slug(Reports::TABS[$params['tab'] ?? 'sales']['label'] ?? 'reporte') . ' ' . Str::slug($company) . ' ' . Carbon::parse($row->finished_at)->format('Y-m-d') . '.xlsx';
    }

    private static function tabLabel(Tenant $tenant, string $tab): string
    {
        $vocab = $tenant->vocabulary();

        return match ($tab) {
            'customers' => $vocab['clients'] ?? 'Clientes',
            'pros'      => $vocab['pros'] ?? 'Profesionales',
            default     => Reports::TABS[$tab]['label'] ?? 'Ventas',
        };
    }

    private static function rangeText(Carbon $from, Carbon $to): string
    {
        return 'del ' . $from->format('d/m/Y') . ' al ' . $to->copy()->subDay()->format('d/m/Y');
    }

    private static function sheet(string $name, array $columns, iterable $rows, bool $money): array
    {
        $columns = array_values(array_filter($columns, fn ($column) => $money || empty($column[2])));

        return [
            'sheet'   => $name,
            'columns' => array_map(fn ($column) => ['header' => $column[0]], $columns),
            'rows'    => collect($rows)->map(fn ($row) => array_map(function ($column) use ($row) {
                $value = $column[1] instanceof \Closure ? ($column[1])($row) : ($row[$column[1]] ?? null);

                return is_bool($value) ? ($value ? 'Sí' : 'No') : $value;
            }, $columns))->values()->all(),
        ];
    }

    private static function summary(array $labels, ?array $current, ?array $previous): array
    {
        $rows = [];

        foreach ($labels as $key => $label) {
            if (!$current || !array_key_exists($key, $current) || $current[$key] === null) {
                continue;
            }
            $rows[] = [$label, $current[$key], $previous[$key] ?? null];
        }

        return $rows;
    }

    public static function sheets(Tenant $tenant, User $user, array $payload): array
    {
        $vocab = $tenant->vocabulary();
        $tab = $payload['tab'];
        $money = (bool) $payload['money'];
        $data = $payload['data'] ?? [];
        $branch = $payload['branch_id'] ? collect($payload['branches'])->firstWhere('id', $payload['branch_id']) : null;
        $branchName = is_array($branch) ? ($branch['name'] ?? null) : ($branch->name ?? null);
        $from = Carbon::parse($payload['range']['from'])->format('d/m/Y');
        $to = Carbon::parse($payload['range']['to'])->format('d/m/Y');

        $meta = [
            ['Empresa', $tenant->name, null],
            ['Reporte', self::tabLabel($tenant, $tab), null],
            ['Periodo', "Del {$from} al {$to}", null],
            ['Sede', $branchName ?? 'Todas las sedes', null],
            ['Moneda', $payload['currency'], null],
            ['Preparado para', Person::name($user), null],
            ['Preparado el', now($tenant->zone())->format('d/m/Y H:i'), null],
        ];

        if ($payload['locked'] || !$data) {
            return [['sheet' => 'Resumen', 'columns' => [['header' => 'Dato'], ['header' => 'Valor'], ['header' => 'Periodo anterior']], 'rows' => array_merge($meta, [['Aviso', 'Esta pestaña viene con el módulo Analítica avanzada.', null]])]];
        }

        $labels = match ($tab) {
            'sales'        => ['income' => 'Ingresos', 'sales' => 'Ventas', 'ticket' => 'Ticket promedio', 'discount' => 'Descuentos', 'tips' => 'Propinas', 'refunds' => 'Devoluciones'],
            'appointments' => ['appointments' => 'Citas (sin canceladas)', 'completed' => 'Atendidas', 'occupancy' => 'Ocupación (%)', 'cancelled' => 'Cancelaciones (%)', 'no_show' => 'No asistieron (%)', 'online' => 'Reservas por el portal'],
            'customers'    => ['attended' => ($vocab['clients'] ?? 'Clientes') . ' atendidos', 'new' => 'Nuevos registrados', 'returning' => 'Volvieron', 'rebooked' => 'Volvieron a reservar (%)'],
            'cash'         => ['sessions' => 'Turnos de caja', 'closed' => 'Turnos cerrados', 'with_difference' => 'Cierres con diferencia', 'sales' => 'Cobrado en caja', 'income' => 'Otros ingresos', 'expense' => 'Gastos', 'withdrawal' => 'Retiros', 'refund' => 'Devoluciones', 'short' => 'Faltantes', 'over' => 'Sobrantes'],
            'inventory'    => ['products' => 'Productos con stock', 'value' => 'Valor del stock', 'low' => 'Bajo el mínimo', 'empty' => 'Sin stock', 'in_value' => 'Entró (a costo)', 'out_value' => 'Salió (a costo)', 'adjust' => 'Ajustes'],
            'mine'         => ['completed' => 'Atendidas', 'income' => 'Producción', 'occupancy' => 'Ocupación (%)', 'customers' => ($vocab['clients'] ?? 'Clientes') . ' atendidos', 'no_show' => 'No asistieron', 'commissions' => 'Comisiones', 'minutes' => 'Tiempo promedio (minutos)'],
            default        => [],
        };

        $sheets = [[
            'sheet'   => 'Resumen',
            'columns' => [['header' => 'Dato'], ['header' => 'Valor'], ['header' => 'Periodo anterior']],
            'rows'    => array_merge($meta, [['', null, null]], self::summary($labels, $data['summary'] ?? null, $data['previous'] ?? null)),
        ]];

        $day = fn ($row) => Carbon::parse($row['day'])->format('d/m/Y');
        $series = fn (string $name, string $header, array $rows, bool $isMoney = false, bool $count = false) => self::sheet($name, array_values(array_filter([
            [$header, 'label'],
            $count ? ['Cobros', 'count'] : null,
            [$isMoney ? 'Monto' : 'Cantidad', 'value', $isMoney],
        ])), $rows, $money);
        $clients = $vocab['client'] ?? 'Cliente';
        $pro = $vocab['pro'] ?? 'Profesional';

        $detail = $data['detail'] ?? null;

        $more = match ($tab) {
            'sales' => $detail ? [
                self::sheet('Ingresos por día', [['Día', $day], ['Monto', 'value', true]], $detail['by_day'] ?? [], $money),
                $series('Por medio de pago', 'Medio', $detail['by_method'] ?? [], true, true),
                $series('Por tipo de venta', 'Tipo', $detail['by_type'] ?? [], true),
                $series('Por sede', 'Sede', $detail['by_branch'] ?? [], true),
            ] : [],
            'appointments' => $detail ? array_filter([
                self::sheet('Ocupación por día', [['Día', $day], ['Ocupación (%)', 'value']], $detail['occupancy_by_day'] ?? [], $money),
                $series('Cómo se reservaron', 'Origen', $detail['by_source'] ?? []),
                isset($detail['by_channel']) ? $series('Reservas por canal', 'Canal', $detail['by_channel'] ?? []) : null,
                isset($detail['heatmap']) ? self::sheet('Días y horas', [['Día', fn ($row) => self::WEEKDAYS[$row['weekday']] ?? $row['weekday']], ['Hora', fn ($row) => sprintf('%02d:00', $row['hour'])], ['Citas', 'value']], $detail['heatmap'] ?? [], $money) : null,
            ]) : [],
            'customers' => isset($data['top']) ? [
                self::sheet('Los que más gastaron', [[$clients, 'name'], ['Atenciones', 'visits'], ['Ventas', 'sales'], ['Gastó', 'spent', true]], $data['top'] ?? [], $money),
            ] : [],
            'pros' => [
                self::sheet('Por ' . mb_strtolower($pro), [[$pro, 'name'], ['Atendidas', 'completed'], ['Producción', 'income', true], ['Cobrado', 'collected', true], ['Comisiones', 'commissions', true], ['Ocupación (%)', 'occupancy'], ['No asistieron', 'no_show'], ['Minutos promedio', 'minutes']], $data['rows'] ?? [], $money),
            ],
            'catalog' => array_filter([
                self::sheet('Servicios', [['Servicio', 'name'], ['Cantidad', 'quantity'], ['Producción', 'income', true], ['Cobrado', 'collected', true]], $data['services'] ?? [], $money),
                isset($data['products']) ? self::sheet('Productos', [['Producto', 'name'], ['Cantidad', 'quantity'], ['Ingresos', 'income', true]], $data['products'] ?? [], $money) : null,
            ]),
            'cash' => $money ? [
                self::sheet('Movimiento por día', [['Día', $day], ['Neto', 'value', true]], $data['by_day'] ?? [], $money),
                self::sheet('Por caja', [['Caja', 'name'], ['Sede', 'branch'], ['Turnos', 'sessions'], ['Cobrado', 'sales', true], ['Neto', 'net', true], ['Diferencia', 'difference', true]], $data['registers'] ?? [], $money),
                self::sheet('Cierres con diferencia', [['Caja', 'register'], ['Cerrada', fn ($row) => $row['closed_at'] ? Carbon::parse($row['closed_at'])->setTimezone($tenant->zone())->format('d/m/Y H:i') : null], ['Cerró', 'closed_by'], ['Diferencia', 'difference', true], ['Nota', 'notes']], $data['differences'] ?? [], $money),
            ] : [],
            'inventory' => [
                $series('Movimientos', 'Motivo', $data['by_type'] ?? []),
                self::sheet('Productos que más salen', [['Producto', 'name'], ['Código', 'sku'], ['Vendidos', 'sold'], ['Usados en atenciones', 'used'], ['Costo', 'value', true]], $data['moved'] ?? [], $money),
                self::sheet('Por reponer', [['Producto', 'name'], ['Código', 'sku'], ['Stock', 'quantity'], ['Mínimo', 'min_stock']], $data['low'] ?? [], $money),
            ],
            'mine' => [
                self::sheet('Ocupación por día', [['Día', $day], ['Ocupación (%)', 'value']], $data['by_day'] ?? [], $money),
                self::sheet('Tus servicios', [['Servicio', 'name'], ['Cantidad', 'quantity'], ['Producción', 'income', true], ['Cobrado', 'collected', true]], $data['services'] ?? [], $money),
                self::sheet('Tus ' . mb_strtolower($vocab['clients'] ?? 'clientes'), [[$clients, 'name'], ['Atenciones', 'visits'], ['Producción', 'income', true], ['Última', fn ($row) => $row['last_at'] ? Carbon::parse($row['last_at'])->setTimezone($tenant->zone())->format('d/m/Y') : null]], $data['customers'] ?? [], $money),
            ],
            default => [],
        };

        return array_merge($sheets, array_values($more));
    }
}
