<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TypeDocument;
use Illuminate\Http\Request;

class TypeDocumentController extends Controller
{
    public function index(Request $request)
    {
        $types = TypeDocument::all();
        return response()->json($types);
    }
}