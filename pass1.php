<?php
	define('COUNT_CREATED', 'COUNT_CREATED');
	define('COUNT_EXISTING', 'COUNT_EXISTING');
	define('COUNT_NEXT_PASS', 'COUNT_NEXT_PASS');

	define('LOGGING_CHANNEL', 'pass1');
	define('LOGGING_LEVEL', 'DEBUG');
	define('LOGGING_FORK_TO_STDOUT', true);
	define('DRY_RUN', true);

	require __DIR__ . '/bootstrap.php';

	use \RuntimeException;

	use \SObject;

	use DaybreakStudios\Common\IO\CsvFileReader;
	use DaybreakStudios\Common\IO\IOException;
	use DaybreakStudios\Common\Utility\Counter;

	use DaybreakStudios\Salesforce\Client;

	$logger = getActiveLogger();
	$client = new Client($config['sf.username'], $config['sf.token'], SYSTEM_WSDL_FILE);

	if (!file_exists(SYSTEM_CSV_FILE))
		throw new IOException('Could not locate ' . SYSTEM_CSV_FILE);

	$f = fopen(SYSTEM_CSV_FILE, 'r');

	if ($f === false)
		throw new IOException('Could not open ' . SYSTEM_CSV_FILE . ' for reading');

	$reader = new CsvFileReader($f);
	$reader->addFields($config['csv.fields']);

	$pos = 0;
	$counter = new Counter(true, [
		COUNT_CREATED,
		COUNT_EXISTING,
		COUNT_NEXT_PASS,
	]);

	while (!$reader->eof() && ++$pos) {
		$row = $reader->read();

		$logger->debug('-- Read CSV row', [ $row ]);

		$qb = $client->createQueryBuilder();
		$qb
			->select('Id')
			->from('Account')
			->where('Name = :dealership');

		$account = $qb
			->setMaxResults(1)
			->setParameter('dealership', $row->dealership)
			->getQuery()
				->getOneOrNullResult();

		if ($account === null) {
			$counter->inc(COUNT_NEXT_PASS);

			continue;
		}

		$logger->debug('Found Account with Id ' . $account->Id, [ $account ]);

		$orx = [
			'Phone like :phone',
			'Phone_2__c like :phone',
		];

		if (strlen($row->altPhone) === 10)
			$orx = array_merge($orx, [
				'Phone like :alt',
				'Phone_2__c like :alt',
			]);

		$qb = $client->createQueryBuilder();
		$qb
			->select('Id')
			->from('Contact')
			->where('AccountId = :id')
			->andWhere($qb->expr()->orX(
				'Phone like :phone',
				'Phone_2__c like :phone',
				'Phone like :alt',
				'Phone_2__c like :alt'
			));

		$contact = $qb
			->setMaxResults(1)
			->setParameter('id', $account->Id)
			->setParameter('phone', getPhoneLikeStatement($row->phone))
			->setParameter('alt', getPhoneLikeStatement($row->altPhone))
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
			'Phone' => $row->phone,
			'Phone_2__c' => $row->altPhone,
			'Job_Title__c' => $row->position,
			'Street' => $row->street,
			'City' => $row->city,
			'State' => $row->state,
			'PostalCode' => $row->zip,
		];

		$logger->info('Creating new Contact on Account (' . $account->Id . ')', [ $sob ]);

		if (!DRY_RUN) {
			$result = $client->create($sob);

			if (sizeof($result) === 0)
				throw new RuntimeException(sprintf(MSG_SF_API_UNKNOWN_ERROR, $pos));
			else if (!$result[0]->success)
				throw getSalesforceException($result[0]);

			$logger->debug(sprintf(MSG_SF_CONTACT_CREATED, $result[0]->id), [ $result ]);
		} else
			$logger->info(sprintf(MSG_SF_CONTACT_CREATED . ' from row %d', $row->phone, $pos));

		$counter->inc(COUNT_CREATED);
	}

	$logger->info(sprintf(MSG_PASS_SUMMARY,
		1,
		$counter->get(COUNT_CREATED),
		$counter->get(COUNT_CREATED) !== 1 ? 's' : '',
		$counter->get(COUNT_EXISTING),
		$counter->get(COUNT_EXISTING) !== 1 ? 's' : '',
		$counter->get(COUNT_NEXT_PASS),
		$counter->get(COUNT_NEXT_PASS) !== 1 ? 's' : ''
	));
?>