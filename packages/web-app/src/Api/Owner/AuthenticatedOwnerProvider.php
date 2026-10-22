<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Api\Owner;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Security\Core\User\UserInterface;
use Yumo\Eleanor\Archive\Owner;

/**
 * Answers with the owner the firewall authenticated.
 *
 * It reads the identifier of the authenticated user and does not know which authenticator wrote it.
 */
final class AuthenticatedOwnerProvider implements OwnerProvider
{
    /**
     * Creates the provider over the security token.
     */
    public function __construct(
        private readonly Security $security,
    ) {
    }

    /**
     * Returns the owner of the authenticated user.
     *
     * @throws \LogicException when the call is not authenticated, which is a programming fault
     */
    public function owner(): Owner
    {
        $user = $this->security->getUser();

        if (!$user instanceof UserInterface) {
            throw new \LogicException('The call has no authenticated owner.');
        }

        return new Owner($user->getUserIdentifier());
    }
}
