<?php


namespace AlwaysOpen\MigrationSnapshot;

use AlwaysOpen\MigrationSnapshot\Handlers\MigrateFinishedHandler;
use AlwaysOpen\MigrationSnapshot\Handlers\MigrateStartingHandler;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;

final class EventServiceProvider extends \Illuminate\Foundation\Support\Providers\EventServiceProvider
{
    protected $listen = [
        // CONSIDER: Only registering these when Laravel version doesn't have
        // more specific hooks.
        CommandFinished::class => [MigrateFinishedHandler::class],
        CommandStarting::class => [MigrateStartingHandler::class],
    ];
}
