<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Models\Tenant;
use App\Support\Branding;
use App\Support\Complaints;
use Illuminate\Http\Request;

class ComplaintController extends Controller
{
    private function company(string $code): Tenant
    {
        abort_unless(preg_match('/^\d{' . Tenant::CODE_LENGTH . '}$/', $code), 404, 'No encontramos esa empresa.');

        return Tenant::with(['settings', 'company'])->where('code', $code)->where('status', 'active')->first()
            ?? abort(404, 'No encontramos esa empresa.');
    }

    private function book(?Tenant $tenant): array
    {
        return [
            'issuer'     => Complaints::issuer($tenant),
            'logo'       => $tenant ? Branding::present($tenant)['logo_url'] : null,
            'vocabulary' => $tenant ? $tenant->vocabulary() : null,
            'currency'   => $tenant ? \App\Support\Currency::of($tenant) : 'PEN',
            'days'       => Complaints::DAYS,
            'documents'  => Complaints::DOCUMENTS,
            'captcha_site_key' => \App\Support\Captcha::siteKey('complaints'),
        ];
    }

    public function platformBook()
    {
        return response()->json($this->book(null));
    }

    public function companyBook(string $code)
    {
        return response()->json($this->book($this->company($code)));
    }

    private function store(Request $request, ?Tenant $tenant)
    {
        abort_unless(Complaints::ready(), 503, 'El Libro de Reclamaciones no está disponible en este momento.');
        \App\Support\Captcha::assert($request, 'complaints');
        $data = $request->validate(Complaints::rules(), Complaints::messages());
        $row = Complaints::submit($data, $tenant);

        return response()->json(['complaint' => Complaints::present($row)], 201);
    }

    public function platformStore(Request $request)
    {
        return $this->store($request, null);
    }

    public function companyStore(Request $request, string $code)
    {
        return $this->store($request, $this->company($code));
    }

    private function scope(Request $request): ?string
    {
        return $request->user()->tenant_id;
    }

    private function tab(Request $request): string
    {
        return $request->validate(['tab' => 'nullable|in:pending,due,answered'])['tab'] ?? 'pending';
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $tenant = $user->tenant;
        $payload = Complaints::ready() ? Complaints::list($this->scope($request), $this->tab($request)) : ['complaints' => [], 'counts' => []];

        if ($tenant) {
            $tenant->loadMissing('company');
            $payload['code'] = $tenant->code;
        }

        $payload['issuer'] = Complaints::issuer($tenant);
        $payload['can_answer'] = $tenant ? $user->allows('complaints.answer') : true;

        return response()->json($payload);
    }

    public function show(Request $request, string $complaint)
    {
        $user = $request->user();

        return response()->json([
            'complaint'  => Complaints::present(Complaints::find($this->scope($request), $complaint)),
            'can_answer' => $user->tenant_id ? $user->allows('complaints.answer') : true,
        ]);
    }

    public function answer(Request $request, string $complaint)
    {
        $data = $request->validate(['response' => 'required|string|min:10|max:5000'], [
            'response.required' => 'Escribe la respuesta.',
            'response.min'      => 'La respuesta es muy corta. Explica qué se hará o por qué.',
        ]);
        $result = Complaints::answer($this->scope($request), $complaint, $data['response'], $request->user());

        AuditEvent::record($request, 'complaint.answered', $request->user()->tenant, null, ['Código' => $result['complaint']['code']]);

        return response()->json([
            ...$result,
            'message' => $result['mailed']
                ? 'Respuesta enviada. Le llegó por correo a ' . $result['complaint']['consumer']['email'] . '.'
                : 'Respuesta guardada, pero el correo no salió. Revisa el correo de la plataforma y avísale a la persona.',
        ]);
    }
}
