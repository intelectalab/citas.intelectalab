<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Pipeline;
use Illuminate\Http\Request;

class PipelineController extends Controller
{
    public function index()
    {
        return response()->json(Pipeline::with('stages')->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate(['name' => 'required|string|max:150']);
        return response()->json(Pipeline::create($validated), 201);
    }
}