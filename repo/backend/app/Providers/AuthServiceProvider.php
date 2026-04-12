<?php

namespace App\Providers;

use App\Models\Attachment;
use App\Models\AuditEvent;
use App\Models\ConfigurationSet;
use App\Models\Department;
use App\Models\Document;
use App\Models\ReturnRecord;
use App\Models\SalesDocument;
use App\Models\WorkflowInstance;
use App\Models\WorkflowTemplate;
use App\Policies\AttachmentPolicy;
use App\Policies\AuditEventPolicy;
use App\Policies\ConfigurationSetPolicy;
use App\Policies\DepartmentPolicy;
use App\Policies\DocumentPolicy;
use App\Policies\ReturnRecordPolicy;
use App\Policies\SalesDocumentPolicy;
use App\Policies\WorkflowInstancePolicy;
use App\Policies\WorkflowTemplatePolicy;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The model to policy mappings for the application.
     */
    protected $policies = [
        Document::class         => DocumentPolicy::class,
        Attachment::class       => AttachmentPolicy::class,
        Department::class       => DepartmentPolicy::class,
        ConfigurationSet::class => ConfigurationSetPolicy::class,
        ReturnRecord::class     => ReturnRecordPolicy::class,
        WorkflowInstance::class => WorkflowInstancePolicy::class,
        WorkflowTemplate::class => WorkflowTemplatePolicy::class,
        SalesDocument::class    => SalesDocumentPolicy::class,
        AuditEvent::class       => AuditEventPolicy::class,
    ];

    /**
     * Register any authentication / authorization services.
     */
    public function boot(): void
    {
        $this->registerPolicies();
    }
}
