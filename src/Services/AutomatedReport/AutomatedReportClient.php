<?php

declare(strict_types=1);

namespace FoleyBridgeSolutions\KotapayCashier\Services\AutomatedReport;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use FoleyBridgeSolutions\KotapayCashier\Exceptions\KotapayException;

/**
 * Client for Kotapay's automated report download system (techdoc v7.6).
 *
 * This is SEPARATE from the OAuth REST API ({@see \FoleyBridgeSolutions\KotapayCashier\Services\ApiClient}).
 * It emulates the documented two-hit HTML-form flow against the secure portal
 * host (https://www.kotapay.com) to download reports as CSV/Excel/PDF files:
 *
 *   1. First hit  — POST {@code InitAutomatedSession.icp} with the login and
 *      optional software-identification fields. Returns an xdoc containing
 *      three grid-card challenge coordinates plus the session identifiers.
 *   2. Second hit — POST {@code GetAutomatedReport.icp?&ICIN=<ICIN>} with the
 *      password, the three card values for the challenges, the secid, the
 *      report code and date range. Returns the report file (or an xdoc/errors
 *      file on failure).
 *
 * SAFETY: the account locks for 30 minutes (-313) after 3 failed authentication
 * attempts. This client therefore:
 *   - reuses cached sessions instead of re-authenticating;
 *   - records a hard local lockout when -313 is seen and refuses further hits
 *     until it expires;
 *   - never loops/retries authentication.
 */
final class AutomatedReportClient
{
    // --- Float-reconciliation report codes (CSV-capable unless noted) ---
    /** Batches submitted summary — money INTO the float (sent to the Fed). */
    public const REPORT_BATCHES_SUBMITTED = 'PBR';

    /** Returns — money OUT of the float. */
    public const REPORT_RETURNS = 'RET';

    /** Unauthorized returns. */
    public const REPORT_UNAUTHORIZED_RETURNS = 'UAR';

    /** Total company returns. */
    public const REPORT_TOTAL_RETURNS = 'TCR';

    /** Corrections (NOCs). */
    public const REPORT_CORRECTIONS = 'COR';

    /** Cleared items by company. */
    public const REPORT_CLEARED_ITEMS = 'CIR';

    /** Monthly billing detail — fees out of the float. */
    public const REPORT_BILLING_DETAIL = 'MBD';

    /** Monthly billing summary. */
    public const REPORT_BILLING_SUMMARY = 'MBS';

    /** Statement of account (interactive, CSV-friendly). */
    public const REPORT_STATEMENT = 'CST';

    /** Statement and projections (PDF-only, returned as a Zip archive). */
    public const REPORT_STATEMENT_PROJECTIONS = 'BRY';

    /** File acknowledgement summary. */
    public const REPORT_FILE_ACK = 'FAR';

    /**
     * Reports for which start/end dates are ignored (per techdoc v7.6).
     *
     * @var array<int,string>
     */
    private const DATELESS_REPORTS = [
        'CAR', 'CFS', 'WBR', 'SLE', 'RECA', 'XCR', 'XCC', 'URR',
        'BRY', 'RECC', 'CCR', 'CAP', 'ASR',
    ];

    /** Cache key marking a hard local lockout window. */
    private const LOCKOUT_CACHE_KEY = 'kotapay_autoreport_lockout_until';

    /**
     * @param  array<string,mixed>  $config  The `kotapay.auto_report` config block.
     */
    public function __construct(private readonly array $config)
    {
    }

