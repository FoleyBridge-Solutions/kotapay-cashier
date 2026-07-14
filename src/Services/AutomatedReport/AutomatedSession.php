<?php

declare(strict_types=1);

namespace FoleyBridgeSolutions\KotapayCashier\Services\AutomatedReport;

/**
 * An authenticated Kotapay automated-report session.
 *
 * Produced by the first ("InitAutomatedSession.icp") hit and reused for the
 * second and all subsequent ("GetAutomatedReport.icp") hits until it expires
 * server-side. Re-authenticating costs a multifactor attempt and risks the
 * 30-minute lockout (-313), so sessions are cached and reused aggressively.
 *
 * The grid-card challenge responses ({@see $pendingResponses}) are only valid
 * on the FIRST report request after authentication; the doc states they are
 * "not needed for the third or subsequent hits". Once consumed they are
 * cleared from the session.
 */
final class AutomatedSession
{
    /**
     * @param  string  $secid  15-digit security id (required on every hit after the first).
     * @param  string  $instanceNumber  22-char ICIN appended to the report URL.
     * @param  string  $cookieName  Session cookie name returned by the first hit.
     * @param  string  $sessionId  Session cookie value returned by the first hit.
     * @param  string|null  $serial  Multifactor card serial associated with the login.
     * @param  array<int,string>  $pendingResponses  The three resolved card values for the
     *                                                first report request (empty once consumed).
     */
    public function __construct(
        public readonly string $secid,
        public readonly string $instanceNumber,
        public readonly string $cookieName,
        public readonly string $sessionId,
        public readonly ?string $serial = null,
        public readonly array $pendingResponses = [],
    ) {
    }

    /**
     * The Cookie header value carried on every hit after the first.
     */
    public function cookieHeader(): string
    {
        return "{$this->cookieName}={$this->sessionId}";
    }

    /**
     * Whether this session still has unconsumed multifactor responses.
     */
    public function hasPendingResponses(): bool
    {
        return count($this->pendingResponses) === 3;
    }

    /**
     * Return a copy with the multifactor responses consumed/cleared.
     */
    public function withResponsesConsumed(): self
    {
        return new self(
            $this->secid,
            $this->instanceNumber,
            $this->cookieName,
            $this->sessionId,
            $this->serial,
            [],
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'secid' => $this->secid,
            'instance_number' => $this->instanceNumber,
            'cookie_name' => $this->cookieName,
            'session_id' => $this->sessionId,
            'serial' => $this->serial,
            'pending_responses' => $this->pendingResponses,
        ];
    }

    /**
     * @param  array<string,mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['secid'] ?? ''),
            (string) ($data['instance_number'] ?? ''),
            (string) ($data['cookie_name'] ?? ''),
            (string) ($data['session_id'] ?? ''),
            isset($data['serial']) ? (string) $data['serial'] : null,
            array_values(array_map('strval', $data['pending_responses'] ?? [])),
        );
    }
}
