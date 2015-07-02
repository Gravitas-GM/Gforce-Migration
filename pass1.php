<?php
	define('LOGGING_CHANNEL', 'pass1');
	define('LOGGING_LEVEL', 'DEBUG');
	define('DRY_RUN', true);

	require __DIR__ . '/bootstrap.php';

	use \Exception;

	use \SObject;

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

		$logger->debug('-- Read CSV row', [ $row ]);

		$account = $client->query('select Id from Account where Name = :dealership limit 1', [
			':dealership' => $row->dealership,
		]);

		if ($account->size === 0) {
			$logger->info('No match for dealership named ' . $row->dealership . '; saving for pass 2');

			continue;
		}

		$account = $account->records[0];

		$logger->debug('Found Account with Id ' . $account->Id, [ $account ]);

		$result = $client->query('select Id, Phone from Contact where AccountId = :id', [
			':id' => $account->Id,
		]);

		foreach ($result->records as $record) {
			$phone = cleanPhone($record->fields->Phone);

			if ($row->phone === $phone) {
				$logger->debug('Found matching phones; ' . $record->Id . ' has phone number ' . $row->phone);

				continue 2;
			}
		}

		$sob = new SObject();
		$sob->type = 'Contact';
		$sob->fields = [
			'AccountId' => $account->Id,
			'FirstName' => $row->firstName,
			'LastName' => $row->lastName,
			'Job_Title__c' => $row->position,
			'Street' => $row->street,
			'City' => $row->city,
			'State' => $row->state,
			'PostalCode' => $row->zip,
		];

		$logger->info('Creating new Contact on Account (' . $account->Id . ')', [ $sob ]);

		if (!DRY_RUN) {
			$result = $client->create($sob);

			$logger->debug('Created new Contact', [ $result ]);
		} else
			printf("Adding new contact with phone %s to %s\n", $row->phone, $account->Id);
	}
?>