<?php

namespace App\Http\Controllers\Api;

use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatBookingController extends PortalController
{
    public function bookFromChat(Tenant $tenant, Customer $customer, array $data): JsonResponse
    {
        request()->setUserResolver(fn () => $customer);

        return $this->placeBooking($tenant, $customer, [
            'service_id'      => $data['service_id'] ?? null,
            'branch_id'       => $data['branch_id'] ?? null,
            'professional_id' => $data['professional_id'] ?? null,
            'starts_at'       => $data['starts_at'] ?? null,
            'notes'           => trim('Reservada por WhatsApp. ' . ($data['notes'] ?? '')),
        ]);
    }

    private function asCustomer(Customer $customer, string $method, array $data, callable $run): JsonResponse
    {
        $previous = app('request');
        $request = Request::create('/api/portal/chat', $method, $data, [], [], ['REMOTE_ADDR' => $previous->ip() ?: '127.0.0.1', 'HTTP_USER_AGENT' => 'Asistente de WhatsApp']);
        $request->setUserResolver(fn () => $customer);
        app()->instance('request', $request);

        try {
            return $run($request);
        } finally {
            app()->instance('request', $previous);
        }
    }

    public function cancelFromChat(Customer $customer, string $appointmentId, string $reason): JsonResponse
    {
        return $this->asCustomer($customer, 'POST', ['reason' => $reason], fn (Request $request) => $this->cancel($request, $appointmentId));
    }

    public function moveFromChat(Customer $customer, string $appointmentId, array $data): JsonResponse
    {
        return $this->asCustomer($customer, 'POST', $data, fn (Request $request) => $this->move($request, $appointmentId));
    }

    public function confirmFromChat(Customer $customer, string $appointmentId): JsonResponse
    {
        return $this->asCustomer($customer, 'POST', [], fn (Request $request) => $this->confirm($request, $appointmentId));
    }
}
