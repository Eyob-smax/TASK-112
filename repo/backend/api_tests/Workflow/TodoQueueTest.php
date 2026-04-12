<?php

use App\Models\Department;
use App\Models\ToDoItem;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * API tests for the To-Do queue — listing, filtering, and completing items.
 */
describe('Todo Queue', function () {

    beforeEach(function () {
        $this->seed(RoleAndPermissionSeeder::class);

        $this->dept = Department::create(['name' => 'Logistics', 'code' => 'LOG']);

        $this->user = User::create([
            'username'      => 'todo_user',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Todo User',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $this->user->assignRole('staff');

        $this->other = User::create([
            'username'      => 'todo_other',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Other User',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $this->other->assignRole('staff');
    });

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    function makeTodo(User $user, bool $completed = false): ToDoItem
    {
        $item = ToDoItem::create([
            'user_id'      => $user->id,
            'type'         => 'workflow_approval',
            'title'        => 'Review expense request',
            'body'         => 'Please review and approve.',
            'reference_id' => Str::uuid()->toString(),
            'due_at'       => now()->addDays(2),
        ]);

        if ($completed) {
            $item->update(['completed_at' => now()]);
        }

        return $item;
    }

    // -------------------------------------------------------------------------
    // Listing
    // -------------------------------------------------------------------------

    it('lists pending to-do items for the authenticated user', function () {
        Sanctum::actingAs($this->user);

        makeTodo($this->user);
        makeTodo($this->user);
        makeTodo($this->other); // Should NOT appear for $this->user

        $response = $this->getJson('/api/v1/todo');

        $response->assertStatus(200);
        expect(count($response->json('data')))->toBe(2);
    });

    it('excludes completed items by default', function () {
        Sanctum::actingAs($this->user);

        makeTodo($this->user);                    // pending
        makeTodo($this->user, completed: true);   // completed

        $response = $this->getJson('/api/v1/todo');

        $response->assertStatus(200);
        expect(count($response->json('data')))->toBe(1);
    });

    it('includes completed items when include_completed=true is passed', function () {
        Sanctum::actingAs($this->user);

        makeTodo($this->user);                    // pending
        makeTodo($this->user, completed: true);   // completed

        $response = $this->getJson('/api/v1/todo?include_completed=true');

        $response->assertStatus(200);
        expect(count($response->json('data')))->toBe(2);
    });

    // -------------------------------------------------------------------------
    // Completing
    // -------------------------------------------------------------------------

    it('completes a to-do item and returns 200', function () {
        Sanctum::actingAs($this->user);

        $item = makeTodo($this->user);

        $response = $this->postJson("/api/v1/todo/{$item->id}/complete");

        $response->assertStatus(200);

        expect($response->json('data.completed_at'))->not->toBeNull();
    });

    it('returns 403 when completing another user\'s to-do item', function () {
        Sanctum::actingAs($this->user);

        $otherItem = makeTodo($this->other);

        $response = $this->postJson("/api/v1/todo/{$otherItem->id}/complete");

        $response->assertStatus(403);
    });
});
