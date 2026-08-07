<?php

namespace NinetyNineX\SwishSuite\queue\jobs;

use Craft;
use craft\queue\BaseJob;

class StoreLogJob extends BaseJob
{
    public string $message = '';
    public string $level = 'info';
    public ?string $category = null;
    public string $date = '';

    public function execute($queue): void
    {
        $logsPath = Craft::$app->getPath()->getLogPath();
        $filename = 'swish-suite-' . $this->date . '.log';
        $filePath = $logsPath . DIRECTORY_SEPARATOR . $filename;
        $level = in_array(strtolower($this->level), ['info', 'warning', 'error', 'debug'], true)
            ? strtolower($this->level)
            : 'info';
        $logEntry = sprintf(
            "[%s] [%s] %s%s\n",
            date('Y-m-d H:i:s'),
            $level,
            $this->category !== null ? '[' . $this->category . '] ' : '',
            $this->message
        );

        $file = @fopen($filePath, 'a');
        if ($file === false) {
            return;
        }

        @flock($file, LOCK_EX);
        @fwrite($file, $logEntry);
        @flock($file, LOCK_UN);
        @fclose($file);
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('swish-suite', 'Write Swish Suite log entry');
    }
}
