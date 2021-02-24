<?php

abstract class AbstractSocket
{
   
   private static $runningId = 0;
   
   /**
    * @var int an ID for this very socket, which you can use as an array key
    */
   protected $tag;
   /**
    * @var SocketEvent callback
    */
   protected $callback;
   
   public function __construct(SocketEvent $callback)
   {
      $this->tag = ++self::$runningId;
      $this->callback = $callback;
   }
   
   public final function __clone()
   {
      trigger_error('Cannot clone a socket', E_USER_ERROR);
      throw new ErrorException('Cannot clone socket');
   }
   
   public function setCallback(SocketEvent $callback)
   {
      $this->callback = $callback;
   }
   
   /**
    * @return int this socket's unique tag/ID
    */
   public function tag() : int
   {
      return $this->tag;
   }
   
   public abstract function sendData(string $data) : bool;
   
   public abstract function close(): void;
   
   public abstract function wantsWrite(): bool;
   
   public abstract function writeHandle();
   
   public abstract function readHandle();
   
   public abstract function isDead() : bool;
   
   public abstract function __socket_event(int $what): void;
   
}