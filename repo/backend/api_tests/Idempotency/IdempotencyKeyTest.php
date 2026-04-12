<?php

use App\Models\Department;
use App\Models\IdempotencyKey;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * API tests for X-Idempotency-Key header enforcement.
 *
 * The IdempotencyMiddleware is applied to all authenticated mutating routes.
 */
describe('X-Idempotency-Key enforcement', function () {

    beforeEach(function () {
        $this->seed(RoleAndPermissionSeeder::class);

        $dept = Department::create(['name' => 'Ops', 'code' => 'OPS2']);

        $this->user = User::create([
            'username'      => 'idem_tester',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Idempotency Tester',
            'department_id' => $dept->id,
            'is_active'     => true,
        ]);
        $this->user->assignRole('staff');
    });

    it('returns 422 when X-Idempotency-Key header is missing on POST', function () {
        Sanctum::actingAs($this->user, ['*'], 'sanctum');

        // POST /auth/logout requires X-Idempotency-Key — sending without it
        $response = $this->postJson('/api/v1/auth/logout');

        $response->assertStatus(422)
            ->assertJson([
                'error' => ['code' => 'idempotency_key_required'],
            ]);
    });

    it('returns 422 when X-Idempotency-Key is not a valid UUID v4', function () {
        Sanctum::actingAs($this->user, ['*'], 'sanctum');

        $response = $this->postJson('/api/v1/auth/logout', [], [
            'X-Idempotency-Key' => 'not-a-uuid-at-all',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'error' => ['code' => 'idempotency_key_invalid'],
            ]);
    });

    it('returns the same response on a replayed request with the same key', function () {
        Sanctum::actingAs($this->user, ['*'], 'sanctum');

        $key = Str::uuid()->toString();

        // First request — processes normally
        $first = $this->postJson('/api/v1/auth/logout', [], [
            'X-Idempotency-Key' => $key,
        ]);

        $first->assertStatus(204);
        expect($first->headers->get('X-Idempotency-Replay'))->toBeNull();

        // Re-authenticate for the second request (first logout invalidated token)
        $this->user->createToken('test');
        Sanctum::actingAs($this->user->fresh(), ['*'], 'sanctum');

        // Second request with same key — should return cached response
        $second = $this->postJson('/api/v1/auth/logout', [], [
            'X-Idempotency-Key' => $key,
        ]);

        $second->assertStatus(204);
        expect($second->headers->get('X-Idempotency-Replay'))->toBe('true');
    });

    it('returns 409 when the same key is reused with a different payload in the same scope', function () {
        $this->user->givePermissionTo('manage departments');
        Sanctum::actingAs($this->user, ['*'], 'sanctum');

        $key = Str::uuid()->toString();

        $this->postJson('/api/v1/departments', [
            'name' => 'Idem Dept A',
            'code' => 'IDA1',
        ], [
            'X-Idempotency-Key' => $key,
        ])->assertStatus(201);

        $second = $this->postJson('/api/v1/departments', [
            'name' => 'Idem Dept B',
            'code' => 'IDB1',
        ], [
            'X-Idempotency-Key' => $key,
        ]);

        $second->assertStatus(409)
            ->assertJson([
                'error' => ['code' => 'idempotency_key_reused'],
            ]);

        expect($second->headers->get('X-Idempotency-Replay'))->toBeNull();
    });

    it('does not replay across different authenticated users for the same key', function () {
        Sanctum::actingAs($this->user, ['*'], 'sanctum');

        $other = User::create([
            'username'      => 'idem_tester_2',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Idempotency Tester 2',
            'department_id' => $this->user->department_id,
            'is_active'     => true,
        ]);
        $other->assignRole('staff');

        $key = Str::uuid()->toString();

        $this->postJson('/api/v1/auth/logout', [], [
            'X-Idempotency-Key' => $key,
        ])->assertStatus(204);

        Sanctum::actingAs($other, ['*'], 'sanctum');

        $second = $this->postJson('/api/v1/auth/logout', [], [
            'X-Idempotency-Key' => $key,
        ]);

        $second->assertStatus(204);
        expect($second->headers->get('X-Idempotency-Replay'))->toBeNull();

        expect(IdempotencyKey::where('key_hash', hash('sha256', $key))->count())->toBe(2);
    });

    it('does not require X-Idempotency-Key for GET requests', function () {
        Sanctum::actingAs($this->user, ['*'], 'sanctum');

        // GET /auth/me — no idempotency key needed
        $response = $this->getJson('/api/v1/auth/me');

        $response->assertStatus(200);
    });

    it('persists idempotency row with http_method and request_path after a successful mutating request', function () {
        Sanctum::actingAs($this->user, ['*'], 'sanctum');

        $key = Str::uuid()->toString();

        $this->postJson('/api/v1/auth/logout', [], [
            'X-Idempotency-Key' => $key,
        ])->assertStatus(204);

        $keyHash = hash('sha256', $key);
        $row     = IdempotencyKey::where('key_hash', $keyHash)->first();

        expect($row)->not->toBeNull();
        expect($row->http_method)->toBe('POST');
        expect($row->request_path)->toBe('api/v1/auth/logout');
        expect($row->response_status)->toBe(204);
        expect($row->actor_scope_hash)->toBe(hash('sha256', $this->user->id));
        expect(strlen($row->request_hash))->toBe(64);
    });

});
