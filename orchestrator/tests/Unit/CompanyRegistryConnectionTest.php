<?php

namespace Tests\Unit;

use PDO;
use Tests\TestCase;

/**
 * The company-registry MariaDB is EXTERNAL and shared: when it is under load a single
 * lookup has been observed blocking for ~6-21 minutes, which stalls the whole
 * /api/run past every caller's timeout. The connection must therefore carry its
 * own connect + statement timeouts so a slow registry degrades into a skipped
 * source (CompanyRegistrySource already catches query failures) instead of hanging
 * the run.
 */
class CompanyRegistryConnectionTest extends TestCase
{
    public function test_connection_caps_connect_time(): void
    {
        $options = config('company_registry.connection.options');

        $this->assertIsArray($options);
        $this->assertSame(5, $options[PDO::ATTR_TIMEOUT]);
    }

    public function test_connection_caps_server_side_statement_time(): void
    {
        $options = config('company_registry.connection.options');

        $this->assertIsArray($options);
        // MariaDB kills the statement itself, so a full-table-scan LIKE cannot
        // outlive the budget even if the client keeps waiting.
        $this->assertSame(
            'SET SESSION max_statement_time=5',
            $options[PDO::MYSQL_ATTR_INIT_COMMAND],
        );
    }

    public function test_registered_database_connection_carries_the_timeouts(): void
    {
        // AppServiceProvider::boot() merges the config into database.connections;
        // the timeouts must survive that copy or they never reach PDO.
        $options = config('database.connections.company_registry.options');

        $this->assertIsArray($options);
        $this->assertSame(5, $options[PDO::ATTR_TIMEOUT]);
    }
}
