<?php

use App\Models\Department;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * API tests for POST /api/v1/auth/login
 */
describe('POST /api/v1/auth/login', function () {

    beforeEach(function () {
        $this->seed(RoleAndPermissionSeeder::class);

        $dept = Department::create([
            'name' => 'Operations',
            'code' => 'OPS',
        ]);

        $this->user = User::create([
            'username'      => 'testuser',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Test User',
            'department_id' => $dept->id,
            'is_active'     => true,
        ]);
    });

    it('returns 200 with token and user data on valid credentials', function () {
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'testuser',
            'password' => 'ValidPass1!',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'token',
                    'user' => ['id', 'username', 'display_name', 'department_id', 'roles'],
                ],
            ]);

        expect($response->json('data.user.id'))->toBe($this->user->id);
        expect($response->json('data.user.username'))->toBe('testuser');
        expect($response->json('data.token'))->not->toBeEmpty();
    });

    it('persists a Sanctum token row keyed by the user UUID', function () {
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'testuser',
            'password' => 'ValidPass1!',
        ]);

        $response->assertStatus(200);

        $tokenRow = DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)
            ->where('tokenable_id', $this->user->id)
            ->latest('created_at')
            ->first();

        expect($tokenRow)->not->toBeNull();
        expect((string) $tokenRow->tokenable_id)->toBe($this->user->id);
    });

    it('returns 422 when username is missing', function () {
        $response = $this->postJson('/api/v1/auth/login', [
            'password' => 'ValidPass1!',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'error' => [
                    'code' => 'validation_error',
                ],
            ]);

        expect($response->json('error.details.username'))->toBeArray();
    });

    it('returns 422 when password is missing', function () {
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'testuser',
        ]);

        $response->assertStatus(422)
            ->assertJson([
                'error' => ['code' => 'validation_error'],
            ]);

        expect($response->json('error.details.password'))->toBeArray();
    });

    it('returns 422 when both fields are missing', function () {
        $response = $this->postJson('/api/v1/auth/login', []);

        $response->assertStatus(422);

        $details = $response->json('error.details');
        expect($details)->toHaveKey('username');
        expect($details)->toHaveKey('password');
    });

    it('returns 401 on wrong password', function () {
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'testuser',
            'password' => 'WrongPassword!',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'error' => ['code' => 'unauthenticated'],
            ]);
    });

    it('returns 401 on unknown username', function () {
        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'nobody',
            'password' => 'AnyPassword1!',
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'error' => ['code' => 'unauthenticated'],
            ]);
    });

    it('returns 423 when the account is locked', function () {
        $this->user->update(['locked_until' => now()->addHour()]);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'testuser',
            'password' => 'ValidPass1!',
        ]);

        $response->assertStatus(423)
            ->assertJson([
                'error' => ['code' => 'account_locked'],
            ]);

        expect($response->json('error.details.locked_until'))->not->toBeNull();
    });

    it('returns 401 when the account is inactive', function () {
        $this->user->update(['is_active' => false]);

        $response = $this->postJson('/api/v1/auth/login', [
            'username' => 'testuser',
            'password' => 'ValidPass1!',
        ]);

        $response->assertStatus(401);
    });

});
