<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Meridian API Routes — /api/v1
|--------------------------------------------------------------------------
| All routes are prefixed with /api/v1 via the bootstrap/app.php apiPrefix.
|
| Route groups will be populated in Prompt 3 (authentication, RBAC middleware)
| and subsequent prompts as controllers are implemented.
|
| Convention:
|   - All mutating endpoints (POST/PUT/PATCH/DELETE) require X-Idempotency-Key
|   - All endpoints except /auth/login require Bearer token authentication
|   - Resource authorization is enforced via Laravel Policies (not inline)
|
| Ports: App → 8000 | MySQL → 3306
*/

// ---------------------------------------------------------------------------
// Public routes — no authentication required
// ---------------------------------------------------------------------------
Route::post('/auth/login', [\App\Http\Controllers\Api\AuthController::class, 'login'])
    ->name('auth.login');

// LAN share link resolution — token is the credential, no Bearer token needed
Route::get('/links/{token}', [\App\Http\Controllers\Api\AttachmentLinkController::class, 'resolve'])
    ->middleware('throttle:public-links')
    ->name('links.resolve');

// ---------------------------------------------------------------------------
// Authenticated routes — require valid Sanctum Bearer token
// ---------------------------------------------------------------------------
Route::middleware(['auth:sanctum', 'idempotency', 'mask.sensitive', 'record.timing'])->group(function () {

    // Auth
    Route::post('/auth/logout', [\App\Http\Controllers\Api\AuthController::class, 'logout'])
        ->name('auth.logout');
    Route::get('/auth/me', [\App\Http\Controllers\Api\AuthController::class, 'me'])
        ->name('auth.me');

    // Roles and Departments
    Route::get('/roles', [\App\Http\Controllers\Api\RoleController::class, 'index'])
        ->name('roles.index');
    Route::apiResource('/departments', \App\Http\Controllers\Api\DepartmentController::class);

    // Documents and Versions
    Route::apiResource('/documents', \App\Http\Controllers\Api\DocumentController::class);
    Route::post('/documents/{document}/archive', [\App\Http\Controllers\Api\DocumentController::class, 'archive'])
        ->name('documents.archive');
    Route::get('/documents/{document}/versions', [\App\Http\Controllers\Api\DocumentVersionController::class, 'index'])
        ->name('documents.versions.index');
    Route::post('/documents/{document}/versions', [\App\Http\Controllers\Api\DocumentVersionController::class, 'store'])
        ->name('documents.versions.store');
    Route::get('/documents/{document}/versions/{versionId}', [\App\Http\Controllers\Api\DocumentVersionController::class, 'show'])
        ->name('documents.versions.show');
    Route::get('/documents/{document}/versions/{versionId}/download', [\App\Http\Controllers\Api\DocumentVersionController::class, 'download'])
        ->name('documents.versions.download');

    // Attachments and Evidence
    Route::post('/records/{type}/{id}/attachments', [\App\Http\Controllers\Api\AttachmentController::class, 'store'])
        ->name('attachments.store');
    Route::get('/records/{type}/{id}/attachments', [\App\Http\Controllers\Api\AttachmentController::class, 'index'])
        ->name('attachments.index');
    Route::get('/attachments/{attachment}', [\App\Http\Controllers\Api\AttachmentController::class, 'show'])
        ->name('attachments.show');
    Route::delete('/attachments/{attachment}', [\App\Http\Controllers\Api\AttachmentController::class, 'destroy'])
        ->name('attachments.destroy');
    Route::post('/attachments/{attachment}/links', [\App\Http\Controllers\Api\AttachmentLinkController::class, 'store'])
        ->name('attachment-links.store');

    // Configuration Center
    Route::apiResource('/configuration/sets', \App\Http\Controllers\Api\ConfigurationSetController::class);
    Route::get('/configuration/sets/{set}/versions', [\App\Http\Controllers\Api\ConfigurationVersionController::class, 'index'])
        ->name('configuration.versions.index');
    Route::post('/configuration/sets/{set}/versions', [\App\Http\Controllers\Api\ConfigurationVersionController::class, 'store'])
        ->name('configuration.versions.store');
    Route::get('/configuration/versions/{version}', [\App\Http\Controllers\Api\ConfigurationVersionController::class, 'show'])
        ->name('configuration.versions.show');
    Route::post('/configuration/versions/{version}/rollout', [\App\Http\Controllers\Api\ConfigurationVersionController::class, 'rollout'])
        ->name('configuration.versions.rollout');
    Route::post('/configuration/versions/{version}/promote', [\App\Http\Controllers\Api\ConfigurationVersionController::class, 'promote'])
        ->name('configuration.versions.promote');
    Route::post('/configuration/versions/{version}/rollback', [\App\Http\Controllers\Api\ConfigurationVersionController::class, 'rollback'])
        ->name('configuration.versions.rollback');

    // Workflow Templates and Instances
    Route::apiResource('/workflow/templates', \App\Http\Controllers\Api\WorkflowTemplateController::class);
    Route::post('/workflow/instances', [\App\Http\Controllers\Api\WorkflowInstanceController::class, 'store'])
        ->name('workflow.instances.store');
    Route::get('/workflow/instances/{instance}', [\App\Http\Controllers\Api\WorkflowInstanceController::class, 'show'])
        ->name('workflow.instances.show');
    Route::post('/workflow/instances/{instance}/withdraw', [\App\Http\Controllers\Api\WorkflowInstanceController::class, 'withdraw'])
        ->name('workflow.instances.withdraw');
    Route::get('/workflow/nodes/{node}', [\App\Http\Controllers\Api\WorkflowNodeController::class, 'show'])
        ->name('workflow.nodes.show');
    Route::post('/workflow/nodes/{node}/approve', [\App\Http\Controllers\Api\WorkflowNodeController::class, 'approve'])
        ->name('workflow.nodes.approve');
    Route::post('/workflow/nodes/{node}/reject', [\App\Http\Controllers\Api\WorkflowNodeController::class, 'reject'])
        ->name('workflow.nodes.reject');
    Route::post('/workflow/nodes/{node}/reassign', [\App\Http\Controllers\Api\WorkflowNodeController::class, 'reassign'])
        ->name('workflow.nodes.reassign');
    Route::post('/workflow/nodes/{node}/add-approver', [\App\Http\Controllers\Api\WorkflowNodeController::class, 'addApprover'])
        ->name('workflow.nodes.add-approver');

    // To-Do Queue
    Route::get('/todo', [\App\Http\Controllers\Api\TodoController::class, 'index'])
        ->name('todo.index');
    Route::post('/todo/{item}/complete', [\App\Http\Controllers\Api\TodoController::class, 'complete'])
        ->name('todo.complete');

    // Sales Documents and Returns
    Route::apiResource('/sales', \App\Http\Controllers\Api\SalesDocumentController::class);
    Route::post('/sales/{document}/submit', [\App\Http\Controllers\Api\SalesDocumentController::class, 'submit'])
        ->name('sales.submit');
    Route::post('/sales/{document}/complete', [\App\Http\Controllers\Api\SalesDocumentController::class, 'complete'])
        ->name('sales.complete');
    Route::post('/sales/{document}/void', [\App\Http\Controllers\Api\SalesDocumentController::class, 'void'])
        ->name('sales.void');
    Route::post('/sales/{document}/link-outbound', [\App\Http\Controllers\Api\SalesDocumentController::class, 'linkOutbound'])
        ->name('sales.link-outbound');
    Route::post('/sales/{document}/returns', [\App\Http\Controllers\Api\ReturnController::class, 'store'])
        ->name('returns.store');
    Route::get('/sales/{document}/returns', [\App\Http\Controllers\Api\ReturnController::class, 'index'])
        ->name('returns.index');
    Route::post('/sales/{document}/exchanges', [\App\Http\Controllers\Api\ReturnController::class, 'storeExchange'])
        ->name('exchanges.store');
    Route::get('/sales/{document}/exchanges', [\App\Http\Controllers\Api\ReturnController::class, 'indexExchanges'])
        ->name('exchanges.index');
    Route::get('/returns/{return}', [\App\Http\Controllers\Api\ReturnController::class, 'show'])
        ->name('returns.show');
    Route::put('/returns/{return}', [\App\Http\Controllers\Api\ReturnController::class, 'update'])
        ->name('returns.update');
    Route::post('/returns/{return}/complete', [\App\Http\Controllers\Api\ReturnController::class, 'complete'])
        ->name('returns.complete');
    Route::post('/exchanges/{return}/complete', [\App\Http\Controllers\Api\ReturnController::class, 'completeExchange'])
        ->name('exchanges.complete');

    // Audit Events
    Route::get('/audit/events', [\App\Http\Controllers\Api\AuditEventController::class, 'index'])
        ->name('audit.events.index');
    Route::get('/audit/events/{event}', [\App\Http\Controllers\Api\AuditEventController::class, 'show'])
        ->name('audit.events.show');

    // Admin — Backups, Metrics, Health, Logs, Security
    Route::prefix('/admin')->name('admin.')->group(function () {
        // Backup history and manual trigger
        Route::get('/backups', [\App\Http\Controllers\Api\Admin\BackupController::class, 'index'])
            ->name('backups.index');
        Route::post('/backups', [\App\Http\Controllers\Api\Admin\BackupController::class, 'store'])
            ->name('backups.store');

        // Metrics snapshot retrieval (raw + summary)
        Route::get('/metrics', [\App\Http\Controllers\Api\Admin\MetricsController::class, 'index'])
            ->name('metrics.index');

        // Local operational health (DB, queue, storage, last backup)
        Route::get('/health', [\App\Http\Controllers\Api\Admin\HealthController::class, 'index'])
            ->name('health.index');

        // Structured log browsing
        Route::get('/logs', [\App\Http\Controllers\Api\Admin\LogController::class, 'index'])
            ->name('logs.index');

        // Security — failed login inspection and locked account list
        Route::get('/failed-logins', [\App\Http\Controllers\Api\Admin\FailedLoginController::class, 'index'])
            ->name('failed-logins.index');
        Route::get('/locked-accounts', [\App\Http\Controllers\Api\Admin\FailedLoginController::class, 'lockedAccounts'])
            ->name('locked-accounts.index');

        // Approval backlog / to-do queue state for operational oversight
        Route::get('/approval-backlog', [\App\Http\Controllers\Api\Admin\ApprovalBacklogController::class, 'index'])
            ->name('approval-backlog.index');

        // Configuration promotion history (filtered audit view)
        Route::get('/config-promotions', [\App\Http\Controllers\Api\AuditEventController::class, 'configPromotions'])
            ->name('config-promotions.index');

        // User management — create users with enforced PasswordPolicy complexity
        Route::post('/users', [\App\Http\Controllers\Api\Admin\AdminUserController::class, 'store'])
            ->name('admin.users.store');
        Route::put('/users/{user}/password', [\App\Http\Controllers\Api\Admin\AdminUserController::class, 'updatePassword'])
            ->name('admin.users.updatePassword');
    });
});
