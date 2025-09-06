<?php
declare(strict_types=1);

namespace Scheduling;

use Cake\Chronos\Chronos;
use Cake\Event\Event as CakeEvent;
use Cake\Event\EventManager;
use Cron\CronExpression;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Event
 *
 * Represents a scheduled event that can be executed.
 */
class Event
{
    use ManageAttributesTrait;
    use ManageFrequenciesTrait;

    /**
     * The command string.
     *
     * @var string|null
     */
    public ?string $command = null;

    /**
     * Get the command string.
     *
     * @return string The command string
     * @throws \LogicException If command is not set
     */
    public function getCommand(): string
    {
        if ($this->command === null) {
            throw new \LogicException('Command is not set for this event.');
        }

        return $this->command;
    }

    /**
     * The location that output should be sent to.
     *
     * @var string
     */
    public string $output = '/dev/null';

    /**
     * Indicates whether output should be appended.
     *
     * @var bool
     */
    public bool $shouldAppendOutput = false;

    /**
     * The array of callbacks to be run before the event is started.
     *
     * @var array<callable>
     */
    protected array $beforeCallbacks = [];

    /**
     * The array of callbacks to be run after the event is finished.
     *
     * @var array<callable>
     */
    protected array $afterCallbacks = [];

    /**
     * The event mutex implementation.
     *
     * @var \Scheduling\EventMutexInterface
     */
    public EventMutexInterface $mutex;

    /**
     * The last time the event was checked for eligibility to run.
     * Utilized by sub-minute repeated events.
     *
     * @var \Cake\Chronos\Chronos|null
     */
    protected ?Chronos $lastChecked = null;

    /**
     * The exit status code of the command.
     *
     * @var int|null
     */
    public ?int $exitCode = null;

    /**
     * Create a new event instance.
     *
     * @param \Scheduling\EventMutexInterface $mutex The mutex implementation
     * @param string $command The command to execute
     * @param \DateTimeZone|string|null $timezone The timezone
     */
    public function __construct(EventMutexInterface $mutex, string $command, $timezone = null)
    {
        $this->mutex = $mutex;
        $this->command = $command;
        $this->timezone = $timezone;

        $this->output = $this->getDefaultOutput();
    }

    /**
     * Get the default output depending on the OS.
     *
     * @return string The default output path
     */
    public function getDefaultOutput(): string
    {
        return DIRECTORY_SEPARATOR === '\\' ? 'NUL' : '/dev/null';
    }

    /**
     * Run the given event.
     *
     * @return void
     * @throws \Throwable
     */
    public function run(): void
    {
        if ($this->shouldSkipDueToOverlapping()) {
            $this->dispatchSchedulerEvent(new \Scheduling\Event\ScheduledTaskSkipped($this));

            return;
        }

        if ($this->isRepeatable()) {
            $this->lastChecked = Chronos::now();
            if ($this->timezone) {
                $this->lastChecked = $this->lastChecked->setTimezone($this->timezone);
            }
        }

        $exitCode = $this->start();

        if (!$this->runInBackground) {
            $this->finish($exitCode);
        }
    }

    /**
     * Determine if the event should skip because another process is overlapping.
     *
     * @return bool True if should skip
     */
    public function shouldSkipDueToOverlapping(): bool
    {
        if (!$this->withoutOverlapping) {
            return false;
        }

        $customExpiresAt = null;
        if ($this->isRepeatable()) {
            $customExpiresAt = $this->repeatSeconds * 2;
        }

        $created = $this->mutex->create($this, $customExpiresAt);

        if ($this->isRepeatable() && $created) {
            $this->lastChecked = Chronos::now();
            if ($this->timezone) {
                $this->lastChecked = $this->lastChecked->setTimezone($this->timezone);
            }
        }

        return !$created;
    }

    /**
     * Determine if the event has been configured to repeat multiple times per minute.
     *
     * @return bool True if repeatable
     */
    public function isRepeatable(): bool
    {
        return $this->repeatSeconds !== null;
    }

