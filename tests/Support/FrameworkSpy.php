<?php

declare(strict_types=1);

namespace Ganesh\MyadminScrubIps\Tests\Support;

/**
 * Collects the side effects the Plugin's hook handlers push out into the MyAdmin
 * framework: log lines, history entries, Wanguard calls, ORM writes and chat notices.
 *
 * MyAdmin is not a dependency of this package, so this is what lets a test assert
 * on what a handler actually did instead of on how its source happens to be written.
 */
final class FrameworkSpy
{
    /** @var array<int, array<int, mixed>> positional arguments of every myadmin_log() call */
    public static $logs = [];

    /** @var array<int, array<int, mixed>> positional arguments of every App::history()->add() call */
    public static $history = [];

    /** @var array<int, array<int, mixed>> messages passed to chatNotify() */
    public static $notifications = [];

    /** @var array<int, array<int, mixed>> Wanguard calls, as [method, ...args] */
    public static $wanguard = [];

    /** @var array<int, array<int, mixed>> ORM calls, as [method, ...args] */
    public static $orm = [];

    /** @var array<int, string> subjects of admin mails sent */
    public static $adminMails = [];

    /** @var array<int|string, array<string, mixed>> rows run_event('get_service_types') hands back */
    public static $serviceTypes = [];

    public static function reset(): void
    {
        self::$logs = [];
        self::$history = [];
        self::$notifications = [];
        self::$wanguard = [];
        self::$orm = [];
        self::$adminMails = [];
        self::$serviceTypes = [];
        DbDouble::reset();
    }

    /**
     * Log calls made at the given level ('info', 'error', ...).
     *
     * @return array<int, array<int, mixed>>
     */
    public static function logsWithLevel(string $level): array
    {
        return array_values(array_filter(self::$logs, static function (array $log) use ($level) {
            return isset($log[1]) && $log[1] === $level;
        }));
    }
}
