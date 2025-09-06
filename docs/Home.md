# Task Scheduling

- [Introduction](#introduction)
- [Installation](Installation.md)
- [Defining Schedules](#defining-schedules)
    - [Scheduling CakePHP Commands](#scheduling-cakephp-commands)
    - [Scheduling Shell Commands](#scheduling-shell-commands)
    - [Schedule Frequency Options](#schedule-frequency-options)
    - [Timezones](#timezones)
    - [Preventing Task Overlaps](#preventing-task-overlaps)
    - [Running Tasks on One Server](#running-tasks-on-one-server)
    - [Background Tasks](#background-tasks)
    - [Schedule Groups](#schedule-groups)
- [Running the Scheduler](#running-the-scheduler)
    - [Sub-Minute Scheduled Tasks](#sub-minute-scheduled-tasks)
    - [Running the Scheduler Locally](#running-the-scheduler-locally)
- [Task Output](Integration.md#task-output)
- [Task Hooks](Integration.md#task-hooks)
- [Events](Integration.md#events)
- [API Reference](API-Reference.md)

<a name="introduction"></a>
## Introduction

In the past, you may have written a cron configuration entry for each task you needed to schedule on your server. However, this can quickly become a pain because your task schedule is no longer in source control and you must SSH into your server to view your existing cron entries or add additional entries.

The Scheduling plugin offers a fresh approach to managing scheduled tasks on your server. The scheduler allows you to fluently and expressively define your command schedule within your CakePHP application itself. When using the scheduler, only a single cron entry is needed on your server. Your task schedule is defined during your application's container building phase using CakePHP's event system.

<a name="defining-schedules"></a>
## Defining Schedules

You may define all of your scheduled tasks using CakePHP's event manager during the container building phase. To get started, let's take a look at an example. In this example, we will schedule a closure to be called every day at midnight. Within the closure we will execute a database query to clear a table:

```php
use Cake\Event\EventManager;
use Scheduling\Schedule;

$eventManager = EventManager::instance();
$eventManager->on('Application.buildContainer', function ($event): void {
    $container = $event->getData('container');
    $schedule = $container->get(Schedule::class);

    // Run a command every minute
    $schedule->command('logs archive')->everyHour();

    // Run a command every day at 2:00 AM
    $schedule->command('reports generate')->dailyAt('02:00');

    // Run a closure every 30 seconds
    $schedule->call(function () {
        // Your custom logic here
    })->everyThirtySeconds();
});
```

In addition to scheduling using closures, you may also schedule invokable objects. Invokable objects are simple PHP classes that contain an `__invoke` method:

```php
$schedule->call(new DeleteRecentUsers)->daily();
```

If you would like to view an overview of your scheduled tasks and the next time they are scheduled to run, you may use the `schedule list` console command:

```bash
bin/cake schedule list
```

<a name="scheduling-cakephp-commands"></a>
### Scheduling CakePHP Commands

In addition to scheduling closures, you may also schedule CakePHP console commands and system commands. For example, you may use the `command` method to schedule a console command using either the command's name or class.

When scheduling console commands using the command's class name, you may pass an array of additional command-line arguments that should be provided to the command when it is invoked:

```php
use App\Command\SendEmailsCommand;

$schedule->command('emails send --force')->daily();

$schedule->command(SendEmailsCommand::class, ['--force'])->daily();
```

<a name="scheduling-shell-commands"></a>
### Scheduling Shell Commands

The `exec` method may be used to issue a command to the operating system:

```php
$schedule->exec('node /home/forge/script.js')->daily();
```

<a name="schedule-frequency-options"></a>
### Schedule Frequency Options

We've already seen a few examples of how you may configure a task to run at specified intervals. However, there are many more task schedule frequencies that you may assign to a task. For a complete reference of all frequency methods, see the [API Reference](API-Reference.md#frequency-methods).

These methods may be combined with additional constraints to create even more finely tuned schedules that only run on certain days of the week. For example, you may schedule a command to run weekly on Monday:

```php
// Run once per week on Monday at 1 PM...
$schedule->call(function () {
    // ...
})->weekly()->mondays()->at('13:00');

// Run hourly from 8 AM to 5 PM on weekdays...
$schedule->command('foo')
    ->weekdays()
    ->hourly()
    ->timezone('America/Chicago')
    ->between('8:00', '17:00');
```

For a complete list of additional schedule constraints, see the [Integration guide](Integration.md#schedule-constraints).

<a name="timezones"></a>
### Timezones

Using the `timezone` method, you may specify that a scheduled task's time should be interpreted within a given timezone:

```php
$schedule->command('report generate')
    ->timezone('America/New_York')
    ->at('2:00');
```

If you are repeatedly assigning the same timezone to all of your scheduled tasks, you can specify a default timezone when creating the schedule instance in your container configuration:

```php
$eventManager->on('Application.buildContainer', function ($event): void {
    $container = $event->getData('container');

    // Create schedule with default timezone
    $schedule = new Schedule('America/Chicago');
    $container->add(Schedule::class, $schedule);

    // All tasks will use this timezone unless overridden
    $schedule->command('report generate')->at('2:00');
});
```

> **Warning**
> Remember that some timezones utilize daylight savings time. When daylight saving time changes occur, your scheduled task may run twice or even not run at all. For this reason, we recommend avoiding timezone scheduling when possible.

<a name="preventing-task-overlaps"></a>
### Preventing Task Overlaps

By default, scheduled tasks will be run even if the previous instance of the task is still running. To prevent this, you may use the `withoutOverlapping` method:

```php
$schedule->command('emails send')->withoutOverlapping();
```

In this example, the `emails send` console command will be run every minute if it is not already running. The `withoutOverlapping` method is especially useful if you have tasks that vary drastically in their execution time, preventing you from predicting exactly how long a given task will take.

If needed, you may specify how many minutes must pass before the "without overlapping" lock expires. By default, the lock will expire after 24 hours:

```php
$schedule->command('emails send')->withoutOverlapping(10);
```

Behind the scenes, the `withoutOverlapping` method utilizes your application's cache to obtain locks. If necessary, you can clear these cache locks using the `schedule clear` console command. This is typically only necessary if a task becomes stuck due to an unexpected server problem.

<a name="running-tasks-on-one-server"></a>
### Running Tasks on One Server

> **Warning**
> To utilize this feature, your application must be using the `database`, `memcached`, `dynamodb`, or `redis` cache driver as your application's default cache driver. In addition, all servers must be communicating with the same central cache server.

If your application's scheduler is running on multiple servers, you may limit a scheduled job to only execute on a single server. For instance, assume you have a scheduled task that generates a new report every Friday night. If the task scheduler is running on three worker servers, the scheduled task will run on all three servers and generate the report three times. Not good!

To indicate that the task should run on only one server, use the `onOneServer` method when defining the scheduled task. The first server to obtain the task will secure an atomic lock on the job to prevent other servers from running the same task at the same time:

```php
$schedule->command('report generate')
    ->fridays()
    ->at('17:00')
    ->onOneServer();
```

<a name="background-tasks"></a>
### Background Tasks

By default, multiple tasks scheduled at the same time will execute sequentially based on the order they are defined in your schedule. If you have long-running tasks, this may cause subsequent tasks to start much later than anticipated. If you would like to run tasks in the background so that they may all run simultaneously, you may use the `runInBackground` method:

```php
$schedule->command('analytics report')
    ->daily()
    ->runInBackground();
```

> **Warning**
> The `runInBackground` method may only be used when scheduling tasks via the `command` and `exec` methods.

<a name="schedule-groups"></a>
### Schedule Groups

When defining multiple scheduled tasks with similar configurations, you can use the task grouping feature to avoid repeating the same settings for each task. Grouping tasks simplifies your code and ensures consistency across related tasks.

To create a group of scheduled tasks, invoke the desired task configuration methods, followed by the `group` method. The `group` method accepts a closure that is responsible for defining the tasks that share the specified configuration:

```php
$schedule->daily()
    ->onOneServer()
    ->timezone('America/New_York')
    ->group(function () {
        $schedule->command('emails send --force');
        $schedule->command('emails prune');
    });
```

<a name="running-the-scheduler"></a>
## Running the Scheduler

Now that we have learned how to define scheduled tasks, let's discuss how to actually run them on our server. The `schedule run` console command will evaluate all of your scheduled tasks and determine if they need to run based on the server's current time.

So, when using the scheduler, we only need to add a single cron configuration entry to our server that runs the `schedule run` command every minute:

```shell
* * * * * cd /path-to-your-project && bin/cake schedule run >> /dev/null 2>&1
```

<a name="sub-minute-scheduled-tasks"></a>
### Sub-Minute Scheduled Tasks

On most operating systems, cron jobs are limited to running a maximum of once per minute. However, the scheduler allows you to schedule tasks to run at more frequent intervals, even as often as once per second:

```php
$schedule->call(function () {
    $table = TableRegistry::getTableLocator()->get('RecentUsers');
    $table->deleteAll(['created <' => new DateTime('-1 hour')]);
})->everySecond();
```

When sub-minute tasks are defined within your application, the `schedule run` command will continue running until the end of the current minute instead of exiting immediately. This allows the command to invoke all required sub-minute tasks throughout the minute.

Since sub-minute tasks that take longer than expected to run could delay the execution of later sub-minute tasks, it is recommended that all sub-minute tasks dispatch queued jobs or background commands to handle the actual task processing:

```php
$schedule->command('users delete')->everyTenSeconds()->runInBackground();
```

<a name="running-the-scheduler-locally"></a>
### Running the Scheduler Locally

Typically, you would not add a scheduler cron entry to your local development machine. Instead, you may use the `schedule work` console command. This command will run in the foreground and invoke the scheduler every minute until you terminate the command. When sub-minute tasks are defined, the scheduler will continue running within each minute to process those tasks:

```shell
bin/cake schedule work
```

For more advanced topics like task output, hooks, and events, see the [Integration guide](Integration.md).
