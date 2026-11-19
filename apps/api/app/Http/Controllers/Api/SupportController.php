<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\Referrals;
use App\Support\SupportDesk;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SupportController extends Controller
{
    private const FILE = 'nullable|file|mimes:jpg,jpeg,png,webp,gif,pdf|max:5120';

    private const FILE_MESSAGES = [
        'attachment.mimes' => 'La captura debe ser una imagen (JPG, PNG o WEBP) o un PDF.',
        'attachment.max'   => 'La captura pesa más de 5 MB.',
    ];

    private function ticket(Request $request, string $id): object
    {
        abort_unless(SupportDesk::ready(), 503, SupportDesk::NOT_READY);

        return SupportDesk::find($id, $request->user()->tenant_id) ?? abort(404, 'No encontramos ese pedido de ayuda.');
    }

    public function index(Request $request)
    {
        if (!SupportDesk::ready()) {
            return response()->json(['ready' => false, 'tickets' => [], 'topics' => [], 'message' => SupportDesk::NOT_READY]);
        }

        $tickets = DB::table('saas_support_tickets')->where('tenant_id', $request->user()->tenant_id)->orderByDesc('last_message_at')->limit(500)->get();

        return response()->json([
            'ready'        => true,
            'tickets'      => SupportDesk::presentMany($tickets, false),
            'topics'       => collect(SupportDesk::TOPICS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'hours'        => SupportDesk::responseHours($request->user()->tenant_id, 'normal'),
            'urgent_hours' => SupportDesk::responseHours($request->user()->tenant_id, 'urgent'),
            'access_hours' => SupportDesk::ACCESS_HOURS,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'subject'      => 'required|string|min:4|max:150',
            'topic'        => 'nullable|string|in:' . implode(',', array_keys(SupportDesk::TOPICS)),
            'priority'     => 'nullable|in:normal,urgent',
            'body'         => 'required|string|min:10|max:5000',
            'allow_access' => 'nullable|boolean',
            'attachment'   => self::FILE,
        ], self::FILE_MESSAGES + [
            'subject.required' => 'Escribe de qué se trata.',
            'subject.min'      => 'El asunto es muy corto.',
            'body.required'    => 'Cuéntanos qué pasó.',
            'body.min'         => 'Cuéntanos un poco más para poder ayudarte.',
        ]);

        $ticket = SupportDesk::create($request->user(), $validated, $request->file('attachment'));

        Audit::log($request, 'support.ticket_created', [
            'tenant'       => $request->user()->tenant_id,
            'entity_type'  => 'support_ticket',
            'entity_id'    => $ticket->id,
            'entity_label' => "{$ticket->code} · {$ticket->subject}",
            'after'        => ['text' => $ticket->subject],
        ]);

        return response()->json(['ticket' => SupportDesk::present($ticket, false, SupportDesk::names([$ticket->created_by])), 'message' => "Recibimos tu pedido {$ticket->code}. Te avisaremos aquí y por correo cuando respondamos."], 201);
    }

    public function show(Request $request, string $ticket)
    {
        $row = $this->ticket($request, $ticket);

        return response()->json([
            'ticket'       => SupportDesk::presentMany(collect([$row]), false)[0],
            'messages'     => SupportDesk::messages($row, false),
            'access_hours' => SupportDesk::ACCESS_HOURS,
        ]);
    }

    public function reply(Request $request, string $ticket)
    {
        $row = $this->ticket($request, $ticket);
        $validated = $request->validate([
            'body'       => 'required|string|min:2|max:5000',
            'attachment' => self::FILE,
        ], self::FILE_MESSAGES + ['body.required' => 'Escribe tu mensaje.']);

        SupportDesk::reply($row, $request->user(), 'tenant', $validated['body'], $request->file('attachment'));

        return $this->show($request, $ticket);
    }

    public function update(Request $request, string $ticket)
    {
        $row = $this->ticket($request, $ticket);
        $validated = $request->validate([
            'resolved'     => 'nullable|boolean',
            'allow_access' => 'nullable|boolean',
        ]);

        $update = ['updated_at' => now()];

        if (array_key_exists('resolved', $validated)) {
            $update['status'] = $validated['resolved'] ? 'resolved' : 'open';
            $update['resolved_at'] = $validated['resolved'] ? now() : null;
        }

        if (array_key_exists('allow_access', $validated)) {
            $update['access_until'] = $validated['allow_access'] ? now()->addHours(SupportDesk::ACCESS_HOURS) : null;
        }

        DB::table('saas_support_tickets')->where('id', $row->id)->update($update);

        Audit::log($request, 'support.ticket_updated', [
            'tenant'       => $row->tenant_id,
            'entity_type'  => 'support_ticket',
            'entity_id'    => $row->id,
            'entity_label' => "{$row->code} · {$row->subject}",
            'after'        => ['text' => collect([
                array_key_exists('resolved', $validated) ? ($validated['resolved'] ? 'Lo marcó como resuelto' : 'Lo volvió a abrir') : null,
                array_key_exists('allow_access', $validated) ? ($validated['allow_access'] ? 'Autorizó que soporte entre a la cuenta' : 'Quitó el permiso para que soporte entre') : null,
            ])->filter()->implode('. ')],
        ]);

        return $this->show($request, $ticket);
    }

    public function file(Request $request, string $ticket, string $message)
    {
        return SupportDesk::file($this->ticket($request, $ticket), $message);
    }

    public function access(Request $request)
    {
        $user = $request->user();

        if (!Schema::hasTable('saas_support_sessions')) {
            return response()->json(['sessions' => [], 'recent' => []]);
        }

        $rows = DB::table('saas_support_sessions')->where('tenant_id', $user->tenant_id)->orderByDesc('started_at')->limit(5)->get();
        $names = SupportDesk::names($rows->pluck('platform_user_id'));
        $present = fn ($r) => [
            'id'         => $r->id,
            'who'        => $names[$r->platform_user_id] ?? 'Soporte',
            'reason'     => $r->reason,
            'started_at' => Carbon::parse($r->started_at)->toIso8601String(),
            'expires_at' => $r->expires_at ? Carbon::parse($r->expires_at)->toIso8601String() : null,
            'ended_at'   => $r->ended_at ? Carbon::parse($r->ended_at)->toIso8601String() : null,
        ];

        return response()->json([
            'sessions' => $rows->filter(fn ($r) => !$r->ended_at && (!$r->expires_at || Carbon::parse($r->expires_at)->isFuture()))->map($present)->values(),
            'recent'   => $rows->map($present)->values(),
        ]);
    }

    public function referral(Request $request)
    {
        abort_unless($request->user()->isTenantAdmin(), 403, 'Solo quien administra la empresa ve el enlace para recomendar.');

        return response()->json(Referrals::forTenant($request->user()->tenant));
    }
}
