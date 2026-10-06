# SSO Login

Users can sign in with an identity provider instead of a password. SSO is a plugin:
the super admin enables **SSO Login** in **Site Setting > Plugins**, then sets up providers on
the **SSO Configuration** tab, which only appears while the plugin is enabled.

## Providers

| Provider | Protocol | Fields besides Client ID / Secret | Official setup guide |
|---|---|---|---|
| Google | OpenID Connect | Workspace domain (optional, checked against the `hd` claim) | https://developers.google.com/identity/openid-connect/openid-connect |
| Microsoft Entra ID | OpenID Connect | Directory (tenant) ID, required; `common` / `organizations` / `consumers` are refused | https://learn.microsoft.com/en-us/entra/identity-platform/quickstart-register-app |
| GitHub | OAuth app | GitHub Enterprise Server URL (optional) | https://docs.github.com/en/apps/oauth-apps/building-oauth-apps/creating-an-oauth-app |
| GitLab | OpenID Connect | GitLab URL (gitlab.com or self-managed) | https://docs.gitlab.com/integration/oauth_provider/ |
| Okta | OpenID Connect | Okta domain; authorization server ID (blank = org server) | https://developer.okta.com/docs/guides/sign-into-web-app-redirect/node-express/main/ |
| Auth0 | OpenID Connect | Domain | https://auth0.com/docs/get-started/applications/application-settings |
| authentik | OpenID Connect | OpenID Configuration Issuer (`https://<authentik>/application/o/<slug>/`) | https://docs.goauthentik.io/add-secure-apps/providers/oauth2/ |
| OpenID Connect (generic) | OpenID Connect | Issuer URL, button name | Keycloak, Zitadel, Ping, JumpCloud, ... |

The SSO tab shows each provider's setup steps and its callback URL:
`<console URL>/auth/sso/<provider>/callback`. Register exactly that URL with the provider.
Providers need an `https://` callback, so set the console up on its HTTPS domain first
(Custom Domain & HTTPS plugin). A provider only gets a login button once all its fields are filled in.

Catalog (fields, steps, docs links): `config/sso.php`. Saved values: `admin_settings`
`sso_provider_config` (JSON) and `sso_provider_client_secrets` (encrypted JSON); helper
`App\Support\SsoProviders`. Plugin state: `App\Support\SsoSettings`.

## How sign-in works

- **OpenID Connect providers** (`App\Services\Sso\OidcClient`):
  - Endpoints come from `<issuer>/.well-known/openid-configuration` (cached 1 hour); every endpoint must be HTTPS.
  - Authorization code flow with `state`, `nonce` and PKCE (S256); scope `openid email profile`.
  - The ID token's `iss`, `aud`/`azp`, `exp`/`iat`, `nonce` and `sub` are checked. Its signature is not: the token comes straight from the token endpoint over TLS (OpenID Connect Core 1.0, 3.1.3.7 step 6).
  - The email must have `email_verified` = true. Okta, Auth0, authentik and generic OIDC have an "Accept email addresses not marked verified" option; authentik sends `false` by default since 2025.10.
  - Microsoft Entra ID has no `email_verified` claim. It is limited to the configured tenant instead: the token's issuer must be that tenant's.
- **GitHub** (`App\Services\Sso\GithubClient`):
  - OAuth with `state` and PKCE; scope `read:user user:email`.
  - Uses the verified primary email, else another verified email. Never an unverified one.
- **Both:**
  - The callback must carry the `state` this browser started with, within 10 minutes.
  - Users are matched by email. A new email creates a user (role User) only while a licence is active.
  - Failures are shown on the login page and logged as `auth.login_failed`.

## Upgrading from earlier versions

- Only GitHub worked before; the other providers redirected to a typed-in URL and had no callback.
- The old `azure-ad` provider is now `microsoft` (Microsoft Entra ID). Its saved client ID and tenant ID are read automatically.
- A saved GitHub URL on a host other than github.com becomes the GitHub Enterprise Server URL.
- Open the SSO tab and save once to move everything to the new settings.
