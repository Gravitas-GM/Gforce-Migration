<?php
	define('COUNT_CREATED', 'COUNT_CREATED');
	define('COUNT_EXISTING', 'COUNT_EXISTING');
	define('COUNT_NEXT_PASS', 'COUNT_NEXT_PASS');

	define('LOGGING_CHANNEL', 'pass2');
	define('LOGGING_LEVEL', 'DEBUG');
	define('LOGGING_FORK_TO_STDOUT', true);
	define('DRY_RUN', true);

	require __DIR__ . '/bootstrap.php';

	use \RuntimeException;

	use \SObject;

	use DaybreakStudios\Common\IO\CsvFileReader;
	use DaybreakStudios\Common\Utility\Counter;
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

	$counter = new Counter(true, [
		COUNT_CREATED,
		COUNT_EXISTING,
		COUNT_NEXT_PASS,
	]);

	$pos = 0;

	while (!$reader->eof() && ++$pos) {
		$row = $reader->read();

		$logger->debug('--> Read CSV row', [ $row ]);

		if (strlen($row->phone) !== 10)
			$logger->warning(sprintf(MSG_SF_UNRELIABLE_PHONE_LOOKUP, $pos));

		$qb = $client->createQueryBuilder();
		$qb
			->select('Id')
			->from('Account')
			->where('Phone like :phone')
			->andWhere('BillingStreet = :street')
			->andWhere('BillingCity = :city')
			->andWhere('BillingState = :state')
			->andWhere('BillingPostalCode = :zip');

		$account = $qb
			->setMaxResults(1)
			->setParameter('phone', getPhoneLikeStatement($row->phone))
			->setParameter('street', $row->street)
			->setParameter('city', $row->city)
			->setParameter('state', $row->state)
			->setParameter('zip', $row->zip)
			->getQuery()
				->getOneOrNullResult();

		if ($account === null) {
			$counter->inc(COUNT_NEXT_PASS);

			$logger->info(sprintf(MSG_SAVED_FOR_NEXT_PASS, 'Account', implode(', ', [
				$row->street,
				$row->city,
				$row->state,
				$row->zip,
			])));

			continue;
		}

		$logger->info(sprintf(MSG_SF_OBJECT_FOUND, 'Account', $account->Id));

		$qb = $client->createQueryBuilder();
		$qb
			->select('Id')
			->from('Contact')
			->where('AccountId = :id')
			->andWhere('Phone like :phone');

		$contact = $qb
			->setMaxResults(1)
			->setParameter('id', $account->Id)
			->setParameter('phone', getPhoneLikeStatement($row->phone))
			->getQuery()
				->getOneOrNullResult();

		if ($contact !== null) {
			$counter->inc(COUNT_EXISTING);

			continue;
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

		if (!DRY_RUN) {
			$result = $client->create($sob);

			if (sizeof($result) === 0)
				throw new RuntimeException(sprintf(MSG_SF_API_UNKNOWN_ERROR, $pos));
			else if (!$result[0]->success)
				throw getSalesforceException($result[0]);

			$counter->inc(COUNT_CREATED);

			$logger->debug(sprintf(MSG_SF_CONTACT_CREATED, $result[0]->id), [ $result[0] ]);
		} else {
			$counter->inc(COUNT_CREATED);

			$logger->info(sprintf(MSG_SF_CONTACT_CREATED . ' from row %d', $row->phone, $pos));
		}
	}

	printf("\n\n" . MSG_PASS_SUMMARY . "\n\n",
		2,
		$counter->get(COUNT_CREATED),
		$counter->get(COUNT_CREATED) !== 1 ? 's' : '',
		$counter->get(COUNT_EXISTING),
		$counter->get(COUNT_EXISTING) !== 1 ? 's' : '',
		$counter->get(COUNT_NEXT_PASS),
		$counter->get(COUNT_NEXT_PASS) !== 1 ? 's' : ''
	);
?>