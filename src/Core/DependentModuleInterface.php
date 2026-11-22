<?php
/**
 * Neora Core — Dependent Module Interface.
 *
 * Modules implementing this interface declare strict internal dependencies
 * on other Neora Core modules by their canonical module keys.
 *
 * @package NeoraStudio\NeoraCore\Core
 */

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Core;

interface DependentModuleInterface extends ModuleInterface {

	/**
	 * Return canonical keys of internal Neora Core modules required by this module.
	 *
	 * @return array<int, string>
	 */
	public function get_dependencies(): array;
}