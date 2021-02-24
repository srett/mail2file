<?php

class Timer
{
   
   /**
    * @var int 
    */
   public $id;
   /**
    * @var int
    */
   private static $nextId;
   /**
    * @var TimerEvent the timer callback function
    */
   public $callback;
   
   /**
    *
    * @var float nextTime this event should fire
    */
   public $nextTimeout;
   
   public function __construct(TimerEvent $callback)
   {
      $this->id = ++self::$nextId;
      $this->callback = $callback;
   }
   
}