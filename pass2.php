<?php
	define('LOGGING_CHANNEL', 'pass2');
	define('LOGGING_LEVEL', 'DEBUG');
	define('LOGGING_FORK_TO_STDOUT', true);
	define('DRY_RUN', true);

	require __DIR__ . '/bootstrap.php';

	use \RuntimeException;

	use \SObject;

	use DaybreakStudios\Common\IO\CsvFileReader;
	use DaybreakStudios\Salesforce\Client;

	$logger = getActiveLogger();
	$client = new Client($config['sf.username'], $config['sf.token'], SYSTEM_WSDL_FILE);

	if (!file_exists(SYSTEM_CSV_FILE))
		throw new RuntimeException(sprintf(MSG_FILE_MISSING, SYSTEM_CSV_FILE));

	$f = fopen(SYSTEM_CSV_FILE, 'r');

	if ($f === false)
		throw new RuntimeException(sprintf(MSG_FILE_NOT_READABLE, SYSTEM_CSV_FILE));

	$reader = new CsvFileReader($f);
	$reader->addFields($config['csv.fields']);

	$pos = 0;

	while (!$reader->eof() && ++$pos) {
		$row = $reader->read();

		$logger->debug('--> Read CSV row', [ $row ]);

		if (strlen($row->phone) !== 10)
			$logger->warning(sprintf(MSG_SF_UNRELIABLE_PHONE_LOOKUP, $pos));

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
			':phone' => getPhoneLikeStatement($row->phone),
			':street' => $row->street,
			':city' => $row->city,
			':state' => $row->state,
			':zip' => $row->zip,
		]);

		if ($account->size > 0) {
			$account = $account[0];

			$logger->info(sprintf(MSG_SF_OBJECT_FOUND, 'Account', $account->Id));

			$contact = $client->query('select Id, Phone from Contact where AccountId = :id and Phone like :phone', [
				':id' => $account->Id,
				':phone' => getPhoneLikeStatement($row->phone),
			]);

			if ($contact->size > 0)
				continue;

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

			if (!DRY_RUN) {
				$result = $client->create($sob);

				if (sizeof($result) === 0)
					throw new RuntimeException(sprintf(MSG_SF_API_UNKNOWN_ERROR, $pos));
				else if (!$result[0]->success)
					throw getSalesforceException($result[0]);

				$logger->debug(sprintf(MSG_SF_CONTACT_CREATED, $result[0]->Id), [ $result[0] ]);
			} else
				$logger->info(sprintf(MSG_SF_CONTACT_CREATED . ' from row %d', $row->phone, $pos));
		} else {
			$logger->info(sprintf(MSG_SF_WILL_CREATE_OBJECT, 'Account'));

			$sob = new SObject();
			$sob->type = 'Account';
			$sob->fields = [
				'Name' => $row->dealership,
				'Phone' => $row->phone,
				'BusinessStreet' => $row->street,
				'BusinessCity' => $row->city,
				'BusinessState' => $row->state,
				'BusinessPostalCode' => $row->zip,
			];

			if (!DRY_RUN) {
				$result = $client->create($sob);

				if (sizeof($result) === 0)
					throw new RuntimeException(sprintf(MSG_SF_API_UNKNOWN_ERROR, $pos));
				else if (!$result[0]->success)
					throw getSalesforceException($result[0]);

				$logger->debug(sprintf(MSG_SF_CONTACT_CREATED, $result[0]->Id), [ $result[0] ]);
			} else
				$logger->info(sprintf(MSG_SF_CONTACT_CREATED . ' from row %d', $row->phone, $pos));
		}
	}
?>