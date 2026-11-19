<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class CustomerImport
{
    public const MAX_ROWS = 2000;

    public const COLUMNS = [
        'first_name'      => ['label' => 'Nombres', 'required' => true, 'example' => 'Juliana', 'aliases' => ['nombre', 'nombres', 'first name']],
        'last_name'       => ['label' => 'Apellidos', 'required' => true, 'example' => 'Díaz Ramos', 'aliases' => ['apellido', 'apellidos', 'last name']],
        'document_type'   => ['label' => 'Tipo de documento', 'example' => 'DNI', 'aliases' => ['tipo de documento', 'tipo documento', 'tipo doc']],
        'document_number' => ['label' => 'Número de documento', 'example' => '45678912', 'aliases' => ['numero de documento', 'documento', 'dni', 'nro documento', 'n de documento', 'numero documento']],
        'phone'           => ['label' => 'Celular', 'example' => '987654321', 'aliases' => ['celular', 'telefono', 'movil', 'whatsapp', 'phone']],
        'email'           => ['label' => 'Correo', 'example' => 'juliana@correo.com', 'aliases' => ['correo', 'email', 'correo electronico', 'e-mail']],
        'birth_date'      => ['label' => 'Fecha de nacimiento', 'example' => '15/03/1990', 'aliases' => ['fecha de nacimiento', 'nacimiento', 'cumpleanos', 'f. nacimiento']],
        'biological_sex'  => ['label' => 'Sexo', 'example' => 'Femenino', 'aliases' => ['sexo', 'genero']],
        'address'         => ['label' => 'Dirección', 'example' => 'Av. Principal 123', 'aliases' => ['direccion', 'domicilio']],
        'district'        => ['label' => 'Distrito', 'example' => 'Miraflores', 'aliases' => ['distrito']],
        'department'      => ['label' => 'Departamento', 'example' => 'Lima', 'aliases' => ['departamento', 'region']],
        'medical_notes'   => ['label' => 'Observaciones', 'example' => '', 'aliases' => ['observaciones', 'notas', 'antecedentes']],
        'alert'           => ['label' => 'Alerta', 'example' => '', 'aliases' => ['alerta', 'aviso']],
    ];

    public static function template(): array
    {
        return [
            'columns' => collect(self::COLUMNS)->map(fn ($c) => ['header' => $c['label'] . (!empty($c['required']) ? ' *' : ''), 'example' => $c['example']])->values()->all(),
            'notes'   => [
                'Los campos con * son obligatorios.',
                'El celular se guarda con el código del país; puedes escribirlo con o sin él.',
                'La fecha de nacimiento va como día/mes/año.',
                'En Sexo escribe Masculino o Femenino.',
            ],
        ];
    }

    private static function plain(string $value): string
    {
        $value = mb_strtolower(trim(str_replace('*', '', $value)));

        return trim(strtr($value, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n', '°' => '', 'º' => '', '.' => '']));
    }

    public static function read(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $rows = $extension === 'xlsx' ? self::readXlsx($file->getRealPath()) : self::readCsv($file->getRealPath());

        return array_values(array_filter($rows, fn ($row) => implode('', array_map(fn ($v) => trim((string) $v), $row)) !== ''));
    }

    private static function readCsv(string $path): array
    {
        $content = (string) file_get_contents($path);
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);

        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $firstLine = strtok($content, "\n") ?: '';
        $delimiter = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : (substr_count($firstLine, "\t") > substr_count($firstLine, ',') ? "\t" : ',');
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);
        $rows = [];

        while (($row = fgetcsv($handle, 0, $delimiter, '"', '')) !== false) {
            $rows[] = $row;

            if (count($rows) > self::MAX_ROWS + 1) {
                break;
            }
        }

        fclose($handle);

        return $rows;
    }

    private static function readXlsx(string $path): array
    {
        $zip = new \ZipArchive();

        if ($zip->open($path) !== true) {
            throw new \RuntimeException('No se pudo abrir el archivo de Excel. Guárdalo como .xlsx o como .csv e inténtalo de nuevo.');
        }

        $shared = [];
        $sharedXml = $zip->getFromName('xl/sharedStrings.xml');

        if ($sharedXml !== false) {
            $xml = simplexml_load_string($sharedXml);

            foreach ($xml->si ?? [] as $si) {
                $text = isset($si->t) ? (string) $si->t : '';

                foreach ($si->r ?? [] as $run) {
                    $text .= (string) $run->t;
                }

                $shared[] = $text;
            }
        }

        $sheetName = 'xl/worksheets/sheet1.xml';

        if ($zip->locateName($sheetName) === false) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);

                if (str_starts_with($name, 'xl/worksheets/') && str_ends_with($name, '.xml')) {
                    $sheetName = $name;
                    break;
                }
            }
        }

        $sheetXml = $zip->getFromName($sheetName);
        $zip->close();

        if ($sheetXml === false) {
            throw new \RuntimeException('El Excel no tiene ninguna hoja con datos.');
        }

        $xml = simplexml_load_string($sheetXml);
        $rows = [];

        foreach ($xml->sheetData->row ?? [] as $row) {
            $cells = [];

            foreach ($row->c as $cell) {
                $ref = (string) $cell['r'];
                $column = self::columnIndex(preg_replace('/\d+/', '', $ref));
                $type = (string) $cell['t'];
                $value = match ($type) {
                    's'         => $shared[(int) $cell->v] ?? '',
                    'inlineStr' => (string) ($cell->is->t ?? ''),
                    default     => (string) $cell->v,
                };
                $cells[$column] = $value;
            }

            if ($cells) {
                $line = array_fill(0, max(array_keys($cells)) + 1, '');

                foreach ($cells as $index => $value) {
                    $line[$index] = $value;
                }

                $rows[] = $line;
            }

            if (count($rows) > self::MAX_ROWS + 1) {
                break;
            }
        }

        return $rows;
    }

    private static function columnIndex(string $letters): int
    {
        $index = 0;

        foreach (str_split(strtoupper($letters)) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return max(0, $index - 1);
    }

    private static function mapHeaders(array $header): array
    {
        $map = [];

        foreach ($header as $index => $title) {
            $plain = self::plain((string) $title);

            foreach (self::COLUMNS as $key => $column) {
                if (!isset($map[$key]) && ($plain === self::plain($column['label']) || in_array($plain, $column['aliases'], true))) {
                    $map[$key] = $index;
                    break;
                }
            }
        }

        return $map;
    }

    private static function birthDate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        if (is_numeric($value) && (float) $value > 1000 && (float) $value < 80000) {
            return Carbon::create(1899, 12, 30)->addDays((int) $value)->format('Y-m-d');
        }

        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y', 'Y/m/d'] as $format) {
            $date = \DateTime::createFromFormat('!' . $format, $value);

            if ($date && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return 'invalid';
    }

    private static function sex(string $value): ?string
    {
        $plain = self::plain($value);

        return match (true) {
            $plain === '' => null,
            in_array($plain, ['m', 'masculino', 'hombre', 'varon', 'male'], true) => 'M',
            in_array($plain, ['f', 'femenino', 'mujer', 'female'], true) => 'F',
            in_array($plain, ['o', 'otro', 'otra'], true) => 'O',
            default => 'invalid',
        };
    }

    public static function analyze(Tenant $tenant, array $rows, string $mode): array
    {
        if (count($rows) < 2) {
            return ['error' => 'El archivo no tiene filas con clientes. Usa la plantilla: la primera fila son los títulos y desde la segunda van los clientes.'];
        }

        $map = self::mapHeaders(array_shift($rows));

        if (!isset($map['first_name']) || !isset($map['last_name'])) {
            return ['error' => 'No encontramos las columnas "Nombres" y "Apellidos" en la primera fila. Descarga la plantilla y copia tus datos en ella.'];
        }

        if (count($rows) > self::MAX_ROWS) {
            return ['error' => 'El archivo tiene más de ' . self::MAX_ROWS . ' clientes. Divídelo en partes más pequeñas.'];
        }

        $prefix = Phones::prefixFor($tenant);
        $types = DB::table('type_documents')->get(['id', 'name', 'value']);
        $defaultType = $types->first()?->id;
        $hasExtra = CustomerProfile::fieldsReady();
        $seenDocs = [];
        $seenPhones = [];
        $result = [];

        foreach ($rows as $offset => $row) {
            $line = $offset + 2;
            $get = fn (string $key) => isset($map[$key]) ? trim((string) ($row[$map[$key]] ?? '')) : '';
            $errors = [];
            $data = [];

            foreach (['first_name', 'last_name', 'document_number', 'email', 'address', 'district', 'department', 'medical_notes'] as $key) {
                $data[$key] = $get($key) ?: null;
            }

            if ($hasExtra) {
                $data['alert'] = $get('alert') ? mb_substr($get('alert'), 0, 160) : null;
            }

            if (!$data['first_name']) {
                $errors[] = 'Falta el nombre.';
            }

            if (!$data['last_name']) {
                $errors[] = 'Faltan los apellidos.';
            }

            foreach (['first_name' => 'El nombre', 'last_name' => 'El apellido'] as $key => $label) {
                if ($data[$key] && mb_strlen($data[$key]) > 150) {
                    $errors[] = "{$label} es demasiado largo.";
                }
            }

            if ($data['document_number'] && mb_strlen($data['document_number']) > 30) {
                $errors[] = 'El número de documento es demasiado largo.';
            }

            if ($typeText = $get('document_type')) {
                $plainType = self::plain($typeText);
                $type = $types->first(fn ($t) => self::plain((string) $t->name) === $plainType || self::plain((string) $t->value) === $plainType || str_starts_with(self::plain((string) $t->name), $plainType));
                if ($type) {
                    $data['type_document_id'] = $type->id;
                } else {
                    $errors[] = "No conocemos el tipo de documento \"{$typeText}\".";
                }
            } elseif ($data['document_number']) {
                $data['type_document_id'] = $defaultType;
            }

            if ($phoneText = $get('phone')) {
                $phone = Phones::normalize($phoneText, $prefix);
                if ($phone) {
                    $data['phone'] = $phone;
                } else {
                    $errors[] = "El celular \"{$phoneText}\" no parece un número.";
                }
            }

            if ($data['email'] && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = "El correo \"{$data['email']}\" no está bien escrito.";
            }

            $birth = self::birthDate($get('birth_date'));

            if ($birth === 'invalid') {
                $errors[] = 'La fecha de nacimiento no se entiende: escríbela como día/mes/año.';
            } elseif ($birth) {
                if ($birth > now()->format('Y-m-d')) {
                    $errors[] = 'La fecha de nacimiento es una fecha futura.';
                } else {
                    $data['birth_date'] = $birth;
                }
            }

            $sex = self::sex($get('biological_sex'));

            if ($sex === 'invalid') {
                $errors[] = 'En Sexo escribe Masculino o Femenino.';
            } elseif ($sex) {
                $data['biological_sex'] = $sex;
            }

            $docKey = $data['document_number'] ? CustomerRecords::normalizedDocument($data['document_number']) : null;
            $phoneKey = isset($data['phone']) ? Phones::digits($data['phone']) : null;

            if ($docKey && isset($seenDocs[$docKey])) {
                $errors[] = "El documento se repite en la fila {$seenDocs[$docKey]}.";
            }

            if ($phoneKey && isset($seenPhones[$phoneKey])) {
                $errors[] = "El celular se repite en la fila {$seenPhones[$phoneKey]}.";
            }

            if ($docKey) {
                $seenDocs[$docKey] ??= $line;
            }

            if ($phoneKey) {
                $seenPhones[$phoneKey] ??= $line;
            }

            $byDoc = $docKey ? CustomerRecords::duplicateByDocument($tenant->id, $data['document_number']) : null;
            $byPhone = $phoneKey ? Customer::where('tenant_id', $tenant->id)->whereRaw("regexp_replace(coalesce(phone, ''), '[^0-9]', '', 'g') = ?", [$phoneKey])->first() : null;

            if ($byDoc && $byPhone && $byDoc->id !== $byPhone->id) {
                $errors[] = 'El documento es de ' . Person::name($byDoc) . ' y el celular de ' . Person::name($byPhone) . ': revisa cuál es el correcto.';
            }

            $match = $byDoc ?? $byPhone;
            $action = match (true) {
                (bool) $errors => 'error',
                $match !== null => $mode === 'update' ? 'update' : 'skip',
                default => 'create',
            };

            $result[] = [
                'row'      => $line,
                'name'     => trim(($data['first_name'] ?? '') . ' ' . ($data['last_name'] ?? '')),
                'document' => $data['document_number'],
                'phone'    => $data['phone'] ?? null,
                'email'    => $data['email'],
                'action'   => $action,
                'errors'   => $errors,
                'match'    => $match ? ['id' => $match->id, 'name' => Person::name($match), 'code' => $match->code, 'by' => $byDoc ? 'documento' : 'celular'] : null,
                'data'     => array_filter($data, fn ($v) => $v !== null && $v !== ''),
            ];
        }

        $counts = collect($result)->countBy('action')->all();

        return [
            'rows'    => $result,
            'columns' => array_values(array_map(fn ($key) => self::COLUMNS[$key]['label'], array_keys($map))),
            'missing_columns' => array_values(array_map(fn ($key) => self::COLUMNS[$key]['label'], array_diff(array_keys(self::COLUMNS), array_keys($map)))),
            'counts'  => ['create' => $counts['create'] ?? 0, 'update' => $counts['update'] ?? 0, 'skip' => $counts['skip'] ?? 0, 'error' => $counts['error'] ?? 0],
        ];
    }

    public static function apply(Tenant $tenant, array $analysis): array
    {
        $created = 0;
        $updated = 0;

        foreach ($analysis['rows'] as $row) {
            if ($row['action'] === 'create') {
                $customer = Customer::create($row['data'] + ['tenant_id' => $tenant->id, 'code' => Customer::nextCode($tenant->id)]);
                $customer->syncPortalPassword();
                $created++;
            } elseif ($row['action'] === 'update') {
                $customer = Customer::where('tenant_id', $tenant->id)->find($row['match']['id']);

                if ($customer) {
                    $documentBefore = $customer->document_number;
                    $customer->fill($row['data'])->save();
                    $customer->syncPortalPassword($customer->document_number !== $documentBefore);
                    $updated++;
                }
            }
        }

        return ['created' => $created, 'updated' => $updated];
    }
}
