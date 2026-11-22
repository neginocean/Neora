<?php
declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Tests\Core;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use NeoraStudio\NeoraCore\Core\MetaHelper;
use PHPUnit\Framework\TestCase;

class MetaHelperTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        Functions\stubs([
            'wp_slash' => fn( $v ) => $v,
            'wp_unslash' => fn( $v ) => $v,
        ]);
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    // ─── helpers ──────────────────────────────────────────────────────────────

    private function validField(array $overrides = []): array
    {
        return array_merge([
            'meta_key'    => 'service_title',
            'post_key'    => 'service_title',
            'type'        => 'string',
            // باید واقعاً callable باشه چون assert_schema چک می‌کنه
            'sanitize_cb' => 'trim',
        ], $overrides);
    }

    /**
     * guard های save_fields رو pass می‌کنه.
     * توجه: sanitize_text_field(wp_unslash()) قبل از wp_verify_nonce صدا زده میشه.
     */
    private function passSaveGuards(int $post_id = 1, string $raw_nonce = 'valid_nonce'): void
    {
        Functions\expect('sanitize_text_field')
            ->once()
            ->with($raw_nonce)
            ->andReturn($raw_nonce);

        Functions\expect('wp_verify_nonce')
            ->once()
            ->with($raw_nonce, 'neora_save_meta_' . $post_id)
            ->andReturn(1);

        Functions\expect('current_user_can')
            ->once()
            ->with('edit_post', $post_id)
            ->andReturn(true);

        Functions\expect('wp_is_post_revision')
            ->once()
            ->with($post_id)
            ->andReturn(false);
    }

    // ─── register_fields ──────────────────────────────────────────────────────

    /** @test */
    public function register_fields_calls_register_meta_for_each_valid_field(): void
    {
        Functions\expect('current_user_can')->andReturn(true);
        Functions\expect('register_meta')
            ->twice()
            ->with('post', \Mockery::type('string'), \Mockery::type('array'));

        MetaHelper::register_fields('service', [
            $this->validField(['meta_key' => 'service_title', 'post_key' => 'service_title']),
            $this->validField(['meta_key' => 'service_desc',  'post_key' => 'service_desc']),
        ]);
    }

    /** @test */
    public function register_fields_skips_field_missing_required_key(): void
    {
        Functions\expect('current_user_can')->andReturn(true);
        Functions\expect('register_meta')->once(); // فقط فیلد معتبر
        Functions\expect('error_log')->once();

        MetaHelper::register_fields('service', [
            $this->validField(),
            ['meta_key' => 'broken'], // post_key, type, sanitize_cb ندارد
        ]);
    }

    /** @test */
    public function register_fields_skips_field_with_invalid_type(): void
    {
        // 'float' در لیست تایپ‌های مجاز نیست → InvalidArgumentException → log و ادامه
        Functions\expect('current_user_can')->andReturn(true);
        Functions\expect('register_meta')->never();
        Functions\expect('error_log')->once();

        MetaHelper::register_fields('service', [
            $this->validField(['type' => 'float']),
        ]);
    }

    /** @test */
    public function register_fields_skips_field_with_uncallable_sanitize_cb(): void
    {
        Functions\expect('current_user_can')->andReturn(true);
        Functions\expect('register_meta')->never();
        Functions\expect('error_log')->once();

        MetaHelper::register_fields('service', [
            $this->validField(['sanitize_cb' => 'this_function_does_not_exist_xyz']),
        ]);
    }

    // ─── save_fields — security guards ────────────────────────────────────────

    /** @test */
    public function save_fields_returns_early_when_nonce_key_is_missing(): void
    {
        Functions\expect('wp_verify_nonce')->never();
        Functions\expect('update_post_meta')->never();

        MetaHelper::save_fields(1, [$this->validField()], []);
    }

    /** @test */
    public function save_fields_returns_early_when_nonce_is_invalid(): void
    {
        Functions\expect('sanitize_text_field')
            ->once()
            ->with('bad_nonce')
            ->andReturn('bad_nonce');

        Functions\expect('wp_verify_nonce')
            ->once()
            ->with('bad_nonce', 'neora_save_meta_1')
            ->andReturn(false);

        Functions\expect('update_post_meta')->never();

        MetaHelper::save_fields(1, [$this->validField()], [
            '_neora_meta_nonce' => 'bad_nonce',
        ]);
    }

    /** @test */
    public function save_fields_returns_early_when_user_lacks_capability(): void
    {
        Functions\expect('sanitize_text_field')->once()->andReturn('valid_nonce');
        Functions\expect('wp_verify_nonce')->once()->andReturn(1);
        Functions\expect('wp_is_post_revision')->once()->with(1)->andReturn(false);
        Functions\expect('current_user_can')
            ->once()
            ->with('edit_post', 1)
            ->andReturn(false);

        Functions\expect('update_post_meta')->never();

        MetaHelper::save_fields(1, [$this->validField()], [
            '_neora_meta_nonce' => 'valid_nonce',
        ]);
    }

    /** @test */
    public function save_fields_returns_early_for_post_revision(): void
    {
        Functions\expect('sanitize_text_field')->once()->andReturn('valid_nonce');
        Functions\expect('wp_verify_nonce')->once()->andReturn(1);
        Functions\expect('wp_is_post_revision')->once()->with(1)->andReturn(true);
        Functions\expect('current_user_can')->never();

        Functions\expect('update_post_meta')->never();

        MetaHelper::save_fields(1, [$this->validField()], [
            '_neora_meta_nonce' => 'valid_nonce',
        ]);
    }

    // ─── save_fields — storage logic ──────────────────────────────────────────

    /** @test */
    public function save_fields_calls_update_post_meta_for_non_empty_value(): void
    {
        $this->passSaveGuards(1);

        Functions\expect('update_post_meta')
            ->once()
            ->with(1, 'service_title', 'My Service');

        Functions\expect('delete_post_meta')->never();

        // sanitize_cb = 'trim' — واقعاً فراخوانده میشه، نیازی به mock نیست
        MetaHelper::save_fields(1, [$this->validField()], [
            '_neora_meta_nonce' => 'valid_nonce',
            'service_title'     => 'My Service',
        ]);
    }

    /** @test */
    public function save_fields_calls_delete_post_meta_for_empty_string(): void
    {
        $this->passSaveGuards(1);

        Functions\expect('delete_post_meta')
            ->once()
            ->with(1, 'service_title');

        Functions\expect('update_post_meta')->never();

        MetaHelper::save_fields(1, [$this->validField()], [
            '_neora_meta_nonce' => 'valid_nonce',
            'service_title'     => '   ', // trim → '' → delete
        ]);
    }

    /** @test */
    public function save_fields_calls_delete_post_meta_when_post_key_absent(): void
    {
        $this->passSaveGuards(1);

        Functions\expect('delete_post_meta')
            ->once()
            ->with(1, 'service_title');

        MetaHelper::save_fields(1, [$this->validField()], [
            '_neora_meta_nonce' => 'valid_nonce',
            // 'service_title' عمداً نیست → raw = '' → trim → '' → delete
        ]);
    }

    /** @test */
    public function save_fields_processes_multiple_fields_independently(): void
    {
        $this->passSaveGuards(5);

        Functions\expect('update_post_meta')
            ->once()->with(5, 'service_title', 'Title');

        Functions\expect('delete_post_meta')
            ->once()->with(5, 'service_desc');

        MetaHelper::save_fields(5, [
            $this->validField(['meta_key' => 'service_title', 'post_key' => 'service_title']),
            $this->validField(['meta_key' => 'service_desc',  'post_key' => 'service_desc']),
        ], [
            '_neora_meta_nonce' => 'valid_nonce',
            'service_title'     => 'Title',
            'service_desc'      => '',
        ]);
    }

    /** @test */
    public function save_fields_skips_invalid_schema_field_during_save(): void
    {
        $this->passSaveGuards(1);

        Functions\expect('error_log')->once();
        Functions\expect('update_post_meta')->never();
        Functions\expect('delete_post_meta')->never();

        MetaHelper::save_fields(1, [
            ['meta_key' => 'broken'], // schema ناقص
        ], [
            '_neora_meta_nonce' => 'valid_nonce',
        ]);
    }

    /** @test */
    public function save_fields_continues_with_remaining_fields_after_invalid_schema_field(): void
    {
        $this->passSaveGuards(1);

        Functions\expect('error_log')->once();
        Functions\expect('update_post_meta')
            ->once()->with(1, 'service_title', 'Title');
        Functions\expect('delete_post_meta')->never();

        MetaHelper::save_fields(1, [
            ['meta_key' => 'broken'],
            $this->validField(),
        ], [
            '_neora_meta_nonce' => 'valid_nonce',
            'service_title'     => 'Title',
        ]);
    }
}
