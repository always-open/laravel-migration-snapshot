<?php


namespace AlwaysOpen\MigrationSnapshot\Tests\Sqlite;

class MigrateDumpTest extends SqliteTestCase
{
    public function test_handle()
    {
        $this->createTestTablesWithoutMigrate();
        $result = \Artisan::call('migrate:dump');
        $this->assertEquals(0, $result);
        $this->assertDirectoryExists($this->schemaSqlDirectory);
        $this->assertFileExists($this->schemaSqlPath);
        $result_sql = file_get_contents($this->schemaSqlPath);
        $this->assertMatchesRegularExpression("/CREATE TABLE( IF NOT EXISTS)? \"{$this->dbPrefix}test_ms\" /", $result_sql);
        $this->assertStringContainsString("INSERT INTO {$this->dbPrefix}migrations VALUES(1,'0000_00_00_000000_create_test_tables',0);", $result_sql);
        $this->assertStringContainsString("INSERT INTO {$this->dbPrefix}migrations VALUES(2,'0000_00_00_000001_second_migration_for_testing',0);", $result_sql);
        $last_character = mb_substr($result_sql, -1);
        $this->assertMatchesRegularExpression("/[\r\n]\z/mu", $last_character);
    }
}
