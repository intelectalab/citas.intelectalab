<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterCustomerController;
use App\Http\Controllers\Auth\RegisterTenantController;
use App\Http\Controllers\Auth\SubscriptionController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Api\TypeDocumentController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\AccessController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\ServiceCategoryController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\SaaSPlanController;
use App\Http\Controllers\Api\TenantContactController;
use App\Http\Controllers\Api\ProfessionalController;
use App\Http\Controllers\Api\ProfessionalScheduleController;
use App\Http\Controllers\Api\AvailabilityBlockController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\CashController;
use App\Http\Controllers\Api\CouponController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\SalesController;
use App\Http\Controllers\Api\HolidayController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\RecordTemplateController;
use App\Http\Controllers\Api\CareRecordController;
use App\Http\Controllers\Api\PortalController;
use App\Http\Controllers\Api\RecallController;
use App\Http\Controllers\Api\WaitlistController;
use App\Http\Controllers\Api\StatusController;
use App\Http\Controllers\Api\SpecialtyController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CustomerDependentController;
use App\Http\Controllers\Api\TenantSettingController;
use App\Http\Controllers\Api\TenantSubscriptionController;
use App\Http\Controllers\Api\ImpersonationController;
use App\Http\Controllers\Api\BusinessTypeController;
use App\Http\Controllers\Api\PlatformSettingController;
use App\Http\Controllers\Api\PipelineController;
use App\Http\Controllers\Api\PipelineStageController;

use App\Models\BusinessType;
use App\Models\SaaSPlan;
use App\Models\SaasModule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// El login prueba la contraseña contra varias cuentas y el registro de clientes
// acepta un código de 8 dígitos: ambos llevan límite de intentos.
Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:10,1');
Route::post('/register-customer', [RegisterCustomerController::class, 'register'])->middleware('throttle:10,1');
Route::post('/register-tenant', [RegisterTenantController::class, 'register']);

// Marca y apariencia de la plataforma: lo leen todas las pantallas, también el login.
Route::get('/platform', [PlatformSettingController::class, 'publicValues']);

Route::get('/modules', function () {
    return SaasModule::orderBy('sort_order')->get(['key', 'name', 'is_core']);
});

Route::get('/business-types', function () {
    return BusinessType::where('status', 'active')->orderBy('sort_order')->get();
});

// Solo el catálogo público. Los planes privados (a medida) los asigna la plataforma.
Route::get('/plans', function () {
    return SaaSPlan::where('status', 'active')->where('is_public_catalog', true)->orderBy('presentation_order')->get();
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [LoginController::class, 'logout']);
    Route::post('/impersonation/stop', [ImpersonationController::class, 'stop']);
});

// Consola de la plataforma. El personal de las empresas no entra aquí.
Route::middleware(['auth:sanctum', 'saas'])->group(function () {
    // Administrador SaaS y soporte SaaS: ver empresas y entrar a su panel.
    Route::get('/tenants', [TenantController::class, 'index']);
    Route::get('/tenants/{tenant}', [TenantController::class, 'show']);
    Route::post('/tenants/{tenant}/impersonate', [ImpersonationController::class, 'start']);

    // Solo administrador SaaS: todo lo que cambia a una empresa o a los catálogos.
    Route::middleware('saas:admin')->group(function () {
        Route::post('/tenants', [TenantController::class, 'store']);
        Route::put('/tenants/{tenant}', [TenantController::class, 'update']);
        Route::delete('/tenants/{tenant}', [TenantController::class, 'destroy']);
        Route::post('/tenants/{tenant}/suspend', [TenantController::class, 'suspend']);
        Route::post('/tenants/{tenant}/reactivate', [TenantController::class, 'reactivate']);
        Route::put('/tenants/{tenant}/plan', [TenantController::class, 'changePlan']);
        Route::put('/tenants/{tenant}/business-type', [TenantController::class, 'changeBusinessType']);
        Route::put('/tenants/{tenant}/modules', [TenantController::class, 'updateModules']);

        Route::post('/tenants/{tenant}/subscription/payments', [TenantSubscriptionController::class, 'registerPayment']);
        Route::put('/tenants/{tenant}/subscription/extend', [TenantSubscriptionController::class, 'extend']);
        Route::post('/tenants/{tenant}/subscription/cancel', [TenantSubscriptionController::class, 'cancel']);

        Route::apiResource('saas-plans', SaaSPlanController::class);
        Route::get('/admin/platform-settings', [PlatformSettingController::class, 'show']);
        Route::put('/admin/platform-settings', [PlatformSettingController::class, 'update']);
        Route::apiResource('admin/business-types', BusinessTypeController::class);
        Route::get('/admin/business-types/{business_type}/tenants', [BusinessTypeController::class, 'tenants']);
        Route::post('/admin/business-types/{business_type}/apply', [BusinessTypeController::class, 'apply']);
        Route::apiResource('tenant-contacts', TenantContactController::class);
    });
});

