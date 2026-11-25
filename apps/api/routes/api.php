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
use App\Http\Controllers\Api\MembershipController;
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
Route::post('/register-customer', [RegisterCustomerController::class, 'register'])->middleware(['throttle:10,1', 'idempotent']);
Route::get('/signup-info/{code}', [BrandingController::class, 'signupInfo'])->middleware('throttle:20,1');
Route::get('/register-tenant/start', [RegisterTenantController::class, 'start'])->middleware('throttle:30,1');
Route::post('/register-tenant', [RegisterTenantController::class, 'register'])->middleware(['throttle:register-tenant', 'idempotent']);

Route::get('/platform', [PlatformSettingController::class, 'publicValues']);
Route::get('/complaints-book', [\App\Http\Controllers\Api\ComplaintController::class, 'platformBook'])->middleware('throttle:30,1');
Route::post('/complaints-book', [\App\Http\Controllers\Api\ComplaintController::class, 'platformStore'])->middleware('throttle:5,1');
Route::get('/complaints-book/{code}', [\App\Http\Controllers\Api\ComplaintController::class, 'companyBook'])->middleware('throttle:30,1');
Route::post('/complaints-book/{code}', [\App\Http\Controllers\Api\ComplaintController::class, 'companyStore'])->middleware('throttle:5,1');
Route::get('/platform/files/{name}', [PlatformLookController::class, 'file'])->where('name', 'logo|logo-dark|landing|login')->middleware('throttle:120,1');

Route::get('/pay/{token}', [PaymentController::class, 'show'])->middleware('throttle:60,1');
Route::get('/branding/{tenant}/{name}', [BrandingController::class, 'file'])->where('name', 'logo|logo-dark')->middleware('throttle:120,1');
Route::post('/pay/{token}/simulate', [PaymentController::class, 'simulate'])->middleware('throttle:20,1');
Route::get('/plan-payments/{id}', [\App\Http\Controllers\Api\BillingController::class, 'simulatedView'])->whereUuid('id')->middleware('throttle:30,1');
Route::post('/plan-payments/{id}/simulate', [\App\Http\Controllers\Api\BillingController::class, 'simulate'])->whereUuid('id')->middleware('throttle:20,1');
Route::get('/data-exports/{export}/download', [\App\Http\Controllers\Api\PlanOperationsController::class, 'download'])->whereUuid('export')->name('data-exports.download')->middleware(['signed:relative', 'throttle:20,1']);
Route::get('/invoice-files/{invoice}/{kind}', [\App\Http\Controllers\Api\InvoiceController::class, 'file'])->whereUuid('invoice')->whereIn('kind', ['pdf', 'xml', 'cdr'])->name('invoices.file')->middleware(['signed:relative', 'throttle:30,1']);
Route::get('/care-record-files/{recordFile}/download', [CareRecordController::class, 'downloadFile'])->whereUuid('recordFile')->name('care-record-files.download')->middleware(['signed:relative', 'throttle:30,1']);
Route::post('/client-errors', [\App\Http\Controllers\Api\PlanOperationsController::class, 'clientError'])->middleware('throttle:30,1,client-errors');
Route::post('/pay/{token}/start', [PaymentController::class, 'start'])->middleware('throttle:20,1');
Route::post('/pay/{token}/culqi', [PaymentController::class, 'culqi'])->middleware('throttle:10,1');
Route::get('/pay/{token}/niubiz', [PaymentController::class, 'niubizForm'])->middleware('throttle:20,1');
Route::post('/pay/{token}/niubiz', [PaymentController::class, 'niubiz'])->middleware('throttle:10,1');
Route::post('/pay/{token}/voucher', [\App\Http\Controllers\Api\PaymentVoucherController::class, 'submit'])->middleware('throttle:6,1');
Route::post('/pay/{token}/check', [PaymentController::class, 'check'])->middleware('throttle:30,1');
Route::post('/pay/{token}/conversion', [\App\Http\Controllers\Api\BookingConversionController::class, 'mark'])->middleware('throttle:20,1');
Route::post('/webhooks/payments/{provider}/{owner?}', [PaymentController::class, 'webhook'])->middleware('throttle:240,1');
Route::get('/webhooks/whatsapp/{tenant}', [\App\Http\Controllers\Api\WhatsAppWebhookController::class, 'verify'])->whereUuid('tenant')->middleware('throttle:60,1');
Route::post('/webhooks/whatsapp/{tenant}', [\App\Http\Controllers\Api\WhatsAppWebhookController::class, 'receive'])->whereUuid('tenant')->middleware('throttle:600,1');
Route::get('/promotions/unsubscribe/{recipient}', [CampaignController::class, 'unsubscribeShow'])->middleware('throttle:60,1');
Route::post('/promotions/unsubscribe/{recipient}', [CampaignController::class, 'unsubscribe'])->middleware('throttle:20,1');
Route::prefix('/public/appointment/{token}')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\AppointmentLinkController::class, 'show'])->middleware('throttle:public-booking-read');
    Route::post('/confirm', [\App\Http\Controllers\Api\AppointmentLinkController::class, 'linkConfirm'])->middleware('throttle:public-booking-read');
    Route::post('/cancel', [\App\Http\Controllers\Api\AppointmentLinkController::class, 'linkCancel'])->middleware('throttle:public-booking-read');
});
Route::prefix('/public/booking/{slug}')->where(['slug' => '[a-z0-9-]+'])->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\PublicBookingController::class, 'show'])->middleware('throttle:public-booking-read');
    Route::get('/cover', [\App\Http\Controllers\Api\PublicBookingController::class, 'cover'])->middleware('throttle:public-booking-read');
    Route::get('/catalog', [\App\Http\Controllers\Api\PublicBookingController::class, 'publicCatalog'])->middleware('throttle:public-booking-read');
    Route::get('/availability', [\App\Http\Controllers\Api\PublicBookingController::class, 'publicAvailability'])->middleware('throttle:public-booking-read');
    Route::post('/appointments', [\App\Http\Controllers\Api\PublicBookingController::class, 'publicBook'])->middleware('throttle:public-booking-write');
    Route::get('/classes', [\App\Http\Controllers\Api\PublicClassController::class, 'index'])->middleware('throttle:public-booking-read');
    Route::post('/classes/{class}/enroll', [\App\Http\Controllers\Api\PublicClassController::class, 'enroll'])->middleware('throttle:public-booking-write');
});
Route::get('/public/booking/{slug}/days', [\App\Http\Controllers\Api\PublicBookingController::class, 'publicDays'])->where(['slug' => '[a-z0-9-]+'])->middleware('throttle:public-booking-read');
Route::get('/public/link/{slug}', [\App\Http\Controllers\Api\PublicLinkController::class, 'show'])->where(['slug' => '[a-z0-9-]+'])->middleware('throttle:public-booking-read');
Route::post('/public/link/{slug}/visit', [\App\Http\Controllers\Api\PublicLinkController::class, 'visit'])->where(['slug' => '[a-z0-9-]+'])->middleware('throttle:60,1,link-visit');
Route::prefix('/public/surveys/{token}')->where(['token' => '[A-Za-z0-9]+'])->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\PublicSurveyController::class, 'show'])->middleware('throttle:public-booking-read');
    Route::post('/', [\App\Http\Controllers\Api\PublicSurveyController::class, 'answer'])->middleware('throttle:public-booking-write');
    Route::post('/google', [\App\Http\Controllers\Api\PublicSurveyController::class, 'google'])->middleware('throttle:public-booking-read');
});

Route::get('/calendar/{token}.ics', [\App\Http\Controllers\Api\ApiIntegrationController::class, 'calendar'])->middleware('throttle:60,1');

Route::prefix('docs')->middleware('throttle:60,1')->group(function () {
    Route::get('/', [\App\Http\Controllers\Api\ApiDocsController::class, 'page']);
    Route::get('/openapi.yaml', [\App\Http\Controllers\Api\ApiDocsController::class, 'yaml']);
    Route::get('/openapi.json', [\App\Http\Controllers\Api\ApiDocsController::class, 'json']);
});

