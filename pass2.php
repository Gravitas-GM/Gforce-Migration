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

	$pos = 0;

	while (!$reader->eof() && ++$pos) {
		$row = $reader->read();

		$logger->debug('--> Read CSV row', [ $row ]);

		if (strlen($row->phone) !== 10)
			printf("Row %d's phone number is not 10 digits, and may not be reliably looked up.\n", $pos);

		$phone = sprintf('%s%%%s%%%s', substr($row->phone, 0, 3), substr($row->phone, 3, 3), substr($row->phone, 6, 4));

		$account = $client->query('
			select
				Id
			from
				Account
			where
				Phone like :phone and
				BillingStreet = :street and
				BillingCity = :city and
				BillingState = :state and
				BillingPostalCode = :zip
			limit 1
		', [
			':phone' => $phone,
			':street' => $row->street,
			':city' => $row->city,
			':state' => $row->state,
			':zip' => $row->zip,
		]);

		if ($account->size > 0) {
			$account = $account->records[0];

			$logger->info('Found Account with Id ' . $account->Id);
		} else {
			$logger->info('No Account match; a new one will be created');
		}
	}
?>