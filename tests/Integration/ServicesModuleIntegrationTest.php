<?php

declare(strict_types=1);

namespace NeoraStudio\NeoraCore\Tests\Integration;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use NeoraStudio\NeoraCore\Modules\Services\ServicesModule;
use PHPUnit\Framework\TestCase;

class ServicesModuleIntegrationTest extends TestCase
{
    private int $postId = 42;

    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();

        // فقط sanitizer های خالص — هیچ‌وقت نیاز به override ندارن.
        Functions\stubs([
            '__'                 => fn($text) => $text,
            'wp_slash'            => fn($v) => $v,
            'sanitize_text_field' => fn($v) => $v,
            'wp_unslash'          => fn($v) => $v,
            'sanitize_key'        => fn($v) => $v,
            'get_option'          => [],
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

        // ServicesModule (step 1) + MetaHelper (step 1) = 2 بار.
        Functions\expect('wp_verify_nonce')
            ->twice()
            ->with('valid-nonce', 'neora_save_meta_' . $postId)
            ->andReturn(1);

        // ServicesModule (step 3) + MetaHelper (step 4) = 2 بار.
        Functions\expect('wp_is_post_revision')
            ->twice()
            ->with($postId)
            ->andReturn(false);

        // ServicesModule (step 4) + MetaHelper (step 2) = 2 بار.
        Functions\expect('current_user_can')
            ->twice()
            ->with('edit_post', $postId)
            ->andReturn(true);

        // هر دو فیلد مقدار دارن → update صدا می‌شه، نه delete.
        Functions\expect('update_post_meta')
            ->atLeast()->once()
            ->andReturn(true);

        $_POST['_neora_meta_nonce']   = 'valid-nonce';
        $_POST['neora_service_code']  = 'SVC-001';
        $_POST['neora_service_price'] = '150000';

        (new ServicesModule())->save_meta($postId);

         // Mockery expectations verified in tearDown count as assertions.
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

        // هیچ تابع دیگه‌ای صدا نمی‌شه — نیازی به تعریف ندارن.

        (new ServicesModule())->save_meta($postId);
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

        (new ServicesModule())->save_meta($postId);
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

        // step 3: revision check → true → return. capability و MetaHelper هرگز صدا نمی‌شن.
        Functions\expect('wp_is_post_revision')
            ->once()
            ->with($postId)
            ->andReturn(99);

        (new ServicesModule())->save_meta($postId);
    }

    // ─── Guard: Capability ───────────────────────────────────────────────────

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

        (new ServicesModule())->save_meta($postId);
    }
}
