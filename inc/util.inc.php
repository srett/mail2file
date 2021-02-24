<?php

class Util
{
   
   public static function arrayHasKeys(array $array, array $keys): bool
   {
      foreach ($keys as $key) {
         if (!array_key_exists($key, $array))
            return false;
      }
      return true;
   }
   
   public static function cleanPathElement(string $str): string
   {
      if (empty($str))
         return mt_rand();
      if ($str[0] === '.') {
         $str = '_' . $str;
      }
      return substr(preg_replace('#[^a-z0-9@$%+=_.-]+#i', '_', $str), -63);
   }

   /**
    * Parse network range in CIDR notion, return
    * ['start' => (int), 'end' => (int)] representing
    * the according start and end addresses as integer
    * values. Returns false on malformed input.
    * @param string $cidr 192.168.101/24, 1.2.3.4/16, ...
    * @return array|false start and end address, false on error
    */
   public static function parseCidr($cidr)
   {
      $parts = explode('/', $cidr);
      if (count($parts) !== 2) {
         $ip = ip2long($cidr);
         if ($ip === false)
            return false;
         if (PHP_INT_SIZE === 4) {
            $ip = sprintf('%u', $ip);
         }
         return ['start' => $ip, 'end' => $ip];
      }
      $ip = $parts[0];
      $bits = $parts[1];
      if (!is_numeric($bits) || $bits < 0 || $bits > 32)
         return false;
      $dots = substr_count($ip, '.');
      if ($dots < 3) {
         $ip .= str_repeat('.0', 3 - $dots);
      }
      $ip = ip2long($ip);
      if ($ip === false)
         return false;
      $bits = pow(2, 32 - $bits) - 1;
      if (PHP_INT_SIZE === 4)
         return ['start' => sprintf('%u', $ip & ~$bits), 'end' => sprintf('%u', $ip | $bits)];
      return ['start' => $ip & ~$bits, 'end' => $ip | $bits];
   }
   
   /**
    * Check whether IPv4 matches given CIDR/IPv4.
    * @param string $ip IP to check
    * @param string $cidr CIDR range or IP to match against
    * @return bool
    */
   public static function ipMatches(string $ip, string $cidr): bool
   {
      $ip = ip2long($ip);
      $range = self::parseCidr($cidr);
      return $ip !== false && $range !== false && $ip >= $range['start'] && $ip <= $range['end'];
   }
   
}
