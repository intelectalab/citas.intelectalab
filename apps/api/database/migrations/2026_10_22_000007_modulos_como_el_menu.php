<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const MODULES = [
        'agenda'       => ['Agenda y citas', 1],
        'crm'          => ['Clientes', 2],
        'booking'      => ['Reserva online y portal', 3],
        'walkin'       => ['Cola sin cita', 4],
        'classes'      => ['Clases', 5],
        'records'      => ['Fichas de atención', 6],
        'resources'    => ['Salas y equipos', 7],
        'pos'          => ['Punto de venta y caja', 8],
        'invoicing'    => ['Facturación electrónica', 9],
        'quotes'       => ['Presupuestos', 10],
        'commissions'  => ['Comisiones', 11],
        'inventory'    => ['Inventario', 12],
        'packages'     => ['Paquetes', 13],
        'memberships'  => ['Membresías', 14],
        'loyalty'      => ['Puntos y gift cards', 15],
        'marketing'    => ['Campañas', 16],
        'automations'  => ['Automatizaciones', 17],
        'multi_branch' => ['Multisede', 18],
        'reports_adv'  => ['Analítica avanzada', 19],
        'api'          => ['API y webhooks', 20],
    ];

    private const PREVIOUS = [
        'crm'      => 'Clientes / CRM 360',
        'walkin'   => 'Cola de llegada sin cita',
        'classes'  => 'Clases grupales',
        'records'  => 'Fichas de atención e historia',
        'quotes'   => 'Presupuestos y planes de tratamiento',
        'marketing' => 'Marketing y WhatsApp',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('saas_modules')) {
            return;
        }

        foreach (self::MODULES as $key => [$name, $order]) {
            DB::table('saas_modules')->where('key', $key)->update(['name' => $name, 'sort_order' => $order]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('saas_modules')) {
            return;
        }

        foreach (self::PREVIOUS as $key => $name) {
            DB::table('saas_modules')->where('key', $key)->update(['name' => $name]);
        }
    }
};
