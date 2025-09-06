<?php
declare(strict_types=1);

namespace Scheduling\Command;

use Cake\Chronos\Chronos;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use SignalHandler\Command\Trait\SignalHandlerTrait;

/**
 * Schedule Work Command
 *
 * Continuously runs the scheduler in development mode.
 */
class ScheduleWorkCommand extends BaseSchedulerCommand
{
    use SignalHandlerTrait;

    /**
     * Whether the command is running
     *
     * @var bool
     */
    protected bool $isRunning = false;

    /**
     * Get the description.
     *
     * @return string
     */
    public static function getDescription(): string
    {
        return 'Continuously run the scheduler in development mode';
    }

    /**
     * Hook method for defining this command's option parser.
     *
     * @param \Cake\Console\ConsoleOptionParser $parser The parser to be defined
     * @return \Cake\Console\ConsoleOptionParser The built parser.
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser = parent::buildOptionParser($parser);
        $parser
            ->setDescription(self::getDescription())
            ->addOption('interval', [
                'short' => 'i',
                'help' => 'The interval in seconds between scheduler runs (default: 60)',
                'default' => '60',
            ])
            ->addOption('max-runs', [
                'short' => 'm',
                'help' => 'Maximum number of runs before stopping (0 = unlimited)',
                'default' => '0',
            ]);

        return $parser;
    }

    /**
     * Implement this method with your command's logic.
     *
     * @param \Cake\Console\Arguments $args The command arguments.
     * @param \Cake\Console\ConsoleIo $io The console io
     * @return int|null The exit code or null for success
     */
    public function execute(Arguments $args, ConsoleIo $io): ?int
    {
        $interval = (int)$args->getOption('interval');
        $maxRuns = (int)$args->getOption('max-runs');
        $verbose = (bool)$args->getOption('verbose');

        if ($interval < 1) {
            $io->error('Interval must be at least 1 second.');

            return static::CODE_ERROR;
        }

        $io->info('Starting scheduler worker...');
        $io->info(sprintf('Interval: %d seconds', $interval));
        $io->info(sprintf('Max runs: %s', $maxRuns === 0 ? 'unlimited' : (string)$maxRuns));
        $io->info('Press Ctrl+C to stop.');
        $io->out('');

        $runCount = 0;
        $startTime = time();

        $this->bindGracefulTermination(function (int $signal) use ($io): void {
            $this->handleGracefulTermination($signal, $io);
        });

        $this->isRunning = true;
        $lastExecutionStartedAt = \Cake\Chronos\Chronos::now()->subMinutes(10);

        while ($this->isRunning) {
            usleep(100 * 1000);

            $now = \Cake\Chronos\Chronos::now();

            $currentMinute = $now->format('Y-m-d H:i');
            $lastMinute = $lastExecutionStartedAt->format('Y-m-d H:i');

            if ($now->second === 0 && $currentMinute !== $lastMinute) {
                $runCount++;

                if ($verbose) {
                    $io->info(sprintf('Run #%d at %s', $runCount, $now->format('Y-m-d H:i:s')));
                }

                try {
                    $this->runScheduler($io, $verbose);
                } catch (\Throwable $e) {
                    $io->error(sprintf('Scheduler run failed: %s', $e->getMessage()));

                    if ($verbose) {
                        $io->out('Stack trace:');
                        $io->out($e->getTraceAsString());
                    }
                }

                $lastExecutionStartedAt = $now;

                if ($maxRuns > 0 && $runCount >= $maxRuns) {
                    $io->info(sprintf('Reached maximum runs (%d). Stopping.', $maxRuns));
                    break;
                }
            }
        }

        $this->unbindSignals();

        $totalTime = time() - $startTime;
        $io->success(sprintf('Scheduler worker stopped after %d runs in %d seconds.', $runCount, $totalTime));

        return static::CODE_SUCCESS;
    }

    /**
     * Run the scheduler once.
     *
     * @param \Cake\Console\ConsoleIo $io The console io
     * @param bool $verbose Whether to show verbose output
     * @return void
     */
    protected function runScheduler(ConsoleIo $io, bool $verbose): void
    {
        $schedule = $this->getSchedule();
        $dueEvents = $schedule->dueEvents();

        if (empty($dueEvents)) {
            if ($verbose) {
                $io->info('No scheduled events are due to run.');
            }

            return;
        }

        if ($verbose) {
            $io->info(sprintf('Running %d scheduled event(s)...', count($dueEvents)));
        }

        $startedAt = microtime(true);

        foreach ($dueEvents as $event) {
            if ($event->isRepeatable()) {
                continue;
            }

            if (!$event->filtersPass()) {
                if ($verbose) {
                    $io->info(sprintf('Skipping [%s] - filters did not pass.', $event->getSummaryForDisplay()));
                }
                continue;
            }

            if ($event->onOneServer && !$schedule->serverShouldRun($event, Chronos::now())) {
                if ($verbose) {
                    $io->info(sprintf('Skipping [%s] - already running on another server.', $event->getSummaryForDisplay()));
                }
                continue;
            }

            $this->runEvent($event, $io, $verbose);
        }

        $repeatableEvents = array_filter($dueEvents, function ($event) {
            return $event->isRepeatable();
        });

        if ($verbose) {
            $io->info(sprintf('Found %d repeatable events out of %d total events', count($repeatableEvents), count($dueEvents)));
        }

        if (!empty($repeatableEvents)) {
            $this->repeatEvents($repeatableEvents, $io, $verbose);
        }

        $runtime = round((microtime(true) - $startedAt) * 1000.0, 2);

        if ($verbose) {
            $io->success(sprintf('Scheduled events completed in %sms.', $runtime));
        }
    }

