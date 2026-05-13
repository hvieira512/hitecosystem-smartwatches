<?php

namespace App\Log;

use Monolog\Logger as MonologLogger;
use Monolog\Handler\StreamHandler;
use Monolog\Formatter\LineFormatter;

class Logger
{
    private static array $instances = [];

    public static function channel(string $name = 'app'): MonologLogger
    {
        if (isset(self::$instances[$name])) {
            return self::$instances[$name];
        }

        $levelName = strtoupper(getenv('LOG_LEVEL') ?: 'info');
        $level = defined("Monolog\Logger::$levelName")
            ? constant("Monolog\Logger::$levelName")
            : MonologLogger::INFO;

        $formatter = new LineFormatter(
            "[%datetime%] %channel%.%level_name%: %message% %context%\n",
            'Y-m-d H:i:s'
        );

        $handler = new StreamHandler('php://stdout', $level);
        $handler->setFormatter($formatter);

        $log = new MonologLogger($name);
        $log->pushHandler($handler);

        $logFile = getenv('LOG_FILE');
        if ($logFile) {
            $dir = dirname($logFile);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
            $fileHandler = new StreamHandler($logFile, $level);
            $fileHandler->setFormatter($formatter);
            $log->pushHandler($fileHandler);
        }

        self::$instances[$name] = $log;
        return $log;
    }
}
