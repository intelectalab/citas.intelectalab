<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterCustomerController;
use App\Http\Controllers\Auth\RegisterTenantController;
use App\Http\Controllers\Auth\SubscriptionController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\Api\TypeDocumentController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\PortalQuoteController;
use App\Http\Controllers\Api\PortalShopController;
use App\Http\Controllers\Api\AccessController;
use App\Http\Controllers\Api\ServiceController;
use App\Http\Controllers\Api\ServiceCategoryController;
use App\Http\Controllers\Api\TenantController;
use App\Http\Controllers\Api\SaaSPlanController;
use App\Http\Controllers\Api\TenantContactController;
use App\Http\Controllers\Api\ProfessionalController;
use App\Http\Controllers\Api\ProfessionalScheduleController;
use App\Http\Controllers\Api\ProfessionalSignatureController;
use App\Http\Controllers\Api\AvailabilityBlockController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\RoomController;
use App\Http\Controllers\Api\CashController;
use App\Http\Controllers\Api\CouponController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\SalesController;
use App\Http\Controllers\Api\PosController;
use App\Http\Controllers\Api\PackageController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\BrandingController;
use App\Http\Controllers\Api\DataExportController;
use App\Http\Controllers\Api\CampaignController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\LoyaltyController;
use App\Http\Controllers\Api\QuoteController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\HolidayController;
use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\RecordTemplateController;
use App\Http\Controllers\Api\CareRecordController;
use App\Http\Controllers\Api\PortalController;
use App\Http\Controllers\Api\RecallController;
use App\Http\Controllers\Api\WaitlistController;
use App\Http\Controllers\Api\WalkinController;
use App\Http\Controllers\Api\AutomationController;
use App\Http\Controllers\Api\ReportController;
use App\Http\Controllers\Api\StatusController;
use App\Http\Controllers\Api\SpecialtyController;
use App\Http\Controllers\Api\CustomerBoardController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\CustomerDependentController;
use App\Http\Controllers\Api\TenantSettingController;
use App\Http\Controllers\Api\TenantSubscriptionController;
use App\Http\Controllers\Api\ImpersonationController;
use App\Http\Controllers\Api\BusinessTypeController;
use App\Http\Controllers\Api\PlatformSettingController;
use App\Http\Controllers\Api\PlatformLookController;

use App\Models\BusinessType;
use App\Models\SaaSPlan;
use App\Models\SaasModule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\CurrencyController;

Route::post('/login', [LoginController::class, 'login'])->middleware('throttle:10,1');
Route::post('/login/verify', [LoginController::class, 'verify'])->middleware('throttle:10,1');
Route::post('/login/resend', [LoginController::class, 'resend'])->middleware('throttle:5,1');
Route::post('/password/forgot', [\App\Http\Controllers\Auth\PasswordController::class, 'forgot'])->middleware('throttle:5,1');
Route::get('/password/reset/{token}', [\App\Http\Controllers\Auth\PasswordController::class, 'show'])->middleware('throttle:20,1');
Route::post('/password/reset', [\App\Http\Controllers\Auth\PasswordController::class, 'reset'])->middleware('throttle:10,1');
Route::post('/register-customer', [RegisterCustomerController::class, 'register'])->middleware('throttle:10,1');
Route::get('/signup-info/{code}', [BrandingController::class, 'signupInfo'])->middleware('throttle:20,1');
Route::post('/register-tenant', [RegisterTenantController::class, 'register']);

Route::get('/platform', [PlatformSettingController::class, 'publicValues']);
Route::get('/platform/files/{name}', [PlatformLookController::class, 'file'])->where('name', 'logo|logo-dark|landing|login')->middleware('throttle:120,1');

