<?php

namespace App\Support;

use App\Models\AuditEvent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DataExports
{
    public const DAYS = 7;

    public const NOT_READY = 'Falta actualizar la base de datos para preparar el archivo con todos los datos: ejecuta php artisan migrate.';

    public const STATUSES = ['queued' => 'En cola', 'running' => 'Preparando', 'ready' => 'Listo', 'failed' => 'No se pudo preparar'];

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('data_exports');
    }

    public static function forget(): void
    {
        self::$ready = null;
    }

    public static function request(Tenant $tenant, User $user, ?Request $request = null): object
    {
        abort_unless(self::ready(), 503, self::NOT_READY);

        $busy = DB::table('data_exports')->where('tenant_id', $tenant->id)->when(ReportExports::ready(), fn ($q) => $q->where('kind', 'all'))->whereIn('status', ['queued', 'running'])->where('created_at', '>', now()->subHour())->first();

        if ($busy) {
            throw ValidationException::withMessages(['export' => 'Ya estamos preparando un archivo con tus datos. Te avisaremos cuando esté listo.']);
        }

        $id = (string) Str::uuid();
        DB::table('data_exports')->insert(['id' => $id, 'tenant_id' => $tenant->id, 'requested_by' => $user->id, 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);

        AuditEvent::create([
            'tenant_id'     => $tenant->id,
            'actor_user_id' => $user->id,
            'action'        => \App\Http\Controllers\Api\DataExportController::ACTION,
            'entity_type'   => 'Todos los datos',
            'ip_address'    => $request?->ip(),
            'user_agent'    => mb_substr((string) $request?->userAgent(), 0, 500),
            'after_data'    => ['title' => 'Todos los datos de la empresa', 'format' => 'xlsx', 'scope' => 'all', 'rows' => 0, 'export_id' => $id],
        ]);

        if (config('queue.default') === 'sync' || app()->runningInConsole()) {
            self::run($id);
        } else {
            dispatch(fn () => self::run($id))->afterResponse();
        }

        return DB::table('data_exports')->where('id', $id)->first();
    }

    public static function run(string $id): void
    {
        $row = Rls::bypass(fn () => DB::table('data_exports')->where('id', $id)->first());

        if (!$row || !in_array($row->status, ['queued', 'running'], true)) {
            return;
        }

        if (($row->kind ?? 'all') === ReportExports::KIND) {
            ReportExports::run($row);

            return;
        }

        Rls::asTenant($row->tenant_id, function () use ($row) {
            DB::table('data_exports')->where('id', $row->id)->update(['status' => 'running', 'started_at' => now(), 'updated_at' => now()]);

            try {
                $tenant = Tenant::findOrFail($row->tenant_id);
                $sheets = DataBackup::sheets($tenant);
                $path = "exports/{$tenant->id}/{$row->id}.xlsx";
                Storage::disk('local')->put($path, self::xlsx($sheets));
                $rows = array_sum(array_map(fn ($sheet) => count($sheet['rows']), $sheets));

                DB::table('data_exports')->where('id', $row->id)->update([
                    'status'      => 'ready',
                    'file_path'   => $path,
                    'file_size'   => Storage::disk('local')->size($path),
                    'rows'        => $rows,
                    'finished_at' => now(),
                    'expires_at'  => now()->addDays(self::DAYS),
                    'updated_at'  => now(),
                ]);

                if ($row->requested_by) {
                    Notifier::send($tenant->id, $row->requested_by, 'data.export_ready', 'Tus datos están listos', 'El archivo con todos los datos de tu empresa ya se puede descargar en Mi empresa → Plan. El enlace sirve por ' . self::DAYS . ' días.', '/companies?tab=plan', 'data_export', $row->id, "export:{$row->id}");
                }
            } catch (\Throwable $e) {
                report($e);
                DB::table('data_exports')->where('id', $row->id)->update(['status' => 'failed', 'error' => 'No se pudo preparar el archivo. Inténtalo de nuevo.', 'finished_at' => now(), 'updated_at' => now()]);
            }
        });
    }

    public static function runPending(): array
    {
        if (!self::ready()) {
            return ['run' => 0, 'removed' => 0];
        }

        return Rls::bypass(function () {
            $run = 0;

            foreach (DB::table('data_exports')->where(fn ($q) => $q->where('status', 'queued')->orWhere(fn ($r) => $r->where('status', 'running')->where('started_at', '<', now()->subMinutes(30))))->where('created_at', '<', now()->subMinute())->pluck('id') as $id) {
                DB::table('data_exports')->where('id', $id)->update(['status' => 'queued']);
                self::run($id);
                $run++;
            }

            $removed = 0;

            foreach (DB::table('data_exports')->where('status', 'ready')->whereNotNull('file_path')->where('expires_at', '<', now())->get(['id', 'file_path']) as $old) {
                Storage::disk('local')->delete($old->file_path);
                DB::table('data_exports')->where('id', $old->id)->update(['file_path' => null, 'updated_at' => now()]);
                $removed++;
            }

            return ['run' => $run, 'removed' => $removed];
        });
    }

    public static function url(object $row): ?string
    {
        if ($row->status !== 'ready' || !$row->file_path || !$row->expires_at || Carbon::parse($row->expires_at)->isPast()) {
            return null;
        }

        return url(URL::temporarySignedRoute('data-exports.download', Carbon::parse($row->expires_at), ['export' => $row->id], false));
    }

    public static function present(object $row): array
    {
        return [
            'id'          => $row->id,
            'status'      => $row->status,
            'label'       => self::STATUSES[$row->status] ?? $row->status,
            'rows'        => (int) $row->rows,
            'size'        => $row->file_size ? (int) $row->file_size : null,
            'created_at'  => Carbon::parse($row->created_at)->toIso8601String(),
            'expires_at'  => $row->expires_at ? Carbon::parse($row->expires_at)->toIso8601String() : null,
            'expired'     => $row->status === 'ready' && (!$row->file_path || ($row->expires_at && Carbon::parse($row->expires_at)->isPast())),
            'url'         => self::url($row),
            'error'       => $row->error,
        ];
    }

    public static function listFor(string $tenantId): array
    {
        if (!self::ready()) {
            return [];
        }

        return DB::table('data_exports')->where('tenant_id', $tenantId)->when(ReportExports::ready(), fn ($q) => $q->where('kind', 'all'))->orderByDesc('created_at')->limit(10)->get()->map(fn ($row) => self::present($row))->values()->all();
    }

    public static function download(string $id)
    {
        $row = Rls::bypass(fn () => DB::table('data_exports')->where('id', $id)->first());
        $where = $row && ($row->kind ?? 'all') === ReportExports::KIND ? 'Reportes' : 'Mi empresa → Plan';
        abort_unless($row && $row->status === 'ready' && $row->file_path && Storage::disk('local')->exists($row->file_path), 404, "Este enlace ya no sirve. Pide un archivo nuevo en {$where}.");
        abort_if($row->expires_at && Carbon::parse($row->expires_at)->isPast(), 410, "Este enlace venció. Pide un archivo nuevo en {$where}.");
        Rls::bypass(fn () => DB::table('data_exports')->where('id', $id)->update(['downloaded_at' => now()]));
        $company = Rls::bypass(fn () => DB::table('tenants')->where('id', $row->tenant_id)->value('name')) ?? 'empresa';
        $name = ($row->kind ?? 'all') === ReportExports::KIND ? ReportExports::fileName($row) : 'Datos de ' . Str::slug($company) . ' ' . Carbon::parse($row->finished_at)->format('Y-m-d') . '.xlsx';

        return Storage::disk('local')->download($row->file_path, $name, [
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private static function column(int $index): string
    {
        $name = '';

        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + (($n - 1) % 26)) . $name;
        }

        return $name;
    }

    private static function cell(mixed $value, string $ref): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_int($value) || is_float($value)) {
            return '<c r="' . $ref . '"><v>' . $value . '</v></c>';
        }

        $text = htmlspecialchars(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $value), ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $text . '</t></is></c>';
    }

    public static function xlsx(array $sheets): string
    {
        $file = tempnam(sys_get_temp_dir(), 'exp');
        $zip = new \ZipArchive();
        $zip->open($file, \ZipArchive::OVERWRITE);
        $names = [];
        $contentSheets = '';
        $workbookSheets = '';
        $rels = '';

        foreach (array_values($sheets) as $i => $sheet) {
            $n = $i + 1;
            $name = mb_substr(preg_replace('/[\[\]\*\?\/\\\\:]/', '', $sheet['sheet']), 0, 31);
            $names[] = $name;
            $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
            $all = array_merge([array_column($sheet['columns'], 'header')], $sheet['rows']);

            foreach ($all as $r => $values) {
                $xml .= '<row r="' . ($r + 1) . '">';

                foreach (array_values((array) $values) as $c => $value) {
                    $xml .= self::cell($value, self::column($c) . ($r + 1));
                }

                $xml .= '</row>';
            }

            $zip->addFromString("xl/worksheets/sheet{$n}.xml", $xml . '</sheetData></worksheet>');
            $contentSheets .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $workbookSheets .= '<sheet name="' . htmlspecialchars($name, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        }

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' . $contentSheets . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $workbookSheets . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '</Relationships>');
        $zip->close();
        $content = (string) file_get_contents($file);
        @unlink($file);

        return $content;
    }
}