    /**
     * Download a report and return its raw file contents.
     *
     * @param  string  $report  3-4 letter report code (see REPORT_* constants).
     * @param  string|null  $startDate  YYYYMMDD; required for dated reports.
     * @param  string|null  $endDate  YYYYMMDD; optional date-range end.
     * @param  string  $format  'csv' | 'exl' | 'pdf' | 'ach' | 'htm'.
     * @param  array<string,string>  $extra  Optional extra fields (compid, refnum, key, separatepdfs).
     * @return string Raw report file bytes.
     *
     * @throws KotapayException On configuration, authentication or report errors.
     */
    public function downloadReport(
        string $report,
        ?string $startDate = null,
        ?string $endDate = null,
        string $format = 'csv',
        array $extra = [],
    ): string {
        $this->assertEnabled();
        $this->assertNotLockedOut();

        $report = strtoupper($report);
        $session = $this->session();

        $fields = [
            'secid' => $session->secid,
            'report' => $report,
            'format' => $format,
            // Always ask for machine-readable error output instead of errors.txt.
            'erroroutput' => 'xml',
        ];

        if (! $this->isDateless($report)) {
            if ($startDate === null || $startDate === '') {
                throw new KotapayException("Kotapay report {$report} requires a start date (YYYYMMDD).");
            }
            $fields['sdate'] = $startDate;
            if ($endDate !== null && $endDate !== '') {
                $fields['edate'] = $endDate;
            }
        }

        // Password + multifactor responses are only valid on the first report
        // request after authentication.
        if ($session->hasPendingResponses()) {
            $fields['pass'] = (string) ($this->config['password'] ?? '');
            $fields['response1'] = $session->pendingResponses[0];
            $fields['response2'] = $session->pendingResponses[1];
            $fields['response3'] = $session->pendingResponses[2];
        }

        foreach ($extra as $key => $value) {
            $fields[$key] = (string) $value;
        }

        $url = $this->baseUrl()
            .$this->endpoint('report_endpoint', '/GetAutomatedReport.icp')
            .'?&ICIN='.$session->instanceNumber;

        $body = $this->postMultipart($url, $fields, $session->cookieHeader());

        // A successful report download is the file itself; only failures come
        // back as an xdoc (we forced erroroutput=xml).
        if ($this->looksLikeError($body)) {
            $this->handleErrorResponse($body, $report);
        }

        // Responses consumed — persist the slimmed session for reuse.
        if ($session->hasPendingResponses()) {
            $this->cacheSession($session->withResponsesConsumed());
        }

        return $body;
    }

    /**
     * Get a live session, reusing the cached one when available.
     *
     * @throws KotapayException
     */
    public function session(): AutomatedSession
    {
        $cached = Cache::get($this->sessionCacheKey());
        if (is_array($cached) && ($cached['secid'] ?? '') !== '') {
            return AutomatedSession::fromArray($cached);
        }

        return $this->authenticate();
    }

    /**
     * Perform the first hit: authenticate and resolve multifactor challenges.
     *
     * @throws KotapayException
     */
    public function authenticate(): AutomatedSession
    {
        $this->assertEnabled();
        $this->assertNotLockedOut();

        $url = $this->baseUrl().$this->endpoint('init_endpoint', '/InitAutomatedSession.icp');

        $fields = array_filter([
            'login' => (string) ($this->config['login'] ?? ''),
            'soft_vendor' => (string) ($this->config['soft_vendor'] ?? ''),
            'soft_name' => (string) ($this->config['soft_name'] ?? ''),
            'soft_version' => (string) ($this->config['soft_version'] ?? ''),
        ], fn (string $v): bool => $v !== '');

        if (($fields['login'] ?? '') === '') {
            throw new KotapayException('Kotapay automated-report login is not configured (KOTAPAY_AUTOREPORT_LOGIN).');
        }

        $body = $this->postMultipart($url, $fields, null);

        if (! XdocResponse::looksLikeXdoc($body)) {
            throw new KotapayException('Unexpected Kotapay InitAutomatedSession response (no xdoc).');
        }

        $xdoc = XdocResponse::parse($body);

        if ($xdoc->isLockout()) {
            $this->recordLockout();
            throw new KotapayException($this->describe($xdoc), $xdoc->toArray(), -313);
        }

        if (! $xdoc->isSuccess()) {
            throw new KotapayException($this->describe($xdoc), $xdoc->toArray(), $xdoc->errorCode());
        }

        $this->assertExpectedSerial($xdoc->serial());

        $card = MfaCard::fromJson((string) ($this->config['mfa_card'] ?? ''));
        $responses = $card->resolveAll($xdoc->challenges());

        if (count($responses) !== 3) {
            throw new KotapayException('Kotapay first hit did not return three multifactor challenges.');
        }

        $session = new AutomatedSession(
            secid: (string) $xdoc->secid(),
            instanceNumber: (string) $xdoc->instanceNumber(),
            cookieName: (string) $xdoc->cookieName(),
            sessionId: (string) $xdoc->sessionId(),
            serial: $xdoc->serial(),
            pendingResponses: $responses,
        );

        if ($session->secid === '' || $session->instanceNumber === '' || $session->cookieName === '' || $session->sessionId === '') {
            throw new KotapayException('Kotapay first hit returned an incomplete session.');
        }

        $this->cacheSession($session);

        Log::info('Kotapay automated-report session established', [
            'serial' => $session->serial,
            'icin' => $session->instanceNumber,
        ]);

        return $session;
    }

    /**
     * Forget any cached session (forces a fresh authentication next time).
     */
    public function forgetSession(): void
    {
        Cache::forget($this->sessionCacheKey());
    }

