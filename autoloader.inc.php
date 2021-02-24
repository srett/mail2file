<?php

spl_autoload_register(function($class) {
   $file = 'inc/' . strtolower(preg_replace('#^.*[/\\\\]#', '', $class)) . '.inc.php';
   if (file_exists($file)) require_once $file;
});