// Portal del cliente: quien inicia sesión es el propio cliente (su ficha es su
// cuenta), y cada uno ve únicamente lo suyo.
Route::middleware(['auth:sanctum', 'customer'])->prefix('portal')->group(function () {
    Route::get('/overview', [PortalController::class, 'overview']);
    Route::put('/profile', [PortalController::class, 'updateProfile']);
    Route::put('/password', [PortalController::class, 'updatePassword']);
    Route::post('/dependents', [PortalController::class, 'storeDependent']);

    // Reservar, cancelar y reprogramar en línea depende del plan de la empresa.
    Route::middleware('module:booking')->group(function () {
        Route::get('/catalog', [PortalController::class, 'catalog']);
        Route::get('/availability', [PortalController::class, 'portalAvailability']);
        Route::post('/appointments', [PortalController::class, 'book']);
        Route::post('/appointments/{appointment}/confirm', [PortalController::class, 'confirm']);
        Route::post('/appointments/{appointment}/cancel', [PortalController::class, 'cancel']);
        Route::post('/appointments/{appointment}/reschedule', [PortalController::class, 'move']);

        // Lista de espera: el cliente se anota cuando ningún horario le sirve.
        Route::get('/waitlist', [PortalController::class, 'waitlist']);
        Route::post('/waitlist', [PortalController::class, 'joinWaitlist']);
        Route::delete('/waitlist/{entry}', [PortalController::class, 'leaveWaitlist']);
    });
});

