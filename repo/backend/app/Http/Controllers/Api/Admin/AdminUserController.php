<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Audit\Contracts\AuditEventRepositoryInterface;
use App\Domain\Audit\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreAdminUserRequest;
use App\Http\Requests\Admin\UpdateUserPasswordRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Admin-only user management endpoints.
 *
 * Authorization: admin role only (enforced via FormRequest::authorize()).
 *
 * Password complexity is enforced by StoreAdminUserRequest and
 * UpdateUserPasswordRequest via PasswordPolicy::violations().
 */
class AdminUserController extends Controller
{
    public function __construct(
        private readonly AuditEventRepositoryInterface $audit,
    ) {}

    /**
     * POST /api/v1/admin/users
     *
     * Create a new user account. Password must satisfy PasswordPolicy requirements.
     */
    public function store(StoreAdminUserRequest $request): JsonResponse
    {
        $user = User::create([
            'username'      => $request->validated('username'),
            'display_name'  => $request->validated('display_name'),
            'email'         => $request->validated('email'),
            'password_hash' => Hash::make($request->validated('password')),
            'department_id' => $request->validated('department_id'),
            'is_active'     => true,
        ]);

        $user->assignRole($request->validated('role'));

        $this->recordAudit(
            action: AuditAction::Create,
            actorId: $request->user()->id,
            auditableId: $user->id,
            ipAddress: $request->ip(),
            afterHash: hash('sha256', json_encode($user->toArray())),
        );

        return response()->json(['data' => $this->userShape($user)], 201);
    }

    /**
     * PUT /api/v1/admin/users/{user}/password
     *
     * Reset a user's password. New password must satisfy PasswordPolicy requirements.
     */
    public function updatePassword(UpdateUserPasswordRequest $request, User $user): JsonResponse
    {
        $beforeHash = hash('sha256', json_encode($user->toArray()));

        $user->update([
            'password_hash' => Hash::make($request->validated('password')),
        ]);

        $this->recordAudit(
            action: AuditAction::PasswordChange,
            actorId: $request->user()->id,
            auditableId: $user->id,
            ipAddress: $request->ip(),
            beforeHash: $beforeHash,
            afterHash: hash('sha256', json_encode($user->fresh()->toArray())),
        );

        return response()->json(['data' => ['message' => 'Password updated successfully.']]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function userShape(User $user): array
    {
        return [
            'id'            => $user->id,
            'username'      => $user->username,
            'display_name'  => $user->display_name,
            'email'         => $user->email,
            'department_id' => $user->department_id,
            'is_active'     => $user->is_active,
        ];
    }

    private function recordAudit(
        AuditAction $action,
        ?string $actorId,
        string $auditableId,
        string $ipAddress,
        array $payload = [],
        ?string $beforeHash = null,
        ?string $afterHash = null,
    ): void {
        $idempotencyKey = request()->header('X-Idempotency-Key');
        $correlationId  = $idempotencyKey !== null
            ? hash('sha256', $idempotencyKey . ':' . $auditableId . ':' . $action->value)
            : (string) Str::uuid();

        $this->audit->record(
            correlationId: $correlationId,
            action: $action,
            actorId: $actorId,
            auditableType: User::class,
            auditableId: $auditableId,
            beforeHash: $beforeHash,
            afterHash: $afterHash,
            payload: $payload,
            ipAddress: $ipAddress,
        );
    }
}
