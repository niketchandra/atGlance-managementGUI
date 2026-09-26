# AI Connect

The **AI Connect** tab (`/admin/settings?tab=ai-connect`) connects one AI
provider for the whole organization. Nothing in AtGlance calls it yet. The
first feature that uses it is a follow-up to issue #11.

## Who can do what

| Role | Access |
|---|---|
| Super admin (`rbac_id` 100) | Edits, tests, loads models |
| Admin (`rbac_id` 101) | Sees the settings read-only; test and model requests return 403 |

## Stored settings

All of these are in `admin_settings`, group `ai`:

| Key | Value |
|---|---|
| `ai_enabled` | `true` / `false` |
| `ai_provider` | A key of `App\Support\AiSettings::PROVIDERS` |
| `ai_base_url` | Blank means the provider default |
| `ai_model` | Model name, deployment name, or OpenClaw agent target |
| `ai_api_key` | Encrypted with `APP_KEY` |
| `ai_last_test` | JSON with the result of the last "Test connection" |

**The API key:**
- It is never rendered in the page.
- `ActivityLogger` strips it from logged payloads, and the `/admin/settings/ai*` routes are not logged anyway.
- Error messages from the provider have the key replaced with `***`.

**Saved key reuse:**
- If the key field is left blank, the saved key is used, but only when the provider and the resolved base URL have not changed. This stops a saved key being sent to a new host.
- Changing the provider or base URL without entering a key clears the saved key.

