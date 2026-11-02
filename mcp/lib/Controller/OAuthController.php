<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\Controller;

use OCA\Mcp\OAuth\AuthorizationRequest;
use OCA\Mcp\OAuth\AuthorizationValidator;
use OCA\Mcp\OAuth\NativeClient;
use OCA\Mcp\OAuth\OAuthException;
use OCA\Mcp\OAuth\RedirectUriMatcher;
use OCA\Mcp\OAuth\ResourceUrl;
use OCA\Mcp\OAuth\TokenService;
use OCA\Mcp\Mcp\Tools\Discovery\ToolPresentation;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Access\Policy\PolicyUnavailable;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\ISession;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * OAuth authorization and token endpoints. The consent page requires a Nextcloud login (unauthenticated users are
 * sent to the login page and back); the pending request lives in the session and the consent POST is protected by
 * the Nextcloud CSRF token. Allowing counts as the user's personal MCP activation.
 */
class OAuthController extends Controller {
    private const PENDING_KEY = 'mcp_oauth_pending';

    public function __construct(
        string $appName,
        IRequest $request,
        private IUserSession $userSession,
        private ISession $session,
        private IURLGenerator $urlGenerator,
        private AuthorizationValidator $validator,
        private TokenService $tokens,
        private GrantPolicy $policy,
        private ResourceUrl $urls,
        private ?ToolRegistry $tools = null,
    ) {
        parent::__construct($appName, $request);
    }

    /** @return TemplateResponse|RedirectResponse the consent page, a local error page, or a redirectable error */
    #[NoAdminRequired]
    #[NoCSRFRequired]
    #[UseSession]
    public function authorize(): TemplateResponse|RedirectResponse {
        try {
            $authRequest = $this->validator->validate($this->request->getParams(), $this->urls->base());
        } catch (PolicyUnavailable) {
            return $this->page(['unavailable' => true]);
        } catch (OAuthException $e) {
            if ($e->redirectable) {
                return new RedirectResponse($this->redirectableError($e));
            }
            return $this->page(['error' => $e->getMessage()]);
        }
        $user = $this->userSession->getUser();
        $uid = $user->getUID();
        try {
            // Eligibility includes the groups that may grant it; an unknown answer is not a refusal (contracts §3.3.5).
            $allowed = $this->policy->globalEnabled() && $this->policy->eligible($uid);
        } catch (PolicyUnavailable) {
            return $this->page(['unavailable' => true]);
        }
        if (!$allowed) {
            return $this->page(['blocked' => true, 'client' => $authRequest->client->name]);
        }
        try {
            [$definitions, $permittedVariants] = $this->policy->within($uid, function () use ($uid): array {
                $definitions = $this->tools?->permittedDefinitions($uid) ?? [];
                $variants = [];
                foreach ($definitions as $definition) {
                    if (isset($definition['grantAnyOf'])) {
                        $variants[$definition['name']] = $this->tools?->permittedVariants($uid, (string)$definition['name']) ?? [];
                    }
                }
                return [$definitions, $variants];
            });
        } catch (PolicyUnavailable) {
            return $this->page(['unavailable' => true]);
        }
        return $this->consentPage($authRequest, $user->getDisplayName(), $definitions, $permittedVariants);
    }

    /** Each rendered form gets a new single-use handle, including a corrected selection. */
    private function consentPage(AuthorizationRequest $authRequest, string $account, array $definitions,
        array $permittedVariants, array $selectedTools = [], array $selectedVariants = [], ?string $missingAction = null): TemplateResponse {
        $toolsCatalog = [];
        $catalogTitles = [];
        foreach ($definitions as $definition) {
            $module = (string)($definition['module'] ?? '');
            $catalogTitles[$module] ??= ToolPresentation::moduleTitle($module);
            $variants = $permittedVariants[$definition['name']] ?? [];
            $toolsCatalog[$module][] = [
                'name' => $definition['name'],
                'title' => $definition['title'] ?? $definition['name'],
                'description' => $definition['description'],
                'approvedVariants' => $variants,
            ];
        }
        $pendingId = bin2hex(random_bytes(16));
        $this->session->set(self::PENDING_KEY, [$pendingId => $authRequest->toArray()]);
        return $this->page([
            'pending' => $pendingId,
            'client' => $authRequest->client->name,
            'clientHost' => $authRequest->client->host(),
            'customClient' => \OCA\Mcp\OAuth\CustomClient::owns($authRequest->client->clientId),
            'nativeClient' => $authRequest->client->clientId === NativeClient::CLIENT_ID,
            'redirectHost' => (string)parse_url($authRequest->redirectUri, PHP_URL_HOST),
            'loopback' => RedirectUriMatcher::isLoopback($authRequest->redirectUri),
            'account' => $account,
            'catalogTitles' => $catalogTitles,
            'missingAction' => $missingAction,
            'toolsCatalog' => $toolsCatalog,
            'selectedTools' => $selectedTools,
            'toolVariants' => $selectedVariants,
            'action' => $this->urlGenerator->linkToRoute('mcp.o_auth.consent'),
        ]);
    }

