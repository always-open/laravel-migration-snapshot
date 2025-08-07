<?php


namespace AlwaysOpen\MigrationSnapshot\Tests;


use AlwaysOpen\MigrationSnapshot\Commands\MigrateDumpCommand;
use AlwaysOpen\MigrationSnapshot\ServiceProvider;

class TestCase extends \Orchestra\Testbench\TestCase
{
    protected $dbDefault = 'mysql';
    protected $dbPrefix = 'omp_';
    protected $schemaSqlDirectory;
    protected $schemaSqlPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->schemaSqlPath = MigrateDumpCommand::getSchemaSqlPath(
            $this->app['config']->get('database.connections.' . $this->dbDefault . '.driver')
        );
        $this->schemaSqlDirectory = dirname($this->schemaSqlPath);

        // Not leaving to tearDown since it can be useful to see result after
        // failure.
        foreach (glob($this->schemaSqlDirectory . '/*') as $sql_path) {
            unlink($sql_path);
        }
        if (file_exists($this->schemaSqlDirectory)) {
            rmdir($this->schemaSqlDirectory);
        }
    }

    protected function getEnvironmentSetUp($app)
    {
        $app['config']->set('database.default', $this->dbDefault);
        $oldDbConnConfig = $app['config']->get('database.connections.' . $this->dbDefault) ?? [];
        $app['config']->set(
            'database.connections.' . $this->dbDefault,
            ['prefix' => $this->dbPrefix] + $oldDbConnConfig
        );
    }

    protected function getPackageProviders($app)
    {
        return [ServiceProvider::class];
    }

    protected function createTestTablesWithoutMigrate() : void
    {
        // Executing without `loadMigrationsFrom` and without `Artisan::call` to
        // avoid unnecessary runs through migration hooks.

        require_once(__DIR__ . '/migrations/setup/0000_00_00_000000_create_test_tables.php');
        \Schema::dropAllTables();
        \Schema::dropAllViews();
        \Schema::create('migrations', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->increments('id');
            $table->string('migration', 255);
            $table->integer('batch');
        });
        (new \CreateTestTables)->up();
        \DB::table('migrations')->insert([
            'migration' => '0000_00_00_000000_create_test_tables',
            'batch' => 1,
        ]);
        \DB::table('migrations')->insert([
            'migration' => '0000_00_00_000001_second_migration_for_testing',
            'batch' => 1,
        ]);
    }
}
