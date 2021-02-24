<?php

class StoreFilter extends Filter
{
   
   protected $fh = false;

	protected $filePath;

	public function __construct($action, MailData $data)
	{
      // Store this attachment to a file
      $rep = ['%DATE%' => date('Y-m-d')];
      foreach (get_object_vars($data) as $k => $v) {
         $rep['%' . strtoupper($k) . '%'] = Util::cleanPathElement($v);
      }
      $this->filePath = str_replace(array_keys($rep), array_values($rep), $action['path']);
      $dir = dirname($this->filePath);
      if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
         Log::error("Cannot create $dir, not saving attachment");
      } else {
         // Dir ok, try to create file
         if (!isset($action['exists']) || $action['exists'] === 'skip') {
            $ok = !(file_exists($this->filePath) || file_exists($this->filePath . '.tmp'));
         } elseif ($action['exists'] === 'rename') {
            $fn = $this->filePath;
            $ctr = 0;
            while (file_exists($this->filePath) || file_exists($this->filePath . '.tmp')) {
               $this->filePath = preg_replace('/([^.]*$)/', "$ctr.\$1", $fn);
               $ctr++;
            }
            $ok = true;
         } elseif ($action['exists'] === 'replace') {
            $ok = true;
         } else {
            $ok = false;
            Log::warn("Invalid value for 'exists': '{$action['exists']}', ignoring filter");
         }
         if ($ok) {
            Log::info("Saving file {$data->fileName} to {$this->filePath}");
            $this->fh = fopen($this->filePath . '.tmp', "wb");
         } else {
            Log::info("Not saving attachment {$data->fileName} as file already exists: {$this->filePath}");
         }
      }
	}

	public function feed(string $chunk)
	{
		if ($this->fh !== false) {
			fwrite($this->fh, $chunk);
		}
	}

	public function finished()
	{
      if ($this->fh !== false) {
         fclose($this->fh);
			$this->fh = false;
         if (file_exists($this->filePath . '.tmp')) {
            rename($this->filePath . '.tmp', $this->filePath);
         }
         Log::debug("Done writing out {$this->filePath}");
      }
	}
   
   public function __destruct()
	{
      if ($this->fh !== false) {
         fclose($this->fh);
         if (file_exists($this->filePath . '.tmp')) {
            unlink($this->filePath . '.tmp');
         } else {
            unlink($this->filePath);
         }
         Log::warn("An error occured writing out {$this->filePath}");
		}
   }
   
}
