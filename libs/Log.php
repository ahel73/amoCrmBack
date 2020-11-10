<?php
/**
 * @author 2016 Dimitry Sountsov
 * @copyright Genezis, 2016
 * @link https://gnzs.ru
 */

namespace Log;

use DateTime;

class Log
{

    private $logfiledir;

    private $logfilename;

    public function __construct($dir = './log/', $prefix = 'core_')
    {
        $this->logfiledir = $dir;
        if (!file_exists($dir)) {
            mkdir($dir, 0700, true);
        }
        $this->logfilename = $this->logFile($prefix);
    }

    protected function logFile($prefix)
    {
        return $this->logfiledir . $prefix . strftime('%Y%m%d', time()) . '.html';
    }

    public function add($msg)
    {
        return file_put_contents($this->logfilename, strftime('%Y.%m.%d %H:%M:%S', time()) . $msg . PHP_EOL, FILE_APPEND);
    }
}