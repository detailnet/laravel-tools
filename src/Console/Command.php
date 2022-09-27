<?php

declare(strict_types=1);

namespace Detail\Laravel\Console;

use DateTime;
use Illuminate\Console\Command as BaseCommand;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Command\SignalableCommandInterface;
use Symfony\Component\Console\Output\OutputInterface as Output;
use Throwable;
use function call_user_func;
use function class_basename;
use function date;
use function floor;
use function get_class;
use function intval;
use function microtime;
use function range;
use function round;
use function sprintf;

abstract class Command extends BaseCommand implements SignalableCommandInterface
{
    public const OPTION_TIMEOUT = 'timeout';
    public const OPTION_INTERVAL = 'interval';
    public const OPTION_MAX_RUNS = 'max-runs';
    public const OPTION_DIE_ON_SUCCESS = 'die-on-success';

    protected const MAX_RUNTIME = 3600; // One hour, used to calculate the max-runs if not passed on command execution

    protected const OPTIONS = [
        self::OPTION_TIMEOUT =>
            ' {--timeout= : Timeout between runs in seconds within max runtime of ' . self::MAX_RUNTIME . '. '
            . 'If bigger than ' . self::MAX_RUNTIME . ' you should pass the "' . self::OPTION_MAX_RUNS . '" option too.}',
        self::OPTION_INTERVAL =>
            ' {--interval= : Interval between runs in seconds within max runtime of ' . self::MAX_RUNTIME . '. '
            . 'If runtime longer than given interval the function is triggered right after completion, '
            . 'otherwise waits the missing interval before re-run. '
            . 'If bigger than ' . self::MAX_RUNTIME . ' you should pass the "' . self::OPTION_MAX_RUNS . '" option too.}',
        self::OPTION_MAX_RUNS =>
            '{--max-runs= : Max runs before soft termination occurs, used in combination with timout or interval, '
            . 'default is ' . self::MAX_RUNTIME . ' seconds divided by timeout or interval.}',
        self::OPTION_DIE_ON_SUCCESS =>
            ' {--die-on-success : After a successful execution and awaited the specified time (timeout or interval), '
            . 'stops function execution. This permits a clean restart to free all resources. '
            . 'To be used in combination with a scheduler that respawns the worker on exit.}',
    ];

    protected const ALL_OPTIONS =
        self::OPTIONS[self::OPTION_TIMEOUT] .
        self::OPTIONS[self::OPTION_INTERVAL] .
        self::OPTIONS[self::OPTION_MAX_RUNS] .
        self::OPTIONS[self::OPTION_DIE_ON_SUCCESS];

    /**
     * Is shutdown requested?
     *
     * This is the flag for a graceful shutdown.
     * Ref: https://help.fortrabbit.com/worker-pro#toc-graceful-shutdown
     */
    protected bool $shutdown = false;
    // Some commands might want to output what a sub-function call logs, simply toggle $this->outputLog before the specific function.
    protected bool $outputLog = false; // Initially set to false

