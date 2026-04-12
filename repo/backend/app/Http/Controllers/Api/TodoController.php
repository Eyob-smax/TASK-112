<?php

namespace App\Http\Controllers\Api;

use App\Application\Todo\TodoService;
use App\Http\Controllers\Controller;
use App\Models\ToDoItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TodoController extends Controller
{
    public function __construct(
        private readonly TodoService $service,
    ) {}

    /**
     * GET /api/v1/todo
     */
    public function index(Request $request): JsonResponse
    {
        $user  = $request->user();
        $query = ToDoItem::query()->where('user_id', $user->id);

        if (!$request->boolean('include_completed', false)) {
            $query->whereNull('completed_at');
        }

        $perPage   = min((int) $request->input('per_page', 25), 100);
        $paginated = $query->orderBy('due_at')->orderBy('created_at', 'desc')->paginate($perPage);

        return response()->json([
            'data' => $paginated->map(fn(ToDoItem $item) => $this->itemShape($item)),
            'meta' => [
                'pagination' => [
                    'current_page' => $paginated->currentPage(),
                    'per_page'     => $paginated->perPage(),
                    'total'        => $paginated->total(),
                    'last_page'    => $paginated->lastPage(),
                ],
            ],
        ]);
    }

    /**
     * POST /api/v1/todo/{item}/complete
     */
    public function complete(Request $request, ToDoItem $item): JsonResponse
    {
        $user = $request->user();

        if ($item->user_id !== $user->id) {
            abort(403, 'You can only complete your own to-do items.');
        }

        $this->service->complete($item->id, $user->id);

        return response()->json(['data' => $this->itemShape($item->fresh())]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function itemShape(ToDoItem $item): array
    {
        return [
            'id'                 => $item->id,
            'user_id'            => $item->user_id,
            'type'               => $item->type,
            'title'              => $item->title,
            'body'               => $item->body,
            'reference_id'       => $item->reference_id,
            'due_at'             => $item->due_at?->toIso8601String(),
            'completed_at'       => $item->completed_at?->toIso8601String(),
            'created_at'         => $item->created_at?->toIso8601String(),
            'updated_at'         => $item->updated_at?->toIso8601String(),
        ];
    }
}
