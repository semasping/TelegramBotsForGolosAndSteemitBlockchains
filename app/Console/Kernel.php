<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [

    ];

    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        // $schedule->command('inspire')
        //          ->hourly();
        if (getenv('APP_ENV') == 'production') {
            $schedule->command('bch:get-block')->everyMinute()->withoutOverlapping();
            //$schedule->command('steemit:getblock')->everyMinute()->withoutOverlapping()   ;
        }
        if (getenv('APP_ENV') == 'production') {
            $schedule->command('GolosVoterBot:checkPosts')->everyMinute()->withoutOverlapping();
            //$schedule->command('steemit:getblock')->everyMinute()->withoutOverlapping()   ;

        }
    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
