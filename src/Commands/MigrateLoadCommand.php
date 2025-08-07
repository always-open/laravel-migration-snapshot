<?php

namespace AlwaysOpen\MigrationSnapshot\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\Console\Output\OutputInterface;

final class MigrateLoadCommand extends Command
{
    protected $signature = 'migrate:load
        {--database= : The database connection to use}
        {--force : Force the operation to run when in production}
        {--no-drop : Do not drop tables before loading new structure, also MIGRATE_LOAD_NO_DROP=1}';

    protected $description = 'Load current database schema/structure from plain-text SQL file.';

    public function handle()
    {
        if (
            ! $this->option('force')
            && app()->environment('production')
            && ! $this->confirm('Are you sure you want to load the database schema from a file?')
        ) {
            return;
        }

        $database = $this->option('database') ?: DB::getDefaultConnection();
        DB::setDefaultConnection($database);
        $db_config = DB::getConfig();

        if (! in_array($db_config['driver'], MigrateDumpCommand::SUPPORTED_DB_DRIVERS, true)) {
            throw new InvalidArgumentException(
                'Unsupported database driver ' . var_export($db_config['driver'], 1)
            );
        }

        $schema_sql_path = MigrateDumpCommand::getSchemaSqlPath($db_config['driver']);
        if (! file_exists($schema_sql_path)) {
            throw new InvalidArgumentException(
                'No schema dump found for the current database driver. Run `migrate:dump --database=' . $database . '` before running this command.'
            );
        }

        // CONSIDER: Moving option to `migrate:dump` instead.
        $is_dropping = ! ($this->option('no-drop')
            ? true
            // Prefixing with command name since `migrate` may implicitly call.
            : (env('MIGRATE_LOAD_NO_DROP') ? true : false));

        if ($is_dropping) {
            \Schema::dropAllViews();
            \Schema::dropAllTables();
            // TODO: Drop others too: sequences, types, etc.
            $this->info('Dropped old tables and views');
        }

        // Delegate to driver-specific restore/load CLI command.
        // ASSUMES: Restore utilities for DBMS installed and in path.
        // CONSIDER: Accepting options for underlying restore utilities from CLI.
        // CONSIDER: Option to restore to console Stdout instead.
        $method = $db_config['driver'] . 'Load';
        $exit_code = self::{$method}($schema_sql_path, $db_config, $this->getOutput()->getVerbosity());

        if (0 !== $exit_code) {
            exit($exit_code); // CONSIDER: Returning instead.
        }

        $this->info('Loaded ' . $db_config['driver'] . ' schema');

        $data_path = MigrateDumpCommand::getDataSqlPath($db_config['driver']);
        if ('pgsql' === $db_config['driver']) {
            $data_path = preg_replace('/\.sql$/', '.pgdump', $data_path);
        }
        if (file_exists($data_path)) {
            $this->info('Loading default data...');

            $exit_code = self::{$method}($data_path, $db_config, $this->getOutput()->getVerbosity());

            if (0 !== $exit_code) {
                exit($exit_code); // CONSIDER: Returning instead.
            }

            $this->info('Loaded default data successfully!');
        }

        if ($after_load = config('migration-snapshot.after-load')) {
            if (is_string($after_load)) {
                Artisan::call($after_load);
            } elseif (is_array($after_load)) {
                Artisan::call($after_load[0], $after_load[1] ?? [], $after_load[2] ?? null);
            } else {
                $after_load($schema_sql_path, $data_path);
            }
            $this->info('Ran After-load');
        }
    }

