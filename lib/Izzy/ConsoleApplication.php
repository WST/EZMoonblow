<?php

namespace Izzy;

use Monolog\Logger;
use Monolog\Level;
use Monolog\Handler\StreamHandler;
use Bramus\Monolog\Formatter\ColoredLineFormatter;

class ConsoleApplication
{
	protected ?Logger $logger = null;

    private string $applicationName;

    public function __construct($applicationName) {
        $this->applicationName = $applicationName;
	}

	public function getLogger(): Logger {
        if(is_null($this->logger)) {
            $this->logger = new Logger($this->applicationName);
            $formatter = new ColoredLineFormatter(
                null,
                "[%datetime%] <%level_name%> %message%\n",
                "Y-m-d H:i:s",
                true, // allowInlineLineBreaks option, default false
                true  // discard empty Square brackets in the end, default false
            );
            $streamHandler = new StreamHandler('php://stdout', Level::Info);
            $streamHandler->setFormatter($formatter);
            $this->logger->pushHandler($streamHandler);
        }
        return $this->logger;
    }
}