// Backoffice: solo personal del tenant y admin SaaS. Las cuentas de cliente no entran.
Route::middleware(['auth:sanctum', 'staff'])->group(function () {
    Route::post('/subscriptions/subscribe', [SubscriptionController::class, 'subscribe']);

    Route::get('/type-documents', [TypeDocumentController::class, 'index']);
    Route::get('/users', [UserController::class, 'index']);

    // La campana: avisos de quien tiene la sesión abierta.
    Route::get('/notifications', [\App\Http\Controllers\Api\NotificationController::class, 'index']);
    Route::post('/notifications/read-all', [\App\Http\Controllers\Api\NotificationController::class, 'readAll']);
    Route::post('/notifications/{notification}/read', [\App\Http\Controllers\Api\NotificationController::class, 'read']);

    Route::apiResource('statuses', StatusController::class);

    // Además del módulo, cada ruta exige el permiso de quien la usa:
    // ->middleware('perm:<pantalla>.<view|create|edit|delete>'). Los permisos y lo
    // que deja hacer cada uno están en App\Support\Access::CATALOG.
    // $guarded registra un recurso con su permiso por acción. Las listas que otros
    // formularios necesitan para elegir (servicios, profesionales, sedes...) quedan
    // abiertas a todo el personal: ahí "ver" solo decide si la pantalla sale en el menú.
    $guarded = function (string $name, string $controller, string $key, bool $guardView = false, array $except = []) {
        foreach (['view' => ['index', 'show'], 'create' => ['store'], 'edit' => ['update'], 'delete' => ['destroy']] as $action => $methods) {
            $methods = array_values(array_diff($methods, $except));

            if (!$methods) {
                continue;
            }

            $routes = Route::apiResource($name, $controller)->only($methods);

            if ($action !== 'view' || $guardView) {
                $routes->middleware("perm:{$key}.{$action}");
            }
        }
    };

    // Sucursales: las ve todo el personal; las cambia quien tenga el permiso.
    Route::get('/branches', [BranchController::class, 'index']);
    Route::post('/branches', [BranchController::class, 'store'])->middleware('perm:branches.create');
    Route::get('/branches/{branch}', [BranchController::class, 'show']);
    Route::put('/branches/{branch}', [BranchController::class, 'update'])->middleware('perm:branches.edit');
    Route::post('/branches/{branch}/status', [BranchController::class, 'changeStatus'])->middleware('perm:branches.edit');
    Route::put('/branches/{branch}/hours', [BranchController::class, 'updateHours'])->middleware('perm:branches.edit');

    Route::middleware('module:resources')->group(function () {
        Route::get('/rooms', [RoomController::class, 'index']);
        Route::post('/branches/{branch}/rooms', [RoomController::class, 'store'])->middleware('perm:rooms.create');
        Route::put('/rooms/{room}', [RoomController::class, 'update'])->middleware('perm:rooms.edit');
        Route::delete('/rooms/{room}', [RoomController::class, 'destroy'])->middleware('perm:rooms.delete');
    });

    // Cada grupo exige que el tenant tenga activo el módulo (plan, rubro y
    // configuración propia). Las rutas de módulos nuevos (inventory, quotes,
    // memberships...) deben declararse igual: ->middleware('module:<clave>').
    Route::middleware('module:agenda')->group(function () use ($guarded) {
        $guarded('specialties', SpecialtyController::class, 'professionals');
        $guarded('services', ServiceController::class, 'services');
        $guarded('service-categories', ServiceCategoryController::class, 'services');
        $guarded('professionals', ProfessionalController::class, 'professionals');
        Route::get('/schedules', [ProfessionalScheduleController::class, 'index']);
        Route::get('/professionals/{professional}/schedule', [ProfessionalScheduleController::class, 'show']);
        Route::put('/professionals/{professional}/schedule', [ProfessionalScheduleController::class, 'update'])->middleware('perm:professionals.edit');
        Route::get('/availability-blocks/options', [AvailabilityBlockController::class, 'options']);
        Route::get('/availability-blocks/affected', [AvailabilityBlockController::class, 'affected']);
        $guarded('availability-blocks', AvailabilityBlockController::class, 'blocks');

        // Feriados: los ve todo el personal; los cambia quien tenga el permiso.
        Route::post('/holidays/national', [HolidayController::class, 'loadNational'])->middleware('perm:holidays.create');
        $guarded('holidays', HolidayController::class, 'holidays');

        Route::middleware('perm:agenda.view')->group(function () {
            Route::get('/appointments/availability', [AppointmentController::class, 'availability']);
            Route::get('/appointments/context', [AppointmentController::class, 'context']);
            Route::get('/appointments/day', [AppointmentController::class, 'day']);
        });
        Route::get('/appointments/metrics', [AppointmentController::class, 'metrics'])->middleware('perm:attention.view');
        Route::get('/dashboard', [\App\Http\Controllers\Api\DashboardController::class, 'show']);
        $guarded('appointments', AppointmentController::class, 'agenda', true, ['destroy']);
        Route::post('/appointments/{appointment}/status', [AppointmentController::class, 'changeStatus'])->middleware('perm:agenda.edit');
        Route::post('/appointments/{appointment}/reschedule', [AppointmentController::class, 'reschedule'])->middleware('perm:agenda.edit');

        // Lista de espera: quién quiere una cita y no encontró horario.
        Route::get('/waitlist', [WaitlistController::class, 'index'])->middleware('perm:waitlist.view');
        Route::post('/waitlist', [WaitlistController::class, 'store'])->middleware('perm:waitlist.create');
        Route::put('/waitlist/{entry}', [WaitlistController::class, 'update'])->middleware('perm:waitlist.edit');
        Route::post('/waitlist/{entry}/status', [WaitlistController::class, 'changeStatus'])->middleware('perm:waitlist.edit');
        Route::get('/waitlist/{entry}/slots', [WaitlistController::class, 'slots'])->middleware('perm:waitlist.view');

        // Controles y seguimiento: a quién hay que volver a llamar y para qué. Va con la
        // agenda (no con las fichas): también nacen del servicio atendido o a mano.
        Route::get('/recalls', [RecallController::class, 'index'])->middleware('perm:recalls.view');
        Route::post('/recalls', [RecallController::class, 'store'])->middleware('perm:recalls.create');
        Route::put('/recalls/{recall}', [RecallController::class, 'update'])->middleware('perm:recalls.edit');
        Route::post('/recalls/{recall}/status', [RecallController::class, 'changeStatus'])->middleware('perm:recalls.edit');
    });

    // Fichas de atención: plantillas de la empresa y fichas llenadas en cada cita.
    Route::middleware('module:records')->group(function () use ($guarded) {
        $guarded('record-templates', RecordTemplateController::class, 'record_templates', false, ['show']);
        Route::get('/appointments/{appointment}/record', [CareRecordController::class, 'forAppointment'])->middleware('perm:records.view');
        Route::put('/appointments/{appointment}/record', [CareRecordController::class, 'save'])->middleware('perm:records.edit');
        Route::post('/appointments/{appointment}/record/refresh-template', [CareRecordController::class, 'refreshTemplate'])->middleware('perm:records.edit');
        Route::get('/care-records', [CareRecordController::class, 'index'])->middleware('perm:records.view');
        Route::get('/care-records/{careRecord}', [CareRecordController::class, 'show'])->middleware('perm:records.view');
        Route::post('/care-records/{careRecord}/addenda', [CareRecordController::class, 'addAddendum'])->middleware('perm:records.edit');
        Route::post('/care-records/{careRecord}/emergency-access', [CareRecordController::class, 'emergencyAccess'])->middleware('perm:records.emergency');
        Route::get('/care-records/{careRecord}/pdf', [CareRecordController::class, 'pdf'])->middleware('perm:records.view');
    });

    // Ventas y caja: cobrar una cita y llevar el efectivo de cada turno.
    Route::middleware('module:pos')->group(function () use ($guarded) {
        Route::get('/cash', [CashController::class, 'index'])->middleware('perm:cash.view');
        Route::post('/cash/registers', [CashController::class, 'storeRegister'])->middleware('perm:cash.create');
        Route::put('/cash/registers/{register}', [CashController::class, 'updateRegister'])->middleware('perm:cash.create');
        Route::post('/cash/registers/{register}/open', [CashController::class, 'open'])->middleware('perm:cash.edit');
        Route::get('/cash/sessions/{session}', [CashController::class, 'show'])->middleware('perm:cash.view');
        Route::post('/cash/sessions/{session}/movements', [CashController::class, 'move'])->middleware('perm:cash.edit');
        Route::post('/cash/sessions/{session}/close', [CashController::class, 'close'])->middleware('perm:cash.edit');

        Route::get('/appointments/{appointment}/sale', [SaleController::class, 'forAppointment'])->middleware('perm:sales.create|sales.view');
        Route::post('/appointments/{appointment}/charge', [SaleController::class, 'charge'])->middleware('perm:sales.create');
        Route::post('/appointments/{appointment}/invoice', [SaleController::class, 'issueInvoice'])->middleware('perm:sales.create');

        // Ventas y pagos de la empresa: consultar, anular lo que no tiene pagos y devolver lo cobrado.
        Route::get('/sales', [SalesController::class, 'index'])->middleware('perm:sales.view');
        Route::get('/sales/{sale}', [SalesController::class, 'show'])->middleware('perm:sales.view');
        Route::post('/sales/{sale}/void', [SalesController::class, 'void'])->middleware('perm:sales.delete');
        Route::post('/sales/{sale}/refunds', [SalesController::class, 'refund'])->middleware('perm:sales.delete');
        Route::get('/payments', [SalesController::class, 'payments'])->middleware('perm:sales.view');

        // Cupones de descuento que se aplican al cobrar.
        $guarded('coupons', CouponController::class, 'coupons', true, ['show']);
    });

    Route::middleware('module:crm')->group(function () use ($guarded) {
        Route::get('/pipeline-stages', [PipelineStageController::class, 'index']);
        $guarded('customers', CustomerController::class, 'customers', true);
        Route::put('/customers/{customer}/portal-access', [CustomerController::class, 'portalAccess'])->middleware('perm:customers.edit');
        Route::post('/customers/{customer}/portal-access/reset', [CustomerController::class, 'resetPortalPassword'])->middleware('perm:customers.edit');
        Route::post('/customers/{customer}/booking-strikes/forgive', [CustomerController::class, 'forgiveBooking'])->middleware('perm:customers.forgive');
        // Agregar una mascota o un hijo también se hace al registrar al cliente.
        Route::apiResource('customers.dependents', CustomerDependentController::class)->only(['index'])->middleware('perm:customers.view');
        Route::apiResource('customers.dependents', CustomerDependentController::class)->only(['store', 'update', 'destroy'])->middleware('perm:customers.edit|customers.create');
        Route::apiResource('pipelines', PipelineController::class);

        Route::prefix('contacts')->group(function () {
            Route::get('/', [ContactController::class, 'index']);
            Route::post('/', [ContactController::class, 'store']);
            Route::put('/{id}', [ContactController::class, 'update']);
            Route::delete('/{id}', [ContactController::class, 'destroy']);
        });
    });

    // Configuración de la empresa: solo su administrador (el controlador lo valida).
    // Datos generales de la empresa: los ve su personal; los cambia su administrador.
    Route::get('/company', [\App\Http\Controllers\Api\CompanyController::class, 'show']);
    Route::put('/company', [\App\Http\Controllers\Api\CompanyController::class, 'update'])->middleware('perm:company.edit');

    Route::get('/tenant-settings', [TenantSettingController::class, 'show'])->middleware('perm:settings.view');
    Route::put('/tenant-settings', [TenantSettingController::class, 'update'])->middleware('perm:settings.edit');

    // Usuarios y roles: quién entra al sistema, con qué rol y qué puede hacer cada quien.
    Route::prefix('access')->group(function () {
        Route::middleware('perm:users.view')->group(function () {
            Route::get('/users', [AccessController::class, 'users']);
            Route::get('/users/{user}', [AccessController::class, 'showUser']);
            Route::get('/roles', [AccessController::class, 'roles']);
            Route::get('/roles/{role}', [AccessController::class, 'showRole']);
        });

        Route::middleware('perm:users.create')->group(function () {
            Route::post('/users', [AccessController::class, 'storeUser']);
            Route::post('/roles', [AccessController::class, 'storeRole']);
        });

        Route::middleware('perm:users.edit')->group(function () {
            Route::put('/users/{user}', [AccessController::class, 'updateUser']);
            Route::post('/users/{user}/password', [AccessController::class, 'resetPassword']);
            Route::put('/users/{user}/permissions', [AccessController::class, 'setUserPermission']);
            Route::delete('/users/{user}/permissions', [AccessController::class, 'resetUserPermissions']);
            Route::put('/roles/{role}', [AccessController::class, 'updateRole']);
            Route::put('/roles/{role}/permissions', [AccessController::class, 'setRolePermission']);
            Route::post('/roles/{role}/restore', [AccessController::class, 'restoreRole']);
            Route::put('/roles/{role}/scope', [AccessController::class, 'setRoleScope']);
        });

        Route::delete('/roles/{role}', [AccessController::class, 'destroyRole'])->middleware('perm:users.delete');
    });



});