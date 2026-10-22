<?php

declare(strict_types=1);

namespace Yumo\Eleanor\Api\Security;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The caller a firewall authenticated, identified by an email.
 *
 * It holds no role and no credential. It is not an `Owner`: the owner type normalizes the email.
 */
final class EmailIdentity implements UserInterface
{
    /** @var non-empty-string */
    private readonly string $email;

    /**
     * Creates the identity for an email.
     *
     * @throws \InvalidArgumentException when the email is empty
     */
    public function __construct(string $email)
    {
        if ($email === '') {
            throw new \InvalidArgumentException('The identity needs an email.');
        }

        $this->email = $email;
    }

    /**
     * Returns the email.
     */
    public function getUserIdentifier(): string
    {
        return $this->email;
    }

    /**
     * Returns no role.
     *
     * @return list<string>
     */
    public function getRoles(): array
    {
        return [];
    }

    /**
     * Does nothing. The identity holds no credential.
     */
    public function eraseCredentials(): void
    {
    }
}
