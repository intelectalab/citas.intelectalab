<?php

namespace App\Support\AuditActions;

class MoneyActions
{
    public const ACTIONS = [
        'payment.registered'      => ['Registró un cobro', 'money'],
        'sale.refunded'           => ['Devolvió dinero de una venta', 'money'],
        'sale.voided'             => ['Anuló la venta', 'money'],
        'cash.opened'             => ['Abrió la caja', 'money'],
        'cash.closed'             => ['Cerró la caja', 'money'],
        'cash.movement'           => ['Registró un movimiento de caja', 'money'],
        'cash_register.created'   => ['Creó una caja', 'money'],
        'cash_register.updated'   => ['Cambió una caja', 'money'],
        'commission.approved'     => ['Aprobó comisiones', 'money'],
        'commission.paid'         => ['Marcó comisiones como pagadas', 'money'],
        'commission.recalculated' => ['Recalculó las comisiones de un mes', 'money'],
        'commission_rule.created' => ['Creó una regla de comisión', 'money'],
        'commission_rule.updated' => ['Cambió una regla de comisión', 'money'],
        'commission_rule.deleted' => ['Eliminó una regla de comisión', 'money'],
        'coupon.created'          => ['Creó un cupón', 'money'],
        'coupon.updated'          => ['Cambió un cupón', 'money'],
        'coupon.deactivated'      => ['Desactivó un cupón que ya se había usado', 'money'],
        'coupon.deleted'          => ['Eliminó un cupón', 'money'],
        'package.cancelled'       => ['Anuló un paquete vendido', 'money'],
        'package.extended'        => ['Extendió la vigencia de un paquete', 'money'],
        'package.used_manually'   => ['Descontó una sesión de un paquete sin cita', 'money'],
        'package.online_started'  => ['Empezó a comprar un paquete en línea', 'money'],
        'package_plan.created'    => ['Creó un paquete', 'money'],
        'package_plan.updated'    => ['Cambió un paquete', 'money'],
        'package_plan.deleted'    => ['Eliminó un paquete', 'money'],
        'quote.approved'          => ['Aprobó un presupuesto', 'money'],
        'quote.rejected'          => ['Rechazó un presupuesto', 'money'],
        'quote.cancelled'         => ['Anuló un presupuesto', 'money'],
        'quote.session_deducted'  => ['Descontó una sesión del plan de tratamiento sin cita', 'money'],
        'loyalty.points_adjusted' => ['Registró un movimiento de puntos', 'money'],
        'gift_card.issued'        => ['Emitió una gift card', 'money'],
        'gift_card.discounted'    => ['Descontó saldo de una gift card', 'money'],
        'gift_card.extended'      => ['Amplió la vigencia de una gift card', 'money'],
        'gift_card.cancelled'     => ['Anuló una gift card', 'money'],
    ];

    public const FIELDS = [
        'count' => 'Cantidad', 'period' => 'Mes', 'created' => 'Calculadas', 'removed' => 'Quitadas', 'percent' => 'Porcentaje', 'fixed' => 'Monto fijo', 'active' => 'Estado', 'name' => 'Nombre',
        'method' => 'Medio de pago', 'customer' => 'Cliente', 'reference' => 'Operación', 'balance' => 'Saldo', 'total' => 'Total',
        'counted' => 'Contado', 'expected' => 'Esperado', 'difference' => 'Diferencia', 'notes' => 'Notas', 'type' => 'Tipo',
        'points' => 'Puntos', 'code' => 'Código', 'discount' => 'Descuento', 'min_purchase' => 'Compra mínima', 'max_uses' => 'Usos máximos',
        'starts_on' => 'Desde', 'ends_on' => 'Hasta', 'price' => 'Precio', 'validity_days' => 'Días de vigencia', 'booking_mode' => 'Cómo se reserva',
        'frequency' => 'Frecuencia', 'professional_mode' => 'Profesional', 'late_policy' => 'Si avisa tarde o no llega', 'refund_policy' => 'Devolución',
        'terms' => 'Condiciones', 'services' => 'Servicios', 'service' => 'Servicio', 'days' => 'Días', 'package' => 'Paquete',
        'refund' => 'Devolución', 'appointments' => 'Citas canceladas', 'note' => 'Nota', 'sale' => 'Venta', 'debt_action' => 'Lo que faltaba pagar',
        'purchaser' => 'Compró', 'recipient' => 'Para', 'amount' => 'Monto', 'branch' => 'Sede', 'sell_online' => 'Se vende en línea',
    ];
}
