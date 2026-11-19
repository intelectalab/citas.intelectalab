<?php

namespace App\Http\Controllers\Api;

use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;

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
}
