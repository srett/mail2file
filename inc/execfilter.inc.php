<?php

class ExecFilter extends StoreFilter implements SocketEvent
{
   
   /** @var bool delete file after process exits? */
   private $delete;
   /** @var string[] command to execute */
   private $cmd;
   
   public function __construct($action, \MailData $data)
   {
      $this->delete = $action['delete'] ?? true;
      $this->cmd = $action['cmd'] ?? false;
      if ($this->cmd === false)
         return;
      if (strpos(implode(' ', $action['cmd']), '%TEMPFILE%') !== false) {
         $this->filePath = tempnam('/tmp', 'm2f-');
         if ($this->filePath !== false) {
            $this->fh = fopen($this->filePath, 'wb');
         }
         foreach ($this->cmd as &$item) {
            $item = str_replace('%TEMPFILE%', $this->filePath, $item);
         }
      }
      $rep = ['%DATE%' => date('Y-m-d')];
      foreach (get_object_vars($data) as $k => $v) {
         $rep['%' . strtoupper($k) . '%'] = $v;
      }
      foreach ($this->cmd as &$item) {
         $item = str_replace(array_keys($rep), array_values($rep), $item);
      }
   }
   
   public function finished()
   {
      parent::finished();
      if ($this->cmd !== false) {
         new Process($this, $this->cmd);
      }
   }

   public function connected(\AbstractSocket $sock) { }

   public function dataArrival(\AbstractSocket $sock, string $data)
   {
      Log::info("EXEC({$this->tag()}): " . substr(str_replace(["\r", "\n"], ' ', $data), 0, 60));
   }

   public function incomingConnection(\Socket $sock, \Socket $new) { }

   public function sendProgress(\AbstractSocket $sock, int $sent, int $remaining) { }

   public function socketClosed(\AbstractSocket $sock)
   {
      Log::info("ExecFilter process finished successfully");
      if ($this->delete && !empty($this->filePath)) {
         unlink($this->filePath);
         $this->filePath = false;
      }
   }

   public function socketError(\AbstractSocket $sock, $error)
   {
      Log::info("ExecFilter process exited with error $error");
      if ($this->delete && !empty($this->filePath)) {
         unlink($this->filePath);
         $this->filePath = false;
      }
   }

}
