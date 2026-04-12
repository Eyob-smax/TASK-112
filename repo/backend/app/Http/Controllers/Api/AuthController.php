<?php

namespace App\Http\Controllers\Api;

use App\Application\Auth\AuthenticationService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuthenticationService $auth,
    ) {}

    /**
     * POST /api/v1/auth/login
     *
     * Authenticates a user and returns a Sanctum bearer token.
     * No idempotency key required — this is a public route.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $result = $this->auth->login(
            username: $request->validated('username'),
            password: $request->validated('password'),
            ipAddress: $request->ip(),
        );

        /** @var \App\Models\User $user */
        $user = $result['user'];

        return response()->json([
            'data' => [
                'token' => $result['token'],
                'user'  => [
                    'id'            => $user->id,
                    'username'      => $user->username,
                    'display_name'  => $user->display_name,
                    'email'         => $user->email,
                    'department_id' => $user->department_id,
                    'roles'         => $user->getRoleNames(),
                ],
            ],
        ], 200);
    }

    /**
     * POST /api/v1/auth/logout
     *
     * Revokes the current bearer token. Requires authentication.
     */
    public function logout(Request $request): Response
    {
        $this->auth->logout($request->user(), $request->ip());

        return response()->noContent();
    }

    /**
     * GET /api/v1/auth/me
     *
     * Returns the authenticated user's profile, roles, and permissions.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'data' => [
                'id'            => $user->id,
                'username'      => $user->username,
                'display_name'  => $user->display_name,
                'email'         => $user->email,
                'department_id' => $user->department_id,
                'roles'         => $user->getRoleNames(),
                'permissions'   => $user->getAllPermissions()->pluck('name'),
            ],
        ]);
    }
}
