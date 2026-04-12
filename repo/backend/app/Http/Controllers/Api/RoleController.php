<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    /**
     * GET /api/v1/roles
     *
     * List all roles. Requires the 'view roles' permission.
     */
    public function index(Request $request): JsonResponse
    {
        abort_if(!$request->user()->can('view roles'), 403);

        $roles = Role::orderBy('name')->get();

        return response()->json([
            'data' => $roles->map(fn($r) => [
                'id'          => $r->id,
                'name'        => $r->name,
                'type'        => $r->type instanceof \BackedEnum ? $r->type->value : $r->type,
                'description' => $r->description,
            ])->values(),
        ]);
    }
}
