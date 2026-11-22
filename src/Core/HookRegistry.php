<?php
/**
 * Neora Core — Hook Registry.
 *
 * A thin injectable wrapper around WordPress add_action() and add_filter().
 * Modules receive an instance of this class in boot() and use it instead
 * of calling the global WordPress functions directly.
 *
 * This is not a custom event bus. Every call delegates immediately to
 * the WordPress hook system.
 *
 * @package NeoraStudio\NeoraCore\Core
 */

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Core;

final class HookRegistry {

    /**
     * Register a callback on a WordPress action hook.
     *
     * @param string   $hook          Hook tag (e.g. 'init', 'admin_menu').
     * @param callable $callback      The callback to attach.
     * @param int      $priority      Lower runs earlier. Default 10.
     * @param int      $accepted_args Arguments WordPress will pass to the callback.
     */
    public function action(
        string $hook,
        callable $callback,
        int $priority = 10,
        int $accepted_args = 1
    ): void {
        add_action( $hook, $callback, $priority, $accepted_args );
    }

    /**
     * Register a callback on a WordPress filter hook.
     *
     * @param string   $hook          Filter tag (e.g. 'the_content', 'body_class').
     * @param callable $callback      The callback to attach.
     * @param int      $priority      Lower runs earlier. Default 10.
     * @param int      $accepted_args Arguments WordPress will pass to the callback.
     */
    public function filter(
        string $hook,
        callable $callback,
        int $priority = 10,
        int $accepted_args = 1
    ): void {
        add_filter( $hook, $callback, $priority, $accepted_args );
    }
}
