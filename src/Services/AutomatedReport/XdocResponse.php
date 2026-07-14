<?php

declare(strict_types=1);

namespace FoleyBridgeSolutions\KotapayCashier\Services\AutomatedReport;

/**
 * Parsed Kotapay automated-report {@code <xdoc>} response.
 *
 * Both the first authentication hit and any failed second hit return an XML
 * document of the form:
 *
 *     <?xml version="1.0"?>
 *     <xdoc>
 *       <errorcode>0</errorcode>
 *       <errordesc>Successful.</errordesc>
 *       <challenge1>A1</challenge1>
 *       <challenge2>B2</challenge2>
 *       <challenge3>C3</challenge3>
 *       <secid>012345678901234</secid>
 *       <serial>1</serial>
 *       <sessionid>1234567890ABC</sessionid>
 *       <instancenumber>12345678901234567890AB</instancenumber>
 *       <cookiename>iclp12345678901234567890AB</cookiename>
 *       <style>KOTA24</style>
 *     </xdoc>
 *
 * A successful second hit returns the report payload itself (not an xdoc), so
 * callers should only parse a response as an xdoc when it is XML beginning with
 * an <xdoc> element.
 */
final class XdocResponse
{
    /**
     * Documented automated-report error codes.
     *
     * @var array<int,string>
     */
    public const ERROR_CODES = [
        0 => 'Successful.',
        -1 => 'Updating server database or request timed out.',
        -10 => 'No report for selected date.',
        -15 => 'Reports have not finished processing.',
        -63 => 'Report request exceeds maximum of 31 days.',
        -93 => 'Report or feature not currently implemented. Try later.',
        -95 => 'Report not available in requested format.',
        -96 => 'Insufficient permission for this task or https not used.',
        -98 => 'Invalid login credentials or session timed-out. Repeat the first hit.',
        -99 => 'Invalid (or missing) parameters, invalid report type.',
        -300 => 'Data file has too many records to convert to Excel. Use CSV.',
        -310 => 'Report is too large to process. Decrease the number of days.',
        -313 => 'Maximum authentication attempts exceeded. Account locked for 30 minutes.',
        -330 => 'A sample report is not available for this report (test/sample login).',
        -314 => 'User not set up for Multifactor Authentication. Contact support.',
        -315 => 'Multifactor error. Log in again to get new MF challenges.',
        -335 => 'Invalid date format or invalid date range.',
    ];

    /**
     * @param  array<string,string>  $fields  Raw element name => text value.
     */
    private function __construct(private readonly array $fields)
    {
    }

    /**
     * Detect whether a raw response body is an xdoc XML document.
     */
    public static function looksLikeXdoc(string $body): bool
    {
        return str_contains($body, '<xdoc');
    }

    /**
     * Parse a raw xdoc XML body into a value object.
     */
    public static function parse(string $body): self
    {
        $fields = [];

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_use_internal_errors($previous);

        if ($xml !== false) {
            foreach ($xml->children() as $child) {
                $fields[$child->getName()] = trim((string) $child);
            }
        }

        return new self($fields);
    }

    public function field(string $name): ?string
    {
        $value = $this->fields[$name] ?? null;

        return ($value === null || $value === '') ? null : $value;
    }

    /**
     * All parsed xdoc fields (safe to attach to an exception for diagnostics).
     *
     * @return array<string,string>
     */
    public function toArray(): array
    {
        return $this->fields;
    }

    public function errorCode(): int
    {
        return (int) ($this->fields['errorcode'] ?? -99);
    }

    public function errorDescription(): string
    {
        $desc = $this->field('errordesc');
        if ($desc !== null) {
            return $desc;
        }

        return self::ERROR_CODES[$this->errorCode()] ?? 'Unknown error.';
    }

    /**
     * Authentication / report request succeeded (errorcode 0).
     *
     * Note: per Kotapay docs, any second-hit errorcode other than -98 means
     * authentication itself succeeded; use {@see authenticationSucceeded()}
     * for that distinction.
     */
    public function isSuccess(): bool
    {
        return $this->errorCode() === 0;
    }

    /**
     * True when authentication passed (login/password/MFA accepted).
     *
     * On the second hit, only -98 indicates an authentication failure; every
     * other error code (e.g. -10 no report, -15 not processed) means the login
     * was valid but the report request had an issue.
     */
    public function authenticationSucceeded(): bool
    {
        return $this->errorCode() !== -98;
    }

    public function isLockout(): bool
    {
        return $this->errorCode() === -313;
    }

    /**
     * The three challenge coordinates returned by the first hit, in order.
     *
     * @return array<int,string>
     */
    public function challenges(): array
    {
        return array_values(array_filter([
            $this->field('challenge1'),
            $this->field('challenge2'),
            $this->field('challenge3'),
        ], fn (?string $v): bool => $v !== null));
    }

    public function serial(): ?string
    {
        return $this->field('serial');
    }

    /**
     * The 15-digit security id required on the second and all subsequent hits.
     */
    public function secid(): ?string
    {
        return $this->field('secid');
    }

    public function sessionId(): ?string
    {
        return $this->field('sessionid');
    }

    public function instanceNumber(): ?string
    {
        return $this->field('instancenumber');
    }

    public function cookieName(): ?string
    {
        return $this->field('cookiename');
    }
}