    /**
     * @param string $pending id of the pending request shown on the consent page
     * @param string $decision allow or deny
     * @return TemplateResponse navigation to the client with code or access_denied; local error page
     */
    #[NoAdminRequired]
    #[UseSession]
    public function consent(string $pending = '', string $decision = '', array $tools = [], int $toolsSubmitted = 0,
        array $variants = [], array $variantTools = []): TemplateResponse {
        $stored = $this->session->get(self::PENDING_KEY);
        $this->session->remove(self::PENDING_KEY);
        if (!is_array($stored) || !is_array($stored[$pending] ?? null)) {
            return $this->page(['error' => 'The authorization request expired. Start the connection again.']);
        }
        $authRequest = AuthorizationRequest::fromArray($stored[$pending]);
        try {
            // Before any answer, deny included: a pending page older than a withdrawal never returns to its redirect URI.
            $this->validator->revalidate($authRequest);
        } catch (PolicyUnavailable) {
            return $this->page(['unavailable' => true]);
        } catch (OAuthException) {
            return $this->page(['error' => 'The authorization request expired. Start the connection again.']);
        }
        $uid = $this->userSession->getUser()->getUID();
        if ($decision !== 'allow') {
            return $this->clientNavigation($authRequest->redirectWith(['error' => 'access_denied'], $this->urls->issuer()));
        }
        if ($toolsSubmitted !== 1) {
            return $this->page(['error' => 'The tool selection expired. Start the connection again.']);
        }
        try {
            [$eligible, $definitions, $allowedVariants] = $this->policy->within($uid, function () use ($uid): array {
                if (!$this->policy->globalEnabled() || !$this->policy->eligible($uid)) {
                    return [false, [], []];
                }
                $definitions = $this->tools?->permittedDefinitions($uid) ?? [];
                $variants = [];
                foreach ($definitions as $definition) {
                    if (isset($definition['grantAnyOf'])) {
                        $name = (string)$definition['name'];
                        $variants[$name] = $this->tools?->permittedVariants($uid, $name) ?? [];
                    }
                }
                return [true, $definitions, $variants];
            });
            if (!$eligible) {
                return $this->page(['blocked' => true, 'client' => $authRequest->client->name]);
            }
            $allowed = array_fill_keys(array_column($definitions, 'name'), true);
            if ($toolsSubmitted === 1) {
                if (!array_is_list($tools) || count($tools) > count($allowed)) {
                    return $this->page(['error' => 'The tool selection is invalid. Start the connection again.']);
                }
                foreach ($tools as $tool) {
                    if (!is_string($tool) || !isset($allowed[$tool])) {
                        return $this->page(['error' => 'The tool selection is invalid. Start the connection again.']);
                    }
                }
                $selectedTools = $tools;
                $selected = array_fill_keys($selectedTools, true);
                $selectedVariants = [];
                foreach ($variants as $tool => $operations) {
                    if (!is_string($tool) || !isset($allowed[$tool]) || !is_array($operations) || !array_is_list($operations)) {
                        return $this->page(['error' => 'The tool selection is invalid. Start the connection again.']);
                    }
                    if (!isset($selected[$tool])) {
                        continue;
                    }
                    foreach ($operations as $operation) {
                        if (!is_string($operation) || !in_array($operation, $allowedVariants[$tool] ?? [], true)) {
                            return $this->page(['error' => 'The tool selection is invalid. Start the connection again.']);
                        }
                    }
                    $selectedVariants[$tool] = array_values(array_unique($operations));
                }
                if (!array_is_list($variantTools) || count($variantTools) > count($allowed)
                    || count(array_filter($variantTools, 'is_string')) !== count($variantTools)) {
                    return $this->page(['error' => 'The tool selection is invalid. Start the connection again.']);
                }
                foreach ($variantTools as $tool) {
                    if (!isset($allowed[$tool])) {
                        return $this->page(['error' => 'The tool selection is invalid. Start the connection again.']);
                    }
                    if (isset($selected[$tool]) && !array_key_exists($tool, $selectedVariants)) {
                        $selectedVariants[$tool] = [];
                    }
                }
                foreach ($selectedTools as $tool) {
                    if (($allowedVariants[$tool] ?? []) !== [] && ($selectedVariants[$tool] ?? []) === []) {
                        return $this->consentPage($authRequest, $this->userSession->getUser()->getDisplayName(),
                            $definitions, $allowedVariants, $selectedTools, $selectedVariants, $tool);
                    }
                }
            } else {
                $selectedTools = [];
                $selectedVariants = [];
            }
            // Connection and code in one command: a failure leaves the user neither connected nor with a code.
            $code = $this->tokens->consent($authRequest, $uid, $selectedTools, $selectedVariants);
        } catch (OAuthException) {
            // The client was withdrawn after the check above (decided under the mutex): nothing was written.
            return $this->page(['error' => 'The authorization request expired. Start the connection again.']);
        } catch (PolicyUnavailable) {
            // Nothing was issued: the client may try again (RFC 6749 §4.1.2.1), the state is never assumed.
            return $this->clientNavigation($authRequest->redirectWith(['error' => 'temporarily_unavailable'], $this->urls->issuer()));
        }
        if ($code === null) {
            // The service or the eligibility changed since the check above.
            return $this->page(['blocked' => true, 'client' => $authRequest->client->name]);
        }
        return $this->clientNavigation($authRequest->redirectWith(['code' => $code], $this->urls->issuer()));
    }

