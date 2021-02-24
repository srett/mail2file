<?php

class Mainloop
{
   
   /**
    * Since the stream_socket API is completely fucked up when it comes to non-blocking IO, we need
    * this abomination with temporarily changing the error handler and saving the error code somewhere,
    * since in the 16+ years since the socket_* API has been deprecated, nobody thought it would be
    * reasonable to add sane error handling to the stream_socket_* API.
    * @var type 
    */
   public static $errno = 0;

   /**
    * @var AbstractSocket[]
    */
   private static $sockets = [];
   private static $readResources = [];
   private static $readSocketLookup = [];
   
   /**
    * @var Timer[]
    */
   private static $timers = [];

   public static function registerSocket(AbstractSocket $instance)
   {
      if (array_search($instance, self::$sockets, true) !== false) {
         Log::warn("Trying to re-register socket " . $instance->tag());
         return;
      }
      self::$sockets[(int)$instance->writeHandle()] = $instance;
      self::$readResources[$instance->tag()] = $instance->readHandle();
      self::$readSocketLookup[(int)$instance->readHandle()] = $instance;
   }

   public static function unregisterSocket(AbstractSocket $instance)
   {
      $key = array_search($instance, self::$sockets, true);
      if ($key === false)
         return;
      unset(self::$sockets[$key]);
      unset(self::$readResources[$instance->tag()]);
      unset(self::$readSocketLookup[array_search($instance, self::$readSocketLookup, true)]);
   }
   
   public static function addTimeoutEvent(TimerEvent $callback, float $timeoutSeconds)
   {
      $timer = new Timer($callback);
      self::insertTimer($timer, microtime(true) + $timeoutSeconds);
      return $timer->id;
   }
   
   private static function insertTimer(Timer $timer, float $nextTimeout)
   {
      
      $timer->nextTimeout = $nextTimeout;
      foreach (self::$timers as $key => $t) {
         if ($t->nextTimeout > $timer->nextTimeout) {
            array_splice(self::$timers, $key, 0, [$timer]);
            return;
         }
      }
      self::$timers[] = $timer;
   }
   
   public static function main()
   {
      for (;;) {
         $now = microtime(true);
         while (!empty(self::$timers) && self::$timers[0]->nextTimeout <= $now) {
            $timer = array_shift(self::$timers);
            $next = $timer->callback->timeout($timer->id);
            if ($next > 0) {
               self::insertTimer($timer, $now + $next);
            }
         }
         if (empty(self::$timers)) {
            $delayMicros = $delaySeconds = 300;
         } else {
            $val = self::$timers[0]->nextTimeout - microtime(true);
            $delaySeconds = (int)$val;
            $delayMicros = (int)(($val - $delaySeconds) * 1000000);
            if ($delaySeconds < 0) {
               Log::warn("Time is running backwards! Mainloop calculated sleep time was $delaySeconds seconds");
               $delaySeconds = 0;
            }
         }
         if (empty(self::$sockets)) {
            time_nanosleep($delaySeconds, $delayMicros * 1000);
            continue;
         }
         $read = self::$readResources;
         $write = [];
         $ex = null;
         foreach (self::$sockets as $sock) {
            if ($sock->wantsWrite()) {
               $write[] = $sock->writeHandle();
            }
         }
         self::setErrorHandler();
         $ret = stream_select($read, $write, $ex, $delaySeconds, $delayMicros);
         //Log::debug("$ret = ss(" . count($read) . ', ' . count($write) . ", $delaySeconds, $delayMicros)");
         restore_error_handler();
         if ($ret === false) {
            $err = self::$errno;
            Log::warn("select() error ");
            Sleep(1);
            continue;
         }
         foreach ($read as $res) {
            if (isset(self::$readSocketLookup[(int)$res])) {
               self::$readSocketLookup[(int)$res]->__socket_event(1);
            }
         }
         foreach ($write as $res) {
            if (isset(self::$sockets[(int)$res])) { // Read CB might have removed it
               self::$sockets[(int)$res]->__socket_event(2);
            }
         }
      }
   }
   
   public static function setErrorHandler()
   {
      Mainloop::$errno = 0;
      set_error_handler(function($type, $str) {
         $i = strpos($str, 'errno=');
         if ($i === false)
            return false;
         // I think on windows the socket error codes are just shifted by 10000, but didn't verify
         Mainloop::$errno = ((int)substr($str, $i + 6)) % 10000;
         return true;
      }, E_NOTICE);
   }

}
