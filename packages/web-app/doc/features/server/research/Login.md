# Discovery: Login

Record of the access discussion for Eleanor. Human login is a later slice. The first implementation uses a configured token so the request check can be built and the rest of the application can be exercised.

Two trunks stay separate:

| Trunk | Responsibility |
| --- | --- |
| Login | How a person or a script obtains a credential. |
| Verification | On every request, accept that credential or refuse the call. Controllers do not choose the method. |

Verification is a Symfony firewall in front of the controllers. It reads the credential the client already sent (header or session cookie) and leaves the controller with an authenticated user, or with a refusal.

The login trunk can grow new methods later. The firewall keeps the same job.

## First slice: token in configuration

A token lives in Eleanor's configuration. The client sends it on each call as `Authorization: Bearer <token>`. The firewall compares the header with the configured value.

This token is how the application is tested and how the firewall is built. It is also the credential for a script and for the first administrator, before any passkey exists. People do not receive it as their login.

When human login exists, the same firewall accepts either the configured token or a session created after a passkey login. Family members use the session. The token stays for non-interactive clients.

## Human login (preferred)

People are known ahead of time. An administrator writes their email addresses into an allowlist. Eleanor does not ask those people for a password, and it does not hand them a token.

The email is proved once, or again after a period of weeks or months. It is not proved on every visit. After a successful proof, the person's device holds a passkey, and later visits use that passkey. The period length is still open.

Sequence:

1. The email is on the allowlist.
2. Eleanor sends a one-time link to that mailbox. Following the link proves the person controls the mailbox and opens an enrollment.
3. The device registers a passkey, stored by Eleanor against that email.
4. Later visits sign the server's challenge with the passkey. Eleanor opens its own session.
5. When the proof period elapses, the person repeats the link before the passkey is accepted again. Until a period is chosen, a single proof is enough.

The firewall checks the session Eleanor issued. It does not contact a mailbox or an external provider on each request, and it does not repeat WebAuthn on each call.

### Passkey (WebAuthn)

Steps 3 and 4 are WebAuthn. The private key stays on the device. Eleanor stores the public key. The confirmation on the device (fingerprint, face, or the device PIN) unlocks that key locally. Eleanor receives a signature.

Enrollment is step 3. It runs on the page the person opened from the email link, after they confirm on that page.

1. Eleanor sends the browser a random challenge and its own hostname. That hostname is the relying party id.
2. The browser asks the authenticator to create a credential. The authenticator is the phone, the laptop, or a security key. The person confirms on the device.
3. The authenticator creates a key pair. The private key stays there. The browser returns a credential id, the public key, and a signature over the challenge.
4. Eleanor checks the signature, then stores the credential id and the public key against the allowlisted email.

A later visit is step 4.

1. Eleanor sends a new challenge.
2. The authenticator signs it with the private key for this hostname, after the person confirms on the device.
3. Eleanor checks the signature with the stored public key and opens a session.

Each challenge is used once. The hostname is part of the signed data, so a credential created for Eleanor's host signs challenges for that host. The relying party id is a hostname, including `localhost` for development. A raw IP address is not a valid id. The page is served over HTTPS, except on `localhost`.

Eleanor keeps, for each passkey, the credential id, the public key, and the email it belongs to.

A platform may copy the private key to the person's other devices (iCloud Keychain, Google Password Manager). That copy is the platform's, and Eleanor does not call Apple or Google for it. A device that does not already hold the key enrolls its own passkey against the same email.

The first administrator has no passkey yet. They use the configured token once, register a passkey, and use the passkey afterwards. A lost device is recovered by the administrator: they remove the stored passkey and the person enrolls again.

### Link, and the one-time code variant

The enrollment message is a link. Some mail clients fetch links automatically and would consume a one-time link before the person does. The page the link opens asks for a confirmation before the enrollment starts. A numeric code typed back into Eleanor is a variant of the same proof if confirmation is not enough. The variant is not chosen.

### What has to exist for the link to arrive

Eleanor sends mail through an SMTP relay the operator already controls, on a domain with SPF and DKIM. A server on a residential address does not deliver reliably to large inboxes.

The relay transports the message. It does not authenticate the person. It can see the recipient address. Login waits on that relay; the configured token does not.

The operator's setup is the relay and the allowlist. There is no application to register at Google, GitHub, or Apple.

## Identity provider (not the preferred path)

An external identity provider remains possible. Recorded here so a later session can see the cost without repeating the research.

The person signs in at the provider in the browser. Eleanor checks the provider's token, reads the email and the stable subject (`sub`), and compares the email with the allowlist. On success Eleanor opens its own session. The same firewall as above checks that session. The provider is not called again until the next login.

The email in the allowlist is the label the administrator writes. The identity Eleanor stores is the provider plus `sub`, bound to that email on the first accepted login. The email is accepted only when that provider marks it verified.

The administrator registers one OAuth client and copies into Eleanor's configuration: client id, client secret, the public base URL of Eleanor, the callback URL (identical to the URL registered at the provider), and the allowlist. One provider is configured, not several. Eleanor needs a stable HTTPS name on a domain the operator controls. `localhost` is enough for development. A raw IP address is not a valid callback for Google.

Whoever owns the provider account that holds the OAuth client can lock the family out by losing that account. Recovery is a new client in Eleanor's configuration.

| Provider | What the administrator does | Limits |
| --- | --- | --- |
| Google | A free Google account, a Cloud project, a consent screen, and a web OAuth client. Scopes are only `openid`, `email`, and `profile`. | With only those scopes, Google does not require app verification, a test-user list, or a seven-day expiry. The Eleanor allowlist is what keeps strangers out. The Cloud console is long. Name and logo on the consent screen require a brand verification (a domain the operator owns, and a privacy policy); a family server can skip that. |
| GitHub | An OAuth app: name, homepage, callback, client secret. | Short to set up. The email GitHub returns may be a `noreply` address, so an email allowlist is a poor fit. Many family members have no GitHub account. The stable id is the GitHub user id. |
| Apple | A paid Apple Developer Program membership. A primary App ID, a Services ID, domains, and return URLs. The client secret is a JWT signed with a `.p8` key that can be downloaded once, and it expires after at most six months. | Eleanor must sign a new secret before expiry. Hide My Email yields a relay address, and later logins may omit the email, so an allowlist of real emails does not match what Apple sends. Disproportionate for this server. |
| Microsoft | An app registration for personal Microsoft accounts, similar in weight to Google Cloud. | Worth it only when Outlook or Hotmail addresses are the ones the family actually uses. |

## Set aside

| Approach | Why it is set aside |
| --- | --- |
| A shared TOTP secret | Enrollment means handing the person a secret. Human login does not work that way. |
| A self-hosted identity provider (Authentik, Zitadel, and similar) | Still an identity provider, plus a second service to run. |
| Proving the email on every visit | The passkey is the standing credential. The mailbox is for enrollment and for the periodic proof. |

## Open inside this discovery

| Question | Notes |
| --- | --- |
| How long is the proof period? | Once is acceptable. So is a repeat every few weeks or every few months. The length is a later decision. |
| Confirmation page, or a numeric code? | The message is a link. The code is the fallback if mail clients consume the link anyway. |
| Enrollment in the same room, with no message? | The administrator, already signed in, can start enrollment and the other device registers a passkey directly. Useful when the person is present. The link remains the path for someone who is not. |
