<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Api\Security;

use Monolog\Attribute\WithMonologChannel;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Authenticates a call that carries the configured secret as `Authorization: Bearer <secret>`.
 *
 * A failure writes one log line and throws. The response for it is not written here.
 */
#[WithMonologChannel('application')]
final class BearerAuthenticator extends AbstractAuthenticator
{
    private const string SCHEME = 'Bearer';

    /**
     * Creates the authenticator from the configured secret and the owner email.
     */
    public function __construct(
        /** The configured secret. */
        private readonly string $secret,
        /** The email the secret authenticates as. */
        private readonly string $email,
        private readonly LoggerInterface $application,
    ) {
    }

    /**
     * Judges every request the firewall passes in.
     */
    public function supports(Request $request): bool
    {
        return true;
    }

    /**
     * Returns a passport for the configured email when the header carries the secret.
     *
     * @throws AuthenticationException when the header is absent or does not carry the secret
     */
    public function authenticate(Request $request): Passport
    {
        $header = $request->headers->get('Authorization');

        if ($header === null) {
            $this->application->info('bearer.absent');

            throw $this->unauthenticated();
        }

        if (!$this->carriesSecret($header)) {
            $this->application->info('bearer.rejected');

            throw $this->unauthenticated();
        }

        return new SelfValidatingPassport(
            new UserBadge($this->email, static fn (string $email): EmailIdentity => new EmailIdentity($email)),
        );
    }

    /**
     * Lets the request go on. The access rule denies the unauthenticated caller.
     */
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    /**
     * Lets the request go on. The access rule denies the unauthenticated caller.
     */
    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return null;
    }

    /**
     * Splits the header at its first space only. The rest is compared as it is, without trimming.
     */
    private function carriesSecret(string $header): bool
    {
        $parts = explode(' ', $header, 2);
        $candidate = $parts[1] ?? '';

        return strcasecmp($parts[0], self::SCHEME) === 0 && hash_equals($this->secret, $candidate);
    }

    private function unauthenticated(): AuthenticationException
    {
        return new CustomUserMessageAuthenticationException('Unauthenticated.');
    }
}
