<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Backup destination disk
    |--------------------------------------------------------------------------
    | Which filesystem disk the daily backup writes to (and prunes). Defaults to
    | the private local `backups` disk. Set BACKUP_DISK=s3 (or any configured
    | disk — e.g. an OneDrive/pCloud-mounted local disk) to ship backups off-box,
    | which is what enhancement #12 asks for. No code change is needed to switch.
    */
    'disk' => env('BACKUP_DISK', 'backups'),

    /*
    | Folder within the disk to keep archives under.
    */
    'path' => env('BACKUP_PATH', 'db'),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    | How many of the most recent backups to keep. Older archives are deleted on
    | every run. The brief asks for "the last 3–4"; 4 is the default.
    */
    'keep' => (int) env('BACKUP_KEEP', 4),

    /*
    |--------------------------------------------------------------------------
    | gzip
    |--------------------------------------------------------------------------
    | Compress the dump. Requires PHP's zlib (bundled). Falls back to an
    | uncompressed dump automatically if unavailable.
    */
    'gzip' => (bool) env('BACKUP_GZIP', true),

];
