<?php
	define('LOGGING_CHANNEL', 'pass2');
	define('LOGGING_LEVEL', 'DEBUG');
	define('DRY_RUN', true);

	require __DIR__ . '/bootstrap.php';

    use \Exception;

    use DaybreakStudios\Common\IO\CsvFileReader;
    use DaybreakStudios\Salesforce\Client;

	$logger = getActiveLogger();
    $client = new Client($config['sf.username'], $config['sf.token'], SYSTEM_WSDL_FILE);

    if (!file_exists(SYSTEM_CSV_FILE))
        throw new Exception('Could not locate ' . SYSTEM_CSV_FILE);

    $f = fopen(SYSTEM_CSV_FILE, 'r');

    if ($f === false)
        throw new Exception('Could not open ' . SYSTEM_CSV_FILE . ' for reading');

    $reader = new CsvFileReader($f);
    $reader->addFields($config['csv.fields']);

    while (!$reader->eof()) {
        $row = $reader->read();
    }
?>