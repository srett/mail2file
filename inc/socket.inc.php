<?php

class Socket extends AbstractSocket
{
   private static $leakCheck = 0;
   
   /**
    * @var resource socket handle
    */
   private $sock = false;
   /**
    * @var string outgoing data is buffered here
    */
   private $writeBuffer = '';
   /**
    * @var string any buffered data we need to send before starting to encrypt the connection
    */
   private $preEncryptionBuffer = '';
   /**
    * @var string remote address
    */
   private $remoteAddr;
   /**
    * @var bool whether this socket has an ssl cert in its context
    */
   private $hasCert = false;
   /**
    * @var int
    */
   private $state = self::UNCONNECTED;
   
   const UNCONNECTED = -1;
   const CONNECTING = 0;
   const CONNECTED = 1;
   const LISTENING = 2;
   const ENCRYPT = 3;
   const ENCRYPT_WANT_WRITE = 4;
   
   /**
    *
    * @var int
    */
   private $crypto_type = 0;
   
   
   public function __construct(SocketEvent $callback, $fromSockFd = false, $addr = false, $encrypt = false)
   {
      parent::__construct($callback);
      if ($fromSockFd !== false) {
         $this->sock = $fromSockFd;
         if ($encrypt) {
            $this->setCryptoVar(true);
            $this->state = self::ENCRYPT_WANT_WRITE;
         } else {
            $this->state = self::CONNECTED;
         }
         $this->remoteAddr = $addr;
         stream_set_blocking($this->sock, false);
         $tmp = stream_context_get_params($this->sock);
         $this->hasCert = isset($tmp['options']['ssl']['local_cert']);
         Mainloop::registerSocket($this);
      }
      Log::debug("Have " . (++self::$leakCheck) . " Socket instances.");
   }
   
   public function connect(string $host, $context = null) : bool
   {
      if ($this->state !== self::UNCONNECTED)
         return false;
      if (substr($host, 0, 3) === 'ssl') {
         // Do this explicitly
         $host = 'tcp' . substr($host, 3);
         $this->setCryptoVar(false);
      }
      if ($context === null) {
         $context = stream_context_create();
      } else {
         $tmp = stream_context_get_params($context);
         $this->hasCert = isset($tmp['options']['ssl']['local_cert']);
      }
      $this->sock = stream_socket_client($host, $errno, $errstr, 0,
              STREAM_CLIENT_ASYNC_CONNECT | STREAM_CLIENT_CONNECT, $context);
      if ($this->sock === false) {
         Log::debug("Cannot start async connect to $host; ($errno): $errstr");
         return false;
      }
      $this->state = self::CONNECTING;
      stream_set_blocking($this->sock, false);
      Mainloop::registerSocket($this);
      if ($this->state === self::CONNECTED) {
         Log::error("BUG, CONNECTED AFTER REGISTER SOCKET");
         $this->callback->connected($this);
      }
      return true;
   }
   
