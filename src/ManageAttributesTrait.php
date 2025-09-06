<?php
declare(strict_types=1);

namespace Scheduling;

/**
 * Manage Attributes Trait
 *
 * Provides common attributes and methods for scheduled events.
 */
trait ManageAttributesTrait
{
    /**
     * The cron expression representing the event's frequency.
     *
     * @var string
     */
    public string $expression = '* * * * *';

    /**
     * How often to repeat the event during a minute.
     *
     * @var int|null
     */
    public ?int $repeatSeconds = null;

    /**
     * The timezone the date should be evaluated on.
     *
     * @var \DateTimeZone|string|null
     */
    public $timezone = null;

    /**
     * The user the command should run as.
     *
     * @var string|null
     */
    public ?string $user = null;

    /**
     * Indicates if the command should run in maintenance mode.
     *
     * @var bool
     */
    public bool $evenInMaintenanceMode = false;

    /**
     * Indicates if the command should not overlap itself.
     *
     * @var bool
     */
    public bool $withoutOverlapping = false;

    /**
     * Indicates if the command should only be allowed to run on one server for each cron expression.
     *
     * @var bool
     */
    public bool $onOneServer = false;

    /**
     * The number of minutes the mutex should be valid.
     *
     * @var int
     */
    public int $expiresAt = 1440;

    /**
     * Indicates if the command should run in the background.
     *
     * @var bool
     */
    public bool $runInBackground = false;

    /**
     * The array of filter callbacks.
     *
     * @var array<callable>
     */
    protected array $filters = [];

    /**
     * The array of reject callbacks.
     *
     * @var array<callable>
     */
    protected array $rejects = [];

    /**
     * The human readable description of the event.
     *
     * @var string|null
     */
    public ?string $description = null;

    /**
     * Set which user the command should run as.
     *
     * @param string $user The user to run as
     * @return $this
     */
    public function user(string $user)
    {
        $this->user = $user;

        return $this;
    }

    /**
     * State that the command should run even in maintenance mode.
     *
     * @return $this
     */
    public function evenInMaintenanceMode()
    {
        $this->evenInMaintenanceMode = true;

        return $this;
    }

    /**
     * Do not allow the event to overlap each other.
     * The expiration time of the underlying cache lock may be specified in minutes.
     *
     * @param int $expiresAt The expiration time in minutes
     * @return $this
     */
    public function withoutOverlapping(int $expiresAt = 1440)
    {
        $this->withoutOverlapping = true;
        $this->expiresAt = $expiresAt;

        return $this->skip(function () {
            $customTtlSeconds = null;
            if ($this->isRepeatable()) {
                $customTtlSeconds = $this->repeatSeconds * 2;
            }

            return $this->mutex->exists($this, $customTtlSeconds);
        });
    }

    /**
     * Allow the event to only run on one server for each cron expression.
     *
     * @return $this
     */
    public function onOneServer()
    {
        $this->onOneServer = true;

        return $this;
    }

    /**
     * State that the command should run in the background.
     *
     * @return $this
     */
    public function runInBackground()
    {
        $this->runInBackground = true;

        return $this;
    }

    /**
     * Register a callback to further filter the schedule.
     *
     * @param callable|bool $callback The callback or boolean value
     * @return $this
     */
    public function when($callback)
    {
        $this->filters[] = is_callable($callback) ? $callback : function () use ($callback) {
            return $callback;
        };

        return $this;
    }

    /**
     * Register a callback to further filter the schedule.
     *
     * @param callable|bool $callback The callback or boolean value
     * @return $this
     */
    public function skip($callback)
    {
        $this->rejects[] = is_callable($callback) ? $callback : function () use ($callback) {
            return $callback;
        };

        return $this;
    }

    /**
     * Set the human-friendly description of the event.
     *
     * @param string $description The description
     * @return $this
     */
    public function name(string $description)
    {
        return $this->description($description);
    }

    /**
     * Set the human-friendly description of the event.
     *
     * @param string $description The description
     * @return $this
     */
    public function description(string $description)
    {
        $this->description = $description;

        return $this;
    }

    /**
     * Send the output of the command to a given location.
     *
     * @param string $location The output location
     * @param bool $append Whether to append to the file
     * @return $this
     */
    public function sendOutputTo(string $location, bool $append = false)
    {
        $this->output = $location;
        $this->shouldAppendOutput = $append;

        return $this;
    }

    /**
     * Append the output of the command to a given location.
     *
     * @param string $location The output location
     * @return $this
     */
    public function appendOutputTo(string $location)
    {
        return $this->sendOutputTo($location, true);
    }
}
