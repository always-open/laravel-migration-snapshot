<?php

namespace AlwaysOpen\MigrationSnapshot;

use AlwaysOpen\MigrationSnapshot\Commands\MigrateDumpCommand;
use AlwaysOpen\MigrationSnapshot\Commands\MigrateLoadCommand;
use AlwaysOpen\MigrationSnapshot\Handlers\MigrateFinishedHandler;
use AlwaysOpen\MigrationSnapshot\Handlers\MigrateStartingHandler;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Event;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/migration-snapshot.php' => config_path('migration-snapshot.php'),
            ], 'config');

            $this->commands([
                MigrateDumpCommand::class,
                MigrateLoadCommand::class,
            ]);
        }

        Event::listen(CommandStarting::class, [MigrateStartingHandler::class, 'handle']);
        Event::listen(CommandFinished::class, [MigrateFinishedHandler::class, 'handle']);

        // Laravel 11+ skips rerouting Symfony console events to their Laravel
        // counterparts while `runningUnitTests()`, which suppresses
        // CommandStarting/CommandFinished during Artisan::call in tests. Force
        // the rerouting so our hooks fire in every context. Idempotent.
        $this->app->booted(function () {
            $this->app[ConsoleKernel::class]->rerouteSymfonyCommandEvents();
        });
    }

    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/migration-snapshot.php', 'migration-snapshot');
    }
}
