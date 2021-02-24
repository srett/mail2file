<?php

/**
 * A Process is clearly a special type of Socket,
 * because I'm very good at OOP.
 * Maybe give Process its own callback type. You could
 * even support stderr then with a bit of work and
 * careful planning. So definitely nothing for this
 * project.
 */
class Process extends AbstractSocket implements TimerEvent
{
   
   private static $leakCheck = 0;
   
   private $proc = false;
   
   private $writePipe = false;
   
   private $readPipe = false;
   
   private $writeBuffer = '';
   
   private $connecting = true;
   
   private $closeCalled = false;
   
   private $terminationCounter = 0;
   
   public function __construct(\SocketEvent $callback, array $cmd)
   {
      parent::__construct($callback);
      if (PHP_VERSION_ID < 70400) {
         $cmd = array_map(function ($item) {
            return escapeshellarg($item);
         }, $cmd);
         $cmd = implode(' ', $cmd);
      }
      $this->proc = proc_open($cmd, [['pipe', 'r'], ['pipe', 'w'], ['file', '/dev/null', 'w']], $pipes);
      if ($this->proc !== false) {
         foreach ($pipes as $id => $pipe) {
            stream_set_blocking($pipe, false);
            if ($id === 0) {
               $this->writePipe = $pipe;
            } elseif ($id === 1) {
               $this->readPipe = $pipe;
            } else {
               fclose($pipe);
            }
         }
         Mainloop::registerSocket($this);
      } else {
         $this->callback->socketError($this, -1);
      }
      Log::debug("Have " . (++self::$leakCheck) . " Process instances. (tag={$this->tag()})");
   }
   
   public function __destruct()
   {
      /* So I'm having this weird issue (PHP 7.4 on Debian 11) that Process instances
       * won't get cleaned up when there is no apparent reference to them anymore.
       * I've tried to find where the stale reference hides, but either I'm stupid or
       * there's a subtle caveat with PHP garbage colection:
       * The Process instance *will* get destroyed, but only when a second instance
       * was created and reaches the point where it *should* be destroyed. This pattern
       * continues whenever a new Process is spawned, so we always have one lingering
       * Process instance.
       * I don't see where the difference to "Socket" is, which work just as expected.
       * If you uncomment the line below, you'll see that when the instance gets destroyed,
       * it's where the first item from the Mainloop::$timers array gets shifted out, which
       * doesn't make any sense to me since there is just one Timer instance in there
       * holding a reference to the *new* Process instance, which is still referenced
       * at that point, and then the array is empty again, just as before. Yet, this will
       * get PHP to destroy this old instance. Off to bed now.
       */
      //debug_print_backtrace();
      $this->cleanup();
      Log::debug("Have " . (--self::$leakCheck) . " Process instances. (tag={$this->tag()})");
   }
   
   /**
    * 
    * @param int $what 1 read, 2 write
    * @return void
    */
   public function __socket_event(int $what): void
   {
      if ($this->proc === false) {
         Mainloop::unregisterSocket($this);
         return;
      }
      if ($what === 1) {
         // Readable
         if ($this->readPipe === false)
            return;
         while ($this->proc !== false) {
            Mainloop::setErrorHandler();
            $buffer = fread($this->readPipe, 65536);
            restore_error_handler();
            if ($buffer === false || Mainloop::$errno !== 0) {
               $this->handleError();
               break;
            }
            if ($buffer === '') {
               if (!feof($this->readPipe))
                  break;
               $this->close();
               return;
            }
            $this->callback->dataArrival($this, $buffer);
         }
      } elseif ($what === 2) {
         // Writable
         if ($this->connecting) {
            $this->connecting = false;
            $this->callback->connected($this);
         }
         $sent = 0;
         $ret = true;
         while ($this->proc !== false && $this->writeBuffer !== '') {
            Mainloop::setErrorHandler();
            $ret = fwrite($this->writePipe, $this->writeBuffer);
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
   
   private function handleError() : bool
   {
      $err = Mainloop::$errno;
      if ($err === 0 || $err === 4 || $err === 11) // EINTR EAGAIN
         return false;
      // Everything else is bad
      $this->close();
      return true;
   }

   public function close(): void
   {
      if ($this->proc === false || $this->closeCalled)
         return;
      $this->closeCalled = true;
      if ($this->writePipe !== false) {
         fclose($this->writePipe);
         $this->writePipe = false;
      }
      if ($this->readPipe !== false) {
         fclose($this->readPipe);
         $this->readPipe = false;
      }
      Mainloop::unregisterSocket($this);
      Mainloop::addTimeoutEvent($this, 0.5);
   }
   
   private function checkIsFinished(): bool
   {
      if ($this->proc === false)
         return true;
      $check = proc_get_status($this->proc);
      if ($check !== false && $check['running']) {
         $this->terminationCounter++;
         if ($this->terminationCounter < 3) {
            $sig = SIGTERM;
         } elseif ($this->terminationCounter < 4) {
            $sig = SIGINT;
         } else {
            $sig = SIGKILL;
         }
         Log::info("Sending sig=$sig to {$check['command']}");
         proc_terminate($this->proc, $sig);
         Mainloop::addTimeoutEvent($this, $this->terminationCounter);
         return false;
      }
      $this->cleanup($check['exitcode']);
      return true;
   }
   
   private function cleanup($ecode = -1)
   {
      if ($this->proc === false)
         return;
      if ($this->writePipe !== false) {
         fclose($this->writePipe);
         $this->writePipe = false;
      }
      if ($this->readPipe !== false) {
         fclose($this->readPipe);
         $this->readPipe = false;
      }
      Mainloop::unregisterSocket($this);
      // It appears that calling proc_get_status() after the process has finished fetches
      // the exit code, so it's not available to proc_close() anymore. This is kinda
      // mentioned in the docs but not in a very clear way, but what it means is that
      // you can never get the exit code from proc_close if you want to use processes
      // in a nonblocking fashion, because proc_close() always blocks until the process
      // is finished, so you need to query the status using proc_get_status() until
      // the process is finished, but then... well...
      $exitCode = proc_close($this->proc);
      $this->proc = false;
      if ($exitCode === -1) {
         // Because of what I wrote above, this will always be the case
         $exitCode = $ecode;
      }
      if ($exitCode !== 0) {
         $this->callback->socketError($this, $exitCode);
      } else {
         $this->callback->socketClosed($this);
      }
      $this->callback = null;
      return true;
   }

   public function isDead(): bool
   {
      return $this->proc === false;
   }

   public function sendData(string $data): bool
   {
      if ($this->proc === false || $this->writePipe === false)
         return false;
      $this->writeBuffer .= $data;
      return true;
   }
   
   public function wantsWrite(): bool
   {
      return $this->proc !== false && $this->writePipe !== false && !empty($this->writeBuffer);
   }

   public function writeHandle()
   {
      return $this->writePipe;
   }
   
   public function readHandle()
   {
      if ($this->proc === false)
         return false;
      return $this->readPipe;
   }

   public function timeout(int $id)
   {
      $this->checkIsFinished();
      return 0;
   }

}
