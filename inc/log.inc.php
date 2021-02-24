<?php

class Log
{
   
   const DEBUG = 3;
   const INFO = 2;
   const WARNING = 1;
   const ERROR = 0;
	
	private static $lastDay;
   
   private static $level = 0;
	
	public static function debug($text)
	{
      if (self::$level < self::DEBUG)
         return;
		self::output('[DEBUG] ', $text);
	}
   
	public static function info($text)
	{
      if (self::$level < self::INFO)
         return;
		self::output('[INFO] ', $text);
	}
	
	public static function warn($text)
	{
      if (self::$level < self::WARNING)
         return;
		self::output('[WARN] ', $text);
	}
	
	public static function error($text)
	{
		self::output('[ERROR] ', $text);
	}
   
   public static function setLogLevel($level)
   {
      self::$level = $level;
   }

	private static function output($prefix, $text)
	{
		if (LOG_WITH_TIME) {
			if (date('d') !== self::$lastDay) { // New day?
				echo '############################# ', date('d.m.Y'), " #############################\n";
				self::$lastDay = date('d');
			}
			echo '[', date('H:i:s'), '] ', $prefix, $text, "\n";
		} else {
			echo $prefix, $text, "\n";
		}
	}

}

if (posix_isatty(STDOUT)) {
	define('LOG_WITH_TIME', true);
} else {
	define('LOG_WITH_TIME', false);
}