   public function listen(string $host, $context = null) : bool
   {
      if ($this->state !== self::UNCONNECTED)
         return false;
      if (substr($host, 0, 3) === 'ssl') {
         // We support ssl:// syntax to designate that accepted connections should immediately do an SSL handshake
         $host = 'tcp' . substr($host, 3);
         $this->crypto_type = -1;
      }
      if ($context === null) {
         // Although the default value for stream_socket_server()'s $context parameter is supposedly null, if you
         // actually pass null, you get an error. At least in PHP 7.4. Nice.
         $context = stream_context_create();
      } else {
         $tmp = stream_context_get_params($context);
         $this->hasCert = isset($tmp['options']['ssl']['local_cert']);
      }
      $this->sock = stream_socket_server($host, $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
      if ($this->sock === false) {
         Log::debug("Cannot start listen on $host; ($errno): $errstr");
         return false;
      }
      stream_set_blocking($this->sock, false);
      $this->state = self::LISTENING;
      Mainloop::registerSocket($this);
      return true;
   }
   
   public function sendData(string $data) : bool
   {
      if ($this->sock === false)
         return false;
      if ($this->state === self::ENCRYPT || $this->state === self::ENCRYPT_WANT_WRITE) {
         $this->preEncryptionBuffer .= $data;
      } else {
         $this->writeBuffer .= $data;
      }
      return true;
   }
   
   public function close(): void
   {
      if ($this->sock === false)
         return;
      Mainloop::unregisterSocket($this);
      fclose($this->sock);
      $this->sock = false;
      $this->callback = false;
   }
   
   private function setCryptoVar(bool $serverSide)
   {
      if ($serverSide) {
         $this->crypto_type = STREAM_CRYPTO_METHOD_TLSv1_2_SERVER;
         if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_SERVER')) {
            $this->crypto_type |= STREAM_CRYPTO_METHOD_TLSv1_3_SERVER;
         }
      } else {
         $this->crypto_type = STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
         if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $this->crypto_type |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
         }
      }
   }
   
   public function encrypt(bool $serverSide): bool
   {
      if ($this->crypto_type !== 0 || $this->state !== self::CONNECTED)
         return false;
      $this->setCryptoVar($serverSide);
      $this->state = self::ENCRYPT_WANT_WRITE;
      return true;
   }
   
   public function isDead() : bool
   {
      return $this->sock === false;
   }
   
   /**
    * @return bool if the connection is encrypted, or accepted connection will be encrypted
    */
   public function isEncrypted(): bool
   {
      return $this->crypto_type !== 0;
   }

   public function hasCert(): bool
   {
      return $this->hasCert;
   }
   
   public function wantsWrite(): bool
   {
      if ($this->sock === false)
         return false;
      switch ($this->state) {
         case self::CONNECTING;
         case self::ENCRYPT_WANT_WRITE;
            return true;
         case self::CONNECTED;
            return ($this->writeBuffer !== '');
      }
      return false;
   }
   
   public function writeHandle()
   {
      return $this->sock;
   }
   
   /**
    * @return return socket if we want to know about incoming data (=always)
    */
   public function readHandle()
   {
      return $this->sock;
   }
   
   /**
    * @return string Remote address of connection, or false if not connected
    */
   public function remoteAddress()
   {
      return $this->remoteAddr;
   }
   
   /**
    * INTERNAL USE ONLY. to be called by the main loop.
    * @param int $what
    */
   public function __socket_event(int $what): void
   {
      if ($this->sock === false)
         return;
      if ($this->state === self::CONNECTING) {
         // Connecting...
         if ($what === 2) {
            $this->remoteAddr = stream_socket_get_name($this->sock, true);
            if ($this->crypto_type !== 0) {
               $this->state = self::ENCRYPT_WANT_WRITE;
               $this->preEncryptionBuffer = $this->writeBuffer;
               $this->writeBuffer = '';
            } else {
               $this->state = self::CONNECTED;
            }
            $this->callback->connected($this);
         } else {
            return;
         }
      }
      if ($this->state === self::LISTENING) {
         // Listening
         for (;;) {
            $new = @stream_socket_accept($this->sock, 0, $addr);
            if ($new === false) {
               $this->handleError();
               break;
            }
            $this->callback->incomingConnection($this, new Socket($this->callback, $new, $addr, $this->crypto_type !== 0));
         }
         return;
      }
      // Connection already established
      if ($what === 1) {
         // Read
         if ($this->state === self::ENCRYPT || $this->state === self::ENCRYPT_WANT_WRITE) {
            // About to encrypt the connection
            if (!empty($this->writeBuffer)) {
               Log::warn("About to encrypt a connection, but received some data in the meantime. No idea how to handle this.");
            } else {
               $this->continueEncryption(true);
               return;
            }
         }
         // Normal communication
         while ($this->sock !== false) {
            Mainloop::setErrorHandler();
            $buffer = fread($this->sock, 65536);
            restore_error_handler();
            if ($buffer === false || Mainloop::$errno !== 0) {
               $this->handleError();
               break;
            }
            if ($buffer === '') {
               if (!feof($this->sock))
                  break;
               $this->callback->socketClosed($this);
               $this->close();
               return;
            }
            $this->callback->dataArrival($this, $buffer);
         }
      } elseif ($what === 2) {
         // Writable
         if (($this->state === self::ENCRYPT || $this->state === self::ENCRYPT_WANT_WRITE) && empty($this->writeBuffer)) {
            $this->continueEncryption(false);
            return;
         }
         $sent = 0;
         $ret = true;
         while ($this->sock !== false && $this->writeBuffer !== '') {
            Mainloop::setErrorHandler();
            $ret = fwrite($this->sock, $this->writeBuffer);
            restore_error_handler();
            if ($ret === false || $ret === 0)
               break;
            $sent += $ret;
            $this->writeBuffer = substr($this->writeBuffer, $ret);
         }
         if ($sent !== 0) {
            $this->callback->sendProgress($this, $ret, strlen($this->writeBuffer));
         }
         if ($ret === false || $ret === 0) {
           $this->handleError();
         }
      }
   }
   
   private function continueEncryption(bool $wantWrite): void
   {
      $ret = stream_socket_enable_crypto($this->sock, true, $this->crypto_type);
      if ($ret === true) {
         Log::debug('Connection successfully encrypted');
         $this->state = self::CONNECTED;
         $this->writeBuffer = $this->preEncryptionBuffer;
         $this->preEncryptionBuffer = '';
      } elseif ($ret === 0) {
         // Need more data
         $this->state = $wantWrite ? self::ENCRYPT_WANT_WRITE : self::ENCRYPT;
      } else {
         Log::warn('Connection could not be encrypted');
         $this->state = self::CONNECTED;
         Mainloop::$errno = 60001;
         $this->handleError();
      }
   }
   
   private function handleError() : bool
   {
      $err = Mainloop::$errno;
      if ($err === 0 || $err === 4 || $err === 11) // EINTR EAGAIN
         return false;
      // Everything else is bad
      $this->callback->socketError($this, $err);
      Mainloop::unregisterSocket($this);
      return true;
   }
   
   public function __destruct()
   {
      $this->close();
      Log::debug("Have " . (--self::$leakCheck) . " Socket instances.");
   }

}
