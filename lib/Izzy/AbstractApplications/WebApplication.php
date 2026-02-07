<?php

namespace Izzy\AbstractApplications;

use Izzy\Configuration\Configuration;
use Izzy\System\Database\Database;
use Izzy\Web\Viewers\PageViewer;
use Slim\App;
use Slim\Factory\AppFactory;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Base class for all web applications.
 */
abstract class WebApplication extends IzzyApplication
{
	protected App $slimApp;

	protected Configuration $configuration;

	protected Database $database;

	protected Environment $twig;

	protected FilesystemLoader $twigLoader;

	public function __construct() {
		$this->slimApp = AppFactory::create();
		$this->twigLoader = new FilesystemLoader(IZZY_TEMPLATES);
		$this->twig = new Environment($this->twigLoader, ['cache' => false]);
		parent::__construct();
		session_start();
	}

	public function run(): void {
		$this->slimApp->run();
	}

	public function getTwig() {
		return $this->twig;
	}
}