Route::prefix('v1')->middleware([\App\Http\Middleware\AuthenticateApiKey::class, 'idempotent'])->group(function () {
    Route::get('/services', [\App\Http\Controllers\Api\V1\PublicApiController::class, 'services']);
    Route::get('/branches', [\App\Http\Controllers\Api\V1\PublicApiController::class, 'branches']);
    Route::get('/professionals', [\App\Http\Controllers\Api\V1\PublicApiController::class, 'professionals']);
    Route::get('/availability', [\App\Http\Controllers\Api\V1\PublicApiController::class, 'availability']);
    Route::get('/appointments', [\App\Http\Controllers\Api\V1\PublicApiController::class, 'appointments']);
    Route::post('/appointments', [\App\Http\Controllers\Api\V1\PublicApiController::class, 'storeAppointment']);
    Route::get('/appointments/{appointment}', [\App\Http\Controllers\Api\V1\PublicApiController::class, 'showAppointment'])->whereUuid('appointment');
    Route::post('/appointments/{appointment}/cancel', [\App\Http\Controllers\Api\V1\PublicApiController::class, 'cancelAppointment'])->whereUuid('appointment');
    Route::get('/customers', [\App\Http\Controllers\Api\V1\PublicApiController::class, 'customers']);
    Route::post('/customers', [\App\Http\Controllers\Api\V1\PublicApiController::class, 'storeCustomer']);
    Route::get('/customers/{customer}', [\App\Http\Controllers\Api\V1\PublicApiController::class, 'showCustomer'])->whereUuid('customer');
});

Route::get('/modules', function () {
    return SaasModule::orderBy('sort_order')->get(['key', 'name', 'is_core']);
});

Route::get('/business-types', function () {
    return BusinessType::where('status', 'active')->orderBy('sort_order')->get();
});

Route::get('/plans', function () {
    return SaaSPlan::where('status', 'active')->where('is_public_catalog', true)->orderBy('presentation_order')->get();
});

Route::prefix('web')->group(function () {
    Route::get('/site', [\App\Http\Controllers\Api\WebController::class, 'site'])->middleware('throttle:240,1,web-site');
    Route::get('/rubros/{slug}', [\App\Http\Controllers\Api\WebController::class, 'businessType'])->where('slug', '[a-z0-9_-]+')->middleware('throttle:240,1,web-businessType');
    Route::get('/posts', [\App\Http\Controllers\Api\WebController::class, 'posts'])->middleware('throttle:240,1,web-posts');
    Route::get('/posts/{post}/cover', [\App\Http\Controllers\Api\WebController::class, 'cover'])->middleware('throttle:240,1,web-cover');
    Route::get('/blog/{slug}', [\App\Http\Controllers\Api\WebController::class, 'post'])->where('slug', '[a-z0-9-]+')->middleware('throttle:240,1,web-post');
    Route::get('/sitemap', [\App\Http\Controllers\Api\WebController::class, 'sitemap'])->middleware('throttle:120,1,web-sitemap');
    Route::get('/legal/{page}', [\App\Http\Controllers\Api\WebController::class, 'legal'])->middleware('throttle:120,1,web-legal');
    Route::post('/visit', [\App\Http\Controllers\Api\WebController::class, 'visit'])->middleware('throttle:60,1,web-visit');
    Route::post('/leads', [\App\Http\Controllers\Api\WebController::class, 'lead'])->middleware(['throttle:5,1,web-lead', 'idempotent']);
    Route::post('/coupon', [\App\Http\Controllers\Api\WebController::class, 'coupon'])->middleware('throttle:20,1,web-coupon');
    Route::post('/chat', [\App\Http\Controllers\Api\PlanOperationsController::class, 'chatLead'])->middleware(['throttle:5,1,web-chat', 'idempotent']);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [LoginController::class, 'logout']);
    Route::get('/session', [LoginController::class, 'ping'])->middleware('throttle:30,1');
    Route::post('/impersonation/stop', [ImpersonationController::class, 'stop']);
});

