<?php
/**
 * Neora Core — Service Container.
 *
 * A lightweight, PSR-11-inspired container for managing service instances.
 * Callables are resolved lazily — only when first requested.
 *
 * @package NeoraStudio\NeoraCore\Core
 */

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Core;

use RuntimeException;

/**
 * Minimal dependency-injection container.
 *
 * Usage:
 *   $container->set( 'my_service', fn() => new MyService() );
 *   $service = $container->get( 'my_service' );
 */
final class Container {

    /**
     * Raw bindings: id → callable or object.
     *
     * @var array<string, callable|object>
     */
    private array $bindings = [];

    /**
     * Resolved singletons: id → object.
     *
     * @var array<string, object>
     */
    private array $resolved = [];

    /**
     * Register a service.
     *
     * Pass a callable (factory) for lazy instantiation, or a pre-built
     * object if you already have an instance.
     *
     * @param string          $id       Unique service identifier.
     * @param callable|object $concrete Factory callable or existing object.
     */
    public function set( string $id, callable|object $concrete ): void {
        $this->bindings[ $id ] = $concrete;
        // Clear any previously resolved instance so the new binding wins.
        unset( $this->resolved[ $id ] );
    }

    /**
     * Resolve and return a service.
     *
     * Callables are invoked once; the result is cached and returned on
     * subsequent calls (singleton behaviour within this container).
     *
     * @param  string $id Service identifier.
     * @return mixed      The resolved service.
     *
     * @throws RuntimeException If the service has not been registered.
     */
    public function get( string $id ): mixed {
        if ( isset( $this->resolved[ $id ] ) ) {
            return $this->resolved[ $id ];
        }

        if ( ! $this->has( $id ) ) {
            throw new RuntimeException(
                sprintf(
                    '[Neora Core] Service not found in container: "%s". ' .
                    'Make sure it is registered before the container is booted.',
                    $id
                )
            );
        }

        $concrete = $this->bindings[ $id ];

        // Resolve callable factories; store objects as-is.
        $instance = is_callable( $concrete ) ? $concrete( $this ) : $concrete;

        $this->resolved[ $id ] = $instance;

        return $instance;
    }

    /**
     * Check whether a service identifier has been registered.
     *
     * @param  string $id Service identifier.
     * @return bool
     */
    public function has( string $id ): bool {
        return isset( $this->bindings[ $id ] );
    }
}
