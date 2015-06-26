<?php
	require __DIR__ . '/vendor/autoload.php';

	define('SYSTEM_CONFIG_FILE', __DIR__ . '/config.yml');
	define('SYSTEM_WSDL_FILE', __DIR__ . '/sf.partner.wsdl.xml');

	define('LOGGING_GLOBAL_CHANNEL', 'bootstrap');
	define('LOGGING_LOG_FILE', __DIR__ . '/logs/log.log');
	define('LOGGING_FORMAT', "[%datetime%] [%extra.uid%] %channel%.%level_name%: %message% %context% %extra%\n");
	define('LOGGING_GLOBAL_LEVEL', Monolog\Logger::WARNING);

	Monolog\Registry::addLogger(makeLogger(LOGGING_GLOBAL_CHANNEL, LOGGING_GLOBAL_LEVEL));

	set_error_handler(function($number, $text) {
		if (!(error_reporting() & $number))
			return;

		getActiveLogger()->critical($text);

		printf('<pre>%s</pre>', $text);

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

		if (!file_exists(LOGGING_LOG_FILE)) {
			if (!file_exists(dirname(LOGGING_LOG_FILE)))
				mkdir(dirname(LOGGING_LOG_FILE), 755, true);

			touch(LOGGING_LOG_FILE);
		}

		$handler = new Monolog\Handler\StreamHandler(LOGGING_LOG_FILE, $level);
		$handler->setFormatter(new Monolog\Formatter\LineFormatter(LOGGING_FORMAT));

		$logger->pushHandler($handler);

		return $logger;
	}

	function getActiveLogger() {
		if (defined('LOGGING_CHANNEL')) {
			if (!Monolog\Registry::hasLogger(LOGGING_CHANNEL))
				Monolog\Registry::addLogger(makeLogger(LOGGING_CHANNEL, defined('LOGGING_LEVEL') ? LOGGING_LEVEL :
					LOGGING_GLOBAL_LEVEL));

			return Monolog\Registry::getInstance(LOGGING_GLOBAL_CHANNEL);
		}

		return Monolog\Registry::getInstance(LOGGING_GLOBAL_CHANNEL);
	}
?>