    /**
     * Determine if the event is ready to repeat.
     *
     * @return bool True if should repeat now
     */
    public function shouldRepeatNow(): bool
    {
        if (!$this->isRepeatable()) {
            return false;
        }

        if ($this->lastChecked === null) {
            return true;
        }

        return $this->lastChecked->diffInSeconds() >= $this->repeatSeconds;
    }

    /**
     * Run the command process.
     *
     * @return int The exit code
     * @throws \Throwable
     */
    protected function start(): int
    {
        try {
            $this->callBeforeCallbacks();

            return $this->execute();
        } catch (Throwable $exception) {
            $this->removeMutex();

            throw $exception;
        }
    }

    /**
     * Run the command process.
     *
     * @return int The exit code
     */
    protected function execute(): int
    {
        $process = Process::fromShellCommandline(
            $this->buildCommand(),
            ROOT,
            null,
            null,
            null
        );

        if (!$this->runInBackground) {
            return $this->runForegroundProcess($process);
        }

        return $process->run();
    }

    /**
     * Run a foreground process with output handling.
     *
     * @param \Symfony\Component\Process\Process $process The process
     * @return int The exit code
     */
    protected function runForegroundProcess($process): int
    {
        if ($this->output !== $this->getDefaultOutput()) {
            return $process->run();
        }

        $process->run(function ($type, $buffer) use ($process): void {
            if ($type === $process::ERR) {
                \Cake\Log\Log::error('Scheduled command error: ' . trim($buffer));
            }
        });

        return $process->getExitCode() ?? 1;
    }

    /**
     * Mark the command process as finished and run callbacks/cleanup.
     *
     * @param int $exitCode The exit code
     * @return void
     */
    public function finish(int $exitCode): void
    {
        $this->exitCode = $exitCode;

        try {
            $this->callAfterCallbacks();
        } finally {
            $this->removeMutex();
        }
    }

    /**
     * Call all of the "before" callbacks for the event.
     *
     * @return void
     */
    public function callBeforeCallbacks(): void
    {
        foreach ($this->beforeCallbacks as $callback) {
            call_user_func($callback);
        }
    }

    /**
     * Call all of the "after" callbacks for the event.
     *
     * @return void
     */
    public function callAfterCallbacks(): void
    {
        foreach ($this->afterCallbacks as $callback) {
            call_user_func($callback);
        }
    }

    /**
     * Build the command string.
     *
     * @return string The built command
     */
    public function buildCommand(): string
    {
        return (new CommandBuilder())->buildCommand($this);
    }

    /**
     * Determine if the given event should run based on the Cron expression.
     *
     * @return bool True if due
     */
    public function isDue(): bool
    {
        if (!$this->expressionPasses()) {
            return false;
        }

        if ($this->repeatSeconds !== null) {
            return $this->shouldRepeat();
        }

        return true;
    }

    /**
     * Determine if the event runs in maintenance mode.
     *
     * @return bool True if runs in maintenance mode
     */
    public function runsInMaintenanceMode(): bool
    {
        return $this->evenInMaintenanceMode;
    }

    /**
     * Determine if the Cron expression passes.
     *
     * @return bool True if expression passes
     */
    protected function expressionPasses(): bool
    {
        $date = Chronos::now();

        if ($this->timezone) {
            $date = $date->setTimezone($this->timezone);
        }

        return (new CronExpression($this->expression))->isDue($date->toDateTimeString());
    }

    /**
     * Determine if the event should repeat based on the repeat seconds interval.
     *
     * @return bool True if should repeat
     */
    protected function shouldRepeat(): bool
    {
        if ($this->lastChecked === null) {
            return true;
        }

        $now = Chronos::now();
        if ($this->timezone) {
            $now = $now->setTimezone($this->timezone);
        }

        $secondsSinceLastCheck = $now->diffInSeconds($this->lastChecked, false);

        return $secondsSinceLastCheck >= $this->repeatSeconds;
    }

