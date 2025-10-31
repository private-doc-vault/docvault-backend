<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Session to API Authenticator
 *
 * Allows API endpoints to accept session-based authentication
 * This enables the web UI (which uses sessions) to call API endpoints
 * without needing to manage JWT tokens separately
 */
class SessionToApiAuthenticator extends AbstractAuthenticator
{
    public function supports(Request $request): ?bool
    {
        // Only support if there's an active session with security token
        if (!$request->hasSession() || !$request->getSession()->isStarted()) {
            return false;
        }

        // Check if there's a security token in the session
        return $request->getSession()->has('_security_main');
    }

    public function authenticate(Request $request): Passport
    {
        $session = $request->getSession();

        // Get the serialized security token from session
        $serializedToken = $session->get('_security_main');

        if (!$serializedToken) {
            throw new AuthenticationException('No security token found in session');
        }

        // Unserialize the token to get the user identifier
        $token = unserialize($serializedToken);

        if (!$token || !method_exists($token, 'getUserIdentifier')) {
            throw new AuthenticationException('Invalid security token in session');
        }

        $userIdentifier = $token->getUserIdentifier();

        if (!$userIdentifier) {
            throw new AuthenticationException('No user identifier in session token');
        }

        // Create a self-validating passport with the user identifier
        return new SelfValidatingPassport(
            new UserBadge($userIdentifier)
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        // Return null to continue with the request
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        // Return null to allow other authenticators to try
        return null;
    }
}