    // ----------------------------------------------------------------------
    // Internals
    // ----------------------------------------------------------------------

    /**
     * POST multipart/form-data and return the raw response body.
     *
     * @param  array<string,string>  $fields
     *
     * @throws KotapayException
     */
    private function postMultipart(string $url, array $fields, ?string $cookieHeader): string
    {
        $multipart = [];
        foreach ($fields as $name => $value) {
            $multipart[] = ['name' => $name, 'contents' => (string) $value];
        }

        $request = Http::asMultipart()->timeout((int) ($this->config['timeout'] ?? 60));

        if ($cookieHeader !== null) {
            $request = $request->withHeaders(['Cookie' => $cookieHeader]);
        }

        try {
            $response = $request->post($url, $multipart);
        } catch (ConnectionException $e) {
            throw new KotapayException('Kotapay automated-report connection failed: '.$e->getMessage(), [], 0, $e);
        }

        if (! $response->successful()) {
            throw new KotapayException(
                'Kotapay automated-report HTTP error. Status: '.$response->status(),
                [],
                $response->status(),
            );
        }

        return $response->body();
    }

    /**
     * Translate an error xdoc into the appropriate exception, recording a
     * lockout when one is reported.
     *
     * @throws KotapayException Always.
     */
    private function handleErrorResponse(string $body, string $report): void
    {
        $xdoc = XdocResponse::parse($body);

        if ($xdoc->isLockout()) {
            $this->recordLockout();
        }

        // -98 means the session expired/was invalid — drop it so the next call
        // re-authenticates (but do NOT loop here; that risks the lockout).
        if ($xdoc->errorCode() === -98) {
            $this->forgetSession();
        }

        throw new KotapayException(
            "Kotapay report {$report} failed: ".$this->describe($xdoc),
            $xdoc->toArray(),
            $xdoc->errorCode(),
        );
    }

    private function looksLikeError(string $body): bool
    {
        $head = ltrim(substr($body, 0, 256));

        return XdocResponse::looksLikeXdoc($body)
            || str_starts_with($head, '<?xml')
            || str_starts_with($head, '<errors')
            || str_contains($head, '<errorcode>');
    }

    private function isDateless(string $report): bool
    {
        return in_array(strtoupper($report), self::DATELESS_REPORTS, true);
    }

    private function assertExpectedSerial(?string $serial): void
    {
        $expected = (string) ($this->config['serial'] ?? '');
        if ($expected !== '' && $serial !== null && $serial !== $expected) {
            throw new KotapayException(
                "Kotapay multifactor card serial mismatch: server expects #{$serial}, configured #{$expected}. "
                .'Refusing to answer with the wrong card.'
            );
        }
    }

    private function describe(XdocResponse $xdoc): string
    {
        return '['.$xdoc->errorCode().'] '.$xdoc->errorDescription();
    }

    private function cacheSession(AutomatedSession $session): void
    {
        Cache::put(
            $this->sessionCacheKey(),
            $session->toArray(),
            now()->addSeconds((int) ($this->config['session_cache_ttl'] ?? 600)),
        );
    }

    private function recordLockout(): void
    {
        Cache::put(self::LOCKOUT_CACHE_KEY, now()->addMinutes(30)->timestamp, now()->addMinutes(30));
        $this->forgetSession();
        Log::error('Kotapay automated-report account locked for 30 minutes (-313).');
    }

    private function assertNotLockedOut(): void
    {
        $until = Cache::get(self::LOCKOUT_CACHE_KEY);
        if ($until !== null && (int) $until > now()->timestamp) {
            $seconds = (int) $until - now()->timestamp;
            throw new KotapayException(
                "Kotapay automated-report is locked out for another {$seconds}s (-313). Refusing to authenticate.",
                [],
                -313,
            );
        }
    }

    private function assertEnabled(): void
    {
        if (! ($this->config['enabled'] ?? false)) {
            throw new KotapayException('Kotapay automated-report is disabled (KOTAPAY_AUTOREPORT_ENABLED).');
        }
    }

    private function baseUrl(): string
    {
        return rtrim((string) ($this->config['base_url'] ?? 'https://www.kotapay.com'), '/');
    }

    private function endpoint(string $key, string $default): string
    {
        $value = (string) ($this->config[$key] ?? $default);

        return '/'.ltrim($value, '/');
    }

    private function sessionCacheKey(): string
    {
        return (string) ($this->config['session_cache_key'] ?? 'kotapay_autoreport_session');
    }
}
