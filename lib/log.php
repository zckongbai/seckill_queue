<?php

class Log
{
    protected $display = true;

    const TRACE   = 0;
    const INFO    = 1;
    const NOTICE  = 2;
    const WARN    = 3;
    const ERROR   = 4;
    
    protected static $level_code = array(
        'TRACE' => 0,
        'INFO' => 1,
        'NOTICE' => 2,
        'WARN' => 3,
        'ERROR' => 4,
    );

    protected static $level_str = array(
        'TRACE',
        'INFO',
        'NOTICE',
        'WARN',
        'ERROR',
    );

    static $date_format = '[Y-m-d H:i:s]';

    function __construct($config='')
    {
		$this->config = $config ? : array(
				'level'	=>	'INFO',
		    	'type' => 'FileLog',
		    	'file' => '/data/log/app/seckill/http.log',
			);
		$this->level = $this->config['level'] ? : "INFO";
		$this->setLevel(intval(self::$level_code[$this->config['level']]));
    }

    function put($msg, $level = self::INFO)
    {
        if ($this->display)
        {
            $log = $this->format($msg, $level);
            file_put_contents($this->config['file'], $log, FILE_APPEND);
        }
    }


    function setLevel($level = self::TRACE)
    {
        $this->level_line = $level;
    }

    static function convert($level)
    {
        if (!is_numeric($level))
        {
            $level = self::$level_code[strtoupper($level)];
        }
        return $level;
    }
    function format($msg, $level, &$date = null)
    {
        $level = self::convert($level);
        if ($level < $this->level_line)
        {
            return false;
        }
        $level_str = self::$level_str[$level];

        $now = new \DateTime('now');
        $date = $now->format('Ymd');
        // $log = $now->format(self::$date_format)."\t{$level_str}\t{$msg}";

        $microtime = explode(" ", microtime())[0];

        $log = $now->format(self::$date_format). "\t$microtime" ."\t{$level_str}\t{$msg}";
   
        $log .= "\n";

        return $log;
    }


    function format2($msg, $level)
    {
        $level = self::convert($level);
        if ($level < $this->level_line)
        {
            return false;
        }
        $level_str = self::$level_str[$level];
        return date(self::$date_format)."\t{$level_str}\t{$msg}\n";
    }

}