    /**
     * @param string $path
     * @param array $db_config
     * @param int|null $verbosity
     *
     * @return int
     */
    private static function mysqlLoad(string $path, array $db_config, int $verbosity = null) : int
    {
        // CONSIDER: Supporting unix_socket.
        // CONSIDER: Directly sending queries via Eloquent (requires parsing SQL
        // or intermediate format).
        // CONSIDER: Capturing Stderr and outputting with `$this->error()`.

        // CONSIDER: Making input file an option which can override default.
        // CONSIDER: Avoiding shell specifics like `cat` and piping using
        // `file_get_contents` or similar.
        $command = 'bash -c "'
            . 'cat ' . escapeshellarg($path)
            . ' | mysql --no-beep'
            . ' --host=' . escapeshellarg($db_config['host'])
            . ' --port=' . escapeshellarg($db_config['port'] ?? 3306)
            . ' --user=' . escapeshellarg($db_config['username'])
            . ' --password=' . escapeshellarg($db_config['password'])
            . ' --database=' . escapeshellarg($db_config['database'])
            . ' 2> >(grep -v \'Using a password on the command line interface can be insecure.\')"';
        switch($verbosity) {
            case OutputInterface::VERBOSITY_QUIET:
                $command .= ' -q';
                break;
            case OutputInterface::VERBOSITY_NORMAL:
                // No op.
                break;
            case OutputInterface::VERBOSITY_VERBOSE:
                $command .= ' -v';
                break;
            case OutputInterface::VERBOSITY_VERY_VERBOSE:
                $command .= ' -v -v';
                break;
            case OutputInterface::VERBOSITY_DEBUG:
                $command .= ' -v -v -v';
                break;
        }

        passthru($command, $exit_code);

        return $exit_code;
    }

    /**
     * @param string $path
     * @param array $db_config
     * @param int|null $verbosity
     *
     * @return int
     */
    private static function pgsqlLoad(string $path, array $db_config, int $verbosity = null) : int
    {
        // CONSIDER: Supporting unix_socket.
        // CONSIDER: Directly sending queries via Eloquent (requires parsing SQL
        // or intermediate format).
        // CONSIDER: Capturing Stderr and outputting with `$this->error()`.

        // CONSIDER: Making input file an option which can override default.
        $needsPgRestore = Str::endsWith($path, '.pgdump');

        // Pg_restore needed to workaround dumping data separately from DDL
        // because FKs are present, so `--disable-triggers` is necessary. An
        // added benefit is smaller file size, at the cost of some readability.
        // CONSIDER: Optionally dumping data as txt and wrapping in
        // `SET session_replication_role =…`, yet requires extra permissions.
        $command = 'PGPASSWORD=' . escapeshellarg($db_config['password'])
            . ($needsPgRestore ? ' pg_restore --disable-triggers' : ' psql')
            . ' --host=' . escapeshellarg($db_config['host'])
            . ' --port=' . escapeshellarg($db_config['port'] ?? 5432)
            . ' --username=' . escapeshellarg($db_config['username'])
            . ' --dbname=' . escapeshellarg($db_config['database']);
        switch($verbosity) {
            case OutputInterface::VERBOSITY_QUIET:
                $command .= $needsPgRestore ? '' : ' --quiet --output=/dev/null';
                break;
            case OutputInterface::VERBOSITY_NORMAL:
                $command .= $needsPgRestore ? ' -v' : ' --output=/dev/null';
                break;
            case OutputInterface::VERBOSITY_VERBOSE:
                $command .= $needsPgRestore ? ' -vv' : ''; // psql is verbose by default.
                break;
            case OutputInterface::VERBOSITY_VERY_VERBOSE:
                $command .= $needsPgRestore ? ' -vvv' : ' --echo-errors';
                break;
            case OutputInterface::VERBOSITY_DEBUG:
                $command .= $needsPgRestore ? ' -vvvv' : ' --echo-all';
                break;
        }
        $command .= ' ' . ($needsPgRestore ? '' : '--file=') . escapeshellarg($path);

        passthru($command, $exit_code);

        return $exit_code;
    }

    /**
     * @param string $path
     * @param array $db_config
     * @param int|null $verbosity
     *
     * @return int
     */
    private static function sqliteLoad(string $path, array $db_config, int $verbosity = null) : int
    {
        // CONSIDER: Directly sending queries via Eloquent (requires parsing SQL
        // or intermediate format).
        // CONSIDER: Capturing Stderr and outputting with `$this->error()`.

        $command = 'sqlite3 ' . escapeshellarg($db_config['database']) . ' ' . escapeshellarg(".read $path");

        passthru($command, $exit_code);

        return $exit_code;
    }
}
