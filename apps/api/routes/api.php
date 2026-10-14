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
use App\Http\Controllers\Api\PosController;
use App\Http\Controllers\Api\ProductController;
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
use App\Http\Controllers\Api\CurrencyController;

Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:10,1');
Route::post('/register-customer', [RegisterCustomerController::class, 'register'])->middleware('throttle:10,1');
Route::post('/register-tenant', [RegisterTenantController::class, 'register']);

Route::get('/platform', [PlatformSettingController::class, 'publicValues']);

Route::get('/modules', function () {
    return SaasModule::orderBy('sort_order')->get(['key', 'name', 'is_core']);
});

Route::get('/business-types', function () {
    return BusinessType::where('status', 'active')->orderBy('sort_order')->get();
});

Route::get('/plans', function () {
    return SaaSPlan::where('status', 'active')->where('is_public_catalog', true)->orderBy('presentation_order')->get();
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [LoginController::class, 'logout']);
    Route::post('/impersonation/stop', [ImpersonationController::class, 'stop']);
});

Route::middleware(['auth:sanctum', 'saas'])->group(function () {
    Route::get('/tenants', [TenantController::class, 'index']);
    Route::get('/tenants/{tenant}', [TenantController::class, 'show']);
    Route::post('/tenants/{tenant}/impersonate', [ImpersonationController::class, 'start']);

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
        Route::get('/admin/currencies', [CurrencyController::class, 'platform']);
        Route::put('/admin/currencies/{code}', [CurrencyController::class, 'toggle']);
        Route::put('/admin/platform-settings', [PlatformSettingController::class, 'update']);
        Route::apiResource('admin/business-types', BusinessTypeController::class);
        Route::get('/admin/business-types/{business_type}/tenants', [BusinessTypeController::class, 'tenants']);
        Route::post('/admin/business-types/{business_type}/apply', [BusinessTypeController::class, 'apply']);
        Route::apiResource('tenant-contacts', TenantContactController::class);
    });
});

Route::middleware(['auth:sanctum', 'customer'])->prefix('portal')->group(function () {
    Route::get('/overview', [PortalController::class, 'overview']);
    Route::put('/profile', [PortalController::class, 'updateProfile']);
    Route::put('/password', [PortalController::class, 'updatePassword']);
    Route::post('/dependents', [PortalController::class, 'storeDependent']);

    Route::middleware('module:booking')->group(function () {
        Route::get('/catalog', [PortalController::class, 'catalog']);
        Route::get('/availability', [PortalController::class, 'portalAvailability']);
        Route::post('/appointments', [PortalController::class, 'book']);
        Route::post('/appointments/{appointment}/confirm', [PortalController::class, 'confirm']);
        Route::post('/appointments/{appointment}/cancel', [PortalController::class, 'cancel']);
        Route::post('/appointments/{appointment}/reschedule', [PortalController::class, 'move']);

        Route::get('/waitlist', [PortalController::class, 'waitlist']);
        Route::post('/waitlist', [PortalController::class, 'joinWaitlist']);
        Route::delete('/waitlist/{entry}', [PortalController::class, 'leaveWaitlist']);
    });
});

