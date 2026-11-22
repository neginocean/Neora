<?php
/**
 * Neora Core — Optional Interface for Modules Managing Rewrite Entities.
 *
 * Allows Bootstrap activation/deactivation routines to register or clean up
 * CPTs and taxonomies without hardcoding concrete module classes.
 *
 * @package NeoraStudio\NeoraCore\Core
 */

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Core;

interface RewriteAwareInterface {

	/**
	 * Register post types and taxonomies during plugin activation.
	 */
	public function register_rewrite_entities(): void;

	/**
	 * Post type slugs registered by this module.
	 *
	 * @return array<int, string>
	 */
	public function get_rewrite_post_types(): array;

	/**
	 * Taxonomy slugs registered by this module.
	 *
	 * @return array<int, string>
	 */
	public function get_rewrite_taxonomies(): array;
}