    /**
     * Run repeatable events in a tight loop for the rest of the minute.
     *
     * @param array<\Scheduling\Event> $events The repeatable events
     * @param \Cake\Console\ConsoleIo $io The console io
     * @param bool $verbose Whether to show verbose output
     * @return void
     */
    protected function repeatEvents(array $events, ConsoleIo $io, bool $verbose): void
    {
        $startedAt = \Cake\Chronos\Chronos::now();
        $endOfMinute = $startedAt->modify('59 seconds');

        if ($verbose) {
            $io->info(sprintf(
                'Running %d repeatable event(s) until %s...',
                count($events),
                $endOfMinute->format('H:i:s')
            ));
        }

        if ($verbose) {
            foreach ($events as $event) {
                $io->info(sprintf('  - %s (repeat every %ds)', $event->getSummaryForDisplay(), $event->repeatSeconds));
            }
        }

        while ($this->isRunning && \Cake\Chronos\Chronos::now()->lessThanOrEquals($endOfMinute)) {
            foreach ($events as $event) {
                if (!$this->isRunning) {
                    break;
                }

                if ($verbose) {
                    $io->info(sprintf('Checking if %s should repeat now...', $event->getSummaryForDisplay()));
                }

                if (!$event->shouldRepeatNow()) {
                    if ($verbose) {
                        $io->info('  -> Not ready to repeat yet');
                    }
                    continue;
                }

                if ($verbose) {
                    $io->info('  -> Ready to repeat! Running...');
                }

                if ($event->shouldSkipDueToOverlapping()) {
                    if ($verbose) {
                        $io->info(sprintf('Skipping repeatable [%s] - overlapping execution.', $event->getSummaryForDisplay()));
                    }
                    continue;
                }

                if (!$event->filtersPass()) {
                    if ($verbose) {
                        $io->info(sprintf('Skipping repeatable [%s] - other filters did not pass.', $event->getSummaryForDisplay()));
                    }
                    continue;
                }

                if ($event->onOneServer) {
                    $schedule = $this->getSchedule();
                    if (!$schedule->serverShouldRun($event, Chronos::now())) {
                        if ($verbose) {
                            $io->info(sprintf('Skipping repeatable [%s] - already running on another server.', $event->getSummaryForDisplay()));
                        }
                        continue;
                    }
                }

                $this->runEvent($event, $io, $verbose);
            }

            if (!$this->isRunning) {
                break;
            }

            usleep(100 * 1000);
        }

        if ($verbose) {
            $runtime = round((microtime(true) - (float)$startedAt->timestamp) * 1000.0, 2);
            $io->info(sprintf('Repeatable events loop completed in %sms.', $runtime));
        }
    }

    /**
     * Run a single scheduled event.
     *
     * @param \Scheduling\Event $event The event to run
     * @param \Cake\Console\ConsoleIo $io The console io
     * @param bool $verbose Whether to show verbose output
     * @return void
     */
    protected function runEvent($event, ConsoleIo $io, bool $verbose): void
    {
        $summary = $event->getSummaryForDisplay();

        if ($verbose) {
            $io->info(sprintf('Running scheduled command: %s', $summary));
        }

        $startedAt = microtime(true);

        try {
            $event->run();
            $runtime = round((microtime(true) - $startedAt) * 1000.0, 2);

            if ($verbose) {
                $io->success(sprintf('Successfully ran scheduled command: %s (%sms)', $summary, $runtime));
            }
        } catch (\Throwable $e) {
            $io->error(sprintf('Failed to run scheduled command: %s - %s', $summary, $e->getMessage()));
        }
    }

    /**
     * Handle graceful termination signal.
     *
     * @param int $signal The signal received
     * @param \Cake\Console\ConsoleIo $io The console io
     * @return void
     */
    protected function handleGracefulTermination(int $signal, ConsoleIo $io): void
    {
        $io->out('');
        $io->info('Received termination signal. Stopping scheduler worker...');
        $this->isRunning = false;
    }
}
