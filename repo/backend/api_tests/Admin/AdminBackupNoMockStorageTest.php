<?php

use App\Models\Department;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * No-mock HTTP coverage for admin backup endpoints using real local disk.
 */
describe('Admin Backup Endpoints No-Mock Storage', function () {

    beforeEach(function () {
        putenv('ATTACHMENT_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));
        Storage::disk('local')->deleteDirectory('backups');

        $this->seed(RoleAndPermissionSeeder::class);

        $dept = Department::create(['name' => 'Backup NoMock', 'code' => 'BNM']);

        $this->admin = User::create([
            'username' => 'nomock_backup_admin',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name' => 'NoMock Backup Admin',
            'department_id' => $dept->id,
            'is_active' => true,
        ]);
        $this->admin->assignRole('admin');
    });

    it('covers GET and POST admin backups without Storage::fake', function () {
        config(['queue.default' => 'sync']);
        Sanctum::actingAs($this->admin, ['*'], 'sanctum');

        $this->getJson('/api/v1/admin/backups')
            ->assertStatus(200)
            ->assertJsonStructure(['data', 'meta' => ['retention_days', 'pagination']]);

        $trigger = $this->postJson(
            '/api/v1/admin/backups',
            [],
            ['X-Idempotency-Key' => Str::uuid()->toString()]
        );

        $trigger->assertStatus(202)
            ->assertJsonPath('data.is_manual', true);

        expect(Storage::disk('local')->allFiles('backups'))->not->toBeEmpty();
    });
});
