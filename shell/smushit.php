<?php
/**
 * @category Fishpig
 * @package Fishpig_SmushIt
 * @license http://fishpig.co.uk/license.txt
 * @author Ben Tideswell <ben@fishpig.co.uk>
 * @SkipObfuscation
 */
define('IS_COMMAND_LINE', PHP_SAPI === 'cli');
define('LB', IS_COMMAND_LINE ? "\n" : '<br/>');
	
error_reporting(E_ALL);
ini_set('display_errors', 1);	

set_time_limit(0);

// This is required incase extension is installed using modman
// Would usually use dirname(__DIR__) however is this file in a symlink
// This will point to the parent directory of the actual file and not the symlink
$dirsToTry = array(
	getcwd(), 					// Calling from the Magento directory
	dirname(getcwd()), 	// Calling from the shell directory
	__DIR__, 						// Calling from the Magento directory
	dirname(__DIR__), 	// Calling from the shell directory
);

$ds = DIRECTORY_SEPARATOR;

foreach($dirsToTry as $dir) {
	$appMageFile = $dir . $ds . 'app' . $ds . 'Mage.php';
	
	if (is_file($appMageFile)) {
		include($appMageFile);
		umask(0);
		Mage::app();
		break;
	}
}

if (!class_exists('Mage')) {
	echo 'Unable to find Magento installation.';
	exit;
}

	try {
 	echo LB . "Running Smush.it" . LB;
 	
 	Mage::helper('smushit')->run();
 	
 	echo "Complete" . LB;
 }
 catch (Exception $e) {
	 echo LB . 'Exception: ' . $e->getMessage() . LB . LB;
 }
	