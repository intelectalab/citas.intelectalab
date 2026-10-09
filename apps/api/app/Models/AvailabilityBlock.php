<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

/**
 * Rango en el que no se atiende: vacaciones o ausencia de un profesional, o
 * cierre de una sede (sin profesional). Las fechas se guardan con zona horaria.
 */
class AvailabilityBlock extends Model
{
    use HasUuids;

    public const KIND_VACATION = 'vacation';
    public const KIND_LEAVE = 'leave';
    public const KIND_HOLIDAY = 'holiday';
    public const KIND_CLOSURE = 'closure';
    public const KIND_OTHER = 'other';

    public const KINDS = [
        self::KIND_VACATION => 'Vacaciones',
        self::KIND_LEAVE    => 'Permiso o descanso médico',
        self::KIND_HOLIDAY  => 'Feriado',
        self::KIND_CLOSURE  => 'Cierre de la sede',
        self::KIND_OTHER    => 'Bloqueo de horario',
    ];

    // Tipos de una persona y tipos de una sede completa.
    public const PROFESSIONAL_KINDS = [self::KIND_VACATION, self::KIND_LEAVE, self::KIND_OTHER];
    public const BRANCH_KINDS = [self::KIND_HOLIDAY, self::KIND_CLOSURE, self::KIND_OTHER];

    protected $table = 'availability_blocks';
    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'branch_id',
        'professional_id',
        'starts_at',
        'ends_at',
        'reason',
        'kind',
        'created_by',
    ];

    /**
     * La base puede ir un esquema por detrás: sin la columna, todo bloqueo se
     * trata como antes (no se puede forzar).
     */
    public static function hasKind(): bool
    {
        static $has = null;

        return $has ??= Schema::hasColumn('availability_blocks', 'kind');
    }

    public function kindKey(): string
    {
        return $this->kind ?: (self::hasKind() ? self::KIND_OTHER : self::KIND_VACATION);
    }

    /**
     * Solo un bloqueo de horario (reunión, capacitación) admite sobre-agenda.
     * Vacaciones, permisos, feriados y cierres no: ahí no hay quien atienda.
     */
    public function isOverridable(): bool
    {
        return $this->kindKey() === self::KIND_OTHER;
    }

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'professional_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
