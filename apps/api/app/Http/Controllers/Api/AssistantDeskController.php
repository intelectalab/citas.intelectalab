<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Support\AssistantDesk;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AssistantDeskController extends Controller
{
    public function board(Request $request)
    {
        $tenant = $request->user()->tenant;
        abort_unless($tenant, 403);
        $days = (int) ($request->validate(['days' => ['nullable', Rule::in([7, 30, 90])]])['days'] ?? 30);

        return response()->json(AssistantDesk::board($tenant, $days) + [
            'can' => [
                'simulate' => $request->user()->allows('settings.view'),
                'settings' => $request->user()->allows('settings.edit'),
                'reply'    => $request->user()->allows('messages.reply'),
            ],
        ]);
    }

    public function simulate(Request $request)
    {
        abort_unless($request->user()->allows('settings.view'), 403, 'Tu cuenta no tiene permiso para probar el asistente.');
        $tenant = $request->user()->tenant;
        abort_unless($tenant, 403);

        $data = $request->validate([
            'text'           => 'required|string|max:1000',
            'customer_id'    => 'nullable|uuid',
            'history'        => 'nullable|array|max:40',
            'history.*.from' => ['required', Rule::in(['customer', 'assistant'])],
            'history.*.text' => 'required|string|max:2000',
        ], ['text.required' => 'Escribe el mensaje como si fueras el cliente.']);

        $customer = !empty($data['customer_id']) ? Customer::where('tenant_id', $tenant->id)->findOrFail($data['customer_id']) : null;

        return response()->json(AssistantDesk::simulate($tenant, $customer, $data['history'] ?? [], trim($data['text'])));
    }
}
