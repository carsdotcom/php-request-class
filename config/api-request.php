<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Storage disk name
    |--------------------------------------------------------------------------
    |
    | This should be the name of a disk where Logs can be stored.
    |
    */
    'logs_storage_disk_name' => 'api-logs',

    /*
    |--------------------------------------------------------------------------
    | Cache key seed
    |--------------------------------------------------------------------------
    |
    | In the rare event that you change *what* we cache, increment this.
    |
    */
    'cache_key_seed' => 'v2022.4.12.0',

    /*
    |--------------------------------------------------------------------------
    | Tapper: Data Storage Disk Name
    |--------------------------------------------------------------------------
    |
    | This should be the name of a disk where data for tapper requests is stored.
    |
    */
    'tapper_data_storage_disk_name' => 'tapper-data',
];
