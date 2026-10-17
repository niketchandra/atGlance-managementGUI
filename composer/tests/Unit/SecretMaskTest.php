<?php

namespace Tests\Unit;

use App\Support\SecretMask;
use PHPUnit\Framework\TestCase;

class SecretMaskTest extends TestCase
{
    /**
     * Test masking OpenAI API keys (sk-* format)
     */
    public function test_masks_openai_api_key(): void
    {
        $key = 'sk-proj-abc123def456ghi789jkl';
        $masked = SecretMask::maskOpenAI($key);

        $this->assertStringContainsString('sk-', $masked);
        $this->assertStringContainsString('jkl', $masked);
        $this->assertStringNotContainsString('abc123', $masked);
        $this->assertStringNotContainsString('def456', $masked);
    }

    /**
     * Test masking Anthropic/Claude API keys (sk-ant-* format)
     */
    public function test_masks_anthropic_api_key(): void
    {
        $key = 'sk-ant-v1-abc123def456ghi789jklmnop';
        $masked = SecretMask::maskAnthropic($key);

        $this->assertStringContainsString('sk-ant', $masked);
        $this->assertStringContainsString('mnop', $masked);
        $this->assertStringNotContainsString('abc123', $masked);
        $this->assertStringNotContainsString('def456', $masked);
    }

    /**
     * Test masking Google Gemini API keys (typically 39+ character alphanumeric)
     */
    public function test_masks_google_gemini_api_key(): void
    {
        $key = 'AIzaSyDEfGhIjKlMnOpQrStUvWxYzAbCdEfGhIj';
        $masked = SecretMask::maskGemini($key);

        $this->assertStringContainsString('AIzaSy', $masked);
        $this->assertStringContainsString('GhIj', $masked);
        $this->assertStringNotContainsString('DEfGhIj', $masked);
        $this->assertStringNotContainsString('KlMnOpQr', $masked);
    }

    /**
     * Test masking Ollama URLs with auth
     */
    public function test_masks_ollama_url(): void
    {
        $url = 'http://host.docker.internal:11434/v1';
        $masked = SecretMask::maskUrl($url);

        $this->assertStringContainsString('host.docker.internal', $masked);
        $this->assertStringContainsString('11434', $masked);
        // URL should not be heavily masked since it's not a secret per se
        $this->assertStringContainsString('http://', $masked);
    }

    /**
     * Test masking generic API keys
     */
    public function test_masks_generic_api_key(): void
    {
        $key = 'generic-secret-key-0123456789abcdef';
        $masked = SecretMask::maskGeneric($key);

        $this->assertStringContainsString('•••', $masked);
        $this->assertStringContainsString('def', $masked);
        $this->assertStringNotContainsString('generic-secret', $masked);
        $this->assertStringNotContainsString('0123456789', $masked);
    }

    /**
     * Test that short keys are fully masked
     */
    public function test_masks_short_keys_completely(): void
    {
        $shortKey = 'secret';
        $masked = SecretMask::maskGeneric($shortKey);

        // Keys shorter than 12 chars should not show the last 4
        $this->assertStringContainsString('••', $masked);
    }

    /**
     * Test masking empty keys
     */
    public function test_masks_empty_key(): void
    {
        $masked = SecretMask::maskGeneric('');
        $this->assertSame('', $masked);
    }

    /**
     * Test the existing key() method for system keys
     */
    public function test_masks_system_key_with_existing_method(): void
    {
        $key = 'system-key-1234567890';
        $masked = SecretMask::key($key);

        $this->assertStringContainsString('••', $masked);
        $this->assertStringContainsString('7890', $masked);
        $this->assertStringNotContainsString('system', $masked);
    }

    /**
     * Test auto-detection and masking of any secret type
     */
    public function test_auto_detects_and_masks_openai_key(): void
    {
        $key = 'sk-proj-abc123def456ghi789jkl';
        $masked = SecretMask::mask($key);

        $this->assertStringContainsString('sk-', $masked);
        $this->assertStringContainsString('jkl', $masked);
        $this->assertStringNotContainsString('abc123', $masked);
    }

    /**
     * Test auto-detection for Anthropic keys
     */
    public function test_auto_detects_and_masks_anthropic_key(): void
    {
        $key = 'sk-ant-v1-abc123def456ghi789jklmnop';
        $masked = SecretMask::mask($key);

        $this->assertStringContainsString('sk-ant', $masked);
        $this->assertStringContainsString('mnop', $masked);
        $this->assertStringNotContainsString('abc123', $masked);
    }

    /**
     * Test auto-detection for Gemini keys
     */
    public function test_auto_detects_and_masks_gemini_key(): void
    {
        $key = 'AIzaSyDEfGhIjKlMnOpQrStUvWxYzAbCdEfGhIj';
        $masked = SecretMask::mask($key);

        $this->assertStringContainsString('AIzaSy', $masked);
        $this->assertStringContainsString('GhIj', $masked);
        $this->assertStringNotContainsString('DEfGhIj', $masked);
    }

    /**
     * Test masking text containing embedded secrets
     */
    public function test_masks_secret_in_error_message(): void
    {
        $message = 'Error: Invalid API key sk-proj-abc123def456ghi789jkl provided';
        $masked = SecretMask::maskInText($message);

        $this->assertStringContainsString('Error:', $masked);
        $this->assertStringContainsString('Invalid API key', $masked);
        $this->assertStringNotContainsString('abc123def456', $masked);
        $this->assertStringNotContainsString('sk-proj-abc123', $masked);
        // Should show the masked version with last 4
        $this->assertStringContainsString('sk-', $masked);
    }

    /**
     * Test that multiple secrets in a message are all masked
     */
    public function test_masks_multiple_secrets_in_text(): void
    {
        $message = 'Using sk-proj-abc123 and sk-ant-def456ghi789 together';
        $masked = SecretMask::maskInText($message);

        $this->assertStringContainsString('Using', $masked);
        $this->assertStringContainsString('and', $masked);
        $this->assertStringContainsString('together', $masked);
        $this->assertStringNotContainsString('abc123', $masked);
        $this->assertStringNotContainsString('def456', $masked);
    }

    /**
     * Test that plaintext keys are not in masked output
     */
    public function test_plaintext_never_in_masked_output(): void
    {
        $plainKey = 'sk-proj-secretkeywithspecialchars123456';
        $masked = SecretMask::mask($plainKey);

        // Plaintext should not be present
        $this->assertStringNotContainsString($plainKey, $masked);
        $this->assertStringNotContainsString('secretkey', $masked);
    }
}
