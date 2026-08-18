<?php

use Illuminate\Support\Facades\Schedule;

// Nightly refresh of the search index. Enable by running the scheduler:
//   docker compose --profile workers up -d scheduler
Schedule::command('search:reindex')->dailyAt('02:30')->withoutOverlapping();
Schedule::command('apis:score')->dailyAt('02:00');
