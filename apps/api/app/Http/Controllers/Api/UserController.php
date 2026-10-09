<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $currentUser = $request->user();

        if (!$currentUser || !$currentUser->tenant_id) {
            return response()->json([]);
        }

        $users = User::where('tenant_id', $currentUser->tenant_id)
            ->select('id', 'first_name', 'last_name','email', 'phone', 'status')
            ->get();

        return response()->json($users);
    }
}