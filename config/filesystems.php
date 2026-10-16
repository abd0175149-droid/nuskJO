<?php

// E26.7: بعد ضم عمّال nginx إلى مجموعة www-data (NUSKJO_STORAGE_PERMS=strict في compose) تُنشأ ملفات القرصين
// local وpublic بـ 0640 ومجلداتهما بـ 0750، فيقرأ nginx صور البطاقات عبر المجموعة ولا يقرؤها غيره. قبله افتراضي
// Flysystem (0644/0755) كي لا تنكسر الصور. قرص wa_media له صلاحياته الخاصة 0600/0700 في الحالين (E14)
$storagePermissions = env('NUSKJO_STORAGE_PERMS') === 'strict'
    ? ['file' => ['public' => 0640, 'private' => 0600], 'dir' => ['public' => 0750, 'private' => 0700]]
    : [];

return [

    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application for file storage.
    |
    */

    'default' => env('FILESYSTEM_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Below you may configure as many filesystem disks as necessary, and you
    | may even configure multiple disks for the same driver. Examples for
    | most supported storage drivers are configured here for reference.
    |
    | Supported drivers: "local", "ftp", "sftp", "s3"
    |
    */

    'disks' => [

        'local' => [
            'driver' => 'local',
            'root' => storage_path('app/private'),
            'serve' => true,
            'permissions' => $storagePermissions,
            'throw' => false,
            'report' => false,
        ],

        // مرفقات العملاء المؤرشفة (E14): خارج جذر local (عليه serve=true)، ولا رابط عام. العرض من
        // WhatsAppMediaController وحده بربط محصور. المفتاح {Y}/{m}/{uuid7}.{ext} بلا اسم ولا رقم
        'wa_media' => [
            'driver' => 'local',
            'root' => storage_path('app/wa-media'),
            'serve' => false,
            'visibility' => 'private',
            'permissions' => [
                'file' => ['public' => 0600, 'private' => 0600],
                'dir' => ['public' => 0700, 'private' => 0700],
            ],
            'throw' => true,
            'report' => false,
        ],

        'public' => [
            'driver' => 'local',
            'root' => storage_path('app/public'),
            'url' => rtrim(env('APP_URL', 'http://localhost'), '/').'/storage',
            'visibility' => 'public',
            'permissions' => $storagePermissions,
            'throw' => false,
            'report' => false,
        ],

        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
            'throw' => false,
            'report' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Symbolic Links
    |--------------------------------------------------------------------------
    |
    | Here you may configure the symbolic links that will be created when the
    | `storage:link` Artisan command is executed. The array keys should be
    | the locations of the links and the values should be their targets.
    |
    */

    'links' => [
        public_path('storage') => storage_path('app/public'),
    ],

];