    /**
     * Determine if the filters pass for the event.
     *
     * @return bool True if filters pass
     */
    public function filtersPass(): bool
    {
        foreach ($this->filters as $callback) {
            $result = call_user_func($callback);
            if (!$result) {
                return false;
            }
        }

        foreach ($this->rejects as $callback) {
            $result = call_user_func($callback);
            if ($result) {
                return false;
            }
        }

        return true;
    }

    /**
     * Register a callback to be called before the operation.
     *
     * @param callable $callback The callback
     * @return $this
     */
    public function before(callable $callback)
    {
        $this->beforeCallbacks[] = $callback;

        return $this;
    }

    /**
     * Register a callback to be called after the operation.
     *
     * @param callable $callback The callback
     * @return $this
     */
    public function after(callable $callback)
    {
        return $this->then($callback);
    }

    /**
     * Register a callback to be called after the operation.
     *
     * @param callable $callback The callback
     * @return $this
     */
    public function then(callable $callback)
    {
        $this->afterCallbacks[] = $callback;

        return $this;
    }

    /**
     * Register a callback to be called if the operation succeeds.
     *
     * @param callable $callback The callback
     * @return $this
     */
    public function onSuccess(callable $callback)
    {
        return $this->then(function ($app) use ($callback): void {
            if ($this->exitCode === 0) {
                $app->call($callback);
            }
        });
    }

    /**
     * Register a callback to be called if the operation fails.
     *
     * @param callable $callback The callback
     * @return $this
     */
    public function onFailure(callable $callback)
    {
        return $this->then(function ($app) use ($callback): void {
            if ($this->exitCode !== 0) {
                $app->call($callback);
            }
        });
    }

    /**
     * Get the summary of the event for display.
     *
     * @return string The summary
     */
    public function getSummaryForDisplay(): string
    {
        if (is_string($this->description)) {
            return $this->description;
        }

        return $this->buildCommand();
    }

    /**
     * Determine the next due date for an event.
     *
     * @param \DateTimeInterface|string $currentTime The current time
     * @param int $nth The nth occurrence
     * @param bool $allowCurrentDate Whether to allow current date
     * @return \Cake\Chronos\Chronos The next run date
     */
    public function nextRunDate($currentTime = 'now', int $nth = 0, bool $allowCurrentDate = false): Chronos
    {
        $timezone = $this->timezone instanceof \DateTimeZone ? $this->timezone->getName() : $this->timezone;

        return Chronos::instance((new CronExpression($this->getExpression()))
            ->getNextRunDate($currentTime, $nth, $allowCurrentDate, $timezone));
    }

    /**
     * Get the Cron expression for the event.
     *
     * @return string The cron expression
     */
    public function getExpression(): string
    {
        return $this->expression;
    }

    /**
     * Get the mutex name for the scheduled command.
     *
     * @return string The mutex name
     */
    public function mutexName(): string
    {
        return 'schedule-' .
            sha1($this->expression . $this->normalizeCommand($this->command ?? ''));
    }

    /**
     * Delete the mutex for the event.
     *
     * @return void
     */
    protected function removeMutex(): void
    {
        if ($this->withoutOverlapping) {
            $this->mutex->forget($this);
        }
    }

    /**
     * Format the given command string with a normalized PHP binary path.
     *
     * @param string $command The command
     * @return string The normalized command
     */
    public static function normalizeCommand(string $command): string
    {
        return str_replace([
            PHP_BINARY,
            'bin/cake.php',
        ], [
            'php',
            'bin/cake.php',
        ], $command);
    }

    /**
     * Dispatch a scheduler event.
     *
     * @param \Cake\Event\Event $event The event to dispatch
     * @return void
     */
    protected function dispatchSchedulerEvent(CakeEvent $event): void
    {
        EventManager::instance()->dispatch($event);
    }
}
