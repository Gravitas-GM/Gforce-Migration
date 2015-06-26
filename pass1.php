<?php
	require __DIR__ . '/bootstrap.php';

	define('LOGGING_CHANNEL', 'pass1');
	define('LOGGING_LEVEL', LOGGING_GLOBAL_LEVEL);

	use DaybreakStudios\Salesforce\Client;

	$client = new Client($config['sf.username'], $config['sf.token'], SYSTEM_WSDL_FILE);

	throw new \InvalidArgumentException('Derping!');
?>