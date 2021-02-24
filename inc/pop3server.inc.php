<?php

class Pop3Server implements SocketEvent
{
   
   public function __construct(string $addr, $context = null)
   {
      $sock = new Socket($this);
      if (!$sock->listen($addr, $context)) {
         die("Could not listen on $addr\n");
      }
   }
   
   /*
    * 
    */
   
   public function connected(\AbstractSocket $sock) { }

   public function incomingConnection(\Socket $sock, \Socket $new)
   {
      new Pop3Client($new);
   }

   public function sendProgress(\AbstractSocket $sock, int $sent, int $remaining) { }

   public function socketClosed(\AbstractSocket $sock) { }

   public function socketError(\AbstractSocket $sock, $error) { }

   public function dataArrival(\AbstractSocket $sock, string $data) { }

}