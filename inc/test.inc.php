<?php

class Test implements SocketEvent
{
   
   private $listen;
   
   private $socks = [];
   
   public function __construct($context)
   {
      /*
      $this->listen = new Socket($this);
      $this->listen->listen('ssl://127.0.0.1:1234', $context);
      $sock = new Socket($this);
      $sock->connect('ssl://www.google.com:443');
       * 
       */
      // This perfectly demonstrates the problem I described in
      // the Proces class.
      new Process($this, ['/usr/bin/top', '-d', '1', '-n', '2', '-b']);
      new Process($this, ['/usr/bin/top', '-d', '1', '-n', '2', '-b']);
      new Process($this, ['/usr/bin/top', '-d', '1', '-n', '2', '-b']);
   }
   
   /*
    * 
    */

   public function connected(\AbstractSocket $sock)
   {
      Log::debug("test connected");
      $sock->sendData("GET / HTTP/1.1\r\nHost: www.google.com\r\n\r\n");
   }

   public function dataArrival(\AbstractSocket $sock, string $data)
   {
      Log::info("IN: '" . substr(str_replace(["\r", "\n"], '_', $data), 0, 30));
      foreach ($this->socks as $other) {
         if ($other !== $sock) {
            $other->sendData($data);
         }
      }
   }

   public function incomingConnection(\Socket $sock, \Socket $new)
   {
      $this->socks[$new->tag()] = $new;
   }

   public function sendProgress(\AbstractSocket $sock, int $sent, int $remaining)
   {
      
   }

   public function socketClosed(\AbstractSocket $sock)
   {
      Log::debug("test sock closed");
      unset($this->socks[$sock->tag()]);
   }

   public function socketError(\AbstractSocket $sock, $error)
   {
      Log::debug("test sock error $error");
      unset($this->socks[$sock->tag()]);
   }

}
