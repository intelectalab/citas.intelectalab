<?php

namespace App\Support;

use App\Models\PlatformSetting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Complaints
{
    public const DAYS = 15;
    public const SOON = 2;
    public const ZONE = 'America/Lima';

    public const KINDS = [
        'reclamo' => 'Reclamo',
        'queja'   => 'Queja',
    ];

    public const DOCUMENTS = ['DNI', 'CE', 'Pasaporte', 'RUC'];

    public static function ready(): bool
    {
        return Schema::hasTable('complaints');
    }

    public static function easter(int $year): Carbon
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return Carbon::create($year, $month, $day, 0, 0, 0, self::ZONE);
    }

    public static function holidays(int $year): array
    {
        $days = [];

        foreach (Holidays::NATIONAL as $day => $name) {
            $days["{$year}-{$day}"] = $name;
        }

        $easter = self::easter($year);
        $days[$easter->copy()->subDays(3)->toDateString()] = 'Jueves Santo';
        $days[$easter->copy()->subDays(2)->toDateString()] = 'Viernes Santo';

        return $days;
    }

    public static function isBusinessDay(Carbon $day): bool
    {
        return !$day->isWeekend() && !array_key_exists($day->toDateString(), self::holidays($day->year));
    }

    public static function dueOn(Carbon $from): Carbon
    {
        $day = $from->copy()->setTimezone(self::ZONE)->startOfDay();
        $count = 0;

        while ($count < self::DAYS) {
            $day->addDay();
            if (self::isBusinessDay($day)) {
                $count++;
            }
        }

        return $day;
    }

    public static function businessDaysLeft(Carbon $today, Carbon $due): int
    {
        $day = $today->copy()->startOfDay();
        $end = $due->copy()->startOfDay();
        $count = 0;

        while ($day->lt($end)) {
            $day->addDay();
            if (self::isBusinessDay($day)) {
                $count++;
            }
        }

        return $count;
    }

    public static function today(): Carbon
    {
        return now(self::ZONE)->startOfDay();
    }

    public static function stateOf(object $row): string
    {
        if ($row->status === 'answered') {
            return 'answered';
        }

        $today = self::today();
        $due = Carbon::parse($row->due_on, self::ZONE)->startOfDay();

        if ($today->gt($due)) {
            return 'late';
        }

        return self::businessDaysLeft($today, $due) <= self::SOON ? 'soon' : 'open';
    }

    public static function issuer(?Tenant $tenant): array
    {
        if (!$tenant) {
            $values = PlatformSetting::values();

            return [
                'name'       => PlatformMail::productName(),
                'legal_name' => $values['legal_name'] ?: null,
                'tax_id'     => $values['legal_tax_id'] ?: null,
                'address'    => $values['legal_address'] ?: null,
            ];
        }

        $company = $tenant->company;
        $address = collect([$company?->address, $company?->district, $company?->department])->filter()->unique()->implode(', ');

        return [
            'name'       => $company?->trade_name ?: $tenant->name,
            'legal_name' => $company?->legal_name ?: null,
            'tax_id'     => $company?->tax_id ?: null,
            'address'    => $address !== '' ? $address : null,
        ];
    }

    public static function rules(): array
    {
        return [
            'name'              => 'required|string|max:150',
            'document_type'     => 'required|in:' . implode(',', self::DOCUMENTS),
            'document_number'   => 'required|string|max:20',
            'address'           => 'required|string|max:255',
            'phone'             => 'required|string|max:30',
            'email'             => 'required|email|max:190',
            'is_minor'          => 'boolean',
            'guardian_name'     => 'required_if:is_minor,true|nullable|string|max:150',
            'guardian_document_type'   => 'required_if:is_minor,true|nullable|in:' . implode(',', self::DOCUMENTS),
            'guardian_document_number' => 'required_if:is_minor,true|nullable|string|max:20',
            'good_type'         => 'required|in:producto,servicio',
            'good_description'  => 'required|string|max:500',
            'amount'            => 'nullable|numeric|min:0|max:9999999',
            'kind'              => 'required|in:reclamo,queja',
            'detail'            => 'required|string|min:10|max:5000',
            'request'           => 'required|string|min:5|max:3000',
            'accepted'          => 'accepted',
        ];
    }

    public static function messages(): array
    {
        return [
            'required'             => 'Completa este dato.',
            'required_if'          => 'Completa este dato del padre, madre o apoderado.',
            'email'                => 'Escribe un correo válido, por ejemplo nombre@correo.com.',
            'detail.min'           => 'Cuéntanos con un poco más de detalle lo que pasó.',
            'request.min'          => 'Escribe qué pides para solucionarlo.',
            'accepted.accepted'    => 'Marca la casilla para confirmar que los datos son verdaderos.',
            'amount.numeric'       => 'Escribe el monto solo con números, por ejemplo 80.50.',
        ];
    }

    public static function submit(array $data, ?Tenant $tenant): object
    {
        if ($tenant && $tenant->status !== 'active') {
            throw ValidationException::withMessages(['name' => 'Esta empresa no está recibiendo reclamos en este momento.']);
        }

        $now = now(self::ZONE);
        $year = (int) $now->year;
        $id = (string) Str::uuid();
        $minor = !empty($data['is_minor']);

        $row = DB::transaction(function () use ($data, $tenant, $now, $year, $id, $minor) {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ['complaints:' . ($tenant?->id ?? 'platform') . ':' . $year]);

            $number = (int) DB::table('complaints')
                ->when($tenant, fn ($q) => $q->where('tenant_id', $tenant->id), fn ($q) => $q->whereNull('tenant_id'))
                ->where('year', $year)
                ->max('number') + 1;

            DB::table('complaints')->insert([
                'id'               => $id,
                'tenant_id'        => $tenant?->id,
                'code'             => sprintf('LR-%d-%04d', $year, $number),
                'year'             => $year,
                'number'           => $number,
                'kind'             => $data['kind'],
                'consumer'         => json_encode([
                    'name'            => trim($data['name']),
                    'document_type'   => $data['document_type'],
                    'document_number' => trim($data['document_number']),
                    'address'         => trim($data['address']),
                    'phone'           => trim($data['phone']),
                    'email'           => mb_strtolower(trim($data['email'])),
                    'is_minor'        => $minor,
                ]),
                'guardian'         => $minor ? json_encode([
                    'name'            => trim((string) $data['guardian_name']),
                    'document_type'   => $data['guardian_document_type'],
                    'document_number' => trim((string) $data['guardian_document_number']),
                ]) : null,
                'good_type'        => $data['good_type'],
                'good_description' => trim($data['good_description']),
                'amount'           => isset($data['amount']) && $data['amount'] !== '' ? round((float) $data['amount'], 2) : null,
                'currency'         => $tenant ? Currency::of($tenant) : 'PEN',
                'detail'           => trim($data['detail']),
                'request'          => trim($data['request']),
                'company'          => json_encode(self::issuer($tenant)),
                'due_on'           => self::dueOn($now)->toDateString(),
                'status'           => 'open',
                'created_at'       => $now->copy()->utc(),
                'updated_at'       => $now->copy()->utc(),
            ]);

            return DB::table('complaints')->where('id', $id)->first();
        });

        self::afterSubmit($row, $tenant);

        return $row;
    }

    private static function afterSubmit(object $row, ?Tenant $tenant): void
    {
        $item = self::present($row);
        $kind = mb_strtolower(self::KINDS[$row->kind]);
        $issuer = $item['company'];

        PlatformMail::trySend(
            $item['consumer']['email'],
            "Constancia de tu {$kind} {$row->code}",
            '<p>Hola ' . e($item['consumer']['name']) . ',</p>'
            . '<p>Recibimos tu ' . $kind . ' en el Libro de Reclamaciones de <strong>' . e($issuer['name']) . '</strong>. Esta es tu constancia: guárdala.</p>'
            . self::summaryHtml($item)
            . '<p>Te responderemos a este correo hasta el <strong>' . self::longDate($row->due_on) . '</strong> (' . self::DAYS . ' días hábiles).</p>',
            $tenant,
        );

        $title = ($row->kind === 'queja' ? 'Nueva' : 'Nuevo') . " {$kind} {$row->code} en el Libro de Reclamaciones";
        $body = $item['consumer']['name'] . ' · ' . $item['good_description'] . '. Hay que responder hasta el ' . self::longDate($row->due_on) . '.';

        if ($tenant) {
            foreach (Notifier::staffWith($tenant->id, 'complaints.view', null) as $userId) {
                Notifier::send($tenant->id, $userId, 'complaint.new', $title, $body, "/complaints/{$row->id}", 'complaint', $row->id, "complaint-new:{$row->id}");
            }

            return;
        }

        foreach (self::platformAdminIds() as $userId) {
            self::notifyPlatform($userId, 'complaint.new', $title, $body, $row->id, "complaint-new:{$row->id}");
        }

        $support = PlatformSetting::value('support_email');

        if ($support) {
            PlatformMail::trySend($support, $title, '<p>Llegó ' . ($row->kind === 'queja' ? 'una' : 'un') . ' ' . $kind . ' al Libro de Reclamaciones de la plataforma.</p>' . self::summaryHtml($item) . '<p>Respóndelo desde la consola, en Libro de Reclamaciones, hasta el <strong>' . self::longDate($row->due_on) . '</strong>.</p>');
        }
    }

    public static function summaryHtml(array $item): string
    {
        $consumer = $item['consumer'];
        $lines = [
            'Código'               => $item['code'],
            'Fecha'                => self::longDate($item['created_on']),
            'Tipo'                 => $item['kind_label'],
            'Nombre'               => $consumer['name'],
            'Documento'            => $consumer['document_type'] . ' ' . $consumer['document_number'],
            'Domicilio'            => $consumer['address'],
            'Teléfono'             => $consumer['phone'],
            'Correo'               => $consumer['email'],
        ];

        if ($item['guardian']) {
            $lines['Padre, madre o apoderado'] = $item['guardian']['name'] . ' (' . $item['guardian']['document_type'] . ' ' . $item['guardian']['document_number'] . ')';
        }

        $lines[$item['good_type'] === 'producto' ? 'Producto' : 'Servicio'] = $item['good_description'];

        if ($item['amount'] !== null) {
            $lines['Monto'] = Currency::format((float) $item['amount'], $item['currency']);
        }

        $company = $item['company'];
        $issuer = collect([$company['legal_name'] ?? null, !empty($company['tax_id']) ? 'RUC ' . $company['tax_id'] : null, $company['address'] ?? null])->filter()->implode(' · ');

        return '<ul>' . collect($lines)->map(fn ($value, $label) => '<li><strong>' . e($label) . ':</strong> ' . e((string) $value) . '</li>')->implode('') . '</ul>'
            . '<p><strong>Detalle:</strong><br>' . nl2br(e($item['detail']), false) . '</p>'
            . '<p><strong>Pedido:</strong><br>' . nl2br(e($item['request']), false) . '</p>'
            . ($issuer !== '' ? '<p>' . e($issuer) . '</p>' : '');
    }

    public static function longDate(string $date): string
    {
        return Carbon::parse($date, self::ZONE)->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
    }

    public static function present(object $row): array
    {
        $consumer = json_decode($row->consumer, true) ?: [];
        $guardian = $row->guardian ? json_decode($row->guardian, true) : null;
        $created = Carbon::parse($row->created_at)->setTimezone(self::ZONE);
        $state = self::stateOf($row);

        return [
            'id'               => $row->id,
            'code'             => $row->code,
            'kind'             => $row->kind,
            'kind_label'       => self::KINDS[$row->kind],
            'consumer'         => $consumer,
            'guardian'         => $guardian,
            'good_type'        => $row->good_type,
            'good_description' => $row->good_description,
            'amount'           => $row->amount !== null ? (float) $row->amount : null,
            'currency'         => $row->currency,
            'detail'           => $row->detail,
            'request'          => $row->request,
            'company'          => json_decode($row->company, true) ?: [],
            'created_on'       => $created->toDateString(),
            'created_at'       => $created->format('Y-m-d\TH:i'),
            'due_on'           => $row->due_on,
            'days_left'        => $state === 'answered' ? null : ($state === 'late' ? 0 : self::businessDaysLeft(self::today(), Carbon::parse($row->due_on, self::ZONE))),
            'state'            => $state,
            'status'           => $row->status,
            'response'         => $row->response,
            'responded_at'     => $row->responded_at ? Carbon::parse($row->responded_at)->setTimezone(self::ZONE)->format('Y-m-d\TH:i') : null,
            'responder'        => $row->responded_by ? Person::name(User::find($row->responded_by)) : null,
        ];
    }

    public static function query(?string $tenantId)
    {
        return DB::table('complaints')->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId), fn ($q) => $q->whereNull('tenant_id'));
    }

    public static function list(?string $tenantId, string $tab): array
    {
        $rows = self::query($tenantId)->orderBy('due_on')->orderBy('created_at')->get()->map(fn ($row) => self::present($row));
        $groups = [
            'pending'  => $rows->where('state', 'open'),
            'due'      => $rows->whereIn('state', ['soon', 'late']),
            'answered' => $rows->where('state', 'answered')->sortByDesc('responded_at'),
        ];

        return [
            'complaints' => ($groups[$tab] ?? $groups['pending'])->values()->all(),
            'counts'     => collect($groups)->map->count()->all(),
        ];
    }

    public static function find(?string $tenantId, string $id): object
    {
        abort_unless(Str::isUuid($id), 404);

        return self::query($tenantId)->where('id', $id)->first() ?? abort(404, 'No encontramos ese reclamo.');
    }

    public static function answer(?string $tenantId, string $id, string $response, User $user): array
    {
        $row = self::find($tenantId, $id);

        if ($row->status === 'answered') {
            throw ValidationException::withMessages(['response' => 'Este reclamo ya fue respondido. La respuesta no se puede cambiar.']);
        }

        DB::table('complaints')->where('id', $row->id)->where('status', 'open')->update([
            'status'       => 'answered',
            'response'     => trim($response),
            'responded_by' => $user->id,
            'responded_at' => now(),
            'updated_at'   => now(),
        ]);

        $row = DB::table('complaints')->where('id', $row->id)->first();
        $item = self::present($row);
        $tenant = $row->tenant_id ? Tenant::find($row->tenant_id) : null;
        $kind = mb_strtolower($item['kind_label']);

        $sent = PlatformMail::trySend(
            $item['consumer']['email'],
            "Respuesta a tu {$kind} {$row->code}",
            '<p>Hola ' . e($item['consumer']['name']) . ',</p>'
            . '<p>Esta es la respuesta de <strong>' . e($item['company']['name'] ?? '') . '</strong> a tu ' . $kind . ' ' . e($row->code) . ' del ' . self::longDate($item['created_on']) . ':</p>'
            . '<p>' . nl2br(e($item['response']), false) . '</p>'
            . self::summaryHtml($item),
            $tenant,
        );

        DB::table('user_notifications')->where('entity_type', 'complaint')->where('entity_id', $row->id)->whereNull('read_at')->update(['read_at' => now()]);

        return ['complaint' => $item, 'mailed' => $sent];
    }

    public static function platformAdminIds(): array
    {
        return Rls::bypass(fn () => User::whereNull('tenant_id')->where('status', 'active')
            ->whereDoesntHave('roles', fn ($r) => $r->where('slug', 'saas_support'))
            ->pluck('id')->all());
    }

    private static function notifyPlatform(string $userId, string $type, string $title, string $body, string $id, string $key): void
    {
        Rls::bypass(fn () => DB::table('user_notifications')->insertOrIgnore([
            'id'          => (string) Str::uuid(),
            'tenant_id'   => null,
            'user_id'     => $userId,
            'type'        => $type,
            'title'       => mb_substr($title, 0, 190),
            'body'        => mb_substr($body, 0, 500),
            'link'        => "/admin/complaints/{$id}",
            'entity_type' => 'complaint',
            'entity_id'   => $id,
            'dedupe_key'  => $key,
            'created_at'  => now(),
        ]));
    }

    public static function notices(?Tenant $tenant): void
    {
        if (!self::ready()) {
            return;
        }

        $open = self::query($tenant?->id)->where('status', 'open')->get();

        if ($open->isEmpty()) {
            return;
        }

        $recipients = $tenant ? Notifier::staffWith($tenant->id, 'complaints.view', null) : self::platformAdminIds();
        $base = $tenant ? '/complaints' : '/admin/complaints';

        foreach ($open as $row) {
            $state = self::stateOf($row);

            if (!in_array($state, ['soon', 'late'], true)) {
                continue;
            }

            $kind = mb_strtolower(self::KINDS[$row->kind]);
            $the = $row->kind === 'queja' ? 'la' : 'el';
            $title = $state === 'late' ? "Venció el plazo de {$the} {$kind} {$row->code}" : ucfirst($the) . " {$kind} {$row->code} vence pronto";
            $body = $state === 'late'
                ? 'El plazo era hasta el ' . self::longDate($row->due_on) . '. ' . ($row->kind === 'queja' ? 'Respóndela' : 'Respóndelo') . ' cuanto antes.'
                : 'Hay que ' . ($row->kind === 'queja' ? 'responderla' : 'responderlo') . ' hasta el ' . self::longDate($row->due_on) . '.';
            $key = "complaint-{$state}:{$row->id}";

            foreach ($recipients as $userId) {
                if ($tenant) {
                    Notifier::send($tenant->id, $userId, "complaint.{$state}", $title, $body, "{$base}/{$row->id}", 'complaint', $row->id, $key);
                } else {
                    self::notifyPlatform($userId, "complaint.{$state}", $title, $body, $row->id, $key);
                }
            }
        }
    }
}
