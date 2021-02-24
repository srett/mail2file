<?php

/**
 * This is a very simple streaming mail parser. It "understands" MIME multipart
 * messages and saves any attachments to disk. Due to its streaming nature it consumes
 * almost no memory during operation, creates no temporary files and can easily handle
 * multi-terabyte attachments.
 * It will recursively instantiate itself for nested messages.
 */
class MailParser
{
   
   private static $leakCheck = 0;

   /** @var MailData */
   private $data;
   
   /** @var string boundary if multipart, empty otherwise */
   private $boundary = '';
   /** @var string same with two dashes, marking end of multipart message */
   private $boundaryEnd = '';
   
   /** @var string byte-encoding of this mail or mime part */
   private $transferEncoding = '';
   
   /** @var string buffering the last line, in case of continuation */
   private $lastLine = '';
   
   /** @var int parser state, head or body */
   private $state;
   
   /* @var MailParser|null another mail parser instance when handling a mime part or nested mime message */
   private $subMail = null;
   
   /** @var int how deeply nested the mime message is */
   private $nestingDepth = 0;

   /** @var Filter[] */
   private $consumers = [];
   
   const STATE_HEADER = 0;
   const STATE_BODY = 1;
   
   // ....
   
   /**
    * @param MailData $data pre-filled metadata for this mail or mime part
    */
   public function __construct($data, $depth = 0)
   {
      $this->state = self::STATE_HEADER;
      $this->data = $data;
      $this->nestingDepth = $depth + 1;
      Log::debug('New MailParser (' . (++self::$leakCheck) . ')');
   }
   
   public function __destruct()
   {
      Log::debug('Destroyed MailParser (' . (--self::$leakCheck) . ')');
   }

   /**
    * This message was read successfully, tell consumers
    */
   protected function finishConsumers(): void
   {
      foreach ($this->consumers as $consumer) {
         $consumer->finished();
      }
      $this->consumers = [];
   }
   
   public function feedDataLine(string $line): bool
   {
      if (!empty($this->boundary)) {
         if ($line === $this->boundary) {
            // Current part done
            if ($this->subMail !== null) {
               $this->subMail->finishConsumers();
            }
            $this->subMail = new MailParser(clone $this->data, $this->nestingDepth);
         } elseif ($line === $this->boundaryEnd) {
            $this->subMail->finishConsumers();
            $this->subMail = null; // End, do nothing from here on
         } elseif ($this->subMail !== null) {
            return $this->subMail->feedDataLine($line);
         }
         return true;
      }
      if ($this->state === self::STATE_BODY) {
         if ($this->transferEncoding === 'base64') {
            // All clients observed supplied 80 character long lines, which makes for
            // fully decodable base64 chunks, but I guess in theory we could have arbitrary
            // line lengths... Soooo in case you need it, uncomment below (untested!):
            /*
            if ($this->lastLine !== '') {
               $line = $this->lastLine . $line;
               $this->lastLine = '';
            }
            $remainder = strlen($line) % 4;
            if ($remainder !== 0 && substr($line, -1) !== '=') {
               $this->lastLine = substr($line, -$remainder);
               $line = substr($line, 0, -$remainder);
            } 
            */
            $line = base64_decode($line);
         } elseif ($this->transferEncoding === 'quoted-printable') {
            $br = empty($line) || ($line[-1] !== '=');
            $line = quoted_printable_decode($line);
            if ($br) {
               $line .= "\n";
            }
         } else {
            // Dunno lol
         }
         foreach ($this->consumers as $consumer) {
            $consumer->feed($line);
         }
         return true;
      }
      // Still in header
      if ($line === '') {
         // Empty line: header end, start of body
         if (!empty($this->boundary) && $this->nestingDepth > 10) {
            // Don't allow too many nested MIME messages
            Log::error("Client {$this->data->srcAddress} sent too deply nested MIME message");
            return false;
         }
         $this->handleLastLine();
         $this->lastLine = '';
         $this->state = self::STATE_BODY;
         if (!empty($this->data->fileName)) {
            $this->consumers = Filter::get($this->data);
         }
         return true;
      }
      if ($line[0] === ' ' || $line[0] === "\t") {
         if (strlen($this->lastLine) > 1000) {
            // This is getting silly...
            return false;
         }
         // Continuation; iconv mime decode expects the CRLF, so add it back in
         $this->lastLine .= "\r\n" . $line;
      } else {
         $this->handleLastLine();
         $this->lastLine = rtrim($line);
      }
      return true;
   }
   
   private function handleLastLine(): void
   {
      $parts = explode(': ', $this->lastLine, 2);
      if (count($parts) !== 2)
         return;
      Log::debug("Handling header line '" . $this->lastLine . "'");
      $key = strtolower($parts[0]);
      // Content-Type: image/png; name="wp_ss_20210130_0001 (2).png"
      // Content-Transfer-Encoding: base64
      // Content-Disposition: attachment; filename="wp_ss_20210130_0001 (2).png"
      $value = iconv_mime_decode(trim($parts[1]), ICONV_MIME_DECODE_CONTINUE_ON_ERROR);
      if ($key === 'subject') {
         if (empty($this->data->subject)) {
            $this->data->subject = $value;
         }
      } elseif ($key === 'content-type') {
         // multipart/mixed; boundary="_3EEF4F57-19FD-4167-AE16-885929D59E6D_"
         if (preg_match('#multipart/.*;\s*boundary=("[^"]+"|\S+)(\s|$)#i', $value, $out)) {
            $this->boundary = '--' . trim($out[1], "\"\t ");
            $this->boundaryEnd = $this->boundary . '--';
         } elseif (preg_match('#(^|;)\s*name=("[^"]+"|\S+)(\s|$)#i', $value, $out)) {
            $this->data->fileName = trim($out[2], "\"\t ");
         }
      } elseif ($key === 'content-disposition') {
         if (preg_match('#(^|;)\s*filename=("[^"]+"|\S+)(\s|$)#i', $value, $out)) {
            $this->data->fileName = trim($out[2], "\"\t ");
         }
      } elseif ($key === 'content-transfer-encoding') {
         $this->transferEncoding = strtolower(trim($value));
      }
   }
   
}
