<?php

return [
	'filter' => function (MailData $data, array $matchedRules) {
		// Catchall, will only activate if no other filter matched
      return empty($matchedRules);
   },
	'action' => 'store',
   'options' => [
		// you can use uppercased members from MailData, plus %DATE%
		'path' => '/mnt/landfill/%DATE%/%FROM%-%FILENAME%',
		'exists' => 'rename',
	],
];