    /**
     * @return JSONResponse token response (no-store), an RFC 6749 error (failed grants are throttled), or 503
     *     temporarily_unavailable with Retry-After when the credential state cannot be read or keeps changing
     */
    #[PublicPage]
    #[NoCSRFRequired]
    #[BruteForceProtection(action: 'mcp_oauth_token')]
    public function token(): JSONResponse {
        $params = $this->request->getParams();
        try {
            $body = match ($params['grant_type'] ?? '') {
                'authorization_code' => $this->tokens->exchangeCode($params),
                'refresh_token' => $this->tokens->refresh($params),
                default => throw new OAuthException('unsupported_grant_type', 'Unsupported grant_type'),
            };
            $response = new JSONResponse($body);
        } catch (OAuthException $e) {
            $response = new JSONResponse(['error' => $e->error, 'error_description' => $e->getMessage()], 400);
            if ($e->error === 'invalid_grant') {
                $response->throttle(['action' => 'mcp_oauth_token']);
            }
        } catch (PolicyUnavailable) {
            // Storage failed or kept changing: nothing was consumed or issued, so the client may simply retry.
            $response = new JSONResponse(['error' => 'temporarily_unavailable',
                'error_description' => 'The authorization server is temporarily unavailable'], 503);
            $response->addHeader('Retry-After', '1');
        }
        $this->tokens->housekeeping();
        $response->addHeader('Cache-Control', 'no-store');
        $response->addHeader('Pragma', 'no-cache');
        return $response;
    }

    /** @param array<string,mixed> $params */
    private function page(array $params): TemplateResponse {
        $response = new TemplateResponse($this->appName, 'consent', $params + [
            'error' => null, 'blocked' => false, 'unavailable' => false, 'pending' => null,
            'toolsCatalog' => [], 'selectedTools' => [], 'toolVariants' => [],
        ], TemplateResponse::RENDER_AS_GUEST);
        if ($params['unavailable'] ?? false) {
            $response->setStatus(503);
            $response->addHeader('Retry-After', '1');
        } elseif (isset($params['error'])) {
            $response->setStatus(400);
        } elseif (isset($params['missingAction'])) {
            $response->setStatus(422);
        }
        return $response;
    }

    /**
     * Builds an OAuth error redirect after the authorization request has validated its redirect URI.
     *
     * @param OAuthException $e safe protocol error to return to the client
     * @return string redirect URI with error details and any supplied state
     */
    private function redirectableError(OAuthException $e): string {
        $params = $this->request->getParams();
        $redirectUri = (string)$params['redirect_uri'];
        $state = is_string($params['state'] ?? null) ? $params['state'] : '';
        return AuthorizationRequest::buildRedirect($redirectUri,
            ['error' => $e->error, 'error_description' => $e->getMessage()], $state, $this->urls->issuer());
    }

    /**
     * Finish the POST before navigating to the validated callback. Chromium applies the consent form's form-action
     * to every HTTP redirect, including a callback's redirect to another origin. A new document starts a normal
     * navigation instead, without relaxing form-action or allowing the callback's unknown downstream origins.
     */
    private function clientNavigation(string $target): TemplateResponse {
        $response = new TemplateResponse($this->appName, 'oauth-redirect', ['target' => $target], TemplateResponse::RENDER_AS_BLANK);
        $response->setContentSecurityPolicy(new ContentSecurityPolicy());
        $response->addHeader('Cache-Control', 'no-store');
        $response->addHeader('Pragma', 'no-cache');
        $response->addHeader('Referrer-Policy', 'no-referrer');
        return $response;
    }
}