Route::middleware(['auth:sanctum', 'saas', 'idempotent'])->group(function () {
    Route::get('/tenants', [TenantController::class, 'index']);
    Route::get('/tenants/{tenant}', [TenantController::class, 'show']);
    Route::post('/tenants/{tenant}/impersonate', [ImpersonationController::class, 'start']);
    Route::get('/admin/support', [\App\Http\Controllers\Api\PlatformSupportController::class, 'index']);
    Route::get('/admin/support/{ticket}', [\App\Http\Controllers\Api\PlatformSupportController::class, 'show'])->whereUuid('ticket');
    Route::post('/admin/support/{ticket}/messages', [\App\Http\Controllers\Api\PlatformSupportController::class, 'reply'])->whereUuid('ticket');
    Route::put('/admin/support/{ticket}', [\App\Http\Controllers\Api\PlatformSupportController::class, 'update'])->whereUuid('ticket');
    Route::get('/admin/support/{ticket}/files/{message}', [\App\Http\Controllers\Api\PlatformSupportController::class, 'file'])->whereUuid(['ticket', 'message']);
    Route::get('/admin/health', [\App\Http\Controllers\Api\PlatformSupportController::class, 'health']);

    Route::middleware('saas:admin')->group(function () {
        Route::get('/admin/audit', [\App\Http\Controllers\Api\AuditController::class, 'platform']);
        Route::post('/admin/data-exports', [DataExportController::class, 'platform'])->middleware('throttle:60,1');
        Route::post('/tenants', [TenantController::class, 'store']);
        Route::put('/tenants/{tenant}', [TenantController::class, 'update']);
        Route::delete('/tenants/{tenant}', [TenantController::class, 'destroy']);
        Route::post('/tenants/{tenant}/deletion/cancel', [TenantController::class, 'cancelDeletion']);
        Route::post('/tenants/{tenant}/suspend', [TenantController::class, 'suspend']);
        Route::post('/tenants/{tenant}/reactivate', [TenantController::class, 'reactivate']);
        Route::put('/tenants/{tenant}/plan', [TenantController::class, 'changePlan']);
        Route::put('/tenants/{tenant}/business-type', [TenantController::class, 'changeBusinessType']);
        Route::put('/tenants/{tenant}/modules', [TenantController::class, 'updateModules']);

        Route::post('/tenants/{tenant}/subscription/payments', [TenantSubscriptionController::class, 'registerPayment']);
        Route::put('/tenants/{tenant}/subscription/extend', [TenantSubscriptionController::class, 'extend']);
        Route::post('/tenants/{tenant}/subscription/cancel', [TenantSubscriptionController::class, 'cancel']);
        Route::post('/tenants/{tenant}/subscription/past-due', [TenantSubscriptionController::class, 'markPastDue']);

        Route::apiResource('saas-plans', SaaSPlanController::class);
        Route::get('/admin/platform-settings', [PlatformSettingController::class, 'show']);
        Route::get('/admin/complaints', [\App\Http\Controllers\Api\ComplaintController::class, 'index']);
        Route::get('/admin/complaints/{complaint}', [\App\Http\Controllers\Api\ComplaintController::class, 'show']);
        Route::put('/admin/complaints/{complaint}', [\App\Http\Controllers\Api\ComplaintController::class, 'answer']);
        Route::get('/admin/platform-mail', [\App\Http\Controllers\Api\PlatformMailController::class, 'show']);
        Route::put('/admin/platform-mail', [\App\Http\Controllers\Api\PlatformMailController::class, 'update']);
        Route::delete('/admin/platform-mail', [\App\Http\Controllers\Api\PlatformMailController::class, 'destroy']);
        Route::post('/admin/platform-mail/test', [\App\Http\Controllers\Api\PlatformMailController::class, 'test'])->middleware('throttle:10,1');
        Route::get('/admin/integrations', [\App\Http\Controllers\Api\PlatformIntegrationController::class, 'show']);
        Route::put('/admin/integrations/ai', [\App\Http\Controllers\Api\PlatformIntegrationController::class, 'saveAi']);
        Route::post('/admin/integrations/ai/test', [\App\Http\Controllers\Api\PlatformIntegrationController::class, 'testAi'])->middleware('throttle:10,1');
        Route::put('/admin/integrations/captcha', [\App\Http\Controllers\Api\PlatformIntegrationController::class, 'saveCaptcha']);
        Route::delete('/admin/integrations/ai', [\App\Http\Controllers\Api\PlatformIntegrationController::class, 'destroyAi']);
        Route::delete('/admin/integrations/captcha', [\App\Http\Controllers\Api\PlatformIntegrationController::class, 'destroyCaptcha']);

        Route::get('/admin/payment-webhooks', [\App\Http\Controllers\Api\GatewayWebhookController::class, 'platformIndex']);
        Route::post('/admin/payment-webhooks/{webhook}/retry', [\App\Http\Controllers\Api\GatewayWebhookController::class, 'platformRetry'])->whereUuid('webhook')->middleware('throttle:30,1');

        Route::post('/tenants/{tenant}/users/{user}/security', [TenantController::class, 'userSecurity']);
        Route::post('/tenants/{tenant}/users/{user}/password', [TenantController::class, 'userPassword']);
        Route::post('/tenants/{tenant}/users/{user}/invite', [TenantController::class, 'userInvite'])->middleware('throttle:10,1');
        Route::put('/tenants/{tenant}/users/{user}/email', [TenantController::class, 'userEmail']);
        Route::get('/admin/reports', [\App\Http\Controllers\Api\PlatformReportController::class, 'show']);
        Route::get('/admin/reports/summary', [\App\Http\Controllers\Api\PlatformReportController::class, 'summary']);
        Route::put('/admin/support-settings', [\App\Http\Controllers\Api\PlatformSupportController::class, 'settings']);
        Route::get('/admin/announcements', [\App\Http\Controllers\Api\AnnouncementController::class, 'index']);
        Route::post('/admin/announcements', [\App\Http\Controllers\Api\AnnouncementController::class, 'store']);
        Route::put('/admin/announcements/{announcement}', [\App\Http\Controllers\Api\AnnouncementController::class, 'update'])->whereUuid('announcement');
        Route::delete('/admin/announcements/{announcement}', [\App\Http\Controllers\Api\AnnouncementController::class, 'destroy'])->whereUuid('announcement');
        Route::get('/admin/web/coupons', [\App\Http\Controllers\Api\PlatformWebController::class, 'coupons']);
        Route::post('/admin/web/coupons', [\App\Http\Controllers\Api\PlatformWebController::class, 'storeCoupon']);
        Route::put('/admin/web/coupons/{coupon}', [\App\Http\Controllers\Api\PlatformWebController::class, 'updateCoupon'])->whereUuid('coupon');
        Route::delete('/admin/web/coupons/{coupon}', [\App\Http\Controllers\Api\PlatformWebController::class, 'destroyCoupon'])->whereUuid('coupon');
        Route::get('/admin/web/funnel', [\App\Http\Controllers\Api\PlatformWebController::class, 'funnel']);
        Route::get('/admin/funnel/extras', [\App\Http\Controllers\Api\PlatformFunnelController::class, 'extras']);
        Route::get('/admin/web/leads', [\App\Http\Controllers\Api\PlatformWebController::class, 'leads']);
        Route::post('/admin/web/leads', [\App\Http\Controllers\Api\PlatformWebController::class, 'storeLead']);
        Route::get('/admin/web/leads/{lead}', [\App\Http\Controllers\Api\PlatformWebController::class, 'lead'])->whereUuid('lead');
        Route::put('/admin/web/leads/{lead}/stage', [\App\Http\Controllers\Api\PlatformWebController::class, 'stage'])->whereUuid('lead');
        Route::post('/admin/web/leads/{lead}/notes', [\App\Http\Controllers\Api\PlatformWebController::class, 'note'])->whereUuid('lead');
        Route::get('/admin/web/settings', [\App\Http\Controllers\Api\PlatformWebController::class, 'settings']);
        Route::put('/admin/web/settings', [\App\Http\Controllers\Api\PlatformWebController::class, 'updateSettings']);
        Route::get('/admin/web/posts', [\App\Http\Controllers\Api\PlatformWebController::class, 'posts']);
        Route::post('/admin/web/posts', [\App\Http\Controllers\Api\PlatformWebController::class, 'storePost']);
        Route::put('/admin/web/posts/{post}', [\App\Http\Controllers\Api\PlatformWebController::class, 'updatePost'])->whereUuid('post');
        Route::delete('/admin/web/posts/{post}', [\App\Http\Controllers\Api\PlatformWebController::class, 'destroyPost'])->whereUuid('post');
        Route::post('/admin/web/posts/{post}/cover', [\App\Http\Controllers\Api\PlatformWebController::class, 'uploadCover'])->whereUuid('post');
        Route::delete('/admin/web/posts/{post}/cover', [\App\Http\Controllers\Api\PlatformWebController::class, 'removeCover'])->whereUuid('post');
        Route::get('/admin/referrals', [\App\Http\Controllers\Api\AnnouncementController::class, 'referrals']);
        Route::put('/admin/referrals', [\App\Http\Controllers\Api\AnnouncementController::class, 'referralSettings']);
        Route::get('/admin/billing', [\App\Http\Controllers\Api\PlatformBillingController::class, 'show']);
        Route::get('/admin/billing/invoices/{invoice}/receipt', [\App\Http\Controllers\Api\PlatformBillingController::class, 'receipt'])->whereUuid('invoice');
        Route::put('/admin/billing', [\App\Http\Controllers\Api\PlatformBillingController::class, 'update']);
        Route::post('/admin/billing/test', [\App\Http\Controllers\Api\PlatformBillingController::class, 'test']);
        Route::put('/admin/billing/methods', [\App\Http\Controllers\Api\PlatformBillingController::class, 'methods']);
        Route::get('/admin/billing/transfers', [\App\Http\Controllers\Api\PlatformBillingController::class, 'transfers']);
        Route::get('/admin/billing/overdue', [\App\Http\Controllers\Api\PlatformBillingController::class, 'overdue']);
        Route::post('/admin/billing/overdue/{subscription}/{action}', [\App\Http\Controllers\Api\PlatformBillingController::class, 'overdueAction'])->whereUuid('subscription')->whereIn('action', ['retry', 'remind', 'extend']);
        Route::put('/admin/billing/cancel-offer', [\App\Http\Controllers\Api\PlatformBillingController::class, 'cancelOffer']);
        Route::get('/admin/billing/cancellations', [\App\Http\Controllers\Api\PlatformBillingController::class, 'cancellations']);
        Route::get('/admin/addons', [\App\Http\Controllers\Api\PlatformAddonController::class, 'index']);
        Route::post('/admin/addons', [\App\Http\Controllers\Api\PlatformAddonController::class, 'store']);
        Route::put('/admin/addons/{addon}', [\App\Http\Controllers\Api\PlatformAddonController::class, 'update'])->whereUuid('addon');
        Route::delete('/admin/addons/{addon}', [\App\Http\Controllers\Api\PlatformAddonController::class, 'destroy'])->whereUuid('addon');
        Route::get('/admin/billing/retention', [\App\Http\Controllers\Api\PlatformAddonController::class, 'retention']);
        Route::put('/admin/billing/retention', [\App\Http\Controllers\Api\PlatformAddonController::class, 'saveRetention']);
        Route::get('/admin/billing/whatsapp', [\App\Http\Controllers\Api\PlatformAddonController::class, 'whatsapp']);
        Route::put('/admin/billing/whatsapp', [\App\Http\Controllers\Api\PlatformAddonController::class, 'saveWhatsapp']);
        Route::post('/admin/billing/whatsapp/test', [\App\Http\Controllers\Api\PlatformAddonController::class, 'testWhatsapp'])->middleware('throttle:10,1');
        Route::delete('/admin/billing/whatsapp', [\App\Http\Controllers\Api\PlatformAddonController::class, 'destroyWhatsapp']);
        Route::get('/admin/billing/invoicing', [\App\Http\Controllers\Api\PlanOperationsController::class, 'invoicing']);
        Route::put('/admin/billing/invoicing', [\App\Http\Controllers\Api\PlanOperationsController::class, 'saveInvoicing']);
        Route::post('/admin/billing/invoices/{invoice}/document', [\App\Http\Controllers\Api\PlanOperationsController::class, 'resendDocument'])->whereUuid('invoice');
        Route::get('/admin/errors', [\App\Http\Controllers\Api\PlanOperationsController::class, 'errors']);
        Route::get('/admin/errors/{error}', [\App\Http\Controllers\Api\PlanOperationsController::class, 'error'])->whereUuid('error');
        Route::put('/admin/errors/{error}', [\App\Http\Controllers\Api\PlanOperationsController::class, 'resolveError'])->whereUuid('error');
        Route::get('/admin/errors-settings', [\App\Http\Controllers\Api\PlanOperationsController::class, 'errorSettings']);
        Route::put('/admin/errors-settings', [\App\Http\Controllers\Api\PlanOperationsController::class, 'saveErrorSettings']);
        Route::get('/admin/help-articles', [\App\Http\Controllers\Api\PlanOperationsController::class, 'helpArticles']);
        Route::post('/admin/help-articles', [\App\Http\Controllers\Api\PlanOperationsController::class, 'storeHelpArticle']);
        Route::put('/admin/help-articles/{article}', [\App\Http\Controllers\Api\PlanOperationsController::class, 'updateHelpArticle'])->whereUuid('article');
        Route::put('/admin/billing/dunning',[\App\Http\Controllers\Api\PlatformBillingController::class, 'dunning']);
        Route::post('/admin/billing/transfers/{id}/approve', [\App\Http\Controllers\Api\PlatformBillingController::class, 'approveTransfer'])->whereUuid('id');
        Route::post('/admin/billing/transfers/{id}/reject', [\App\Http\Controllers\Api\PlatformBillingController::class, 'rejectTransfer'])->whereUuid('id');
        Route::get('/admin/billing/transfers/{id}/voucher', [\App\Http\Controllers\Api\PlatformBillingController::class, 'transferVoucher'])->whereUuid('id');
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
        Route::post('/admin/business-types/{business_type}/reset', [BusinessTypeController::class, 'reset']);
        Route::apiResource('tenant-contacts', TenantContactController::class);
    });
});

