<?php

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Tests\Integration;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use NeoraStudio\NeoraCore\Core\HookRegistry;
use NeoraStudio\NeoraCore\Modules\Portfolio\PortfolioModule;
use PHPUnit\Framework\TestCase;

class PortfolioModuleIntegrationTest extends TestCase
{
    private int $postId = 99;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // sanitizer های خالص — نیازی به override ندارن.
        Functions\stubs([
            'wp_slash'                => fn( $v ) => $v,
            'sanitize_text_field'     => fn( $v ) => $v,
            'sanitize_textarea_field' => fn( $v ) => trim( (string) $v ),
            'wp_unslash'              => fn( $v ) => $v,
            'sanitize_key'            => fn( $v ) => $v,
            'esc_url_raw'             => fn( $v ) => $v,
            '__'                      => fn( $text ) => $text,
        ]);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        Mockery::close();
        $_POST = [];
        parent::tearDown();
    }

    // ─── Happy Path ───────────────────────────────────────────────────────────

    public function test_save_meta_calls_update_post_meta_when_all_guards_pass(): void
    {
        $postId = $this->postId;

        // PortfolioModule (step 1) + MetaHelper (step 1) = 2 بار.
        Functions\expect('wp_verify_nonce')
            ->twice()
            ->with('valid-nonce', 'neora_save_meta_' . $postId)
            ->andReturn(1);

        // PortfolioModule (step 3) + MetaHelper (step 4) = 2 بار.
        Functions\expect('wp_is_post_revision')
            ->twice()
            ->with($postId)
            ->andReturn(false);

        // PortfolioModule (step 4) + MetaHelper (step 2) = 2 بار.
        Functions\expect('current_user_can')
            ->twice()
            ->with('edit_post', $postId)
            ->andReturn(true);

        // همه فیلدها مقدار دارن → update صدا می‌شه.
        Functions\expect('update_post_meta')
            ->atLeast()->once()
            ->andReturn(true);

        $_POST['_neora_meta_nonce']                 = 'valid-nonce';
        $_POST['neora_portfolio_client_name']       = 'Acme Corp';
        $_POST['neora_portfolio_project_url']       = 'https://example.com';
        $_POST['neora_portfolio_completion_year']   = '2024';
        $_POST['neora_portfolio_short_description'] = 'WordPress, PHP';

        (new PortfolioModule())->save_meta($postId);

        // Mockery expectations verified in tearDown count as assertions.
        $this->addToAssertionCount(1);
    }

    // ─── Happy Path: فیلدهای اختیاری خالی → delete ──────────────────────────

    public function test_save_meta_deletes_optional_fields_when_empty(): void
    {
        $postId = $this->postId;

        Functions\expect('wp_verify_nonce')
            ->twice()
            ->with('valid-nonce', 'neora_save_meta_' . $postId)
            ->andReturn(1);

        Functions\expect('wp_is_post_revision')
            ->twice()
            ->with($postId)
            ->andReturn(false);

        Functions\expect('current_user_can')
            ->twice()
            ->with('edit_post', $postId)
            ->andReturn(true);

        // client و url مقدار دارن → update. year و tech خالی → delete.
        Functions\expect('update_post_meta')
            ->twice()
            ->andReturn(true);

        Functions\expect('delete_post_meta')
            ->twice()
            ->andReturn(true);

        $_POST['_neora_meta_nonce']                 = 'valid-nonce';
         $_POST['neora_portfolio_client_name']       = 'Acme Corp';
        $_POST['neora_portfolio_project_url']       = 'https://example.com';
        $_POST['neora_portfolio_completion_year']   = '';    // اختیاری → delete
        $_POST['neora_portfolio_short_description'] = '   '; // فضای خالی → delete
        (new PortfolioModule())->save_meta($postId);

        $this->addToAssertionCount(1);
    }

    // ─── Guard: Nonce ─────────────────────────────────────────────────────────

    public function test_save_meta_returns_early_when_nonce_invalid(): void
    {
        $this->expectNotToPerformAssertions();

        $postId = $this->postId;
        $_POST['_neora_meta_nonce'] = 'bad-nonce';

        Functions\expect('wp_verify_nonce')
            ->once()
            ->with('bad-nonce', 'neora_save_meta_' . $postId)
            ->andReturn(false);

        (new PortfolioModule())->save_meta($postId);
    }

    // ─── Guard: Autosave ──────────────────────────────────────────────────────

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_save_meta_returns_early_when_doing_autosave(): void
    {
        $this->expectNotToPerformAssertions();

        $postId = $this->postId;
        $_POST['_neora_meta_nonce'] = 'valid-nonce';

        Functions\expect('wp_verify_nonce')
            ->once()
            ->with('valid-nonce', 'neora_save_meta_' . $postId)
            ->andReturn(1);

        define('DOING_AUTOSAVE', true);

        // step 2: DOING_AUTOSAVE → return. هیچ تابع دیگه‌ای صدا نمی‌شه.

        (new PortfolioModule())->save_meta($postId);
    }

    // ─── Guard: Revision ──────────────────────────────────────────────────────

    public function test_save_meta_returns_early_when_post_is_revision(): void
    {
        $this->expectNotToPerformAssertions();

        $postId = $this->postId;
        $_POST['_neora_meta_nonce'] = 'valid-nonce';

        Functions\expect('wp_verify_nonce')
            ->once()
            ->with('valid-nonce', 'neora_save_meta_' . $postId)
            ->andReturn(1);

        // step 3: revision → true → return. capability و MetaHelper هرگز صدا نمی‌شن.
        Functions\expect('wp_is_post_revision')
            ->once()
            ->with($postId)
            ->andReturn(99);

        (new PortfolioModule())->save_meta($postId);
    }

    // ─── Guard: Capability ────────────────────────────────────────────────────

    public function test_save_meta_returns_early_when_user_cannot_edit_post(): void
    {
        $this->expectNotToPerformAssertions();

        $postId = $this->postId;
        $_POST['_neora_meta_nonce'] = 'valid-nonce';

        Functions\expect('wp_verify_nonce')
            ->once()
            ->with('valid-nonce', 'neora_save_meta_' . $postId)
            ->andReturn(1);

        // step 3: revision → false → ادامه می‌ده.
        Functions\expect('wp_is_post_revision')
            ->once()
            ->with($postId)
            ->andReturn(false);

        // step 4: capability → false → return. MetaHelper هرگز صدا نمی‌شه.
        Functions\expect('current_user_can')
            ->once()
            ->with('edit_post', $postId)
            ->andReturn(false);

        (new PortfolioModule())->save_meta($postId);
    }

    // ─── sanitize_year ────────────────────────────────────────────────────────

    public function test_sanitize_year_returns_integer_for_valid_year(): void
    {
        $this->assertSame(2024, PortfolioModule::sanitize_year('2024'));
    }

    public function test_sanitize_year_returns_integer_for_year_with_surrounding_whitespace(): void
    {
        $this->assertSame(1399, PortfolioModule::sanitize_year(' 1399 '));
    }

    public function test_sanitize_year_returns_empty_string_for_empty_input(): void
    {
        $this->assertSame('', PortfolioModule::sanitize_year(''));
    }

    public function test_sanitize_year_returns_empty_string_for_whitespace_only(): void
    {
        $this->assertSame('', PortfolioModule::sanitize_year('   '));
    }

    public function test_sanitize_year_returns_empty_string_for_alphabetic_input(): void
    {
        $this->assertSame('', PortfolioModule::sanitize_year('abc'));
    }

    public function test_sanitize_year_returns_empty_string_for_float_string(): void
    {
        // is_numeric پاس می‌کنه ولی ctype_digit رد می‌کنه — باید '' برگرده.
        $this->assertSame('', PortfolioModule::sanitize_year('20.24'));
    }

    public function test_sanitize_year_returns_empty_string_for_negative_value(): void
    {
        $this->assertSame('', PortfolioModule::sanitize_year('-2024'));
    }

    // ─── Lifecycle / hooks ────────────────────────────────────────────────────

    public function test_boot_registers_expected_hooks(): void
    {
        $module = new PortfolioModule();
        $module->boot(new HookRegistry());

        $this->assertNotFalse(has_action('init', [$module, 'register_post_type']));
        $this->assertNotFalse(has_action('init', [$module, 'register_taxonomy']));
        $this->assertNotFalse(has_action('init', [$module, 'register_meta_fields']));
        $this->assertNotFalse(has_action('add_meta_boxes_' . PortfolioModule::CPT, [$module, 'add_meta_box']));
        $this->assertNotFalse(has_action('save_post_' . PortfolioModule::CPT, [$module, 'save_meta']));
    }

    // ─── Meta registration ────────────────────────────────────────────────────

    public function test_field_schema_defines_required_keys_for_every_field(): void
    {
        $schema = PortfolioModule::field_schema();

        $this->assertCount(4, $schema);

        foreach ($schema as $field) {
            foreach (['meta_key', 'post_key', 'type', 'sanitize_cb'] as $key) {
                $this->assertArrayHasKey($key, $field);
            }
            $this->assertStringStartsWith('_neora_portfolio_', $field['meta_key']);
            $this->assertIsCallable($field['sanitize_cb']);
        }
    }

    public function test_register_meta_fields_uses_object_aware_edit_post_auth(): void
    {
        $registered = [];

        Functions\expect('register_meta')
            ->times(4)
            ->andReturnUsing(function ($objectType, $metaKey, $args) use (&$registered) {
                $registered[$metaKey] = $args;
                return true;
            });

        Functions\expect('current_user_can')
            ->once()
            ->with('edit_post', $this->postId)
            ->andReturn(true);

        (new PortfolioModule())->register_meta_fields();

        $this->assertSame(
            array_column(PortfolioModule::field_schema(), 'meta_key'),
            array_keys($registered)
        );

        foreach ($registered as $args) {
            $this->assertSame(PortfolioModule::CPT, $args['object_subtype']);
            $this->assertTrue($args['single']);
        }

        $auth = $registered['_neora_portfolio_client_name']['auth_callback'];

        $this->assertFalse($auth(false, '_neora_portfolio_client_name', 0));
        $this->assertTrue($auth(false, '_neora_portfolio_client_name', $this->postId));
    }
}
