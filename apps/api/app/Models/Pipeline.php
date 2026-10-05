<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids; 
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Pipeline extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'pipelines';

    protected $fillable = [
        'id',
        'name'
    ];

    public function stages()
    {
        return $this->hasMany(PipelineStage::class, 'pipeline_id');
    }
}