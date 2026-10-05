<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PipelineStage;
use Illuminate\Http\Request;

class PipelineStageController extends Controller
{

    public function index(Request $request)
    {
        $query = PipelineStage::query();

        if ($request->has('pipeline_id')) {
            $query->where('pipeline_id', $request->pipeline_id);
        }

        $stages = $query->orderBy('stage_order', 'asc')->get();

        return response()->json($stages);
    }
}