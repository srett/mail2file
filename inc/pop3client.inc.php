<?php

class Pop3Client implements SocketEvent
{
   
   static private $idCount = 0;
   
   private $sock;
   
   private $readBuffer = '';
   
   private $lastData;
   
   private $id;
   
   /**
    * @var bool if true, connection will close when send buffer is empty
    */
   private $doClose = false;
   
   public function __construct(Socket $sock)
   {
      $sock->setCallback($this);
      $this->sock = $sock;
      $this->lastData = time();
      $this->id = ++self::$idCount;
      $this->send('+OK POP3 100% legit server ready <' . mt_rand(100, 9000) . '.' . time() . '@' . CONFIG_DOMAIN . '>');
   }
   
   private function send(string $data)
   {
      if ($this->sock === null) {
         Log::warn("Socket of POP3 client {$this->id} is already null when trying to send '$data'");
         return;
      }
      Log::debug("POP3 {$this->id} OUT: $data");
      $this->sock->sendData($data . "\r\n");
   }
   
   private function handleLine(string $line)
   {
      Log::debug("POP3 {$this->id}  IN: $line");
      $cmdEnd = strpos($line, ' ');
      if ($cmdEnd === false) {
         $cmd = $line;
      } else {
         $cmd = substr($line, 0, $cmdEnd);
      }
      $cmd = strtoupper($cmd);
      if ($cmd === 'USER' || $cmd === 'PASS' || $cmd === 'NOOP' || $cmd === 'RSET' || $cmd === 'APOP') {
         $this->send("+OK sure whatever...");
      } elseif ($cmd === 'STLS' && $this->sock->hasCert() && !$this->sock->isEncrypted()) {
         $this->send('+OK going to encrypt');
         $this->sock->encrypt(true);
      } elseif ($cmd === 'STAT') {
         $this->send("+OK 0 0");
      } elseif ($cmd === 'CAPA') {
         $this->send("+OK Here comes the list of supported capabilities");
         $this->send("PIPELINING");
         $this->send("UIDL");
         $this->send("TOP");
         if ($this->sock->hasCert() && !$this->sock->isEncrypted()) {
            $this->send("STLS");
         }
         $this->send(".");
      } elseif ($cmd === 'LAST') {
         $this->send("+OK 0");
      } elseif ($cmd === 'LIST') {
         if ($cmdEnd === false) {
            $this->send("+OK Here comes the long list.");
            $this->send('.');
         } else {
            $this->send("-ERR Message not found");
         }
      } elseif ($cmd === 'QUIT') {
         $this->doClose = true;
         $this->send('+OK all done, goodbye');
      } elseif ($cmd === 'UIDL' && $cmdEnd === false) {
         $this->send('+OK my friend, heres teh list');
         $this->send('.');
      } else {
         $this->send("-ERR huh?");
      }
   }
   
   /*
    * 
    */

   public function connected(\AbstractSocket $sock) { }

   public function dataArrival(\AbstractSocket $sock, string $data)
   {
      $this->readBuffer .= $data;
      $start = 0;
      $bufferLen = strlen($this->readBuffer);
      // Look for <CRLF>
      while (($newLine = strpos($this->readBuffer, "\r\n", $start)) !== false) {
         $this->handleLine(substr($this->readBuffer, $start, $newLine - $start));
         $start = $newLine + 2; // Skip over <CRLF>
      }
      if ($start < $bufferLen) {
         $this->readBuffer = substr($this->readBuffer, $start);
         // Detect evil clients
         if ($bufferLen - $start > 1000) {
            Log::info("POP3 Client {$this->id} is flooding us with data, dropping...");
            $this->sock->close();
            return;
         }
      } else {
         $this->readBuffer = '';
      }
   }

   public function incomingConnection(\Socket $sock, \Socket $new)
   {
      
   }

   public function sendProgress(\AbstractSocket $sock, int $sent, int $remaining)
   {
      if ($this->doClose && $remaining === 0) {
         Log::info("POP3 Closing connection to client {$this->id}");
         $this->sock->close();
         $this->sock = null;
      }
   }

   public function socketClosed(\AbstractSocket $sock)
   {
      Log::info("POP3 Client {$this->id} disconnected");
      $sock->close();
      $this->sock = null;
   }

   public function socketError(\AbstractSocket $sock, $error)
   {
      Log::info("POP3 Client {$this->id} aborted connection");
      $sock->close();
      $this->sock = null;
   }

}