Route::middleware(['auth:sanctum', 'customer', 'idempotent'])->prefix('portal')->group(function () {
    Route::get('/overview', [PortalController::class, 'overview']);
    Route::get('/surveys', [\App\Http\Controllers\Api\PortalSurveyController::class, 'index']);
    Route::get('/privacy', [PortalController::class, 'privacy']);
    Route::post('/privacy/accept', [PortalController::class, 'acceptPrivacyNow']);
    Route::put('/profile', [PortalController::class, 'updateProfile']);
    Route::put('/consents', [PortalController::class, 'updateConsents']);
    Route::put('/password', [PortalController::class, 'updatePassword']);
    Route::post('/dependents', [PortalController::class, 'storeDependent']);
    Route::get('/packages', [PortalController::class, 'packages'])->middleware('module:packages|quotes');
    Route::get('/memberships', [PortalController::class, 'memberships'])->middleware('module:memberships');
    Route::get('/benefits', [PortalController::class, 'benefits']);
    Route::get('/purchases/{sale}', [PortalController::class, 'purchase']);

    Route::middleware('module:quotes')->group(function () {
        Route::get('/quotes', [PortalQuoteController::class, 'index']);
        Route::get('/quotes/{quote}/pdf', [PortalQuoteController::class, 'pdf']);
        Route::post('/quotes/{quote}/accept', [PortalQuoteController::class, 'accept'])->middleware('plan:portal');
        Route::post('/quotes/{quote}/reject', [PortalQuoteController::class, 'reject']);
    });

    Route::middleware('module:classes')->group(function () {
        Route::get('/classes', [\App\Http\Controllers\Api\PortalClassController::class, 'index']);
        Route::post('/classes/{class}/enroll', [\App\Http\Controllers\Api\PortalClassController::class, 'enroll'])->middleware('plan:portal');
        Route::post('/classes/{class}/leave', [\App\Http\Controllers\Api\PortalClassController::class, 'leave']);
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

    Route::get('/availability/days', [PortalController::class, 'portalDays'])->middleware('module:booking');
    Route::get('/dependents', [PortalController::class, 'dependentsWithControls']);
});

Route::prefix('kiosk')->middleware('throttle:60,1')->group(function () {
    Route::get('/{code}', [WalkinController::class, 'kioskInfo']);
    Route::get('/{code}/choices', [WalkinController::class, 'kioskChoices']);
    Route::post('/{code}/lookup', [WalkinController::class, 'kioskLookup']);
    Route::post('/{code}/queue', [WalkinController::class, 'kioskRegister'])->middleware('throttle:15,1');
});

Route::middleware(['auth:sanctum', 'staff', 'plan', 'idempotent'])->group(function () {
    Route::post('/subscriptions/subscribe', [SubscriptionController::class, 'subscribe']);
    Route::put('/account/password', [\App\Http\Controllers\Api\AccountPasswordController::class, 'update'])->middleware('throttle:10,1');
    Route::get('/account/devices', [\App\Http\Controllers\Api\AccountDeviceController::class, 'index']);
    Route::get('/account/profile', [\App\Http\Controllers\Api\AccountDeviceController::class, 'profile']);
    Route::delete('/account/devices/{device}', [\App\Http\Controllers\Api\AccountDeviceController::class, 'destroy'])->whereUuid('device');
    Route::get('/billing', [\App\Http\Controllers\Api\BillingController::class, 'show']);
    Route::post('/billing/card', [\App\Http\Controllers\Api\BillingController::class, 'card'])->middleware('throttle:20,1');
    Route::post('/billing/pay', [\App\Http\Controllers\Api\BillingController::class, 'pay'])->middleware('throttle:20,1');
    Route::post('/billing/activate', [\App\Http\Controllers\Api\BillingController::class, 'activate'])->middleware('throttle:20,1');
    Route::post('/billing/culqi', [\App\Http\Controllers\Api\BillingController::class, 'confirm'])->middleware('throttle:20,1');
    Route::post('/billing/plan', [\App\Http\Controllers\Api\BillingController::class, 'change'])->middleware('throttle:20,1');
    Route::post('/billing/plan/preview', [\App\Http\Controllers\Api\BillingController::class, 'preview'])->middleware('throttle:60,1');
    Route::post('/billing/plan/cancel', [\App\Http\Controllers\Api\BillingController::class, 'cancelPending'])->middleware('throttle:20,1');
    Route::post('/billing/addons/preview', [\App\Http\Controllers\Api\BillingController::class, 'addonPreview'])->middleware('throttle:60,1');
    Route::post('/billing/addons', [\App\Http\Controllers\Api\BillingController::class, 'addAddon'])->middleware('throttle:20,1');
    Route::delete('/billing/addons/{addon}', [\App\Http\Controllers\Api\BillingController::class, 'removeAddon'])->whereUuid('addon')->middleware('throttle:20,1');
    Route::post('/billing/addons/{addon}/keep', [\App\Http\Controllers\Api\BillingController::class, 'keepAddon'])->whereUuid('addon')->middleware('throttle:20,1');
    Route::put('/billing/details', [\App\Http\Controllers\Api\BillingController::class, 'details'])->middleware('throttle:20,1');
    Route::get('/billing/exports', [\App\Http\Controllers\Api\BillingController::class, 'exports']);
    Route::post('/billing/exports', [\App\Http\Controllers\Api\BillingController::class, 'requestExport'])->middleware('throttle:5,1');
    Route::get('/help/articles', [\App\Http\Controllers\Api\PlanOperationsController::class, 'publishedHelp']);
    Route::post('/billing/cancel-card', [\App\Http\Controllers\Api\BillingController::class, 'cancelCard']);
    Route::post('/billing/coupon', [\App\Http\Controllers\Api\BillingController::class, 'coupon'])->middleware('throttle:10,1');
    Route::post('/billing/cancel', [\App\Http\Controllers\Api\BillingController::class, 'requestCancel'])->middleware('throttle:10,1');
    Route::post('/billing/resume', [\App\Http\Controllers\Api\BillingController::class, 'resumeCancel'])->middleware('throttle:10,1');
    Route::get('/billing/invoices/{invoice}/receipt', [\App\Http\Controllers\Api\BillingController::class, 'receipt'])->whereUuid('invoice');
    Route::get('/billing/export',[\App\Http\Controllers\Api\BillingController::class, 'exportAll'])->middleware('throttle:5,1');
    Route::get('/billing/transfer', [\App\Http\Controllers\Api\BillingController::class, 'transferOptions']);
    Route::post('/billing/transfer', [\App\Http\Controllers\Api\BillingController::class, 'transfer'])->middleware('throttle:10,1');
    Route::get('/billing/transfers/{id}/voucher', [\App\Http\Controllers\Api\BillingController::class, 'transferVoucher'])->whereUuid('id');

    Route::get('/type-documents', [TypeDocumentController::class, 'index']);
    Route::get('/users', [UserController::class, 'index']);

    Route::get('/notifications', [\App\Http\Controllers\Api\NotificationController::class, 'index']);
    Route::post('/notifications/read-all', [\App\Http\Controllers\Api\NotificationController::class, 'readAll']);
    Route::post('/notifications/{notification}/read', [\App\Http\Controllers\Api\NotificationController::class, 'read']);

    Route::get('/support/tickets', [\App\Http\Controllers\Api\SupportController::class, 'index']);
    Route::post('/support/tickets', [\App\Http\Controllers\Api\SupportController::class, 'store'])->middleware('throttle:20,1');
    Route::get('/support/tickets/{ticket}', [\App\Http\Controllers\Api\SupportController::class, 'show'])->whereUuid('ticket');
    Route::post('/support/tickets/{ticket}/messages', [\App\Http\Controllers\Api\SupportController::class, 'reply'])->whereUuid('ticket')->middleware('throttle:30,1');
    Route::put('/support/tickets/{ticket}', [\App\Http\Controllers\Api\SupportController::class, 'update'])->whereUuid('ticket');
    Route::get('/support/tickets/{ticket}/files/{message}', [\App\Http\Controllers\Api\SupportController::class, 'file'])->whereUuid(['ticket', 'message']);
    Route::get('/support/access', [\App\Http\Controllers\Api\SupportController::class, 'access']);
    Route::get('/support/referral', [\App\Http\Controllers\Api\SupportController::class, 'referral']);
    Route::get('/announcements', [\App\Http\Controllers\Api\AnnouncementController::class, 'feed']);
    Route::post('/announcements/{announcement}/dismiss', [\App\Http\Controllers\Api\AnnouncementController::class, 'dismiss'])->whereUuid('announcement');

    Route::apiResource('statuses', StatusController::class)->except(['show']);

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

    Route::get('/search', [\App\Http\Controllers\Api\GlobalSearchController::class, 'search']);
    Route::get('/tenant/onboarding', [\App\Http\Controllers\Api\OnboardingController::class, 'show']);
    Route::post('/tenant/onboarding/hide', [\App\Http\Controllers\Api\OnboardingController::class, 'hide']);
    Route::get('/tenant/sample-data', [\App\Http\Controllers\Api\OnboardingController::class, 'sampleStatus'])->middleware('perm:settings.edit');
    Route::post('/tenant/sample-data', [\App\Http\Controllers\Api\OnboardingController::class, 'createSample'])->middleware('perm:settings.edit');
    Route::delete('/tenant/sample-data', [\App\Http\Controllers\Api\OnboardingController::class, 'removeSample'])->middleware('perm:settings.edit');
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

    Route::middleware('module:resources')->group(function () {
        Route::post('/rooms/{room}/maintenance', [RoomController::class, 'maintenance'])->middleware('perm:rooms.edit')->whereUuid('room');
    });

    Route::middleware('module:agenda')->group(function () {
        Route::put('/service-categories/{service_category}', [ServiceCategoryController::class, 'update'])->middleware('perm:services.edit')->whereUuid('service_category');
        Route::get('/team-overview', [\App\Http\Controllers\Api\TeamOverviewController::class, 'overview']);
        Route::get('/professionals/{professional}/performance', [\App\Http\Controllers\Api\TeamOverviewController::class, 'performance'])->whereUuid('professional');
    });

    Route::get('/reports', [ReportController::class, 'show'])->middleware('perm:reports.view|reports.own');
    Route::post('/reports/export', [ReportController::class, 'export'])->middleware(['perm:reports.view|reports.own', 'perm:data.export', 'throttle:10,1']);
    Route::get('/reports/exports', [ReportController::class, 'exports'])->middleware('perm:reports.view|reports.own');

    Route::middleware('module:automations')->group(function () {
        Route::get('/automations', [AutomationController::class, 'index'])->middleware('perm:automations.view');
        Route::put('/automations/{automation}', [AutomationController::class, 'update'])->middleware('perm:automations.edit');
    });

    Route::get('/surveys', [\App\Http\Controllers\Api\SurveyController::class, 'index'])->middleware('perm:surveys.view');
    Route::get('/surveys/settings', [\App\Http\Controllers\Api\SurveyController::class, 'settings'])->middleware('perm:surveys.view');
    Route::put('/surveys/settings', [\App\Http\Controllers\Api\SurveyController::class, 'saveSettings'])->middleware('perm:surveys.edit');
    Route::get('/surveys/{survey}', [\App\Http\Controllers\Api\SurveyController::class, 'show'])->middleware('perm:surveys.view')->whereUuid('survey');
    Route::put('/surveys/{survey}/recovery', [\App\Http\Controllers\Api\SurveyController::class, 'followUp'])->middleware('perm:surveys.edit')->whereUuid('survey');
    Route::put('/surveys/{survey}/publish', [\App\Http\Controllers\Api\SurveyController::class, 'publish'])->middleware('perm:surveys.edit')->whereUuid('survey');
    Route::put('/surveys/{survey}/reply', [\App\Http\Controllers\Api\SurveyController::class, 'reply'])->middleware('perm:surveys.edit')->whereUuid('survey');

    Route::middleware('module:walkin')->group(function () {
        Route::get('/walkins', [WalkinController::class, 'index'])->middleware('perm:walkin.view');
        Route::get('/walkins/choices', [WalkinController::class, 'choices'])->middleware('perm:walkin.view');
        Route::post('/walkins', [WalkinController::class, 'store'])->middleware('perm:walkin.create');
        Route::put('/walkins/{walkin}', [WalkinController::class, 'update'])->middleware('perm:walkin.edit');
        Route::get('/walkins/{walkin}/options', [WalkinController::class, 'options'])->middleware('perm:walkin.edit');
        Route::post('/walkins/{walkin}/serve', [WalkinController::class, 'serve'])->middleware('perm:walkin.edit');
        Route::post('/walkins/{walkin}/status', [WalkinController::class, 'changeStatus'])->middleware('perm:walkin.edit');
    });

    Route::middleware('module:classes')->group(function () {
        Route::get('/classes', [\App\Http\Controllers\Api\ClassSessionController::class, 'index'])->middleware('perm:classes.view');
        Route::post('/classes', [\App\Http\Controllers\Api\ClassSessionController::class, 'store'])->middleware('perm:classes.create');
        Route::get('/classes/{class}', [\App\Http\Controllers\Api\ClassSessionController::class, 'show'])->middleware('perm:classes.view');
        Route::put('/classes/{class}', [\App\Http\Controllers\Api\ClassSessionController::class, 'update'])->middleware('perm:classes.create');
        Route::post('/classes/{class}/cancel', [\App\Http\Controllers\Api\ClassSessionController::class, 'cancel'])->middleware('perm:classes.cancel');
        Route::post('/classes/{class}/enrollments', [\App\Http\Controllers\Api\ClassSessionController::class, 'enroll'])->middleware('perm:classes.edit');
        Route::post('/classes/{class}/attend-all', [\App\Http\Controllers\Api\ClassSessionController::class, 'attendAll'])->middleware('perm:classes.edit');
        Route::post('/class-enrollments/{enrollment}/leave', [\App\Http\Controllers\Api\ClassSessionController::class, 'leave'])->middleware('perm:classes.edit');
        Route::post('/class-enrollments/{enrollment}/promote', [\App\Http\Controllers\Api\ClassSessionController::class, 'promote'])->middleware('perm:classes.edit');
        Route::post('/class-enrollments/{enrollment}/attendance', [\App\Http\Controllers\Api\ClassSessionController::class, 'attendance'])->middleware('perm:classes.edit');
        Route::post('/class-enrollments/{enrollment}/charge', [\App\Http\Controllers\Api\ClassSessionController::class, 'charge'])->middleware('perm:classes.edit');
        Route::post('/class-enrollments/{enrollment}/package', [\App\Http\Controllers\Api\ClassSessionController::class, 'usePackage'])->middleware('perm:classes.edit');
    });

    Route::middleware('module:agenda')->group(function () use ($guarded) {
        $guarded('specialties', SpecialtyController::class, 'professionals');
        Route::get('/services/next-code', [ServiceController::class, 'nextCode'])->middleware('perm:services.create');
        $guarded('services', ServiceController::class, 'services', false, ['show']);
        Route::get('/services/{service}/impact', [ServiceController::class, 'impact'])->middleware('perm:services.edit|services.delete');
        $guarded('service-categories', ServiceCategoryController::class, 'services', false, ['show', 'update', 'destroy']);
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
        Route::get('/appointments/customer-notes', [AppointmentController::class, 'customerNotes'])->middleware('perm:agenda.create');

        Route::get('/appointments/customer-duplicates', [AppointmentController::class, 'customerDuplicates'])->middleware('perm:customers.create');
        Route::post('/appointments/customer-consent', [AppointmentController::class, 'customerConsent'])->middleware('perm:customers.create');
        Route::post('/appointments/{appointment}/waive-penalty', [AppointmentController::class, 'waivePenalty'])->middleware('perm:sales.delete')->whereUuid('appointment');

        Route::get('/dashboard', [\App\Http\Controllers\Api\DashboardController::class, 'show']);
        $guarded('appointments', AppointmentController::class, 'agenda', true, ['destroy']);
        Route::post('/appointments/bulk-status', [AppointmentController::class, 'bulkStatus'])->middleware('perm:agenda.confirm|agenda.cancel');
        Route::post('/appointments/{appointment}/status', [AppointmentController::class, 'changeStatus'])->middleware('perm:agenda.confirm|agenda.checkin|agenda.start|agenda.complete|agenda.noshow|agenda.cancel');
        Route::post('/appointments/{appointment}/remind', [AppointmentController::class, 'remind'])->middleware('perm:agenda.confirm');
        Route::post('/appointments/{appointment}/undo-no-show', [AppointmentController::class, 'undoNoShow'])->middleware('perm:agenda.noshow');
        Route::post('/appointments/{appointment}/reschedule', [AppointmentController::class, 'reschedule'])->middleware('perm:agenda.edit');
        Route::post('/appointments/{appointment}/room', [AppointmentController::class, 'changeRoom'])->middleware('perm:agenda.edit');
        Route::post('/appointments/series', [AppointmentController::class, 'series'])->middleware('perm:agenda.create');

        Route::get('/waitlist', [WaitlistController::class, 'index'])->middleware('perm:waitlist.view');
        Route::post('/waitlist', [WaitlistController::class, 'store'])->middleware('perm:waitlist.create');
        Route::put('/waitlist/{entry}', [WaitlistController::class, 'update'])->middleware('perm:waitlist.edit');
        Route::post('/waitlist/{entry}/status', [WaitlistController::class, 'changeStatus'])->middleware('perm:waitlist.edit');
        Route::get('/waitlist/{entry}/slots', [WaitlistController::class, 'slots'])->middleware('perm:waitlist.view');

        Route::get('/recalls', [RecallController::class, 'index'])->middleware('perm:recalls.view');
        Route::get('/recalls/summary', [RecallController::class, 'summary'])->middleware('perm:recalls.view');
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
        Route::post('/care-records/{careRecord}/files', [CareRecordController::class, 'uploadFile'])->middleware(['perm:records.edit', 'throttle:30,1']);
        Route::get('/care-records/{careRecord}/files/{recordFile}/link', [CareRecordController::class, 'fileLink'])->middleware('perm:records.view');
        Route::delete('/care-records/{careRecord}/files/{recordFile}', [CareRecordController::class, 'deleteFile'])->middleware('perm:records.edit');
        Route::get('/record-sheet-templates', [CareRecordController::class, 'sheetTemplates'])->middleware('perm:records.edit');
        Route::post('/record-sheets', [CareRecordController::class, 'storeLoose'])->middleware('perm:records.edit');
        Route::get('/record-sheets/{careRecord}', [CareRecordController::class, 'sheet'])->middleware('perm:records.view')->whereUuid('careRecord');
        Route::put('/record-sheets/{careRecord}', [CareRecordController::class, 'saveSheet'])->middleware('perm:records.edit')->whereUuid('careRecord');
    });

    Route::middleware('module:commissions')->group(function () {
        Route::get('/commissions', [\App\Http\Controllers\Api\CommissionController::class, 'index'])->middleware('perm:commissions.view');
        Route::post('/commissions/transition', [\App\Http\Controllers\Api\CommissionController::class, 'transition'])->middleware('perm:commissions.approve|commissions.pay');
        Route::post('/commissions/recalculate', [\App\Http\Controllers\Api\CommissionController::class, 'recalculate'])->middleware('perm:commissions.rules');
        Route::get('/commission-rules', [\App\Http\Controllers\Api\CommissionRuleController::class, 'index'])->middleware('perm:commissions.view');
        Route::get('/professionals/{professional}/commissions', [\App\Http\Controllers\Api\CommissionRuleController::class, 'professional'])->whereUuid('professional');
        Route::get('/commission-rules/preview',[\App\Http\Controllers\Api\CommissionRuleController::class, 'preview'])->middleware('perm:commissions.view');
        Route::post('/commission-rules', [\App\Http\Controllers\Api\CommissionRuleController::class, 'store'])->middleware('perm:commissions.rules');
        Route::put('/commission-rules/{rule}', [\App\Http\Controllers\Api\CommissionRuleController::class, 'update'])->middleware('perm:commissions.rules')->whereUuid('rule');
        Route::delete('/commission-rules/{rule}', [\App\Http\Controllers\Api\CommissionRuleController::class, 'destroy'])->middleware('perm:commissions.rules')->whereUuid('rule');
    });

    Route::middleware('module:pos')->group(function () use ($guarded) {
        Route::get('/cash', [CashController::class, 'index'])->middleware('perm:cash.view');
        Route::get('/cash/online', [CashController::class, 'online'])->middleware('perm:cash.view');
        Route::post('/cash/registers', [CashController::class, 'storeRegister'])->middleware('perm:cash.create');
        Route::put('/cash/registers/{register}', [CashController::class, 'updateRegister'])->middleware('perm:cash.create');
        Route::post('/cash/registers/{register}/open', [CashController::class, 'open'])->middleware('perm:cash.edit');
        Route::get('/cash/sessions/{session}', [CashController::class, 'show'])->middleware('perm:cash.view');
        Route::post('/cash/sessions/{session}/movements', [CashController::class, 'move'])->middleware('perm:cash.edit');
        Route::post('/cash/sessions/{session}/close', [CashController::class, 'close'])->middleware('perm:cash.edit');
        Route::post('/cash/sessions/{session}/review', [CashController::class, 'review'])->middleware('perm:cash.approve');

        Route::get('/appointments/{appointment}/sale', [SaleController::class, 'forAppointment'])->middleware('perm:sales.create|sales.view');
        Route::post('/appointments/{appointment}/charge', [SaleController::class, 'charge'])->middleware('perm:sales.create');
        Route::post('/appointments/{appointment}/invoice', [SaleController::class, 'issueInvoice'])->middleware('perm:sales.create');

        Route::get('/sales', [SalesController::class, 'index'])->middleware('perm:sales.view');
        Route::get('/sales/{sale}', [SalesController::class, 'show'])->middleware('perm:sales.view');
        Route::post('/sales/{sale}/void', [SalesController::class, 'void'])->middleware('perm:sales.delete');
        Route::get('/invoices', [\App\Http\Controllers\Api\InvoiceController::class, 'index'])->middleware('perm:sales.view');
        Route::post('/invoices/{invoice}/resend', [\App\Http\Controllers\Api\InvoiceController::class, 'resend'])->middleware('perm:sales.create');
        Route::post('/invoices/{invoice}/void', [\App\Http\Controllers\Api\InvoiceController::class, 'void'])->middleware('perm:sales.delete');
        Route::post('/sales/{sale}/refunds', [SalesController::class, 'refund'])->middleware('perm:sales.delete');
        Route::post('/refund-requests/{refundRequest}/approve', [SalesController::class, 'approveRefund'])->middleware('perm:sales.approve');
        Route::post('/refund-requests/{refundRequest}/reject', [SalesController::class, 'rejectRefund'])->middleware('perm:sales.approve');
        Route::get('/payments', [SalesController::class, 'payments'])->middleware('perm:payments.view');

        $guarded('coupons', CouponController::class, 'coupons', true, ['show']);
        Route::get('/pos', [PosController::class, 'board'])->middleware('perm:pos.view');
        Route::post('/pos/preview', [PosController::class, 'preview'])->middleware('perm:pos.view');
        Route::get('/pos/customers/{customer}/summary', [PosController::class, 'customerSummary'])->whereUuid('customer')->middleware('perm:pos.view');
        Route::post('/pos/discount-approval', [PosController::class, 'approveDiscount'])->middleware(['perm:pos.create|pos.edit', 'throttle:6,1']);
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
        Route::get('/integrations/invoicing', [\App\Http\Controllers\Api\InvoicingSettingsController::class, 'show']);
        Route::put('/integrations/invoicing', [\App\Http\Controllers\Api\InvoicingSettingsController::class, 'save']);
        Route::post('/integrations/invoicing/test', [\App\Http\Controllers\Api\InvoicingSettingsController::class, 'test']);
        Route::put('/integrations/invoicing/series', [\App\Http\Controllers\Api\InvoicingSettingsController::class, 'saveSeries']);
        Route::get('/integrations/transfer', [\App\Http\Controllers\Api\PaymentVoucherController::class, 'settings']);
        Route::put('/integrations/transfer', [\App\Http\Controllers\Api\PaymentVoucherController::class, 'saveSettings']);
        Route::get('/payment-vouchers', [\App\Http\Controllers\Api\PaymentVoucherController::class, 'index'])->middleware('perm:payments.review');
        Route::post('/payment-vouchers/{voucher}/approve', [\App\Http\Controllers\Api\PaymentVoucherController::class, 'approve'])->middleware('perm:payments.review')->whereUuid('voucher');
        Route::post('/payment-vouchers/{voucher}/reject', [\App\Http\Controllers\Api\PaymentVoucherController::class, 'reject'])->middleware('perm:payments.review')->whereUuid('voucher');
        Route::get('/payment-vouchers/{voucher}/file', [\App\Http\Controllers\Api\PaymentVoucherController::class, 'file'])->middleware('perm:payments.review')->whereUuid('voucher');
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
    Route::get('/messaging/whatsapp', [\App\Http\Controllers\Api\WhatsAppController::class, 'settings']);
    Route::put('/messaging/whatsapp', [\App\Http\Controllers\Api\WhatsAppController::class, 'save']);
    Route::delete('/messaging/whatsapp', [\App\Http\Controllers\Api\WhatsAppController::class, 'remove']);
    Route::post('/messaging/whatsapp/test', [\App\Http\Controllers\Api\WhatsAppController::class, 'test']);
    Route::put('/messaging/whatsapp/rules', [\App\Http\Controllers\Api\WhatsAppController::class, 'saveRules']);
    Route::get('/whatsapp/conversations', [\App\Http\Controllers\Api\WhatsAppController::class, 'conversations'])->middleware('perm:messages.view');
    Route::get('/whatsapp/conversations/{conversation}', [\App\Http\Controllers\Api\WhatsAppController::class, 'conversation'])->middleware('perm:messages.view')->whereUuid('conversation');
    Route::post('/whatsapp/conversations/{conversation}/reply', [\App\Http\Controllers\Api\WhatsAppController::class, 'reply'])->middleware(['perm:messages.reply', 'throttle:60,1'])->whereUuid('conversation');
    Route::post('/whatsapp/conversations/{conversation}/resolve', [\App\Http\Controllers\Api\WhatsAppController::class, 'resolve'])->middleware('perm:messages.reply')->whereUuid('conversation');
    Route::post('/whatsapp/conversations/{conversation}/file', [\App\Http\Controllers\Api\WhatsAppController::class, 'sendFile'])->middleware(['perm:messages.reply', 'throttle:30,1'])->whereUuid('conversation');
    Route::get('/whatsapp/messages/{message}/media', [\App\Http\Controllers\Api\WhatsAppController::class, 'media'])->middleware('perm:messages.view')->whereUuid('message');
    Route::get('/messaging/whatsapp/templates', [\App\Http\Controllers\Api\WhatsAppController::class, 'templates'])->middleware('throttle:20,1');
    Route::put('/messaging/assistant', [\App\Http\Controllers\Api\AssistantController::class, 'save']);
    Route::delete('/messaging/assistant', [\App\Http\Controllers\Api\AssistantController::class, 'remove']);
    Route::post('/messaging/assistant/test', [\App\Http\Controllers\Api\AssistantController::class, 'test'])->middleware('throttle:10,1');

    Route::post('/messaging/whatsapp/templates', [\App\Http\Controllers\Api\WhatsAppController::class, 'submitTemplate'])->middleware('throttle:20,1');
    Route::get('/assistant/board', [\App\Http\Controllers\Api\AssistantDeskController::class, 'board'])->middleware('perm:messages.view');
    Route::post('/assistant/simulate', [\App\Http\Controllers\Api\AssistantDeskController::class, 'simulate'])->middleware('throttle:30,1');
    Route::get('/integrations/payment-webhooks', [\App\Http\Controllers\Api\GatewayWebhookController::class, 'index']);
    Route::post('/integrations/payment-webhooks/{webhook}/retry', [\App\Http\Controllers\Api\GatewayWebhookController::class, 'retry'])->whereUuid('webhook')->middleware('throttle:30,1');

    Route::get('/booking-page', [\App\Http\Controllers\Api\BookingPageController::class, 'show']);
    Route::put('/booking-page', [\App\Http\Controllers\Api\BookingPageController::class, 'save']);
    Route::post('/booking-page/cover', [\App\Http\Controllers\Api\BookingPageController::class, 'uploadCover']);
    Route::delete('/booking-page/cover', [\App\Http\Controllers\Api\BookingPageController::class, 'removeCover']);
    Route::put('/booking-page/publish', [\App\Http\Controllers\Api\BookingPageController::class, 'publish'])->middleware('throttle:20,1');
    Route::put('/booking-page/slug', [\App\Http\Controllers\Api\BookingPageController::class, 'slug'])->middleware('throttle:10,1');
    Route::get('/link-page', [\App\Http\Controllers\Api\LinkPageController::class, 'show']);
    Route::put('/link-page', [\App\Http\Controllers\Api\LinkPageController::class, 'save']);
    Route::put('/link-page/channels', [\App\Http\Controllers\Api\LinkPageController::class, 'saveChannels']);
    Route::get('/link-page/google-feed', [\App\Http\Controllers\Api\LinkPageController::class, 'googleFeed']);
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
        Route::get('/campaigns/segments', [CampaignController::class, 'segments'])->middleware('perm:campaigns.view');
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

    Route::middleware('module:memberships')->group(function () {
        Route::get('/memberships', [MembershipController::class, 'index'])->middleware('perm:memberships.view');
        Route::get('/memberships/{membership}', [MembershipController::class, 'show'])->middleware('perm:memberships.view');
        Route::post('/memberships/{membership}/pause', [MembershipController::class, 'pause'])->middleware('perm:memberships.edit');
        Route::post('/memberships/{membership}/resume', [MembershipController::class, 'resume'])->middleware('perm:memberships.edit');
        Route::post('/memberships/{membership}/cancel', [MembershipController::class, 'cancel'])->middleware('perm:memberships.delete');
        Route::post('/membership-plans', [MembershipController::class, 'storePlan'])->middleware('perm:memberships.create');
        Route::put('/membership-plans/{plan}', [MembershipController::class, 'updatePlan'])->middleware('perm:memberships.edit');
        Route::delete('/membership-plans/{plan}', [MembershipController::class, 'destroyPlan'])->middleware('perm:memberships.delete');
    });

    Route::middleware('module:packages|quotes')->group(function () {
        Route::post('/appointments/{appointment}/package', [SaleController::class, 'usePackage'])->middleware('perm:sales.create');
        Route::delete('/appointments/{appointment}/package', [SaleController::class, 'releasePackage'])->middleware('perm:sales.create');
    });

    Route::middleware('module:inventory')->group(function () use ($guarded) {
        $guarded('products', ProductController::class, 'products', true, ['show']);
        Route::post('/product-categories', [ProductController::class, 'storeCategory'])->middleware('perm:products.create');
        Route::get('/products/{product}', [ProductController::class, 'show'])->whereUuid('product')->middleware('perm:products.view');
        Route::post('/product-brands', [ProductController::class, 'storeBrand'])->middleware('perm:products.create');
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
        Route::post('/loyalty/referrals', [LoyaltyController::class, 'storeReferral'])->middleware('perm:loyalty.create');
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
        Route::get('/customers/import/template', [\App\Http\Controllers\Api\CustomerImportController::class, 'template'])->middleware('perm:customers.import');
        Route::post('/customers/import/preview', [\App\Http\Controllers\Api\CustomerImportController::class, 'preview'])->middleware('perm:customers.import');
        Route::post('/customers/import', [\App\Http\Controllers\Api\CustomerImportController::class, 'store'])->middleware('perm:customers.import');
        Route::get('/customer-tags', [\App\Http\Controllers\Api\CustomerProfileController::class, 'tags'])->middleware('perm:customers.view');
        Route::post('/customer-tags', [\App\Http\Controllers\Api\CustomerProfileController::class, 'storeTag'])->middleware('perm:customers.edit');
        Route::put('/customer-tags/{tag}', [\App\Http\Controllers\Api\CustomerProfileController::class, 'updateTag'])->middleware('perm:customers.edit');
        Route::delete('/customer-tags/{tag}', [\App\Http\Controllers\Api\CustomerProfileController::class, 'destroyTag'])->middleware('perm:customers.edit');
        Route::get('/customers/{customer}/profile', [\App\Http\Controllers\Api\CustomerProfileController::class, 'show'])->middleware('perm:customers.view');
        Route::put('/customers/{customer}/tags', [\App\Http\Controllers\Api\CustomerProfileController::class, 'assignTags'])->middleware('perm:customers.edit');
        Route::get('/customers/{customer}/notes', [\App\Http\Controllers\Api\CustomerProfileController::class, 'notes'])->middleware('perm:customers.view');
        Route::post('/customers/{customer}/notes', [\App\Http\Controllers\Api\CustomerProfileController::class, 'storeNote'])->middleware('perm:customers.edit|customers.notes');
        Route::delete('/customers/{customer}/notes/{note}', [\App\Http\Controllers\Api\CustomerProfileController::class, 'destroyNote'])->middleware('perm:customers.edit|customers.notes');
        Route::get('/customers/segments', [CustomerController::class, 'segments'])->middleware('perm:customers.view');
        Route::get('/customers/{customer}/channels', [MessageController::class, 'channels'])->middleware('perm:messages.create')->whereUuid('customer');
        Route::post('/customers/{customer}/calls', [MessageController::class, 'logCall'])->middleware('perm:messages.create|customers.edit')->whereUuid('customer');
        $guarded('customers', CustomerController::class, 'customers', true);
        Route::put('/customers/{customer}/portal-access', [CustomerController::class, 'portalAccess'])->middleware('perm:customers.edit');
        Route::post('/customers/{customer}/merge-preview', [CustomerController::class, 'mergePreview'])->middleware('perm:customers.delete');
        Route::post('/customers/{customer}/merge', [CustomerController::class, 'merge'])->middleware('perm:customers.delete');
        Route::post('/customers/{customer}/anonymize', [CustomerController::class, 'anonymize'])->middleware('perm:customers.delete');
        Route::post('/customers/{customer}/portal-access/reset', [CustomerController::class, 'resetPortalPassword'])->middleware('perm:customers.edit');
        Route::post('/customers/{customer}/booking-strikes/forgive', [CustomerController::class, 'forgiveBooking'])->middleware('perm:customers.forgive');
        Route::apiResource('customers.dependents', CustomerDependentController::class)->only(['index'])->middleware('perm:customers.view');
        Route::apiResource('customers.dependents', CustomerDependentController::class)->only(['store', 'update', 'destroy'])->middleware('perm:customers.edit|customers.create');
    });

    Route::get('/company', [\App\Http\Controllers\Api\CompanyController::class, 'show']);
    Route::put('/company', [\App\Http\Controllers\Api\CompanyController::class, 'update'])->middleware('perm:company.edit');
    Route::get('/company/catalog-import', [\App\Http\Controllers\Api\CompanyController::class, 'catalogPreview'])->middleware('perm:company.edit');
    Route::post('/company/catalog-import', [\App\Http\Controllers\Api\CompanyController::class, 'importCatalog'])->middleware(['perm:company.edit', 'throttle:10,1']);

    Route::get('/tenant-settings', [TenantSettingController::class, 'show'])->middleware('perm:settings.view');
    Route::get('/audit', [\App\Http\Controllers\Api\AuditController::class, 'tenant'])->middleware('perm:audit.view');
    Route::get('/complaints', [\App\Http\Controllers\Api\ComplaintController::class, 'index'])->middleware('perm:complaints.view');
    Route::get('/complaints/{complaint}', [\App\Http\Controllers\Api\ComplaintController::class, 'show'])->middleware('perm:complaints.view');
    Route::put('/complaints/{complaint}', [\App\Http\Controllers\Api\ComplaintController::class, 'answer'])->middleware('perm:complaints.answer');

    Route::get('/currency', [CurrencyController::class, 'show'])->middleware('perm:settings.view');
    Route::middleware('perm:settings.edit')->group(function () {
        Route::put('/currency', [CurrencyController::class, 'update']);
        Route::post('/currency/records/preview', [CurrencyController::class, 'preview']);
        Route::post('/currency/records', [CurrencyController::class, 'apply']);
    });
    Route::put('/tenant-settings', [TenantSettingController::class, 'update'])->middleware('perm:settings.edit');

    Route::middleware('module:api')->prefix('integrations/api')->group(function () {
        Route::middleware('perm:integrations.view')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\ApiIntegrationController::class, 'show']);
            Route::get('/webhooks/{webhook}/deliveries', [\App\Http\Controllers\Api\ApiIntegrationController::class, 'deliveries'])->whereUuid('webhook');
            Route::get('/calendars', [\App\Http\Controllers\Api\ApiIntegrationController::class, 'feeds']);
        });

        Route::middleware('perm:integrations.edit')->group(function () {
            Route::post('/keys', [\App\Http\Controllers\Api\ApiIntegrationController::class, 'storeKey']);
            Route::post('/keys/{key}/revoke', [\App\Http\Controllers\Api\ApiIntegrationController::class, 'revokeKey'])->whereUuid('key');
            Route::post('/webhooks', [\App\Http\Controllers\Api\ApiIntegrationController::class, 'storeWebhook']);
            Route::put('/webhooks/{webhook}', [\App\Http\Controllers\Api\ApiIntegrationController::class, 'updateWebhook'])->whereUuid('webhook');
            Route::delete('/webhooks/{webhook}', [\App\Http\Controllers\Api\ApiIntegrationController::class, 'destroyWebhook'])->whereUuid('webhook');
            Route::post('/webhooks/{webhook}/secret', [\App\Http\Controllers\Api\ApiIntegrationController::class, 'rotateSecret'])->whereUuid('webhook');
            Route::post('/webhooks/{webhook}/test', [\App\Http\Controllers\Api\ApiIntegrationController::class, 'testWebhook'])->whereUuid('webhook')->middleware('throttle:20,1');
            Route::post('/webhooks/{webhook}/deliveries/{delivery}/resend', [\App\Http\Controllers\Api\ApiIntegrationController::class, 'resend'])->whereUuid(['webhook', 'delivery'])->middleware('throttle:30,1');
            Route::post('/calendars', [\App\Http\Controllers\Api\ApiIntegrationController::class, 'storeFeed']);
            Route::put('/calendars/{feed}', [\App\Http\Controllers\Api\ApiIntegrationController::class, 'updateFeed'])->whereUuid('feed');
            Route::delete('/calendars/{feed}', [\App\Http\Controllers\Api\ApiIntegrationController::class, 'destroyFeed'])->whereUuid('feed');
        });
    });

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