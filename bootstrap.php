<?php
	ini_set('auto_detect_line_endings', true);

	require __DIR__ . '/vendor/autoload.php';

	define('SYSTEM_CONFIG_FILE', __DIR__ . '/config.yml');
	define('SYSTEM_WSDL_FILE', __DIR__ . '/sf.partner.wsdl.xml');
	define('SYSTEM_CSV_FILE', __DIR__ . '/list.csv');

	define('LOGGING_GLOBAL_CHANNEL', 'bootstrap');
	define('LOGGING_GLOBAL_LOG_FILE', __DIR__ . '/logs/log.log');
	define('LOGGING_GLOBAL_FORMAT', "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n");
	define('LOGGING_GLOBAL_LEVEL', Monolog\Logger::DEBUG);

	define('MSG_FILE_MISSING', 'Could not locate file: %s');
	define('MSG_FILE_NOT_READABLE', 'Could not open %s for reading');
	define('MSG_SF_API_ERROR', "An error occurred while accessing the Salesforce API (row %d): %s");
	define('MSG_SF_API_UNKNOWN_ERROR', 'An unknown error occurred while accessing the Salesforce API (row %d)');
	define('MSG_SF_OBJECT_CREATED', 'Created new %s (%s)');
	define('MSG_SF_ACCOUNT_CREATED', sprintf(MSG_SF_OBJECT_CREATED, 'Account', '%s'));
	define('MSG_SF_CONTACT_CREATED', sprintf(MSG_SF_OBJECT_CREATED, 'Contact', '%s'));
	define('MSG_SF_OBJECT_FOUND', 'Found %s with Id %s');
	define('MSG_SF_WILL_CREATE_OBJECT', 'No %s match; a new one will be created');
	define('MSG_SF_UNRELIABLE_PHONE_LOOKUP', 'Row %d\'s phone number is not 10 digits, and may not be reliably looked up');

	Monolog\Registry::addLogger(makeLogger(LOGGING_GLOBAL_CHANNEL, LOGGING_GLOBAL_LEVEL));

	set_error_handler(function($number, $text, $file, $line) {
		if (!(error_reporting() & $number))
			return;

		getActiveLogger()->critical($text);

		printf("%s in %s on line %d\n", $text, $file, $line);

		die();
	});

	set_exception_handler(function(\Exception $e) {
		getActiveLogger()->critical($e->getMessage(), [ $e->getTraceAsString() ]);

		$trace = "\t" . implode("\n\t", explode("\n", $e->getTraceAsString()));

		printf("Uncaught Exception: %s\n\nStack Trace: \n%s\n", $e->getMessage(), $trace);

		die();
	});

	$config = Symfony\Component\Yaml\Yaml::parse(file_get_contents(SYSTEM_CONFIG_FILE));

	function makeLogger($channel, $level) {
		$logger = new Monolog\Logger($channel);

		$file = defined('LOGGING_LOG_FILE') ? LOGGING_LOG_FILE : LOGGING_GLOBAL_LOG_FILE;

		if (!file_exists($file)) {
			if (!file_exists(dirname($file)))
				mkdir(dirname($file), 755, true);

			touch($file);
		}

		$format = defined('LOGGING_FORMAT') ? LOGGING_FORMAT : LOGGING_GLOBAL_FORMAT;
		$formatter = new Monolog\Formatter\LineFormatter($format);

		if (defined('LOGGING_FORK_TO_STDOUT') && LOGGING_FORK_TO_STDOUT) {
			$handler = new Monolog\Handler\StreamHandler(fopen('php://stdout', 'w'), $level);
			$handler->setFormatter($formatter);

			$logger->pushHandler($handler);
		}

		$handler = new Monolog\Handler\StreamHandler($file, $level);
		$handler->setFormatter($formatter);

		$logger->pushHandler($handler);

		return $logger;
	}

	function getActiveLogger() {
		if (defined('LOGGING_CHANNEL')) {
			if (!Monolog\Registry::hasLogger(LOGGING_CHANNEL)) {
				$level = defined('LOGGING_LEVEL') ? LOGGING_LEVEL : LOGGING_GLOBAL_LEVEL;

				if (is_string($level))
					$level = (new \ReflectionClass('Monolog\\Logger'))->getConstant($level);

				Monolog\Registry::addLogger(makeLogger(LOGGING_CHANNEL, $level));
			}

			return Monolog\Registry::getInstance(LOGGING_GLOBAL_CHANNEL);
		}

		return Monolog\Registry::getInstance(LOGGING_GLOBAL_CHANNEL);
	}

	function cleanPhone($value) {
		$v = '';

		for ($i = 0, $ii = strlen($value); $i < $ii; $i++)
			if (is_numeric($value[$i]))
				$v .= $value[$i];

		return $v;
	}

	function getPhoneLikeStatement($phone) {
		return sprintf('%s%%%s%%%s', substr($phone, 0, 3), substr($phone, 3, 3), substr($phone, 6, 4));
	}

	function getSalesforceException($record) {
		$error = [];

		foreach ($record->errors as $e)
			$error[] = $e->statusCode . ' - ' . $e->message;

		return new RuntimeException(sprintf(MSG_SF_API_ERROR, $pos, implode('; ', $error)));
	}
?>