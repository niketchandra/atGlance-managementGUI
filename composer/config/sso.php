<?php

/*
 * SSO providers shown on Site Setting > SSO Configuration and on the login page.
 *
 * type: 'oidc' uses the shared OpenID Connect flow (App\Services\Sso\OidcClient);
 *       'github' uses GitHub's OAuth flow (App\Services\Sso\GithubClient).
 * fields: provider-specific settings besides Client ID and Client Secret, which every provider has.
 * steps: setup on the provider's side, from its official documentation (docs).
 * Placeholders in steps: :callback is this console's callback URL for the provider.
 */

return [
    // Old provider keys still found in saved settings.
    'aliases' => [
        'azure-ad' => 'microsoft',
    ],

    'providers' => [
        'google' => [
            'label' => 'Google',
            'icon' => 'fab fa-google',
            'type' => 'oidc',
            'docs' => 'https://developers.google.com/identity/openid-connect/openid-connect',
            'client_id_label' => 'Client ID',
            'client_secret_label' => 'Client secret',
            'fields' => [
                'allowed_domain' => [
                    'label' => 'Google Workspace domain (optional)',
                    'type' => 'text',
                    'placeholder' => 'example.com',
                    'help' => 'Only accounts from this Workspace domain can sign in (checked against the hd claim). Leave blank to allow any Google account.',
                ],
            ],
            'steps' => [
                'In Google Cloud Console, open Google Auth Platform (APIs & Services) and set up the OAuth consent screen (Branding, Audience). Choose Internal to allow only your Workspace users.',
                'Open Clients and create a client with application type Web application.',
                'Under Authorized redirect URIs add exactly: :callback',
                'Copy the Client ID and Client secret into the fields below.',
            ],
        ],

        'microsoft' => [
            'label' => 'Microsoft Entra ID',
            'icon' => 'fab fa-microsoft',
            'type' => 'oidc',
            'docs' => 'https://learn.microsoft.com/en-us/entra/identity-platform/quickstart-register-app',
            'client_id_label' => 'Application (client) ID',
            'client_secret_label' => 'Client secret value',
            'fields' => [
                'tenant_id' => [
                    'label' => 'Directory (tenant) ID',
                    'type' => 'text',
                    'required' => true,
                    'placeholder' => '00000000-0000-0000-0000-000000000000 or contoso.onmicrosoft.com',
                    'help' => 'Only users of this tenant can sign in. common, organizations and consumers are not allowed: sign-in matches accounts by email, which other tenants control.',
                ],
            ],
            'steps' => [
                'In the Microsoft Entra admin center, go to Entra ID > App registrations > New registration.',
                'Supported account types: Single tenant only.',
                'Redirect URI: platform Web, value exactly: :callback',
                'On the Overview page, copy the Application (client) ID and the Directory (tenant) ID.',
                'Go to Certificates & secrets > Client secrets > New client secret and copy the secret Value (not the Secret ID). Note its expiry date: sign-in stops when it expires.',
            ],
        ],

        'github' => [
            'label' => 'GitHub',
            'icon' => 'fab fa-github',
            'type' => 'github',
            'docs' => 'https://docs.github.com/en/apps/oauth-apps/building-oauth-apps/creating-an-oauth-app',
            'client_id_label' => 'Client ID',
            'client_secret_label' => 'Client secret',
            'fields' => [
                'enterprise_url' => [
                    'label' => 'GitHub Enterprise Server URL (optional)',
                    'type' => 'url',
                    'placeholder' => 'https://github.example.com',
                    'help' => 'Leave blank for github.com.',
                ],
            ],
            'steps' => [
                'On GitHub, go to your profile picture > Settings > Developer settings > OAuth Apps > New OAuth App (for an organization: the organization\'s Settings > Developer settings).',
                'Homepage URL: this console\'s address. Authorization callback URL exactly: :callback',
                'Select Register application, then Generate a new client secret.',
                'Copy the Client ID and the client secret into the fields below. Users sign in with their verified primary email address.',
            ],
        ],

        'gitlab' => [
            'label' => 'GitLab',
            'icon' => 'fab fa-gitlab',
            'type' => 'oidc',
            'docs' => 'https://docs.gitlab.com/integration/oauth_provider/',
            'client_id_label' => 'Application ID',
            'client_secret_label' => 'Secret',
            'fields' => [
                'base_url' => [
                    'label' => 'GitLab URL',
                    'type' => 'url',
                    'required' => true,
                    'default' => 'https://gitlab.com',
                    'placeholder' => 'https://gitlab.com',
                    'help' => 'https://gitlab.com, or your self-managed GitLab address.',
                ],
            ],
            'steps' => [
                'In GitLab, create an application: as a user (avatar > Edit profile > Applications), for a group (group Settings > Applications), or instance-wide (Admin > Applications).',
                'Redirect URI exactly: :callback. Keep Confidential checked.',
                'Scopes: openid, profile and email.',
                'Save, then copy the Application ID and Secret into the fields below.',
            ],
        ],

        'okta' => [
            'label' => 'Okta',
            'icon' => 'fas fa-building',
            'type' => 'oidc',
            'docs' => 'https://developer.okta.com/docs/guides/sign-into-web-app-redirect/node-express/main/',
            'client_id_label' => 'Client ID',
            'client_secret_label' => 'Client secret',
            'fields' => [
                'domain' => [
                    'label' => 'Okta domain',
                    'type' => 'url',
                    'required' => true,
                    'placeholder' => 'https://your-org.okta.com',
                ],
                'auth_server' => [
                    'label' => 'Authorization server ID (optional)',
                    'type' => 'text',
                    'placeholder' => 'default',
                    'help' => 'Leave blank to use the org authorization server (recommended for sign-in). Set it only if you use a custom authorization server.',
                ],
                'trust_email' => [
                    'label' => 'Accept email addresses not marked verified',
                    'type' => 'checkbox',
                    'help' => 'Only if every user\'s email in Okta is managed by your administrators.',
                ],
            ],
            'steps' => [
                'In the Okta Admin Console, go to Applications > Applications > Create App Integration.',
                'Sign-in method: OIDC - OpenID Connect. Application type: Web Application.',
                'Sign-in redirect URIs exactly: :callback',
                'Assignments: choose who can use the app, then Save.',
                'On the General tab, copy the Client ID and Client secret. Your Okta domain is your organization address, e.g. https://your-org.okta.com.',
            ],
        ],

        'auth0' => [
            'label' => 'Auth0',
            'icon' => 'fas fa-shield-alt',
            'type' => 'oidc',
            'docs' => 'https://auth0.com/docs/get-started/applications/application-settings',
            'client_id_label' => 'Client ID',
            'client_secret_label' => 'Client Secret',
            'fields' => [
                'domain' => [
                    'label' => 'Domain',
                    'type' => 'text',
                    'required' => true,
                    'placeholder' => 'your-tenant.us.auth0.com',
                    'help' => 'The Domain shown on the application\'s Settings tab, or your custom domain.',
                ],
                'trust_email' => [
                    'label' => 'Accept email addresses not marked verified',
                    'type' => 'checkbox',
                    'help' => 'Leave off unless every connection verifies email addresses. Database sign-ups are unverified until the user confirms their email.',
                ],
            ],
            'steps' => [
                'In the Auth0 Dashboard, go to Applications > Applications > Create Application and choose Regular Web Applications.',
                'On the Settings tab, set Allowed Callback URLs exactly: :callback and save.',
                'Copy the Domain, Client ID and Client Secret into the fields below.',
            ],
        ],

        'authentik' => [
            'label' => 'authentik',
            'icon' => 'fas fa-lock',
            'type' => 'oidc',
            'docs' => 'https://docs.goauthentik.io/add-secure-apps/providers/oauth2/',
            'client_id_label' => 'Client ID',
            'client_secret_label' => 'Client Secret',
            'fields' => [
                'issuer' => [
                    'label' => 'OpenID Configuration Issuer',
                    'type' => 'url',
                    'required' => true,
                    'placeholder' => 'https://authentik.example.com/application/o/atglance/',
                    'help' => 'Shown on the provider\'s page. Format: https://<authentik>/application/o/<application slug>/',
                ],
                'trust_email' => [
                    'label' => 'Accept email addresses not marked verified',
                    'type' => 'checkbox',
                    'help' => 'Since authentik 2025.10 email_verified is false unless you set it. Turn this on only if users cannot change their own email in authentik.',
                ],
            ],
            'steps' => [
                'In the authentik Admin interface, go to Applications > Applications > Create with provider.',
                'Provider type: OAuth2/OpenID Provider. Client type: Confidential.',
                'Redirect URIs: Strict, exactly: :callback',
                'Finish the wizard, open the provider and copy the Client ID, Client Secret and OpenID Configuration Issuer into the fields below.',
            ],
        ],

        'oidc' => [
            'label' => 'OpenID Connect',
            'icon' => 'fas fa-fingerprint',
            'type' => 'oidc',
            'docs' => 'https://openid.net/specs/openid-connect-core-1_0.html',
            'client_id_label' => 'Client ID',
            'client_secret_label' => 'Client secret',
            'fields' => [
                'button_label' => [
                    'label' => 'Button name (optional)',
                    'type' => 'text',
                    'placeholder' => 'Company SSO',
                    'help' => 'Shown on the login page as "Sign in with ...".',
                ],
                'issuer' => [
                    'label' => 'Issuer URL',
                    'type' => 'url',
                    'required' => true,
                    'placeholder' => 'https://id.example.com/realms/main',
                    'help' => 'The provider must publish <issuer>/.well-known/openid-configuration (Keycloak, Zitadel, Ping, JumpCloud and others do).',
                ],
                'trust_email' => [
                    'label' => 'Accept email addresses not marked verified',
                    'type' => 'checkbox',
                    'help' => 'Only if every user\'s email in the provider is managed by your administrators.',
                ],
            ],
            'steps' => [
                'In your identity provider, create a confidential OpenID Connect client (web application) using the authorization code flow.',
                'Redirect URI exactly: :callback',
                'Allow the scopes openid, email and profile.',
                'Copy the Client ID, Client secret and Issuer URL into the fields below.',
            ],
        ],
    ],
];
