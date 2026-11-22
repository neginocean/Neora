<?php
/**
 * Neora Core — Application.
 *
 * Central holder for the two primary infrastructure objects: the service
 * container and the module manager.
 *
 * @package NeoraStudio\NeoraCore\Core
 */

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Core;

final class Application {

	private Container $container;
	private ModuleManager $module_manager;

	public function __construct() {
		$this->container      = new Container();
		$this->module_manager = new ModuleManager();
	}

	public function container(): Container {
		return $this->container;
	}

	public function module_manager(): ModuleManager {
		return $this->module_manager;
	}

	public function get_module_manager(): ModuleManager {
		return $this->module_manager;
	}
}