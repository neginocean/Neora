<?php

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Tests\Integration;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use NeoraStudio\NeoraCore\Core\Bootstrap;
use NeoraStudio\NeoraCore\Core\MetaHelper;
use NeoraStudio\NeoraCore\Modules\Portfolio\PortfolioModule;
use NeoraStudio\NeoraCore\Modules\Services\ServicesModule;
use PHPUnit\Framework\TestCase;

final class ReleaseRegressionTest extends TestCase {
    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\stubs([
            '__' => static fn($text) => $text,
            'sanitize_text_field' => static fn($value) => trim(strip_tags($value)),
            'wp_unslash' => static fn($value) => is_string($value) ? stripslashes($value) : $value,
            'wp_slash' => static fn($value) => is_string($value) ? addslashes($value) : $value,
            'get_option' => [],
        ]);
    }

    protected function tearDown(): void {
        $_POST = [];
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_year_rejects_malformed_values_without_truncating(): void {
        foreach (['2024abc', '2024.9', '2e3', '+2024', '02024', 2024.5, [], null, true] as $value) {
            $this->assertSame('', PortfolioModule::sanitize_year($value));
        }
        $this->assertSame(2024, PortfolioModule::sanitize_year(2024));
        $this->assertSame(1399, PortfolioModule::sanitize_year(' 1399 '));
    }

    public function test_array_nonce_never_reaches_verification_or_storage(): void {
        Functions\expect('wp_verify_nonce')->never();
        Functions\expect('update_post_meta')->never();
        Functions\expect('delete_post_meta')->never();
        $_POST = ['_neora_meta_nonce' => ['invalid']];
        (new ServicesModule())->save_meta(42);
        (new PortfolioModule())->save_meta(42);
        MetaHelper::save_fields(42, [], $_POST);
        $this->addToAssertionCount(1);
    }

    public function test_scalar_field_rejects_array_without_overwriting_existing_value(): void {
        $this->allowSave();
        Functions\expect('update_post_meta')->never();
        Functions\expect('delete_post_meta')->never();
        MetaHelper::save_fields(42, [[
            'meta_key' => '_title', 'post_key' => 'title', 'type' => 'string',
            'sanitize_cb' => 'trim',
        ]], ['_neora_meta_nonce' => 'valid', 'title' => ['bad']]);
        $this->addToAssertionCount(1);
    }

    public function test_service_text_survives_wordpress_slash_and_sanitization_cycle(): void {
        $this->allowSave();
        $registered = [];
        Functions\expect('register_meta')->twice()->andReturnUsing(
            static function ($type, $key, $args) use (&$registered) {
                $registered[$key] = $args;
                return true;
            }
        );
        $module = new ServicesModule();
        $module->register_meta();
        $expected = 'Client\'s C:\\design\\file';
        // Match update_metadata(): unslash first, then apply registered sanitizer.
        Functions\expect('update_post_meta')->once()->with(
            42, '_neora_service_code', \Mockery::type('string')
        )->andReturnUsing(function ($id, $key, $value) use ($registered, $expected) {
            $stored = $registered[$key]['sanitize_callback'](stripslashes($value));
            $this->assertSame($expected, $stored);
            return true;
        });
        Functions\expect('delete_post_meta')->once()->with(42, '_neora_service_price');
        $_POST = ['_neora_meta_nonce' => 'valid', 'neora_service_code' => addslashes($expected)];
        $module->save_meta(42);
    }

    public function test_deactivation_unregisters_routes_before_flushing(): void {
        Functions\expect('unregister_post_type')->once()->with('service')->ordered();
        Functions\expect('unregister_post_type')->once()->with(PortfolioModule::CPT)->ordered();
        Functions\expect('unregister_taxonomy')->once()->with('service_category')->ordered();
        Functions\expect('unregister_taxonomy')->once()->with(PortfolioModule::TAX)->ordered();
        Functions\expect('flush_rewrite_rules')->once()->ordered();
        Bootstrap::deactivate();
        $this->addToAssertionCount(1);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_service_meta_is_removed_from_public_rest_contexts(): void {
        $wpFunctions = dirname(__DIR__, 5) . '/wp-includes/rest-api.php';
        if (!is_file($wpFunctions)) {
            $this->markTestSkipped('Requires the adjacent WordPress installation for its real REST context filter.');
        }
        require_once $wpFunctions;
        $registered = [];
        Functions\expect('register_meta')->twice()->andReturnUsing(
            static function ($type, $key, $args) use (&$registered) {
                $registered[$key] = $args;
                return true;
            }
        );
        (new ServicesModule())->register_meta();
        $schema = ['type' => 'object', 'properties' => []];
        $values = ['_neora_service_code' => 'PRIVATE', '_neora_service_price' => '150000'];
        $this->assertSame(array_keys($values), array_keys($registered));
        foreach ($registered as $key => $args) {
            $schema['properties'][$key] = $args['show_in_rest']['schema'];
            $this->assertSame('service', $args['object_subtype']);
        }
        $this->assertSame([], rest_filter_response_by_context($values, $schema, 'view'));
        $this->assertSame([], rest_filter_response_by_context($values, $schema, 'embed'));
        $this->assertSame($values, rest_filter_response_by_context($values, $schema, 'edit'));

        Functions\expect('current_user_can')->twice()->with('edit_post', 42)->andReturn(false, true);
        $auth = $registered['_neora_service_code']['auth_callback'];
        $this->assertFalse($auth(false, '_neora_service_code', 0));
        $this->assertFalse($auth(false, '_neora_service_code', 42));
        $this->assertTrue($auth(false, '_neora_service_code', 42));
    }

    public function test_shared_helper_unslashes_before_sanitizing_and_reslashes_for_storage(): void {
        $this->allowSave();
        $expected = 'Client\'s C:\\design\\file';
        Functions\expect('update_post_meta')->once()->with(42, '_title', addslashes($expected));
        MetaHelper::save_fields(42, [[
            'meta_key' => '_title', 'post_key' => 'title', 'type' => 'string',
            'sanitize_cb' => function ($value) use ($expected) {
                $this->assertSame($expected, $value);
                return $value;
            },
        ]], ['_neora_meta_nonce' => 'valid', 'title' => addslashes($expected)]);
    }

    private function allowSave(): void {
        Functions\when('wp_verify_nonce')->justReturn(1);
        Functions\when('wp_is_post_revision')->justReturn(false);
        Functions\when('current_user_can')->justReturn(true);
    }
}
