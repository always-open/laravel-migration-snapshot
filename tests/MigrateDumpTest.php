<?php

use AlwaysOpen\MigrationSnapshot\Commands\MigrateDumpCommand;
use AlwaysOpen\MigrationSnapshot\Tests\TestCase;

class MigrateDumpTest extends TestCase
{
    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set(
            'migration-snapshot.after-dump',
            function ($schema_sql_path, $data_sql_path) {
                file_put_contents(
                    $schema_sql_path,
                    preg_replace(
                        '~^/\*.*\*/;?[\r\n]+~mu', // Remove /**/ comments.
                        '',
                        file_get_contents($schema_sql_path)
                    )
                );
            }
        );
    }

    public function test_dump_callsAfterDumpClosure()
    {
        $this->createTestTablesWithoutMigrate();
        // TODO: Fix inclusion of `, ['--quiet' => true]` here breaking test.
        $result = \Artisan::call('migrate:dump');
        $this->assertEquals(0, $result);

        $schema_sql = file_get_contents($this->schemaSqlPath);
        $this->assertStringNotContainsString('/*', $schema_sql);
    }

    public function test_dumpWithData()
    {
        if (!file_exists($this->schemaSqlDirectory)) {
            mkdir($this->schemaSqlDirectory, 0755);
        }

        file_put_contents($this->schemaSqlPath, 'Line that should not exist 1' . PHP_EOL);
        file_put_contents($this->schemaSqlPath, 'Line that should not exist 2', FILE_APPEND);

        $dataSqlPath = MigrateDumpCommand::getDataSqlPath(
            $this->app['config']->get('database.connections.' . $this->dbDefault . '.driver')
        );

        file_put_contents($dataSqlPath, 'Line that should not exist 1' . PHP_EOL);
        file_put_contents($dataSqlPath, 'Line that should not exist 2', FILE_APPEND);

        $this->createTestTablesWithoutMigrate();
        $result = \Artisan::call('migrate:dump', ['--include-data' => true]);
        $this->assertEquals(0, $result);
        $this->assertDirectoryExists($this->schemaSqlDirectory);

        $this->assertFileExists($this->schemaSqlPath);
        $result_sql = file_get_contents($this->schemaSqlPath);
        $this->assertStringNotContainsString('Line that should not exist 1', $result_sql);
        $this->assertStringNotContainsString('Line that should not exist 2', $result_sql);

        $this->assertFileExists($dataSqlPath);
        $result_data = file_get_contents($dataSqlPath);
        $this->assertStringNotContainsString('Line that should not exist 1', $result_data);
        $this->assertStringNotContainsString('Line that should not exist 2', $result_data);
    }
}
