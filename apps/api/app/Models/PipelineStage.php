<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PipelineStage extends Model
{
    use HasFactory;

    protected $table = 'pipeline_stages';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'pipeline_id',
        'name',
        'color',
        'probability',
        'stage_order'
    ];

    public function pipeline()
    {
        return $this->belongsTo(Pipeline::class, 'pipeline_id');
    }
}