<?php

class SmtpClient implements SocketEvent
{
   
   private static $idCount = 0;

   /** @var Socket */
   private $sock;
   /** @var int timestamp of last data received */
   private $lastData;
   /** @var int total number of bytes received */
   private $bytesReceived = 0;
   /** @var int unique ID of this client*/
   private $id;
   /** @var string Read buffer (partial messages) */
   private $readBuffer = '';
   
   /** @var MailData */
   private $mailData;

   /** @var MailParser */
   private $mailParser;
   
   private $doClose = false;
   
   private $state = 0;
   
   const STATE_HANDSHAKE = 0;
   const STATE_COMMANDS = 1;
   const STATE_DATA = 2;
   
   public function __construct(Socket $sock)
   {
      $sock->setCallback($this);
      $this->sock = $sock;
      $this->lastData = time();
      $this->id = ++self::$idCount;
      $this->mailData = new MailData($this->sock->remoteAddress());
      $this->send('220 ' . CONFIG_DOMAIN . ' SMTP Service almost ready');
   }
   
   private function send(string $data)
   {
      if ($this->sock === null) {
         Log::warn("Socket of client {$this->id} is already null when trying to send '$data'");
         return;
      }
      Log::debug("SMTP {$this->id} OUT: $data");
      $this->sock->sendData($data . "\r\n");
   }
   
   private function handleLine(string $line)
   {
      if ($this->state === self::STATE_DATA) {
         if ($line === '.') {
            $this->send("250 OK, im totally an open relay");
            $this->state = self::STATE_COMMANDS;
            $this->mailData = new MailData($this->sock->remoteAddress());
            $this->mailParser = null;
         } else {
            if ($line !== '' && $line[0] === '.') {
               // Remove dot stuffing
               $line = substr($line, 1);
            }
            if (!$this->mailParser->feedDataLine($line)) {
               $this->doClose = true;
               $this->send('521 Just go away');
            }
         }
         return;
      }
      // Not data
      $cmdEnd = strpos($line, ' ');
      if ($cmdEnd === false) {
         $cmd = $line;
      } else {
         $cmd = substr($line, 0, $cmdEnd);
      }
      $cmd = strtoupper($cmd);
      Log::debug("SMTP {$this->id}  IN: $line");
      if ($this->state === self::STATE_HANDSHAKE) {
         if ($cmd === 'HELO') {
            Log::info('SMTP Login from ' . $this->sock->remoteAddress());
            $this->send('250 OK');
            $this->state = self::STATE_COMMANDS;
         } elseif ($cmd === 'EHLO') {
            Log::info('SMTP Login from ' . $this->sock->remoteAddress());
            $this->send('250-' . CONFIG_DOMAIN);
            $this->send('250-AUTH PLAIN');
            if ($this->sock->hasCert() && !$this->sock->isEncrypted()) {
               $this->send('250-STARTTLS');
            }
            $this->send('250-8BITMIME');
            $this->send('250-UTF8SMTP');
            $this->send('250 OK');
            $this->state = self::STATE_COMMANDS;
         } elseif ($cmd === 'STARTTLS' && !$this->sock->isEncrypted()) {
            $this->send('220 Encrypting session...');
            $this->sock->encrypt(true);
         } elseif ($cmd === 'QUIT') {
            $this->doClose = true;
            $this->send('221 Have a nice day');
         } else {
            $this->send('502 Command not implemented');
         }
         return;
      }
      // State = commands
      if ($cmd === 'AUTH') {
         $this->send('235 2.7.0 Authentication successful');
      } elseif ($cmd === 'MAIL' && preg_match('/FROM:<(.*)>/i', $line, $out)) {
         $this->mailData->from = $out[1];
         $this->send('250 OK');
      } elseif ($cmd === 'RCPT' && preg_match('/TO:<(.*)>/i', $line, $out)) {
         $this->mailData->to = $out[1];
         $this->send('250 OK');
      } elseif ($cmd === 'DATA') {
         $this->state = self::STATE_DATA;
         $this->send("354 Start mail input; end with <CRLF>.<CRLF>");
         $this->mailParser = new MailParser($this->mailData);
      } elseif ($cmd === 'RSET') {
         $this->mailData = new MailData($this->sock->remoteAddress());
         $this->send('250 OK');
      } elseif ($cmd === 'STARTTLS' && $this->sock->hasCert() && !$this->sock->isEncrypted()) {
         $this->send('220 Encrypting session...');
         $this->sock->encrypt(STREAM_CRYPTO_METHOD_TLSv1_2_SERVER);
         $this->state = self::STATE_HANDSHAKE; // Back to square one
      } elseif ($cmd === 'QUIT') {
         $this->doClose = true;
         $this->send('221 Have a nice day');
      } else {
         $this->send('502 Command not implemented');
      }
   }
   
   /*
    * Socket events
    */

   public function connected(\AbstractSocket $sock) { }

   public function dataArrival(\AbstractSocket $sock, string $data)
   {
      $this->bytesReceived += strlen($data);
      if ($this->bytesReceived > 2 * 1024 * 1024 * 1024) { // TODO configurable
         Log::info("SMTP Client {$this->id} sent more than 2GB of data, dropping...");
         $this->sock->close();
         return;
      }
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
         if ($bufferLen - $start > 1000) {
            Log::info("SMTP Client {$this->id} is flooding us with data, dropping...");
            $this->sock->close();
            return;
         }
      } else {
         $this->readBuffer = '';
      }
   }

   public function incomingConnection(\Socket $sock, \Socket $new) { }

   public function sendProgress(\AbstractSocket $sock, int $sent, int $remaining)
   {
      if ($this->doClose && $remaining === 0) {
         Log::info("SMTP Closing connection to client {$this->id}");
         $this->sock->close();
         $this->sock = null;
      }
   }

   public function socketClosed(\AbstractSocket $sock)
   {
      Log::info("SMTP Client {$this->id} disconnected");
      $sock->close();
      $this->sock = null;
   }

   public function socketError(\AbstractSocket $sock, $error)
   {
      Log::info("SMTP Client {$this->id} aborted connection");
      $sock->close();
      $this->sock = null;
   }

}
