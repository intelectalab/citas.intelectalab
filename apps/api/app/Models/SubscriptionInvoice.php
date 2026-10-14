<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionInvoice extends Model
{
    use HasUuids;

    protected $table = 'subscription_invoices';

    protected $fillable = [
        'subscription_id',
        'tenant_id',
        'status_id',
        'amount',
        'currency',
        'billing_reason',
        'invoice_date',
        'due_date',
        'paid_at',
        'external_invoice_id',
        'period_start',
        'period_end',
        'notes',
    ];

    protected $casts = [
        'amount'       => 'float',
        'invoice_date' => 'datetime',
        'due_date'     => 'datetime',
        'paid_at'      => 'datetime',
        'period_start' => 'datetime',
        'period_end'   => 'datetime',
    ];

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'subscription_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(Status::class, 'status_id');
    }
}
