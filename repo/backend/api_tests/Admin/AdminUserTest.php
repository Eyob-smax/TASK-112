<?php

use App\Models\Department;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * API tests for admin user management — creation and password reset with PasswordPolicy enforcement.
 */
describe('Admin User Management', function () {

    beforeEach(function () {
        $this->seed(RoleAndPermissionSeeder::class);

        $this->dept = Department::create(['name' => 'Administration', 'code' => 'ADM']);

        $this->admin = User::create([
            'username'      => 'user_mgmt_admin',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'User Mgmt Admin',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $this->admin->assignRole('admin');

        $this->staff = User::create([
            'username'      => 'user_mgmt_staff',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'User Mgmt Staff',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $this->staff->assignRole('staff');
    });

    // -------------------------------------------------------------------------
    // Create user
    // -------------------------------------------------------------------------

    it('allows admin to create a user with a valid password and returns 201', function () {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/admin/users', [
            'username'      => 'new_valid_user',
            'display_name'  => 'New Valid User',
            'email'         => 'new_valid_user@example.com',
            'password'      => 'SecurePass123!',
            'role'          => 'staff',
            'department_id' => $this->dept->id,
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(201)
                 ->assertJsonPath('data.username', 'new_valid_user')
                 ->assertJsonStructure(['data' => ['id', 'username', 'display_name', 'email', 'department_id']]);
    });

    it('returns 422 with PasswordPolicy violation messages when password is too short', function () {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/admin/users', [
            'username'      => 'weak_pwd_short',
            'display_name'  => 'Weak Short',
            'email'         => 'weak_short@example.com',
            'password'      => 'Short1!',   // < 12 chars, no uppercase, has digit
            'role'          => 'staff',
            'department_id' => $this->dept->id,
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(422);
        // PasswordPolicy must surface the length violation message
        $errorBody = $response->json();
        $messages = data_get($errorBody, 'error.details.password', data_get($errorBody, 'errors.password', data_get($errorBody, 'message', '')));
        expect(json_encode($messages))->toContain('at least 12');
    });

    it('returns 422 with PasswordPolicy violation messages when password lacks uppercase and digit', function () {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/admin/users', [
            'username'      => 'weak_pwd_lower',
            'display_name'  => 'Weak Lowercase Only',
            'email'         => 'weak_lower@example.com',
            'password'      => 'alllowercasenodigit',  // no uppercase, no digit
            'role'          => 'staff',
            'department_id' => $this->dept->id,
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(422);
        $errorBody = $response->json();
        $messages = json_encode(data_get($errorBody, 'error.details.password', data_get($errorBody, 'errors.password', data_get($errorBody, 'message', ''))));
        expect($messages)->toContain('uppercase');
        expect($messages)->toContain('digit');
    });

    it('returns 403 when a non-admin user tries to create a user', function () {
        Sanctum::actingAs($this->staff);

        $response = $this->postJson('/api/v1/admin/users', [
            'username'      => 'unauthorized_create',
            'display_name'  => 'Unauthorized',
            'email'         => 'unauth@example.com',
            'password'      => 'ValidPass123!',
            'role'          => 'staff',
            'department_id' => $this->dept->id,
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(403);
    });

    it('returns 422 validation_error when department_id is missing', function () {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson('/api/v1/admin/users', [
            'username'     => 'missing_department_user',
            'display_name' => 'Missing Department',
            'email'        => 'missing_department@example.com',
            'password'     => 'ValidPass123!',
            'role'         => 'staff',
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(422)
                 ->assertJsonPath('error.code', 'validation_error');

        expect($response->json('error.details.department_id'))->not->toBeEmpty();
    });

    // -------------------------------------------------------------------------
    // Reset password
    // -------------------------------------------------------------------------

    it('allows admin to reset a user password with a valid password and returns 200', function () {
        Sanctum::actingAs($this->admin);

        $response = $this->putJson("/api/v1/admin/users/{$this->staff->id}/password", [
            'password' => 'NewSecurePass99!',
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(200);
    });

    it('returns 422 with PasswordPolicy violation messages when resetting to a weak password', function () {
        Sanctum::actingAs($this->admin);

        $response = $this->putJson("/api/v1/admin/users/{$this->staff->id}/password", [
            'password' => 'weak',  // too short, no uppercase, no digit
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(422);
        $errorBody = $response->json();
        $messages = json_encode(data_get($errorBody, 'error.details.password', data_get($errorBody, 'errors.password', data_get($errorBody, 'message', ''))));
        expect($messages)->toContain('at least 12');
    });

    it('returns 403 when a non-admin user tries to reset a password', function () {
        Sanctum::actingAs($this->staff);

        $response = $this->putJson("/api/v1/admin/users/{$this->staff->id}/password", [
            'password' => 'ValidPass123!',
        ], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(403);
    });
});
