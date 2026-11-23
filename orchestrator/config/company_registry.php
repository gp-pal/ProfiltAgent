<?php

// Seconds any single the registry interaction may take. The DB is external and
// shared, so when it is loaded a lookup has been observed blocking for 6-21
// minutes — far past every caller's HTTP timeout. Capping it here turns a slow
// registry into a skipped source (CompanyRegistrySource catches query failures)
// instead of a stalled run.
$timeout = max(1, (int) env('REGISTRY_DB_TIMEOUT', 5));

// Exploratory, human-initiated queries (the admin's gap-firm picker) legitimately
// need a full-table scan: "no annotation AND no email AND no phone AND no mobile,
// ordered by employees" cannot use an index. The 5s request-path cap kills them
// ("1969 Query execution was interrupted"), so they get their own longer budget.
// Never raise the request-path cap to match — that is what caused the 20-minute
// /api/run stalls this cap exists to prevent.
$exploratoryTimeout = max($timeout, (int) env('REGISTRY_DB_EXPLORATORY_TIMEOUT', 30));

return [
    'timeout_s' => $timeout,
    'exploratory_timeout_s' => $exploratoryTimeout,

    // Read-only secondary connection to the external company-registry MariaDB.
    // Merged into database.connections by AppServiceProvider::boot() so it does
    // not require publishing the framework's config/database.php.
    'connection' => [
        'driver' => 'mysql',
        'host' => env('REGISTRY_DB_HOST', '127.0.0.1'),
        'port' => env('REGISTRY_DB_PORT', '3306'),
        'database' => env('REGISTRY_DB_DATABASE', 'company_registry'),
        'username' => env('REGISTRY_DB_USERNAME', ''),
        'password' => env('REGISTRY_DB_PASSWORD', ''),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'strict' => true,
        'options' => [
            // Client-side: cap the connect/read wait.
            PDO::ATTR_TIMEOUT => $timeout,
            // Server-side: MariaDB aborts the statement itself, so an unindexed
            // full-table scan cannot outlive the budget. (MariaDB spelling —
            // MySQL calls this max_execution_time and measures it in ms.)
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET SESSION max_statement_time={$timeout}",
        ],
    ],
];