Route::get('/pay/{token}', [PaymentController::class, 'show'])->middleware('throttle:60,1');
Route::get('/branding/{tenant}/{name}', [BrandingController::class, 'file'])->where('name', 'logo|logo-dark')->middleware('throttle:120,1');
Route::post('/pay/{token}/simulate', [PaymentController::class, 'simulate'])->middleware('throttle:20,1');
Route::get('/plan-payments/{id}', [\App\Http\Controllers\Api\BillingController::class, 'simulatedView'])->whereUuid('id')->middleware('throttle:30,1');
Route::post('/plan-payments/{id}/simulate', [\App\Http\Controllers\Api\BillingController::class, 'simulate'])->whereUuid('id')->middleware('throttle:20,1');
Route::post('/pay/{token}/start', [PaymentController::class, 'start'])->middleware('throttle:20,1');
Route::post('/pay/{token}/check', [PaymentController::class, 'check'])->middleware('throttle:30,1');
Route::post('/webhooks/payments/{provider}/{owner?}', [PaymentController::class, 'webhook'])->middleware('throttle:240,1');
Route::get('/promotions/unsubscribe/{recipient}', [CampaignController::class, 'unsubscribeShow'])->middleware('throttle:60,1');
Route::post('/promotions/unsubscribe/{recipient}', [CampaignController::class, 'unsubscribe'])->middleware('throttle:20,1');

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
        Route::get('/admin/audit', [\App\Http\Controllers\Api\AuditController::class, 'platform']);
        Route::post('/admin/data-exports', [DataExportController::class, 'platform'])->middleware('throttle:60,1');
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
        Route::get('/admin/platform-mail', [\App\Http\Controllers\Api\PlatformMailController::class, 'show']);
        Route::put('/admin/platform-mail', [\App\Http\Controllers\Api\PlatformMailController::class, 'update']);
        Route::delete('/admin/platform-mail', [\App\Http\Controllers\Api\PlatformMailController::class, 'destroy']);
        Route::post('/admin/platform-mail/test', [\App\Http\Controllers\Api\PlatformMailController::class, 'test'])->middleware('throttle:10,1');
        Route::post('/tenants/{tenant}/users/{user}/security', [TenantController::class, 'userSecurity']);
        Route::get('/admin/billing', [\App\Http\Controllers\Api\PlatformBillingController::class, 'show']);
        Route::put('/admin/billing', [\App\Http\Controllers\Api\PlatformBillingController::class, 'update']);
        Route::post('/admin/billing/test', [\App\Http\Controllers\Api\PlatformBillingController::class, 'test']);
        Route::get('/admin/currencies', [CurrencyController::class, 'platform']);
        Route::put('/admin/currencies/{code}', [CurrencyController::class, 'toggle']);
        Route::put('/admin/platform-settings', [PlatformSettingController::class, 'update']);
        Route::get('/admin/personalization', [PlatformLookController::class, 'show']);
        Route::put('/admin/personalization/{section}', [PlatformLookController::class, 'save']);
        Route::post('/admin/personalization/{section}/reset', [PlatformLookController::class, 'reset']);
        Route::post('/admin/personalization/files/{name}', [PlatformLookController::class, 'upload']);
        Route::delete('/admin/personalization/files/{name}', [PlatformLookController::class, 'remove']);
        Route::apiResource('admin/business-types', BusinessTypeController::class);
        Route::get('/admin/business-types/{business_type}/tenants', [BusinessTypeController::class, 'tenants']);
        Route::post('/admin/business-types/{business_type}/apply', [BusinessTypeController::class, 'apply']);
        Route::apiResource('tenant-contacts', TenantContactController::class);
    });
});

Route::middleware(['auth:sanctum', 'customer'])->prefix('portal')->group(function () {
    Route::get('/overview', [PortalController::class, 'overview']);
    Route::get('/privacy', [PortalController::class, 'privacy']);
    Route::post('/privacy/accept', [PortalController::class, 'acceptPrivacyNow']);
    Route::put('/profile', [PortalController::class, 'updateProfile']);
    Route::put('/consents', [PortalController::class, 'updateConsents']);
    Route::put('/password', [PortalController::class, 'updatePassword']);
    Route::post('/dependents', [PortalController::class, 'storeDependent']);
    Route::get('/packages', [PortalController::class, 'packages'])->middleware('module:packages|quotes');

    Route::middleware('module:quotes')->group(function () {
        Route::get('/quotes', [PortalQuoteController::class, 'index']);
        Route::get('/quotes/{quote}/pdf', [PortalQuoteController::class, 'pdf']);
        Route::post('/quotes/{quote}/accept', [PortalQuoteController::class, 'accept'])->middleware('plan:portal');
        Route::post('/quotes/{quote}/reject', [PortalQuoteController::class, 'reject']);
    });

    Route::middleware('module:packages')->group(function () {
        Route::get('/shop', [PortalShopController::class, 'index']);
        Route::post('/shop/packages/{plan}', [PortalShopController::class, 'buy'])->middleware('plan:portal');
    });

    Route::middleware('module:booking')->group(function () {
        Route::get('/catalog', [PortalController::class, 'catalog']);
        Route::get('/availability', [PortalController::class, 'portalAvailability']);
        Route::post('/appointments', [PortalController::class, 'book'])->middleware('plan:portal');
        Route::post('/appointments/{appointment}/confirm', [PortalController::class, 'confirm']);
        Route::post('/appointments/{appointment}/cancel', [PortalController::class, 'cancel']);
        Route::post('/appointments/{appointment}/reschedule', [PortalController::class, 'move'])->middleware('plan:portal');

        Route::get('/payment-links', [PortalController::class, 'paymentLinks']);
        Route::post('/payment-links/{link}/cancel', [PortalController::class, 'cancelPaymentLink']);

        Route::get('/waitlist', [PortalController::class, 'waitlist']);
        Route::post('/waitlist', [PortalController::class, 'joinWaitlist'])->middleware('plan:portal');
        Route::delete('/waitlist/{entry}', [PortalController::class, 'leaveWaitlist']);
    });
});

