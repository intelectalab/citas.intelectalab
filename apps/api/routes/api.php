<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterCustomerController;
use App\Http\Controllers\Auth\RegisterTenantController;
use App\Http\Controllers\Auth\SubscriptionController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Api\TypeDocumentController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\Api\UserController;
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

    // Sucursales: las ve todo el personal; las cambia el administrador de la empresa.
    Route::get('/branches', [BranchController::class, 'index']);
    Route::post('/branches', [BranchController::class, 'store']);
    Route::get('/branches/{branch}', [BranchController::class, 'show']);
    Route::put('/branches/{branch}', [BranchController::class, 'update']);
    Route::post('/branches/{branch}/status', [BranchController::class, 'changeStatus']);
    Route::put('/branches/{branch}/hours', [BranchController::class, 'updateHours']);

    Route::middleware('module:resources')->group(function () {
        Route::post('/branches/{branch}/rooms', [RoomController::class, 'store']);
        Route::put('/rooms/{room}', [RoomController::class, 'update']);
        Route::delete('/rooms/{room}', [RoomController::class, 'destroy']);
    });

    // Cada grupo exige que el tenant tenga activo el módulo (plan, rubro y
    // configuración propia). Las rutas de módulos nuevos (inventory, quotes,
    // memberships...) deben declararse igual: ->middleware('module:<clave>').
    Route::middleware('module:agenda')->group(function () {
        Route::apiResource('specialties', SpecialtyController::class);
        Route::apiResource('services', ServiceController::class);
        Route::apiResource('service-categories', ServiceCategoryController::class);
        Route::apiResource('professionals', ProfessionalController::class);
        Route::get('/schedules', [ProfessionalScheduleController::class, 'index']);
        Route::get('/professionals/{professional}/schedule', [ProfessionalScheduleController::class, 'show']);
        Route::put('/professionals/{professional}/schedule', [ProfessionalScheduleController::class, 'update']);
        Route::get('/availability-blocks/options', [AvailabilityBlockController::class, 'options']);
        Route::get('/availability-blocks/affected', [AvailabilityBlockController::class, 'affected']);
        Route::apiResource('availability-blocks', AvailabilityBlockController::class)->except(['create', 'edit']);

        // Feriados: los ve todo el personal, los cambia el administrador de la empresa.
        Route::post('/holidays/national', [HolidayController::class, 'loadNational']);
        Route::apiResource('holidays', HolidayController::class)->except(['create', 'edit']);

        Route::get('/appointments/availability', [AppointmentController::class, 'availability']);
        Route::get('/appointments/context', [AppointmentController::class, 'context']);
        Route::get('/appointments/day', [AppointmentController::class, 'day']);
        Route::get('/appointments/metrics', [AppointmentController::class, 'metrics']);
        Route::get('/dashboard', [\App\Http\Controllers\Api\DashboardController::class, 'show']);
        Route::apiResource('appointments', AppointmentController::class)->except(['destroy']);
        Route::post('/appointments/{appointment}/status', [AppointmentController::class, 'changeStatus']);
        Route::post('/appointments/{appointment}/reschedule', [AppointmentController::class, 'reschedule']);

        // Lista de espera: quién quiere una cita y no encontró horario.
        Route::get('/waitlist', [WaitlistController::class, 'index']);
        Route::post('/waitlist', [WaitlistController::class, 'store']);
        Route::put('/waitlist/{entry}', [WaitlistController::class, 'update']);
        Route::post('/waitlist/{entry}/status', [WaitlistController::class, 'changeStatus']);
        Route::get('/waitlist/{entry}/slots', [WaitlistController::class, 'slots']);

        // Controles y seguimiento: a quién hay que volver a llamar y para qué. Va con la
        // agenda (no con las fichas): también nacen del servicio atendido o a mano.
        Route::get('/recalls', [RecallController::class, 'index']);
        Route::post('/recalls', [RecallController::class, 'store']);
        Route::put('/recalls/{recall}', [RecallController::class, 'update']);
        Route::post('/recalls/{recall}/status', [RecallController::class, 'changeStatus']);
    });

    // Fichas de atención: plantillas de la empresa y fichas llenadas en cada cita.
    Route::middleware('module:records')->group(function () {
        Route::apiResource('record-templates', RecordTemplateController::class)->except(['show']);
        Route::get('/appointments/{appointment}/record', [CareRecordController::class, 'forAppointment']);
        Route::put('/appointments/{appointment}/record', [CareRecordController::class, 'save']);
        Route::post('/appointments/{appointment}/record/refresh-template', [CareRecordController::class, 'refreshTemplate']);
        Route::get('/care-records', [CareRecordController::class, 'index']);
        Route::get('/care-records/{careRecord}', [CareRecordController::class, 'show']);
        Route::post('/care-records/{careRecord}/addenda', [CareRecordController::class, 'addAddendum']);
        Route::post('/care-records/{careRecord}/emergency-access', [CareRecordController::class, 'emergencyAccess']);
        Route::get('/care-records/{careRecord}/pdf', [CareRecordController::class, 'pdf']);
    });

    Route::middleware('module:crm')->group(function () {
        Route::get('/pipeline-stages', [PipelineStageController::class, 'index']);
        Route::apiResource('customers', CustomerController::class);
        Route::put('/customers/{customer}/portal-access', [CustomerController::class, 'portalAccess']);
        Route::post('/customers/{customer}/portal-access/reset', [CustomerController::class, 'resetPortalPassword']);
        Route::apiResource('customers.dependents', CustomerDependentController::class)->except(['show']);
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
    Route::put('/company', [\App\Http\Controllers\Api\CompanyController::class, 'update']);

    Route::get('/tenant-settings', [TenantSettingController::class, 'show']);
    Route::put('/tenant-settings', [TenantSettingController::class, 'update']);
    


});