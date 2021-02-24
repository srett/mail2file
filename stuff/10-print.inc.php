<?php

return [
   'filter' => function (MailData $data, array $matchedRules) {
      return preg_match('/^print@/i', $data->to)
              && (Util::ipMatches($data->srcAddr, '192.168.0/24')
                  || Util::ipMatches($data->srcAddr, '127/8'));
   },
   'action' => 'exec',
   'options' => [
      // %TEMPFILE% is where the file was stored, %FILENAME% the original attachment name,
      // %DATE% is Y-m-d, other vars are uppercased MailData members.
      'cmd' => [
         '/usr/bin/lpr',
         '-P', 'HP_LaserJet_1020',
         '%TEMPFILE%',
      ],
      // Delete after process exits
      'delete' => true,
      /*
      'cwd' => '.',
      'env' => [
          'foo' => 'bar',
      ], 
      */
   ],
];
