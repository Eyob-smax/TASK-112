<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    | Must always be 'local' for offline single-host operation.
    | Do not configure S3, cloud storage, or remote filesystem adapters.
    */
    'default' => env('FILESYSTEM_DISK', 'local'),

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root'   => storage_path('app'),
            'throw'  => false,
        ],

        /*
        |----------------------------------------------------------------------
        | Attachment Disk
        |----------------------------------------------------------------------
        | Dedicated disk for encrypted attachment payloads.
        | Files are stored as {year}/{month}/{uuid}.enc
        | Access is application-controlled only — no public URL served.
        */
        'attachments' => [
            'driver'     => 'local',
            'root'       => storage_path('app/attachments'),
            'throw'      => true,
            'visibility' => 'private',
        ],

        /*
        |----------------------------------------------------------------------
        | Backup Disk
        |----------------------------------------------------------------------
        | Local disk for backup manifests and database dumps.
        | Retention: 14 days, managed by PruneBackupsJob.
        */
        'backups' => [
            'driver'     => 'local',
            'root'       => storage_path('app/backups'),
            'throw'      => true,
            'visibility' => 'private',
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    | No public symlinks — all file access is application-mediated.
    */
    'links' => [],

];
