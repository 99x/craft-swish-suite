<?php

namespace NinetyNineX\SwishSuite\services;

use Craft;
use NinetyNineX\SwishSuite\SwishSuite;
use yii\base\Component;

class HelperFunctionService extends Component
{
    /**
     * Writes a log entry directly to a daily rotating file under Craft's log path.
     * Falls back to Craft's native logger when the file cannot be opened.
     *
     * Using direct I/O (instead of the queue) guarantees that entries are written
     * immediately and are not lost if the queue runner is unavailable.
     */
    public function log(string $message, string $level = 'info', ?string $category = null): void
    {
        $logsEnabled = true;

        try {
            $logsEnabled = SwishSuite::getInstance()->getSettings()->logsEnabled;
        } catch (\Exception $exception) {
            Craft::warning(
                'Could not determine Swish Suite logging settings: ' . $exception->getMessage(),
                __METHOD__
            );
        }

        if (!$logsEnabled) {
            // Always pass errors through to Craft's native logger regardless of the setting.
            if ($level === 'error') {
                Craft::error('[SwishSuite] ' . $message, $category ?? __METHOD__);
            }
            return;
        }

        $level = in_array(strtolower($level), ['info', 'warning', 'error', 'debug'], true)
            ? strtolower($level)
            : 'info';
        $logEntry = sprintf(
            "[%s] [%s] %s%s\n",
            date('Y-m-d H:i:s'),
            $level,
            $category !== null ? '[' . $category . '] ' : '',
            $message
        );

        $written = $this->writeToFile($logEntry);

        if (!$written || $level === 'error') {
            // Mirror errors (and file-write failures) to Craft's native logger.
            $prefix = '[SwishSuite] ' . ($category !== null ? '[' . $category . '] ' : '');
            match ($level) {
                'error' => Craft::error($prefix . $message, __METHOD__),
                'warning' => Craft::warning($prefix . $message, __METHOD__),
                default => Craft::info($prefix . $message, __METHOD__),
            };
        }
    }

    public function logInfo(string $message, ?string $category = null): void
    {
        $this->log($message, 'info', $category);
    }

    public function logError(string $message, ?string $category = null): void
    {
        $this->log($message, 'error', $category);
    }

    public function logWarning(string $message, ?string $category = null): void
    {
        $this->log($message, 'warning', $category);
    }

    public function logDebug(string $message, ?string $category = null): void
    {
        $this->log($message, 'debug', $category);
    }

    /**
     * Appends a pre-formatted log entry to the daily log file.
     * Returns true on success, false if the file could not be opened or written.
     */
    private function writeToFile(string $logEntry): bool
    {
        try {
            $logsPath = Craft::$app->getPath()->getLogPath();
            $filePath = $logsPath . DIRECTORY_SEPARATOR . 'swish-suite-' . date('Y-m-d') . '.log';

            $bytes = file_put_contents($filePath, $logEntry, FILE_APPEND | LOCK_EX);

            return $bytes !== false;
        } catch (\Throwable) {
            return false;
        }
    }
}
