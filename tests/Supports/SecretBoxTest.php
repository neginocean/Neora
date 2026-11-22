<?php

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Tests\Support;

use Brain\Monkey;
use Brain\Monkey\Functions;
use NeoraStudio\NeoraCore\Support\SecretBox;
use PHPUnit\Framework\TestCase;

final class SecretBoxTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_salt' )->justReturn( 'unit-test-static-salt-value-123456' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_encrypt_and_decrypt_roundtrip(): void {
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			$this->markTestSkipped( 'OpenSSL extension not available.' );
		}

		$secret    = 'super-secret-melipayamak-key';
		$encrypted = SecretBox::encrypt( $secret );

		$this->assertStringStartsWith( 'neora_enc_v1:', $encrypted );
		$this->assertNotSame( $secret, $encrypted );
		$this->assertSame( $secret, SecretBox::decrypt( $encrypted ) );
		$this->assertSame( $encrypted, SecretBox::encrypt( $encrypted ) );
	}

	public function test_decrypt_returns_legacy_plaintext_and_rejects_tampered_payload(): void {
		$this->assertSame( 'plain-legacy-pass', SecretBox::decrypt( 'plain-legacy-pass' ) );
		$this->assertSame( '', SecretBox::decrypt( 'neora_enc_v1:tampered-invalid-data' ) );
		$this->assertSame( '', SecretBox::encrypt( '' ) );
	}
}