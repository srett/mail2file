<?php

/**
 * Information about incoming mail, or
 * part of a multi-part mime mail.
 */
class MailData
{
   
   public $from = '';
   
   public $to = '';
   
   public $subject = '';
   
   public $fileName = '';
   
   public $srcAddr = '';
   
   public function __construct(string $srcAddr)
   {
      $this->srcAddr = preg_replace(['/:\d+$/', '/\]\[/'], '', $srcAddr);
   }

}
