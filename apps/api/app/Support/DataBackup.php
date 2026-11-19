<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DataBackup
{
    public const LIMIT = 50000;

    public static function sheets(Tenant $tenant): array
    {
        $zone = $tenant->zone();
        $when = fn ($value) => $value ? Carbon::parse($value)->setTimezone($zone)->format('Y-m-d H:i') : null;
        $day = fn ($value) => $value ? Carbon::parse($value)->format('Y-m-d') : null;
        $sheets = [];

        $sheets[] = self::sheet('Clientes', ['Código', 'Nombres', 'Apellidos', 'Documento', 'Correo', 'Celular', 'Nacimiento', 'Dirección', 'Distrito', 'Registrado'],
            DB::table('customers')->where('tenant_id', $tenant->id)->orderBy('created_at')->limit(self::LIMIT)->get()
                ->map(fn ($c) => [$c->code, $c->first_name, $c->last_name, $c->document_number, $c->email, $c->phone, $day($c->birth_date), $c->address, $c->district, $when($c->created_at)]));

        $sheets[] = self::sheet('Citas', ['Código', 'Inicio', 'Fin', 'Estado', 'Cliente', 'Servicio', 'Profesional', 'Sede', 'Precio', 'Origen', 'Notas'],
            DB::table('appointments as a')
                ->leftJoin('customers as c', 'c.id', '=', 'a.customer_id')
                ->leftJoin('services as s', 's.id', '=', 'a.service_id')
                ->leftJoin('professionals as p', 'p.id', '=', 'a.professional_id')
                ->leftJoin('branches as b', 'b.id', '=', 'a.branch_id')
                ->where('a.tenant_id', $tenant->id)->orderBy('a.starts_at')->limit(self::LIMIT)
                ->get(['a.code', 'a.starts_at', 'a.ends_at', 'a.status', 'c.first_name', 'c.last_name', 's.name as service', 'p.display_name as pro', 'b.name as branch', 'a.price', 'a.source', 'a.notes'])
                ->map(fn ($a) => [$a->code, $when($a->starts_at), $when($a->ends_at), AppointmentFlow::name($tenant, $a->status), trim("{$a->first_name} {$a->last_name}"), $a->service, $a->pro, $a->branch, self::num($a->price), $a->source, $a->notes]), ['Precio']);

        if (Schema::hasTable('sales')) {
            $sheets[] = self::sheet('Ventas', ['Código', 'Fecha', 'Estado', 'Cliente', 'Sede', 'Total', 'Descuento', 'Pagado', 'Saldo', 'Moneda'],
                DB::table('sales as v')
                    ->leftJoin('customers as c', 'c.id', '=', 'v.customer_id')
                    ->leftJoin('branches as b', 'b.id', '=', 'v.branch_id')
                    ->where('v.tenant_id', $tenant->id)->orderBy('v.created_at')->limit(self::LIMIT)
                    ->get(['v.code', 'v.created_at', 'v.status', 'c.first_name', 'c.last_name', 'b.name as branch', 'v.total', 'v.discount', 'v.paid', 'v.balance', 'v.currency'])
                    ->map(fn ($v) => [$v->code, $when($v->created_at), Sales::STATUS_LABELS[$v->status] ?? $v->status, trim("{$v->first_name} {$v->last_name}"), $v->branch, self::num($v->total), self::num($v->discount), self::num($v->paid), self::num($v->balance), $v->currency]), ['Total', 'Descuento', 'Pagado', 'Saldo']);

            $sheets[] = self::sheet('Pagos', ['Fecha', 'Venta', 'Medio', 'Monto', 'Referencia', 'Estado', 'Sede'],
                DB::table('payments as p')
                    ->leftJoin('sales as v', 'v.id', '=', 'p.sale_id')
                    ->leftJoin('branches as b', 'b.id', '=', 'p.branch_id')
                    ->where('p.tenant_id', $tenant->id)->orderBy('p.created_at')->limit(self::LIMIT)
                    ->get(['p.created_at', 'v.code', 'p.method', 'p.amount', 'p.external_reference', 'p.status', 'b.name as branch'])
                    ->map(fn ($p) => [$when($p->created_at), $p->code, Sales::METHODS[$p->method] ?? Sales::OTHER_METHODS[$p->method] ?? $p->method, self::num($p->amount), $p->external_reference, $p->status, $p->branch]), ['Monto']);
        }

        $sheets[] = self::sheet('Servicios', ['Nombre', 'Duración (min)', 'Precio', 'Descripción'],
            DB::table('services')->where('tenant_id', $tenant->id)->orderBy('name')->limit(self::LIMIT)->get()
                ->map(fn ($s) => [$s->name, (int) ($s->duration_minutes ?? 0), self::num($s->price), $s->description ?? null]), ['Precio']);

        $sheets[] = self::sheet('Profesionales', ['Nombre', 'Documento', 'Celular', 'Colegiatura', 'Correo'],
            DB::table('professionals as p')->leftJoin('users as u', 'u.id', '=', 'p.user_id')
                ->where('p.tenant_id', $tenant->id)->orderBy('p.display_name')->limit(self::LIMIT)
                ->get(['p.display_name', 'p.document_number', 'p.phone', 'p.license_number', 'u.email'])
                ->map(fn ($p) => [$p->display_name, $p->document_number, $p->phone, $p->license_number, $p->email]));

        if (Schema::hasTable('products')) {
            $sheets[] = self::sheet('Productos', ['Nombre', 'Código', 'Precio'],
                DB::table('products')->where('tenant_id', $tenant->id)->whereNull('deleted_at')->orderBy('name')->limit(self::LIMIT)->get()
                    ->map(fn ($p) => [$p->name, $p->sku ?? null, self::num($p->price)]), ['Precio']);
        }

        return $sheets;
    }

    private static function num($value): ?float
    {
        return $value === null ? null : round((float) $value, 2);
    }

    private static function sheet(string $name, array $headers, $rows, array $money = []): array
    {
        return [
            'sheet'   => $name,
            'columns' => array_map(fn ($header) => ['header' => $header, 'money' => in_array($header, $money, true)], $headers),
            'rows'    => collect($rows)->values()->all(),
        ];
    }
}
