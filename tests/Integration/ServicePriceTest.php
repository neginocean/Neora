<?php

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Tests\Integration;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use NeoraStudio\NeoraCore\Modules\Services\ServicesModule;
use PHPUnit\Framework\TestCase;

final class ServicePriceTest extends TestCase {
    use MockeryPHPUnitIntegration;

    protected function setUp(): void {
        parent::setUp();
        Monkey\setUp();
        Functions\stubs([
            '__' => static fn($text) => $text,
            'sanitize_text_field' => static fn($value) => trim($value),
            'wp_unslash' => static fn($value) => $value,
            'wp_slash' => static fn($value) => $value,
            'wp_verify_nonce' => 1,
            'wp_is_post_revision' => false,
            'current_user_can' => true,
            'get_option' => [],
        ]);
    }

    protected function tearDown(): void {
        $_POST = [];
        $_GET = [];
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_price_normalizes_whole_tomans_without_integer_overflow(): void {
        foreach ([
            ['1500000', '1500000'], ['۱۵۰۰۰۰۰', '1500000'], ['١٥٠٠٠٠٠', '1500000'],
            [' 00042 ', '42'], ['0', '0'], ['۰۰۰', '0'], ['', ''], ['  ', ''],
            ['999999999999999999999999', '999999999999999999999999'], [42, '42'],
        ] as [$input, $expected]) {
            $this->assertSame($expected, ServicesModule::sanitize_price($input));
        }
    }

    public function test_invalid_price_is_not_coerced_to_another_amount(): void {
        foreach (['12.5', '1..2', '-42', '1,500', '۱٬۵۰۰', '۱۲٫۵', '1e3', '12abc', '+42', "1\n2", [], null, true, 12.5] as $input) {
            $this->assertNull(ServicesModule::sanitize_price($input));
        }
    }

    public function test_invalid_form_price_preserves_old_price_and_saves_valid_code(): void {
        $_POST = ['_neora_meta_nonce' => 'valid', 'neora_service_code' => 'SVC-2', 'neora_service_price' => '12.5'];
        Functions\expect('update_post_meta')->once()->with(42, '_neora_service_code', 'SVC-2');
        Functions\expect('delete_post_meta')->never();
        (new ServicesModule())->save_meta(42);
        $this->assertNotFalse(has_filter('redirect_post_location'));
    }

    public function test_valid_persian_form_price_is_stored_as_ascii_string(): void {
        $_POST = ['_neora_meta_nonce' => 'valid', 'neora_service_code' => 'SVC-2', 'neora_service_price' => '۱۵۰۰۰۰۰'];
        Functions\expect('update_post_meta')->once()->with(42, '_neora_service_code', 'SVC-2');
        Functions\expect('update_post_meta')->once()->with(42, '_neora_service_price', '1500000');
        Functions\expect('delete_post_meta')->never();
        (new ServicesModule())->save_meta(42);
        $this->addToAssertionCount(1);
    }

    public function test_empty_form_price_deletes_but_zero_is_saved(): void {
        $_POST = ['_neora_meta_nonce' => 'valid', 'neora_service_code' => 'SVC-2', 'neora_service_price' => ''];
        Functions\expect('update_post_meta')->twice()->with(42, '_neora_service_code', 'SVC-2');
        Functions\expect('delete_post_meta')->once()->with(42, '_neora_service_price');
        $module = new ServicesModule();
        $module->save_meta(42);
        $_POST['neora_service_price'] = '۰';
        Functions\expect('update_post_meta')->once()->with(42, '_neora_service_price', '0');
        $module->save_meta(42);
        $this->addToAssertionCount(1);
    }

    public function test_price_error_notice_is_visible_on_service_screen_only(): void {
        $_GET['neora_price_invalid'] = '1';
        Functions\when('get_current_screen')->justReturn((object)['post_type' => 'service']);
        Functions\when('esc_html__')->alias(static fn($text) => htmlspecialchars($text, ENT_QUOTES, 'UTF-8'));
        Functions\when('esc_html')->alias(static fn($text) => htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8'));
        ob_start();
        (new ServicesModule())->render_price_notice();
        $notice = ob_get_clean();
        $this->assertStringContainsString('notice-error', $notice);
        $this->assertStringContainsString('was not saved', $notice);
        $this->assertStringContainsString('tomans', $notice);
        Functions\when('get_current_screen')->justReturn((object)['post_type' => 'post']);
        ob_start();
        (new ServicesModule())->render_price_notice();
        $this->assertSame('', ob_get_clean());
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_real_wordpress_rest_validator_rejects_invalid_price(): void {
        $wpIncludes = dirname(__DIR__, 5) . '/wp-includes/';
        if (!is_file($wpIncludes . 'rest-api.php')) {
            $this->markTestSkipped('Requires the adjacent WordPress installation.');
        }
        require_once $wpIncludes . 'class-wp-error.php';
        require_once $wpIncludes . 'rest-api.php';
        Functions\when('is_wp_error')->alias(static fn($value) => $value instanceof \WP_Error);
        $registered = [];
        Functions\expect('register_meta')->twice()->andReturnUsing(
            static function ($type, $key, $args) use (&$registered) {
                $registered[$key] = $args;
                return true;
            }
        );
        (new ServicesModule())->register_meta();
        $price = $registered['_neora_service_price'];
        $schema = array_merge(['type' => $price['type']], $price['show_in_rest']['schema']);
        $this->assertSame(['edit'], $schema['context']);
        foreach (['1500000', '۱۵۰۰۰۰۰', '١٥٠٠٠٠٠', '0', ''] as $input) {
            $this->assertTrue(rest_validate_value_from_schema($input, $schema, 'price'));
        }
        foreach (['12.5', '-42', '1,500', 'abc', "12\n", [], true, 12.5, 12] as $input) {
            $this->assertInstanceOf(\WP_Error::class, rest_validate_value_from_schema($input, $schema, 'price'));
        }
        $this->assertSame('1500000', $price['sanitize_callback']('۱۵۰۰۰۰۰'));
    }
}