Route::prefix('kiosk')->middleware('throttle:60,1')->group(function () {
    Route::get('/{code}', [WalkinController::class, 'kioskInfo']);
    Route::get('/{code}/choices', [WalkinController::class, 'kioskChoices']);
    Route::post('/{code}/lookup', [WalkinController::class, 'kioskLookup']);
    Route::post('/{code}/queue', [WalkinController::class, 'kioskRegister'])->middleware('throttle:15,1');
});

Route::middleware(['auth:sanctum', 'staff', 'plan'])->group(function () {
    Route::post('/subscriptions/subscribe', [SubscriptionController::class, 'subscribe']);
    Route::get('/billing', [\App\Http\Controllers\Api\BillingController::class, 'show']);
    Route::post('/billing/card', [\App\Http\Controllers\Api\BillingController::class, 'card'])->middleware('throttle:20,1');
    Route::post('/billing/pay', [\App\Http\Controllers\Api\BillingController::class, 'pay'])->middleware('throttle:20,1');
    Route::post('/billing/plan', [\App\Http\Controllers\Api\BillingController::class, 'change'])->middleware('throttle:20,1');
    Route::post('/billing/cancel-card', [\App\Http\Controllers\Api\BillingController::class, 'cancelCard']);

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

    Route::get('/reports', [ReportController::class, 'show'])->middleware('perm:reports.view|reports.own');

    Route::middleware('module:automations')->group(function () {
        Route::get('/automations', [AutomationController::class, 'index'])->middleware('perm:automations.view');
        Route::put('/automations/{automation}', [AutomationController::class, 'update'])->middleware('perm:automations.edit');
    });

    Route::middleware('module:walkin')->group(function () {
        Route::get('/walkins', [WalkinController::class, 'index'])->middleware('perm:walkin.view');
        Route::get('/walkins/choices', [WalkinController::class, 'choices'])->middleware('perm:walkin.view');
        Route::post('/walkins', [WalkinController::class, 'store'])->middleware('perm:walkin.create');
        Route::put('/walkins/{walkin}', [WalkinController::class, 'update'])->middleware('perm:walkin.edit');
        Route::get('/walkins/{walkin}/options', [WalkinController::class, 'options'])->middleware('perm:walkin.edit');
        Route::post('/walkins/{walkin}/serve', [WalkinController::class, 'serve'])->middleware('perm:walkin.edit');
        Route::post('/walkins/{walkin}/status', [WalkinController::class, 'changeStatus'])->middleware('perm:walkin.edit');
    });

    Route::middleware('module:agenda')->group(function () use ($guarded) {
        $guarded('specialties', SpecialtyController::class, 'professionals');
        $guarded('services', ServiceController::class, 'services');
        $guarded('service-categories', ServiceCategoryController::class, 'services');
        $guarded('professionals', ProfessionalController::class, 'professionals');
        Route::get('/schedules', [ProfessionalScheduleController::class, 'index'])->middleware('perm:schedules.view');
        Route::get('/professionals/{professional}/signature', [ProfessionalSignatureController::class, 'show'])->middleware('perm:professionals.view');
        Route::post('/professionals/{professional}/signature', [ProfessionalSignatureController::class, 'store'])->middleware('perm:professionals.edit');
        Route::delete('/professionals/{professional}/signature', [ProfessionalSignatureController::class, 'destroy'])->middleware('perm:professionals.edit');
        Route::get('/professionals/{professional}/schedule', [ProfessionalScheduleController::class, 'show']);
        Route::put('/professionals/{professional}/schedule', [ProfessionalScheduleController::class, 'update'])->middleware('perm:schedules.edit');
        Route::get('/availability-blocks/options', [AvailabilityBlockController::class, 'options']);
        Route::get('/availability-blocks/affected', [AvailabilityBlockController::class, 'affected']);
        $guarded('availability-blocks', AvailabilityBlockController::class, 'blocks');

        Route::post('/holidays/national', [HolidayController::class, 'loadNational'])->middleware('perm:holidays.create');
        $guarded('holidays', HolidayController::class, 'holidays');

        Route::middleware('perm:agenda.view')->group(function () {
            Route::get('/appointments/availability', [AppointmentController::class, 'availability']);
            Route::get('/appointments/context', [AppointmentController::class, 'context']);
            Route::get('/appointments/day', [AppointmentController::class, 'day']);
            Route::get('/appointments/range', [AppointmentController::class, 'range']);
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
        Route::get('/payments', [SalesController::class, 'payments'])->middleware('perm:payments.view');

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

        Route::post('/appointments/{appointment}/link', [SaleController::class, 'link'])->middleware('perm:sales.create');
        Route::post('/sales/{sale}/links', [PaymentController::class, 'createForSale'])->middleware('perm:sales.create');
        Route::post('/payment-links/{link}/cancel', [PaymentController::class, 'cancel'])->middleware('perm:sales.create');
        Route::get('/integrations', [PaymentController::class, 'integrations']);
        Route::put('/integrations/payments', [PaymentController::class, 'savePayments']);
        Route::post('/integrations/payments/test', [PaymentController::class, 'test']);
        Route::put('/payment-qr/{method}', [PaymentController::class, 'saveQr']);
        Route::delete('/payment-qr/{method}', [PaymentController::class, 'deleteQr']);
    });

    Route::post('/data-exports', [DataExportController::class, 'store'])->middleware(['perm:data.export', 'throttle:60,1']);
    Route::get('/data-exports', [DataExportController::class, 'index']);
    Route::get('/messages', [MessageController::class, 'index'])->middleware('perm:messages.view');
    Route::post('/messages', [MessageController::class, 'store'])->middleware('perm:messages.create');
    Route::post('/messages/process', [MessageController::class, 'process'])->middleware('perm:messages.view');
    Route::put('/messages/{message}', [MessageController::class, 'update'])->middleware('perm:messages.edit');
    Route::post('/messages/{message}/cancel', [MessageController::class, 'cancel'])->middleware('perm:messages.edit');
    Route::post('/messages/{message}/retry', [MessageController::class, 'retry'])->middleware('perm:messages.edit');
    Route::get('/messaging/settings', [MessageController::class, 'settings']);
    Route::put('/messaging/email', [MessageController::class, 'saveEmail']);
    Route::delete('/messaging/email', [MessageController::class, 'removeEmail']);
    Route::post('/messaging/email/test', [MessageController::class, 'testEmail']);
    Route::put('/messaging/notices/{kind}', [MessageController::class, 'saveNotice']);
    Route::post('/messaging/notices/{kind}/reset', [MessageController::class, 'resetNotice']);
    Route::put('/messaging/design', [MessageController::class, 'saveDesign']);
    Route::post('/messaging/design/logo', [MessageController::class, 'uploadLogo']);
    Route::delete('/messaging/design/logo', [MessageController::class, 'removeLogo']);
    Route::post('/messaging/preview', [MessageController::class, 'preview']);
    Route::get('/branding', [BrandingController::class, 'show']);
    Route::put('/branding', [BrandingController::class, 'save']);
    Route::put('/branding/portal', [BrandingController::class, 'savePortal']);
    Route::put('/branding/privacy', [BrandingController::class, 'savePrivacy']);
    Route::put('/branding/documents', [BrandingController::class, 'saveDocuments']);
    Route::put('/branding/codes', [BrandingController::class, 'saveCodes']);
    Route::get('/branding/signature', [BrandingController::class, 'signatureFile']);
    Route::post('/branding/signature', [BrandingController::class, 'uploadSignature']);
    Route::delete('/branding/signature', [BrandingController::class, 'removeSignature']);
    Route::post('/branding/logo', [BrandingController::class, 'uploadLogo']);
    Route::delete('/branding/logo/{variant}', [BrandingController::class, 'removeLogo']);
    Route::post('/payment-links/{link}/send', [MessageController::class, 'sendPaymentLink'])->middleware('perm:sales.create');
    Route::post('/sales/{sale}/receipt/send', [MessageController::class, 'sendReceipt'])->middleware('perm:sales.view|sales.create');
    Route::put('/customers/{customer}/consents', [MessageController::class, 'consents'])->middleware('perm:customers.edit');

    Route::middleware('module:marketing')->group(function () {
        Route::get('/campaigns', [CampaignController::class, 'index'])->middleware('perm:campaigns.view');
        Route::get('/campaigns/options', [CampaignController::class, 'options'])->middleware('perm:campaigns.view');
        Route::post('/campaigns/audience', [CampaignController::class, 'audience'])->middleware('perm:campaigns.view');
        Route::post('/campaigns', [CampaignController::class, 'store'])->middleware('perm:campaigns.create');
        Route::get('/campaigns/{campaign}', [CampaignController::class, 'show'])->middleware('perm:campaigns.view');
        Route::put('/campaigns/{campaign}', [CampaignController::class, 'update'])->middleware('perm:campaigns.edit');
        Route::post('/campaigns/{campaign}/cancel', [CampaignController::class, 'cancel'])->middleware('perm:campaigns.edit');
    });

    Route::middleware('module:packages')->group(function () {
        Route::get('/packages', [PackageController::class, 'index'])->middleware('perm:packages.view');
        Route::get('/packages/free-times', [PackageController::class, 'freeTimes'])->middleware('perm:pos.create|agenda.create');
        Route::post('/packages/schedule/preview', [PackageController::class, 'preview'])->middleware('perm:pos.create|agenda.create');
        Route::get('/packages/{package}', [PackageController::class, 'show'])->middleware('perm:packages.view');
        Route::post('/packages/{package}/usages', [PackageController::class, 'use'])->middleware('perm:packages.use');
        Route::post('/packages/{package}/extend', [PackageController::class, 'extend'])->middleware('perm:packages.edit');
        Route::post('/packages/{package}/cancel', [PackageController::class, 'cancel'])->middleware('perm:packages.delete');
        Route::post('/packages/{package}/schedule', [PackageController::class, 'schedule'])->middleware('perm:agenda.create');
        Route::post('/package-plans', [PackageController::class, 'storePlan'])->middleware('perm:packages.create');
        Route::put('/package-plans/{plan}', [PackageController::class, 'updatePlan'])->middleware('perm:packages.edit');
        Route::delete('/package-plans/{plan}', [PackageController::class, 'destroyPlan'])->middleware('perm:packages.delete');
    });

    Route::middleware('module:packages|quotes')->group(function () {
        Route::post('/appointments/{appointment}/package', [SaleController::class, 'usePackage'])->middleware('perm:sales.create');
        Route::delete('/appointments/{appointment}/package', [SaleController::class, 'releasePackage'])->middleware('perm:sales.create');
    });

    Route::middleware('module:inventory')->group(function () use ($guarded) {
        $guarded('products', ProductController::class, 'products', true, ['show']);
        Route::post('/product-categories', [ProductController::class, 'storeCategory'])->middleware('perm:products.create');
    });

    Route::middleware('module:inventory')->group(function () {
        Route::get('/inventory', [InventoryController::class, 'index'])->middleware('perm:inventory.view');
        Route::post('/inventory/movements', [InventoryController::class, 'storeMovement'])->middleware('perm:inventory.create|inventory.edit');
        Route::get('/inventory/services/{service}/supplies', [InventoryController::class, 'serviceSupplies'])->middleware('perm:inventory.view|services.view');
        Route::put('/inventory/services/{service}/supplies', [InventoryController::class, 'updateServiceSupplies'])->middleware('perm:inventory.edit');
        Route::post('/inventory/transfers', [InventoryController::class, 'storeTransfer'])->middleware('perm:inventory.create');
        Route::post('/inventory/transfers/{transfer}/receive', [InventoryController::class, 'receiveTransfer'])->middleware('perm:inventory.create|inventory.edit');
        Route::post('/inventory/transfers/{transfer}/cancel', [InventoryController::class, 'cancelTransfer'])->middleware('perm:inventory.delete');
        Route::post('/inventory/suppliers', [InventoryController::class, 'storeSupplier'])->middleware('perm:inventory.create');
        Route::put('/inventory/suppliers/{supplier}', [InventoryController::class, 'updateSupplier'])->middleware('perm:inventory.edit');
        Route::delete('/inventory/suppliers/{supplier}', [InventoryController::class, 'destroySupplier'])->middleware('perm:inventory.delete');
        Route::post('/inventory/purchases', [InventoryController::class, 'storePurchase'])->middleware('perm:inventory.create');
        Route::post('/inventory/purchases/{order}/receive', [InventoryController::class, 'receivePurchase'])->middleware('perm:inventory.edit');
        Route::post('/inventory/purchases/{order}/cancel', [InventoryController::class, 'cancelPurchase'])->middleware('perm:inventory.delete');
    });

    Route::middleware('module:loyalty')->group(function () {
        Route::get('/loyalty', [LoyaltyController::class, 'index'])->middleware('perm:loyalty.view');
        Route::post('/loyalty/points/adjust', [LoyaltyController::class, 'adjustPoints'])->middleware('perm:loyalty.create|loyalty.edit');
        Route::post('/loyalty/gift-cards', [LoyaltyController::class, 'storeGiftCard'])->middleware('perm:loyalty.create');
        Route::post('/loyalty/gift-cards/{giftCard}/redeem', [LoyaltyController::class, 'redeemGiftCard'])->middleware('perm:loyalty.edit|loyalty.create');
        Route::post('/loyalty/gift-cards/{giftCard}/extend', [LoyaltyController::class, 'extendGiftCard'])->middleware('perm:loyalty.edit');
        Route::post('/loyalty/gift-cards/{giftCard}/cancel', [LoyaltyController::class, 'cancelGiftCard'])->middleware('perm:loyalty.delete');
    });

    Route::middleware('module:quotes')->group(function () {
        Route::get('/quotes', [QuoteController::class, 'index'])->middleware('perm:quotes.view');
        Route::post('/quotes', [QuoteController::class, 'store'])->middleware('perm:quotes.create');
        Route::get('/quotes/{quote}/pdf', [QuoteController::class, 'pdf'])->middleware('perm:quotes.view');
        Route::put('/quotes/{quote}', [QuoteController::class, 'update'])->middleware('perm:quotes.edit');
        Route::post('/quotes/{quote}/status', [QuoteController::class, 'changeStatus'])->middleware('perm:quotes.edit|quotes.delete');
        Route::post('/quotes/{quote}/items/{item}/sessions', [QuoteController::class, 'recordSession'])->middleware('perm:quotes.edit');
        Route::post('/quotes/{quote}/sale', [QuoteController::class, 'createSale'])->middleware('perm:quotes.edit|sales.create');
        Route::get('/quotes/{quote}/cancel', [QuoteController::class, 'cancelPreview'])->middleware('perm:quotes.delete');
        Route::post('/quotes/{quote}/cancel', [QuoteController::class, 'cancelAccepted'])->middleware('perm:quotes.delete');
    });

    Route::middleware('module:crm')->group(function () use ($guarded) {
        Route::get('/customer-board', [CustomerBoardController::class, 'show'])->middleware('perm:customers.view|customers.create|customers.edit');
        Route::put('/customer-board/columns', [CustomerBoardController::class, 'saveColumns'])->middleware('perm:settings.edit');
        Route::put('/customers/{customer}/stage', [CustomerBoardController::class, 'move'])->middleware('perm:customers.edit');
        Route::get('/customers/{customer}/stage-changes', [CustomerBoardController::class, 'changes'])->middleware('perm:customers.view');
        $guarded('customers', CustomerController::class, 'customers', true);
        Route::put('/customers/{customer}/portal-access', [CustomerController::class, 'portalAccess'])->middleware('perm:customers.edit');
        Route::post('/customers/{customer}/portal-access/reset', [CustomerController::class, 'resetPortalPassword'])->middleware('perm:customers.edit');
        Route::post('/customers/{customer}/booking-strikes/forgive', [CustomerController::class, 'forgiveBooking'])->middleware('perm:customers.forgive');
        Route::apiResource('customers.dependents', CustomerDependentController::class)->only(['index'])->middleware('perm:customers.view');
        Route::apiResource('customers.dependents', CustomerDependentController::class)->only(['store', 'update', 'destroy'])->middleware('perm:customers.edit|customers.create');
    });

    Route::get('/company', [\App\Http\Controllers\Api\CompanyController::class, 'show']);
    Route::put('/company', [\App\Http\Controllers\Api\CompanyController::class, 'update'])->middleware('perm:company.edit');

    Route::get('/tenant-settings', [TenantSettingController::class, 'show'])->middleware('perm:settings.view');
    Route::get('/audit', [\App\Http\Controllers\Api\AuditController::class, 'tenant'])->middleware('perm:audit.view');

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
            Route::post('/users/{user}/security', [AccessController::class, 'security']);
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