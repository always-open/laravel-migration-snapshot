<?php

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
}
