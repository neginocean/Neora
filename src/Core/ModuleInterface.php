<?php
/**
 * Neora Core — Module Interface.
 *
 * All Neora Core modules must implement this interface.
 *
 * Lifecycle:
 *  1. register() — bind services into the container. No hooks here.
 *  2. boot()     — attach WordPress hooks. All modules have registered by now.
 *
 * @package NeoraStudio\NeoraCore\Core
 */

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Core;

interface ModuleInterface {

    /**
     * A unique slug identifying this module.
     */
    public function get_key(): string;

    /**
     * Bind module services into the container.
     *
     * Called for every module before any module is booted.
     * Do not attach WordPress hooks here.
     */
    public function register( Container $container ): void;

    /**
     * Attach WordPress hooks and start up the module.
     *
     * Called after all modules have completed register().
     *
     * @param HookRegistry $hooks Use this instead of calling add_action/add_filter directly.
     */
    public function boot( HookRegistry $hooks ): void;
}