    public final function handle(): int
    {
        $commandName = class_basename(static::class);
        $options = $this->options();

        Log::withContext(['command' => $commandName]);
        Log::info('Handle ' . $commandName, $options);
        Log::listen(
            function (MessageLogged $message): void {
                if ($this->outputLog) {
                    $this->line($message->message, null, $message->level);
                }
            }
        );

        $timeout = $options[self::OPTION_TIMEOUT] ?? null;
        $timeout = $timeout !== null ? intval($timeout) : null;
        $interval = $options[self::OPTION_INTERVAL] ?? null;
        $interval = $interval !== null ? intval($interval) : null;
        $dieOnSuccess = (bool) ($options[self::OPTION_DIE_ON_SUCCESS] ?? false);
        $remainingRuns = 1;

        if ($timeout !== null || $interval !== null) {
            $remainingRuns = intval(
                $options[self::OPTION_MAX_RUNS] ?? (string) floor(self::MAX_RUNTIME / (($timeout ?? 1) + ($interval ?? 0)))
            );
            if ($remainingRuns === 0) {
                $this->warn(
                    sprintf(
                        'Timeout or interval bigger than %d (max runtime), you should set "%s" option too.',
                        self::MAX_RUNTIME,
                        self::OPTION_MAX_RUNS
                    )
                );

                $remainingRuns = 1; // Run at least once
            }

            $this->verbose(sprintf('Maximum run iterations set to %d', $remainingRuns));
        }

        // Using a global try/catch block to let even long-running scripts fail
        try {
            while (true) {
                if (--$remainingRuns < 0) {
                    $this->verbose('Maximum run iterations reached');
                    exit(0);
                }

                Log::info('Start ' . $commandName);

                $startedOn = microtime(true);
                $success = $this->handleCommand();
                $runtime = microtime(true) - $startedOn;

                Log::info('Finished ' . $commandName, ['success' => $success, 'runtime' => round($runtime, 3)]);

                $runtime = (int) $runtime;

                if ($timeout !== null || $interval !== null) {
                    // Detach managed data from entity/document manager, this is needed because as long running
                    // script, the actual managed data might be changed in the database from other processes.
                    // If not detached, the cached data will be returned, and not the real (current) one.
                    // see: http://doctrine-orm.readthedocs.org/en/latest/reference/working-with-objects.html#detaching-entities
                    //$this->clearObjectManagers();

                    if ($interval !== null) {
                        $timeout = $interval > $runtime ? $interval - $runtime : null;

                        $this->verbose(
                            sprintf(
                                'Runtime %d [s]: %d',
                                $runtime,
                                $timeout !== null ? sprintf('Set timeout to %d [s]', $timeout) : 'Restarting immediately'
                            ),
                            2,
                            false
                        );
                    }

                    if ($timeout !== null) {
                        $this->verbose(sprintf('Sleeping for %d seconds', $timeout), 2, false);
                        $this->sleep($timeout);
                    }

                    if ($dieOnSuccess && $success === true) {
                        $this->verbose('Halting execution on success');
                        exit(0);
                    }
                } else {
                    break;
                }
            }
        } catch (Throwable $e) {
            // Pretty exceptions for console
            $i = 0;

            while ($e !== null) {
                $this->error(
                    sprintf(
                        'Exception #%d: %s (%s)',
                        ++$i,
                        $e->getMessage(),
                        get_class($e)
                    )
                );

                $this->error(
                    sprintf(
                        "Exception #%d trace:\n%s",
                        $i,
                        $e->getTraceAsString()
                    ),
                    1
                );

                $e = $e->getPrevious();
            }

            /** @todo We should log it */

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    abstract public function handleCommand(): bool;

    protected function verbose(string $message, int $level = Output::VERBOSITY_VERBOSE, bool $log = true): void
    {
        $this->info(
            $message,
            match ($level) {
                3, Output::VERBOSITY_DEBUG => Output::VERBOSITY_DEBUG,
                2, Output::VERBOSITY_VERY_VERBOSE => Output::VERBOSITY_VERY_VERBOSE,
                default => Output::VERBOSITY_VERBOSE,
            },
            $log
        );
    }

    public function info($string, $verbosity = null, bool $log = true)
    {
        if ($log) {
            Log::info($string);
        }

        parent::info($string, $verbosity);
    }

    /**
     *  Sleeps for the given amount of time, but graceful exit if requested.
     */
    protected function sleep(int $timeout): void
    {
        $msgPattern = 'Shutting down safely while in sleep (slept for %d out of %d seconds)';

        foreach (range(1, $timeout) as $counter) {
            $this->shutdownWatchdog(sprintf($msgPattern, $counter - 1, $timeout));

            sleep(1);
        }
    }

    /**
     * Watchdog for graceful shutdown
     *
     * This watchdog should be placed in every processing loop, where a graceful shutdown can be done.
     * Can optionally pass a callback to execute if shutdown received.
     *
     * @param mixed[] $callbackParams
     */
    protected function shutdownWatchdog(string $message = null, callable $callback = null, array $callbackParams = []): void
    {
        if ($this->shutdown) {
            if ($callback !== null) {
                $this->error('Running shutdown callback');

                call_user_func($callback, $callbackParams);
            }

            $this->error($message ?: 'Shutting down safely');

            exit(0);
        }
    }

    public function error($string, $verbosity = null, bool $log = true)
    {
        if ($log) {
            Log::error($string);
        }

        parent::error($string, $verbosity);
    }

    public function warn($string, $verbosity = null, bool $log = true)
    {
        if ($log) {
            Log::warning($string);
        }

        parent::warn($string, $verbosity);
    }

    /**
     * Get the list of signals handled by the command.
     *
     * @return int[]
     */
    public function getSubscribedSignals(): array
    {
        return [SIGINT, SIGTERM];
    }

    /**
     * Handle an incoming signal.
     *
     * @param int $signal
     *
     * @return void
     */
    public function handleSignal(int $signal): void
    {
        $this->error(sprintf('Received shutdown signal %d', $signal));

        if ($this->shutdown) { // Twice <Ctrl>+C => Hard kill
            $this->error('Received shutdown signal more than once, exiting now');

            exit(0);
        }

        $this->shutdown = true;
    }

    protected function getTime(): string
    {
        $time = microtime(true);
        $micro = sprintf("%06d", ($time - floor($time)) * 1000000);
        $date = new DateTime(date('Y-m-d H:i:s.' . $micro, intval($time)));

        return $date->format("Y-m-d H:i:s.u");
    }
}
