<?php
	define('LOGGING_CHANNEL', 'pass3');
	define('LOGGING_LEVEL', 'DEBUG');
	define('LOGGING_FORK_TO_STDOUT', true);
	define('DRY_RUN', true);

	require __DIR__ . '/bootstrap.php';

	use \SObject;

	use DaybreakStudios\Common\IO\CsvFileReader;
	use DaybreakStudios\Common\IO\IOException;
	use DaybreakStudios\Salesforce\Client;

	$logger = getActiveLogger();
	$client = new Client($config['sf.username'], $config['sf.token'], SYSTEM_WSDL_FILE);

	if (!file_exists(SYSTEM_CSV_FILE))
		throw new IOException(sprintf(MSG_FILE_MISSING, SYSTEM_CSV_FILE));

	$f = fopen(SYSTEM_CSV_FILE, 'r');

	if ($f === false)
		throw new IOException(sprintf(MSG_FILE_NOT_READABLE, SYSTEM_CSV_FILE));

	$reader = new CsvFileReader($f);
	$reader->addFields($config['csv.fields']);
?>