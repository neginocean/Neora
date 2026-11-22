<?php
/**
 * PHPUnit bootstrap file for Neora Core.
 *
 * @package NeoraStudio\NeoraCore\Tests
 */

declare(strict_types=1);

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$patchwork = dirname( __DIR__ ) . '/vendor/antecedent/patchwork/Patchwork.php';
if ( is_file( $patchwork ) ) {
	require_once $patchwork;
}
unset( $patchwork );

require_once __DIR__ . '/Stubs.php';

$functions_file = dirname( __DIR__ ) . '/src/Support/Functions.php';
if ( is_file( $functions_file ) ) {
	require_once $functions_file;
}
unset( $functions_file );