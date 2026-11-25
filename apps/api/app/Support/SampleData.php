<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Professional;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SampleData
{
    public const TAG = 'Ejemplo';

    private const CUSTOMERS = [
        ['Lucía', 'Ramírez Soto', '987654321'],
        ['Carlos', 'Mendoza Ríos', '976543210'],
        ['Ana', 'Torres Vega', '965432109'],
        ['Jorge', 'Quispe Huamán', '954321098'],
        ['María', 'Flores Castillo', '943210987'],
        ['Rosa', 'Chávez Paredes', '932109876'],
    ];

    private const PETS = [
        ['Luna', 'Perro', 'Mestiza', 'Hembra'],
        ['Rocky', 'Perro', 'Labrador', 'Macho'],
        ['Michi', 'Gato', 'Doméstico', 'Macho'],
        ['Nala', 'Perro', 'Shih tzu', 'Hembra'],
        ['Toby', 'Perro', 'Schnauzer', 'Macho'],
        ['Kira', 'Gato', 'Siamés', 'Hembra'],
    ];

    private const CHILDREN = [
        ['Mateo', 'Hijo', 'Masculino', 6],
        ['Valentina', 'Hija', 'Femenino', 4],
        ['Thiago', 'Hijo', 'Masculino', 8],
        ['Camila', 'Hija', 'Femenino', 5],
        ['Santiago', 'Hijo', 'Masculino', 7],
        ['Luciana', 'Hija', 'Femenino', 3],
    ];

    private const ORDER = ['appointments', 'customer_dependents', 'professional_schedules', 'customers', 'professionals'];

    public static function ready(): bool
    {
        return Schema::hasTable('sample_records');
    }

    public static function status(Tenant $tenant): array
    {
        $counts = self::ready()
            ? DB::table('sample_records')->where('tenant_id', $tenant->id)->selectRaw('table_name, count(*) as total')->groupBy('table_name')->pluck('total', 'table_name')->all()
            : [];

        return [
            'ready'         => self::ready(),
            'loaded'        => array_sum($counts) > 0,
            'customers'     => (int) ($counts['customers'] ?? 0),
            'professionals' => (int) ($counts['professionals'] ?? 0),
            'appointments'  => (int) ($counts['appointments'] ?? 0),
            'dependents'    => (int) ($counts['customer_dependents'] ?? 0),
        ];
    }

    public static function create(Tenant $tenant, User $user): array
    {
        $branch = Branch::where('tenant_id', $tenant->id)->where('status', 'active')->orderBy('created_at')->first();
        $services = Service::where('tenant_id', $tenant->id)->where('kind', '!=', Service::KIND_GROUP)->with('status')->orderBy('created_at')->get()
            ->filter(fn (Service $s) => $s->status?->slug !== 'inactive' && $s->offeredAt($branch?->id))->values();

        if (!$branch) {
            throw new \RuntimeException('Primero crea una sede para poder cargar datos de ejemplo.');
        }

        if ($services->isEmpty()) {
            throw new \RuntimeException('Primero crea al menos un servicio: los datos de ejemplo usan tus servicios.');
        }

        return DB::transaction(function () use ($tenant, $user, $branch, $services) {
            $position = 0;
            $mark = function (string $table, string $id) use ($tenant, &$position) {
                DB::table('sample_records')->insert(['tenant_id' => $tenant->id, 'table_name' => $table, 'record_id' => $id, 'position' => $position++]);
            };

            $professionals = Professional::where('tenant_id', $tenant->id)->with('status')->get()->filter(fn ($p) => $p->status?->slug !== 'inactive')->values();
            $limit = $tenant->planLimits()['professionals'] ?? null;
            $room = is_numeric($limit) ? max(0, (int) $limit - ($tenant->usage()['professionals'] ?? 0)) : 2;
            $vocab = $tenant->vocabulary();
            $proWord = $vocab['pro'] ?? 'Profesional';
            $samplePros = collect();

            foreach (array_slice(['A', 'B'], 0, min(2, $room)) as $letter) {
                $id = (string) Str::uuid();
                DB::table('professionals')->insert([
                    'id' => $id, 'tenant_id' => $tenant->id, 'display_name' => "{$proWord} de ejemplo {$letter}",
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $mark('professionals', $id);

                foreach (range(1, 6) as $weekday) {
                    $scheduleId = (string) Str::uuid();
                    DB::table('professional_schedules')->insert([
                        'id' => $scheduleId, 'tenant_id' => $tenant->id, 'professional_id' => $id, 'branch_id' => $branch->id,
                        'weekday' => $weekday, 'starts_local' => '09:00', 'ends_local' => '18:00',
                    ]);
                    $mark('professional_schedules', $scheduleId);
                }

                $samplePros->push(Professional::with('status')->find($id));
            }

            if ($samplePros->isNotEmpty()) {
                $professionals = $samplePros;
            }

            $customers = [];
            foreach (self::CUSTOMERS as [$first, $last, $phone]) {
                $customer = Customer::create([
                    'tenant_id'  => $tenant->id,
                    'first_name' => $first,
                    'last_name'  => $last,
                    'phone'      => $phone,
                    'email'      => Str::lower(Str::ascii($first)) . '.' . Str::lower(Str::random(5)) . '@ejemplo.test',
                ]);
                $mark('customers', $customer->id);
                $customers[] = $customer;
            }

            $dependents = [];
            $dependentRule = $tenant->dependents();

            if (!empty($dependentRule['enabled']) && Schema::hasTable('customer_dependents')) {
                $pets = ($dependentRule['kind'] ?? null) === 'pet';
                $extra = fn (string $column) => Schema::hasColumn('customer_dependents', $column);

                foreach ($customers as $index => $customer) {
                    $id = (string) Str::uuid();
                    $row = $pets ? self::PETS[$index % count(self::PETS)] : self::CHILDREN[$index % count(self::CHILDREN)];
                    DB::table('customer_dependents')->insert(array_filter([
                        'id'             => $id,
                        'tenant_id'      => $tenant->id,
                        'customer_id'    => $customer->id,
                        'name'           => $row[0],
                        'kind'           => $dependentRule['kind'] ?? null,
                        'species'        => $pets ? $row[1] : null,
                        'breed'          => $pets ? $row[2] : null,
                        'biological_sex' => $pets ? $row[3] : $row[2],
                        'birth_date'     => $pets ? now()->subYears(2 + $index % 5)->format('Y-m-d') : now()->subYears($row[3])->format('Y-m-d'),
                        'relationship'   => !$pets && $extra('relationship') ? $row[1] : null,
                        'notes'          => 'Dato de ejemplo: puedes borrarlo cuando quieras.',
                        'status'         => 'active',
                        'created_at'     => now(),
                        'updated_at'     => now(),
                    ], fn ($value) => $value !== null));
                    $mark('customer_dependents', $id);
                    $dependents[$customer->id] = $id;
                }
            }

            $created = 0;
            $scheduler = new AppointmentScheduler($tenant);
            $timezone = AppointmentScheduler::timezone($branch, $tenant);

            if ($professionals->isNotEmpty()) {
                foreach (range(1, 21) as $offset) {
                    if ($created >= count($customers)) {
                        break;
                    }
                    $service = $services[$offset % $services->count()];
                    $professional = $professionals[$offset % $professionals->count()];
                    $customer = $customers[$created];
                    $date = Carbon::now($timezone)->startOfDay()->addDays($offset);
                    $slots = $scheduler->slots($service, $branch, $date, $professional)[0]['slots'] ?? [];

                    if ($slots === []) {
                        continue;
                    }

                    $start = Carbon::createFromFormat('Y-m-d H:i', $date->format('Y-m-d') . ' ' . $slots[min(count($slots) - 1, $offset)], $timezone);

                    try {
                        $appointment = DB::transaction(fn () => Appointment::create([
                            'tenant_id'       => $tenant->id,
                            'code'            => Appointment::nextCode($tenant->id),
                            'branch_id'       => $branch->id,
                            'customer_id'     => $customer->id,
                            'customer_dependent_id' => $dependents[$customer->id] ?? null,
                            'service_id'      => $service->id,
                            'professional_id' => $professional->id,
                            'starts_at'       => $start,
                            'ends_at'         => $start->copy()->addMinutes((int) $service->duration_minutes),
                            'status'          => AppointmentFlow::CONFIRMED,
                            'source'          => 'staff',
                            'price'           => $service->priceAt($branch->id),
                            'notes'           => 'Cita de ejemplo: puedes borrarla cuando quieras.',
                            'confirmed_at'    => now(),
                            'created_by'      => $user->id,
                        ] + Currency::column($tenant)));
                    } catch (\Illuminate\Database\QueryException) {
                        continue;
                    }

                    $mark('appointments', $appointment->id);
                    $created++;
                }
            }

            return ['customers' => count($customers), 'dependents' => count($dependents), 'professionals' => min(2, $room), 'appointments' => $created];
        });
    }

    public static function remove(Tenant $tenant): array
    {
        $removed = array_fill_keys(self::ORDER, 0);
        $kept = 0;

        foreach (self::ORDER as $table) {
            $ids = DB::table('sample_records')->where('tenant_id', $tenant->id)->where('table_name', $table)->pluck('record_id');

            foreach ($ids as $id) {
                try {
                    DB::transaction(function () use ($table, $id, $tenant) {
                        if ($table === 'appointments' && Schema::hasTable('appointment_status_history')) {
                            DB::table('appointment_status_history')->where('appointment_id', $id)->delete();
                        }
                        DB::table($table)->where('tenant_id', $tenant->id)->where('id', $id)->delete();
                        DB::table('sample_records')->where('tenant_id', $tenant->id)->where('table_name', $table)->where('record_id', $id)->delete();
                    });
                    $removed[$table]++;
                } catch (\Illuminate\Database\QueryException) {
                    $kept++;
                }
            }
        }

        return $removed + ['kept' => $kept];
    }
}
