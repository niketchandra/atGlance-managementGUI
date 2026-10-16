<?php

namespace Tests\Feature;

use App\Services\AiClient;
use App\Support\SecretMask;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Integration tests for AiClient secret masking.
 * Ensures that no plaintext API keys leak in error messages.
 */
class AiClientSecretMaskingTest extends TestCase
{
    private const OPENAI_KEY = 'sk-proj-abc123def456ghi789jklmnop';
    private const ANTHROPIC_KEY = 'sk-ant-v1-abc123def456ghi789jklmnop';
    private const GEMINI_KEY = 'AIzaSyDEfGhIjKlMnOpQrStUvWxYzAbCdEfGhIj';
    private const OLLAMA_URL = 'http://host.docker.internal:11434/v1';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    /**
     * Test that connection errors redact the API key.
     */
    public function test_connection_error_redacts_openai_key(): void
    {
        $client = app(AiClient::class);

        Http::fake([
            '*' => Http::response([
                'error' => 'Server error: ' . self::OPENAI_KEY . ' is invalid',
            ], 500),
        ]);

        $connection = [
            'provider' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4',
            'api_key' => self::OPENAI_KEY,
        ];

        try {
            $client->complete($connection, 'test');
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            $this->assertStringNotContainsString(self::OPENAI_KEY, $message);
            $this->assertStringNotContainsString('abc123def456', $message);
            // Should show masked format
            $this->assertStringContainsString('sk-', $message);
        }
    }

    /**
     * Test that API error responses redact the Anthropic key.
     */
    public function test_api_error_redacts_anthropic_key(): void
    {
        $client = app(AiClient::class);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'error' => ['message' => 'Invalid API key: ' . self::ANTHROPIC_KEY],
            ], 401),
        ]);

        $connection = [
            'provider' => 'anthropic',
            'base_url' => 'https://api.anthropic.com',
            'model' => 'claude-opus-5',
            'api_key' => self::ANTHROPIC_KEY,
        ];

        try {
            $client->complete($connection, 'test');
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            $this->assertStringNotContainsString(self::ANTHROPIC_KEY, $message);
            $this->assertStringNotContainsString('abc123def456', $message);
            // Should show masked format
            $this->assertStringContainsString('sk-ant', $message);
            $this->assertStringContainsString('HTTP 401', $message);
        }
    }

    /**
     * Test that Gemini key is properly redacted.
     */
    public function test_api_error_redacts_gemini_key(): void
    {
        $client = app(AiClient::class);

        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'error' => ['message' => 'Invalid key: ' . self::GEMINI_KEY],
            ], 401),
        ]);

        $connection = [
            'provider' => 'gemini',
            'base_url' => 'https://generativelanguage.googleapis.com/v1beta/openai',
            'model' => 'gemini-pro',
            'api_key' => self::GEMINI_KEY,
        ];

        try {
            $client->complete($connection, 'test');
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            $this->assertStringNotContainsString(self::GEMINI_KEY, $message);
            $this->assertStringNotContainsString('DEfGhIjKlMn', $message);
            // Should show masked format
            $this->assertStringContainsString('AIzaSy', $message);
        }
    }

    /**
     * Test that test() method returns redacted error messages.
     */
    public function test_test_method_redacts_errors(): void
    {
        $client = app(AiClient::class);

        Http::fake([
            '*' => Http::response([
                'error' => ['message' => 'Bad API key: ' . self::OPENAI_KEY],
            ], 401),
        ]);

        $connection = [
            'provider' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4',
            'api_key' => self::OPENAI_KEY,
        ];

        $result = $client->test($connection);

        $this->assertFalse($result['ok']);
        $this->assertStringNotContainsString(self::OPENAI_KEY, $result['message']);
        $this->assertStringContainsString('sk-', $result['message']);
    }

    /**
     * Test that models() method redacts errors.
     */
    public function test_models_method_redacts_errors(): void
    {
        $client = app(AiClient::class);

        Http::fake([
            '*' => Http::response([
                'error' => ['message' => 'Bad API key: ' . self::ANTHROPIC_KEY],
            ], 401),
        ]);

        $connection = [
            'provider' => 'anthropic',
            'base_url' => 'https://api.anthropic.com',
            'model' => 'claude-opus-5',
            'api_key' => self::ANTHROPIC_KEY,
        ];

        try {
            $client->models($connection);
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $this->assertStringNotContainsString(self::ANTHROPIC_KEY, $e->getMessage());
            $this->assertStringContainsString('sk-ant', $e->getMessage());
        }
    }

    /**
     * Test that HTML error responses are sanitized and key is redacted.
     */
    public function test_html_error_response_redacts_key(): void
    {
        $client = app(AiClient::class);

        Http::fake([
            '*' => Http::response(
                '<html><body><h1>401 Unauthorized</h1><p>Bad API key: ' . self::OPENAI_KEY . '</p></body></html>',
                401,
                ['Content-Type' => 'text/html']
            ),
        ]);

        $connection = [
            'provider' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4',
            'api_key' => self::OPENAI_KEY,
        ];

        try {
            $client->complete($connection, 'test');
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            $this->assertStringNotContainsString(self::OPENAI_KEY, $message);
            // HTML tags should be stripped
            $this->assertStringNotContainsString('<html>', $message);
        }
    }

    /**
     * Test that response bodies with embedded keys are properly redacted.
     */
    public function test_json_error_with_key_in_message_is_redacted(): void
    {
        $client = app(AiClient::class);

        $errorMessage = 'The API key sk-proj-abc123def456ghi789jklmnop is not valid for your account';

        Http::fake([
            '*' => Http::response([
                'error' => $errorMessage,
            ], 401),
        ]);

        $connection = [
            'provider' => 'openai',
            'base_url' => 'https://api.openai.com/v1',
            'model' => 'gpt-4',
            'api_key' => self::OPENAI_KEY,
        ];

        try {
            $client->complete($connection, 'test');
            $this->fail('Expected RuntimeException');
        } catch (\RuntimeException $e) {
            $message = $e->getMessage();
            // Both the key in the connection and any in the error message should be redacted
            $this->assertStringNotContainsString(self::OPENAI_KEY, $message);
            $this->assertStringNotContainsString('abc123def456', $message);
            $this->assertStringContainsString('sk-', $message);
        }
    }

    /**
     * Test SecretMask integration with multiple key types in one message.
     */
    public function test_multiple_keys_in_message_are_redacted(): void
    {
        $text = 'Failed: tried ' . self::OPENAI_KEY . ' then ' . self::ANTHROPIC_KEY;
        $masked = SecretMask::maskInText($text);

        $this->assertStringNotContainsString(self::OPENAI_KEY, $masked);
        $this->assertStringNotContainsString(self::ANTHROPIC_KEY, $masked);
        $this->assertStringContainsString('Failed:', $masked);
        $this->assertStringContainsString('then', $masked);
    }
}