Route::middleware(['auth:sanctum', 'staff'])->group(function () {
    Route::post('/subscriptions/subscribe', [SubscriptionController::class, 'subscribe']);

    Route::get('/type-documents', [TypeDocumentController::class, 'index']);
    Route::get('/users', [UserController::class, 'index']);

    Route::get('/notifications', [\App\Http\Controllers\Api\NotificationController::class, 'index']);
    Route::post('/notifications/read-all', [\App\Http\Controllers\Api\NotificationController::class, 'readAll']);
    Route::post('/notifications/{notification}/read', [\App\Http\Controllers\Api\NotificationController::class, 'read']);

    Route::apiResource('statuses', StatusController::class);

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

        Route::get('/waitlist', [WaitlistController::class, 'index'])->middleware('perm:waitlist.view');
        Route::post('/waitlist', [WaitlistController::class, 'store'])->middleware('perm:waitlist.create');
        Route::put('/waitlist/{entry}', [WaitlistController::class, 'update'])->middleware('perm:waitlist.edit');
        Route::post('/waitlist/{entry}/status', [WaitlistController::class, 'changeStatus'])->middleware('perm:waitlist.edit');
        Route::get('/waitlist/{entry}/slots', [WaitlistController::class, 'slots'])->middleware('perm:waitlist.view');

        Route::get('/recalls', [RecallController::class, 'index'])->middleware('perm:recalls.view');
        Route::post('/recalls', [RecallController::class, 'store'])->middleware('perm:recalls.create');
        Route::put('/recalls/{recall}', [RecallController::class, 'update'])->middleware('perm:recalls.edit');
        Route::post('/recalls/{recall}/status', [RecallController::class, 'changeStatus'])->middleware('perm:recalls.edit');
    });

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

        Route::get('/sales', [SalesController::class, 'index'])->middleware('perm:sales.view');
        Route::get('/sales/{sale}', [SalesController::class, 'show'])->middleware('perm:sales.view');
        Route::post('/sales/{sale}/void', [SalesController::class, 'void'])->middleware('perm:sales.delete');
        Route::post('/sales/{sale}/refunds', [SalesController::class, 'refund'])->middleware('perm:sales.delete');
        Route::get('/payments', [SalesController::class, 'payments'])->middleware('perm:sales.view');

        $guarded('coupons', CouponController::class, 'coupons', true, ['show']);
        Route::get('/pos', [PosController::class, 'board'])->middleware('perm:pos.view');
        Route::post('/pos/preview', [PosController::class, 'preview'])->middleware('perm:pos.view');
        Route::post('/pos/sales', [PosController::class, 'createSale'])->middleware('perm:pos.create');
        Route::get('/pos/sales/{sale}', [PosController::class, 'showSale'])->middleware('perm:pos.view');
        Route::put('/pos/sales/{sale}', [PosController::class, 'updateSale'])->middleware('perm:pos.edit');
        Route::get('/pos/walk-in', [PosController::class, 'walkInOptions'])->middleware('perm:agenda.create');
        Route::post('/pos/walk-in', [PosController::class, 'walkIn'])->middleware(['perm:agenda.create', 'module:agenda']);
        Route::get('/sales/{sale}/charge', [PosController::class, 'chargeShow'])->middleware('perm:sales.create|sales.view');
        Route::post('/sales/{sale}/pay', [PosController::class, 'pay'])->middleware('perm:sales.create');
        Route::post('/sales/{sale}/invoice', [PosController::class, 'invoice'])->middleware('perm:sales.create');

        $guarded('products', ProductController::class, 'products', true, ['show']);
        Route::post('/product-categories', [ProductController::class, 'storeCategory'])->middleware('perm:products.create');
    });

    Route::middleware('module:crm')->group(function () use ($guarded) {
        Route::get('/pipeline-stages', [PipelineStageController::class, 'index']);
        $guarded('customers', CustomerController::class, 'customers', true);
        Route::put('/customers/{customer}/portal-access', [CustomerController::class, 'portalAccess'])->middleware('perm:customers.edit');
        Route::post('/customers/{customer}/portal-access/reset', [CustomerController::class, 'resetPortalPassword'])->middleware('perm:customers.edit');
        Route::post('/customers/{customer}/booking-strikes/forgive', [CustomerController::class, 'forgiveBooking'])->middleware('perm:customers.forgive');
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

    Route::get('/company', [\App\Http\Controllers\Api\CompanyController::class, 'show']);
    Route::put('/company', [\App\Http\Controllers\Api\CompanyController::class, 'update'])->middleware('perm:company.edit');

    Route::get('/tenant-settings', [TenantSettingController::class, 'show'])->middleware('perm:settings.view');

    Route::get('/currency', [CurrencyController::class, 'show'])->middleware('perm:settings.view');
    Route::middleware('perm:settings.edit')->group(function () {
        Route::put('/currency', [CurrencyController::class, 'update']);
        Route::post('/currency/records/preview', [CurrencyController::class, 'preview']);
        Route::post('/currency/records', [CurrencyController::class, 'apply']);
    });
    Route::put('/tenant-settings', [TenantSettingController::class, 'update'])->middleware('perm:settings.edit');

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