> The key is encrypted with `APP_KEY`. If the Docker image regenerates
> `APP_KEY` on rebuild (see issue #3 follow-ups), you must enter the key again.

## Routes

| Method | Path | Name | Purpose |
|---|---|---|---|
| POST | `/admin/settings/ai` | `admin.settings.ai` | Save |
| POST | `/admin/settings/ai/test` | `admin.settings.ai.test` | Test the form values without saving; returns JSON |
| POST | `/admin/settings/ai/models` | `admin.settings.ai.models` | List the models the provider reports; returns JSON |

## How requests are sent

`App\Services\AiClient` sends every request with Laravel's HTTP client:
- 30 s timeout and 5 s connect timeout.
- No provider SDKs.

| Protocol | Providers | Chat call | Model list |
|---|---|---|---|
| Anthropic Messages | Anthropic Claude | `POST {base}/v1/messages`, headers `x-api-key` and `anthropic-version: 2023-06-01` | `GET {base}/v1/models` |
| OpenAI Chat Completions | All others | `POST {base}/chat/completions`, `Authorization: Bearer <key>` (Azure: `api-key: <key>`) | `GET {base}/models` |

"Test connection" does two things:
1. It sends `Reply with the single word OK.` with `max_tokens: 32`.
2. It shows the latency and the reply.

Any HTTP 2xx response counts as success. A reasoning model can return an empty reply within 32 tokens and still pass.

For new features, use `AiClient::complete($connection, $prompt, $maxTokens, $system)` with `AiSettings::connection()`.

## Providers and setup

The tab shows these steps for the selected provider. The source of truth is
`AiSettings::PROVIDERS`.

| Provider | Default base URL | API key | Model |
|---|---|---|---|
| Anthropic Claude | `https://api.anthropic.com` | Required | e.g. `claude-opus-5`, `claude-sonnet-5`, `claude-haiku-4-5` |
| OpenAI | `https://api.openai.com/v1` | Required | Any chat model the project can use |
| Azure OpenAI / Microsoft Foundry | none: `https://<resource>.openai.azure.com/openai/v1` | Required (`api-key`) | Deployment name |
| Google Gemini | `https://generativelanguage.googleapis.com/v1beta/openai` | Required | A `gemini-*` model |
| Mistral AI | `https://api.mistral.ai/v1` | Required | e.g. `mistral-small-latest` |
| Groq | `https://api.groq.com/openai/v1` | Required | Any Groq model |
| DeepSeek | `https://api.deepseek.com/v1` | Required | e.g. `deepseek-chat` |
| xAI Grok | `https://api.x.ai/v1` | Required | A `grok-*` model |
| OpenRouter | `https://openrouter.ai/api/v1` | Required | `vendor/model` |
| Ollama | `http://host.docker.internal:11434/v1` | Optional (Ollama Cloud only) | A pulled model, e.g. `llama3.2` |
| LM Studio | `http://host.docker.internal:1234/v1` | None | The loaded model identifier |
| OpenClaw gateway | `http://host.docker.internal:18789/v1` | Gateway token | `openclaw` or `openclaw/<agentId>` |
| Custom OpenAI-compatible | none | Optional | As the server exposes it |

### Anthropic Claude
1. Sign in at https://console.anthropic.com and add billing credits.
2. Create a key in **Settings > API keys**.
3. Paste the key and choose a model.

### OpenAI
1. Add a payment method at https://platform.openai.com.
2. Create a project key at https://platform.openai.com/api-keys.
3. Make sure the project can use the model you enter.

### Azure OpenAI / Microsoft Foundry
1. Create the resource and deploy a model.
2. Copy the endpoint and a key from **Keys and Endpoint**.
3. Set the base URL to `https://<resource>.openai.azure.com/openai/v1`.
4. Set the model to the **deployment name**.

### Google Gemini
1. Create a key at https://aistudio.google.com/apikey.
2. Keep the default base URL.

### Mistral, Groq, DeepSeek, xAI, OpenRouter
1. Create a key in the provider's console.
2. Keep the default base URL.
3. For OpenRouter, write the model as `vendor/model`.

### Ollama (self-hosted)
1. Install Ollama and pull a model: `ollama pull llama3.2`.
2. Make Ollama listen beyond localhost:
   ```bash
   sudo systemctl edit ollama
   # [Service]
   # Environment="OLLAMA_HOST=0.0.0.0:11434"
   sudo systemctl restart ollama
   ```
3. Use a base URL for where Ollama runs:
   - **Ollama on the Docker host:** keep the default `http://host.docker.internal:11434/v1`. `docker-compose.yml` maps `host.docker.internal` to the host gateway for `api`, `queue-worker` and `scheduler`, so this also works on Linux.
   - **Ollama on another machine:** use `http://<ip>:11434/v1`.
4. Leave the key blank.
5. **Ollama Cloud:** use `https://ollama.com/v1` and an ollama.com API key.
6. Ollama has no authentication. Firewall port 11434 so only trusted hosts can reach it.

### LM Studio (self-hosted)
1. Load a model.
2. In the **Developer** tab, start the server.
3. Turn on **Serve on Local Network**.
4. Keep the default base URL. No key is needed.

### OpenClaw gateway
1. Turn on the OpenAI-compatible endpoint in the gateway config. It is off by default:
   ```json5
   { gateway: { http: { endpoints: { chatCompletions: { enabled: true } } } } }
   ```
2. Set the API key to the gateway token (`gateway.auth.token` or `OPENCLAW_GATEWAY_TOKEN`). In password mode, use the gateway password.
3. Set the model to `openclaw` for the default agent, or `openclaw/<agentId>` for a specific agent.
4. **Security:** the gateway token is full operator access to that OpenClaw instance. Keep the gateway on loopback, a tailnet or a private network only.

### Custom OpenAI-compatible
Use this for any server with `POST /chat/completions`, such as vLLM, LocalAI, a LiteLLM proxy or text-generation-webui.
1. Enter its `/v1` base URL.
2. Enter the model name.
3. Enter a key only if the server needs one.

## Out of scope

- **Amazon Bedrock and Google Vertex AI.** They need AWS SigV4 or Google OAuth. For now, use OpenRouter, or run a LiteLLM proxy and connect it as a Custom provider.
- **Requests to internal addresses (accepted risk).** The base URL lets the super admin make the server call internal addresses. Self-hosted models need this, and the super admin is trusted.

## Tests

`tests/Feature/AiConnectTest.php` (10 tests) covers:
- saving, and the encrypted key
- blank-key reuse, including no reuse across a provider or URL change
- validation
- admin read-only access
- Anthropic and OpenAI-compatible request shapes (OpenClaw, Azure)
- redacted errors
- Ollama model listing
