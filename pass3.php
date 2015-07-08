<?php
	define('COUNT_ACCOUNT_CREATED', 'COUNT_ACCOUNT_CREATED');
	define('COUNT_ACCOUNT_EXISTING', 'COUNT_ACCOUNT_EXISTING');
	define('COUNT_CONTACT_CREATED', 'COUNT_CONTACT_CREATED');
	define('COUNT_CONTACT_EXISTING', 'COUNT_CONTACT_EXISTING');

	define('LOGGING_CHANNEL', 'pass3');
	define('LOGGING_LEVEL', 'DEBUG');
	define('LOGGING_FORK_TO_STDOUT', true);
	define('DRY_RUN', true);

	require __DIR__ . '/bootstrap.php';

	use \SObject;

	use DaybreakStudios\Common\IO\CsvFileReader;
	use DaybreakStudios\Common\IO\IOException;
	use DaybreakStudios\Common\Utility\Counter;

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

	$pos = 0;
	$counter = new Counter(true, [
		COUNT_ACCOUNT_CREATED,
		COUNT_ACCOUNT_EXISTING,
		COUNT_CONTACT_CREATED,
		COUNT_CONTACT_EXISTING,
	]);

	while (!$reader->eof() && ++$pos) {
		$row = $reader->read();

		$logger->debug('-- Read CSV row', [ $row ]);

		$qb = $client->createQueryBuilder();
		$qb
			->select('Id', 'Name', 'Phone')
			->from('Account')
			->where('BillingStreet = :street')
			->andWhere('BillingCity = :city')
			->andWhere('BillingState = :state')
			->andWhere('BillingPostalCode = :zip');

		$account = $qb
			->setMaxResults(1)
			->setParameter('street', $row->street)
			->setParameter('city', $row->city)
			->setParameter('state', $row->state)
			->setParameter('zip', $row->zip)
			->getQuery()
				->getOneOrNullResult();

		if ($account === null) {
			$sob = new SObject();
			$sob->type = 'Account';
			$sob->fields = [
				'Name' => $row->dealership,
				'BillingStreet' => $row->street,
				'BillingCity' => $row->city,
				'BillingState' => $row->state,
				'BillingPostalCode' => $row->zip,
			];

			if (!DRY_RUN) {
				$result = $client->create($sob);

				if (sizeof($result) === 0)
					throw new RuntimeException(sprintf(MSG_SF_API_UNKNOWN_ERROR, $pos));
				else if (!$result[0]->success)
					throw getSalesforceException($result[0]);

				$account = $result[0];
				$account->Id = $account->id;
				$account->fresh = true;

				unset($account->id);

				$logger->debug(sprintf(MSG_SF_ACCOUNT_CREATED, $result[0]->id), [ $result[0] ]);
			} else {
				$account = new stdClass();
				$account->Id = sprintf('row#%d', $pos);
				$account->fresh = true;

				$logger->info(sprintf(MSG_SF_ACCOUNT_CREATED . ' from row %d', $row->phone, $pos));
			}
		} else {
			if (strtolower($account->fields->Name) === strtolower($row->dealership))
				continue;
			else if (in_array(cleanPhone($account->fields->Phone), [ $row->phone, $row->altPhone ]))
				continue;

			$account->fresh = false;
		}

		$counter->inc($account->fresh ? COUNT_ACCOUNT_CREATED : COUNT_ACCOUNT_EXISTING);

		if (!$account->fresh) {
			if (strlen($row->phone) !== 10)
				$logger->warning(sprintf(MSG_SF_UNRELIABLE_PHONE_LOOKUP, $pos));

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
				$counter->inc(COUNT_CONTACT_EXISTING);

				continue;
			}
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

		if (!DRY_RUN) {
			$result = $client->create($sob);

			if (sizeof($result) === 0)
				throw new RuntimeException(sprintf(MSG_SF_API_UNKNOWN_ERROR, $pos));
			else if (!$result[0]->success)
				throw new getSalesforceException($result[0]);

			$logger->debug(sprintf(MSG_SF_CONTACT_CREATED, $result[0]->id), [ $result[0] ]);
		} else
			$logger->info(sprintf(MSG_SF_CONTACT_CREATED . ' from row %d', $row->phone, $pos));

		$counter->inc(COUNT_CONTACT_CREATED);
	}

	$logger->info(sprintf(MSG_PASS3_SUMMARY,
		$counter->get(COUNT_ACCOUNT_EXISTING),
		$counter->get(COUNT_ACCOUNT_EXISTING) !== 1 ? 's' : '',
		$counter->get(COUNT_ACCOUNT_EXISTING) !== 1 ? 'were' : 'was',
		$counter->get(COUNT_CONTACT_EXISTING),
		$counter->get(COUNT_CONTACT_EXISTING) !== 1 ? 's' : '',
		$counter->get(COUNT_ACCOUNT_CREATED),
		$counter->get(COUNT_ACCOUNT_CREATED) !== 1 ? 's' : '',
		$counter->get(COUNT_CONTACT_CREATED),
		$counter->get(COUNT_CONTACT_CREATED) !== 1 ? 's' : ''
	));
?>