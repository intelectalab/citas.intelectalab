<?php

namespace App\Support\AuditActions;

class SubscriptionActions
{
    public const ACTIONS = [
        'company.updated'               => ['Cambió los datos de la empresa', 'settings'],
        'booking_page.published'        => ['Publicó la página de reservas', 'settings'],
        'booking_page.unpublished'      => ['Despublicó la página de reservas', 'settings'],
        'booking_page.slug_changed'     => ['Cambió la dirección de la página de reservas', 'settings'],
        'catalog.imported'              => ['Importó el catálogo de un rubro', 'settings'],
        'business_type.reset'           => ['Restableció la plantilla de fábrica de un rubro', 'platform'],
        'tenant.marked_past_due'        => ['Marcó el pago como vencido', 'companies'],
        'funnel.stage_changed'          => ['Cambió la etapa de un contacto del embudo', 'platform'],
    ];

    public const FIELDS = [
        'legal_name'  => 'Razón social',
        'tax_id'      => 'RUC',
        'trade_name'  => 'Nombre comercial',
        'district'    => 'Distrito',
        'department'  => 'Departamento',
        'slug'        => 'Dirección de la página',
        'published'   => 'Publicada',
    ];
}
