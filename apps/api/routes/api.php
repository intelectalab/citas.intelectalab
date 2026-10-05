<?php

use App\Http\Controllers\Auth\LoginController;
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
use App\Http\Controllers\Api\StatusController;
use App\Http\Controllers\Api\SpecialtyController;
use App\Http\Controllers\Api\PatientController;
use App\Http\Controllers\Api\PipelineController;
use App\Http\Controllers\Api\PipelineStageController;

use App\Models\SaaSPlan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [LoginController::class, 'login']);
Route::post('/register-tenant', [RegisterTenantController::class, 'register']);

Route::get('/plans', function () {
    return SaaSPlan::where('status', 'active')->get();
});

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'user']);
    Route::post('/logout', [LoginController::class, 'logout']);
    Route::post('/subscriptions/subscribe', [SubscriptionController::class, 'subscribe']);

    Route::get('/type-documents', [TypeDocumentController::class, 'index']);
    Route::get('/pipeline-stages', [PipelineStageController::class, 'index']);
    Route::get('/users', [UserController::class, 'index']);

    Route::apiResource('specialties', SpecialtyController::class);
    Route::apiResource('statuses', StatusController::class);
    Route::apiResource('services', ServiceController::class);
    Route::apiResource('service-categories', ServiceCategoryController::class);
    Route::apiResource('tenants', TenantController::class);
    Route::apiResource('saas-plans', SaaSPlanController::class);
    Route::apiResource('professionals', ProfessionalController::class);
    Route::apiResource('patients', PatientController::class);
    Route::apiResource('pipelines', PipelineController::class);

    Route::prefix('contacts')->group(function () {
        Route::get('/', [ContactController::class, 'index']);
        Route::post('/', [ContactController::class, 'store']);
        Route::put('/{id}', [ContactController::class, 'update']);
        Route::delete('/{id}', [ContactController::class, 'destroy']);
    });

    Route::apiResource('tenant-contacts', TenantContactController::class);
    


});