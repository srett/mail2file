<?php

abstract class Filter
{

	private static $rules = [];

	public static function load()
	{
		self::$rules = [];
		foreach (glob('rules.d/*.inc.php') as $file) {
			$rule = include($file);
			if (!is_array($rule) || !isset($rule['action'])) {
				Log::warn("Rule without action: $file");
				continue;
			}
			if (isset($rule['filter']) && !is_callable($rule['filter'])) {
				Log::warn("Rule filter not callable: $file");
				continue;
			}
			self::$rules[basename($file)] = $rule;
		}
	}

	public static function get(MailData $data)
	{
		$return = [];
		foreach (self::$rules as $file => $rule) {
			if (isset($rule['filter']) && !$rule['filter']($data, array_keys($return)))
				continue;
         $class = $rule['action'] . 'Filter';
         if (class_exists($class)) {
            $return[$file] = new $class($rule['options'], $data);
         } else {
            Log::warn("Filter Type $class not found");
         }
		}
		return $return;
	}

	/*
	 *
	 */

	public abstract function __construct($action, MailData $data);

	public abstract function feed(string $chunk);

	public abstract function finished();


}
