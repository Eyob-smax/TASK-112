<?php

use App\Application\Backup\BackupMetadataService;
use App\Models\BackupJob;
use App\Models\Department;
use App\Models\User;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * API tests for admin backup endpoints.
 *
 * Covers: backup history listing, status filtering, authorization, manual trigger.
 */
describe('Admin Backup Endpoints', function () {

    beforeEach(function () {
        $this->seed(RoleAndPermissionSeeder::class);

        $this->dept = Department::create(['name' => 'Admin Dept', 'code' => 'ADM']);

        $this->admin = User::create([
            'username'      => 'backup_admin',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Backup Admin',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $this->admin->assignRole('admin');

        $this->staff = User::create([
            'username'      => 'bk_staff',
            'password_hash' => Hash::make('ValidPass1!'),
            'display_name'  => 'Staff',
            'department_id' => $this->dept->id,
            'is_active'     => true,
        ]);
        $this->staff->assignRole('staff');

        // Seed some backup job records
        BackupJob::create([
            'started_at'           => now()->subHours(24),
            'completed_at'         => now()->subHours(23),
            'status'               => 'success',
            'size_bytes'           => 1024 * 1024 * 10,
            'retention_expires_at' => now()->addDays(14),
            'is_manual'            => false,
            'manifest'             => ['tables' => [], 'attachment_file_count' => 5],
        ]);
        BackupJob::create([
            'started_at'           => now()->subDays(5),
            'status'               => 'failed',
            'error_message'        => 'Disk full',
            'retention_expires_at' => now()->addDays(9),
            'is_manual'            => false,
        ]);
    });

    // -------------------------------------------------------------------------
    // GET /admin/backups
    // -------------------------------------------------------------------------

    it('returns 200 with paginated backup history for admin role', function () {
        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/v1/admin/backups');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [[
                    'id', 'status', 'is_manual', 'started_at', 'completed_at',
                    'size_bytes', 'manifest', 'retention_expires_at',
                ]],
                'meta' => ['retention_days', 'pagination'],
            ]);

        expect($response->json('meta.pagination.total'))->toBe(2);
    });

    it('returns retention_days from meridian config in meta', function () {
        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/v1/admin/backups');

        $response->assertStatus(200);
        expect($response->json('meta.retention_days'))->toBe(
            (int) config('meridian.backup.retention_days', 14)
        );
    });

    it('filters backup history by status', function () {
        Sanctum::actingAs($this->admin);
        $response = $this->getJson('/api/v1/admin/backups?status=success');

        $response->assertStatus(200);
        $data = $response->json('data');
        expect(count($data))->toBe(1)
            ->and($data[0]['status'])->toBe('success');
    });

    it('returns 403 for non-admin users on backup listing', function () {
        Sanctum::actingAs($this->staff);
        $response = $this->getJson('/api/v1/admin/backups');

        $response->assertStatus(403);
    });

    // -------------------------------------------------------------------------
    // POST /admin/backups (manual trigger)
    // -------------------------------------------------------------------------

    it('returns 202 when admin triggers a manual backup', function () {
        Queue::fake();

        Sanctum::actingAs($this->admin);
        $response = $this->postJson('/api/v1/admin/backups', [], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(202);
        Queue::assertPushed(\App\Jobs\RunBackupJob::class);
    });

    it('returns 403 for non-admin when triggering manual backup', function () {
        Sanctum::actingAs($this->staff);
        $response = $this->postJson('/api/v1/admin/backups', [], ['X-Idempotency-Key' => Str::uuid()->toString()]);

        $response->assertStatus(403);
    });

    // -------------------------------------------------------------------------
    // Retention pruning — physical artifact deletion (HIGH-3)
    // -------------------------------------------------------------------------

    it('deletes the physical backup artifact from storage when pruning expired records', function () {
        Storage::fake('local');

        // Place a fake encrypted dump file in the backups directory
        $fakeFilePath = 'backups/2024-01-01-fakejob.sql.gz.enc';
        Storage::disk('local')->put($fakeFilePath, 'fake-encrypted-content');
        expect(Storage::disk('local')->exists($fakeFilePath))->toBeTrue();

        // Seed an expired BackupJob whose manifest references the fake file
        BackupJob::create([
            'started_at'           => now()->subDays(20),
            'completed_at'         => now()->subDays(20),
            'status'               => 'success',
            'size_bytes'           => 100,
            'retention_expires_at' => now()->subDays(6), // expired 6 days ago
            'is_manual'            => false,
            'manifest'             => ['dump_file' => $fakeFilePath, 'attachment_file_count' => 0],
        ]);

        $service = app(BackupMetadataService::class);
        $deleted = $service->pruneExpired();

        // Metadata row was deleted
        expect($deleted)->toBeGreaterThan(0);

        // Physical file was also deleted from storage
        expect(Storage::disk('local')->exists($fakeFilePath))->toBeFalse();
    });
});
