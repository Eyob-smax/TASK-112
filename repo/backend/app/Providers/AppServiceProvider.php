<?php

namespace App\Providers;

use App\Application\Attachment\AttachmentService;
use App\Application\Auth\AuthenticationService;
use App\Application\Backup\BackupMetadataService;
use App\Application\Configuration\ConfigurationService;
use App\Application\Document\DocumentService;
use App\Application\Idempotency\Contracts\IdempotencyServiceInterface;
use App\Application\Idempotency\IdempotencyService;
use App\Application\Logging\StructuredLogger;
use App\Application\Metrics\MetricsRetentionService;
use App\Application\Sales\ReturnService;
use App\Application\Sales\SalesDocumentService;
use App\Application\Todo\TodoService;
use App\Application\Workflow\WorkflowService;
use App\Domain\Attachment\Contracts\AttachmentRepositoryInterface;
use App\Domain\Audit\Contracts\AuditEventRepositoryInterface;
use App\Domain\Auth\Contracts\UserRepositoryInterface;
use App\Domain\Document\Contracts\DocumentRepositoryInterface;
use App\Infrastructure\Persistence\EloquentAttachmentRepository;
use App\Infrastructure\Persistence\EloquentAuditEventRepository;
use App\Infrastructure\Persistence\EloquentConfigurationRepository;
use App\Infrastructure\Persistence\EloquentDocumentRepository;
use App\Infrastructure\Persistence\EloquentSalesRepository;
use App\Infrastructure\Persistence\EloquentUserRepository;
use App\Infrastructure\Persistence\EloquentWorkflowRepository;
use App\Infrastructure\Security\EncryptionService;
use App\Infrastructure\Security\ExpiryEvaluator;
use App\Infrastructure\Security\FingerprintService;
use App\Infrastructure\Security\PdfWatermarkService;
use App\Infrastructure\Security\WatermarkEventService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register domain service bindings and infrastructure adapters.
     */
    public function register(): void
    {
        // ---------------------------------------------------------------
        // Domain repository interfaces → Eloquent implementations
        // ---------------------------------------------------------------
        $this->app->bind(UserRepositoryInterface::class, EloquentUserRepository::class);
        $this->app->bind(AuditEventRepositoryInterface::class, EloquentAuditEventRepository::class);

        // ---------------------------------------------------------------
        // Idempotency
        // ---------------------------------------------------------------
        $this->app->bind(IdempotencyServiceInterface::class, IdempotencyService::class);

        // ---------------------------------------------------------------
        // Application services (singletons — stateless, safe to share)
        // ---------------------------------------------------------------
        $this->app->singleton(AuthenticationService::class);
        $this->app->singleton(StructuredLogger::class);
        $this->app->singleton(BackupMetadataService::class);
        $this->app->singleton(MetricsRetentionService::class);
        $this->app->singleton(TodoService::class);

        // ---------------------------------------------------------------
        // Document and Attachment domain repositories
        // ---------------------------------------------------------------
        $this->app->bind(DocumentRepositoryInterface::class, EloquentDocumentRepository::class);
        $this->app->bind(AttachmentRepositoryInterface::class, EloquentAttachmentRepository::class);

        // ---------------------------------------------------------------
        // Document and Attachment application services
        // ---------------------------------------------------------------
        $this->app->singleton(DocumentService::class);
        $this->app->singleton(AttachmentService::class);

        // ---------------------------------------------------------------
        // Configuration Center
        // ---------------------------------------------------------------
        $this->app->singleton(EloquentConfigurationRepository::class);
        $this->app->singleton(ConfigurationService::class);

        // ---------------------------------------------------------------
        // Workflow Engine
        // ---------------------------------------------------------------
        $this->app->singleton(EloquentWorkflowRepository::class);
        $this->app->singleton(WorkflowService::class);

        // ---------------------------------------------------------------
        // Sales and Returns
        // ---------------------------------------------------------------
        $this->app->singleton(EloquentSalesRepository::class);
        $this->app->singleton(SalesDocumentService::class);
        $this->app->singleton(ReturnService::class);

        // ---------------------------------------------------------------
        // Infrastructure security (singletons — read config once)
        // ---------------------------------------------------------------
        $this->app->singleton(EncryptionService::class);
        $this->app->singleton(FingerprintService::class);
        $this->app->singleton(PdfWatermarkService::class);
        $this->app->singleton(WatermarkEventService::class);
        $this->app->singleton(ExpiryEvaluator::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('public-links', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip() ?: 'unknown');
        });
    }
}
