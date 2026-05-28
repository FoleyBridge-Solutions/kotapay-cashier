<?php

declare(strict_types=1);

namespace FoleyBridgeSolutions\KotapayCashier\Services;

use FoleyBridgeSolutions\KotapayCashier\Exceptions\KotapayException;
use Illuminate\Support\Facades\Log;

/**
 * Service for running Kotapay reports.
 *
 * Uses the POST /v1/Reports/{type} endpoint to execute reports
 * and return structured data.
 *
 * Report types:
 *   - far: File Acknowledgement Report (summary or detail via FileUniqueID)
 *   - pbr: Processed Batches Report (summary or detail via BatchUniqueID)
 *   - ret: Returns Report (ACH returns with EntryID, Code, Reason)
 *   - cor: Corrections Report (NOC entries with EntryID)
 *   - car: Company Activity Report
 *   - mbs: Monthly Billing Summary
 */
class ReportService
{
    /**
     * Known report type codes to try for the File Acknowledgement Report.
     *
     * The Kotapay API does not publicly document the exact report type string,
     * so we try several common variations until one succeeds.
     */
    public const FAR_REPORT_TYPES = [
        'FAR',
        'far',
        'FileAcknowledgement',
        'FileAcknowledgementReport',
        'file-acknowledgement',
        'file_acknowledgement',
        'AchFar',
        'ACH_FAR',
    ];

    /**
     * The API client instance.
     */
    protected ApiClient $api;

    /**
     * Create a new report service instance.
     *
     * @return void
     */
    public function __construct(ApiClient $api)
    {
        $this->api = $api;
    }

    /**
     * Run a report by type.
     *
     * Calls POST /v1/Reports/{type} with the given parameters.
     *
     * @param  string  $type  The report type code (e.g., 'far', 'ret', 'cor')
     * @param  array  $params  Report request parameters (startDate, endDate, etc.)
     * @return array The API response
     *
     * @throws KotapayException
     */
    public function runReport(string $type, array $params = []): array
    {
        try {
            $response = $this->api->post("/v1/Reports/{$type}", $params);

            Log::info('Kotapay report executed', [
                'type' => $type,
                'params' => $params,
                'response_status' => $response['status'] ?? null,
            ]);

            return $response;
        } catch (KotapayException $e) {
            Log::error('Kotapay report request failed', [
                'type' => $type,
                'params' => $params,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    // =========================================================================
    // Returns Report (ret)
    // =========================================================================

    /**
     * Get the ACH Returns Report from Kotapay.
     *
     * Returns all ACH transactions that were returned by the receiving bank.
     * Each row contains EntryID (the AccountNameId), Code (R01, R02, etc.),
     * Reason, EffectiveDate, and bank details for matching.
     *
     * @param  string  $startDate  Start date in Y-m-d format
     * @param  string|null  $endDate  End date in Y-m-d format (optional; Kotapay ret may only use startDate)
     * @return array Structured report data with 'rowCount' and 'rows' keys
     *
     * @throws KotapayException
     */
    public function getReturnsReport(string $startDate, ?string $endDate = null): array
    {
        $params = [
            'startDate' => $startDate.'T00:00:00',
            'ReportFormat' => 'JSON',
            'IsTest' => false,
        ];

        if ($endDate !== null) {
            $params['endDate'] = $endDate.'T23:59:59';
        }

        $response = $this->runReport('ret', $params);

        $responseStatus = $response['status'] ?? null;
        if ($responseStatus === 'fail' || $responseStatus === 'error') {
            $message = $response['message'] ?? 'Unknown error';

            throw new KotapayException("Returns report failed: {$message}");
        }

        return $this->parseReportResponse($response);
    }

    // =========================================================================
    // Corrections Report (cor)
    // =========================================================================

    /**
     * Get the ACH Corrections (NOC) Report from Kotapay.
     *
     * Returns all Notification of Change entries. Each row contains EntryID,
     * CorrectionInfo with updated bank details, and the change code.
     *
     * @param  string  $startDate  Start date in Y-m-d format
     * @param  string|null  $endDate  End date in Y-m-d format (optional)
     * @return array Structured report data with 'rowCount' and 'rows' keys
     *
     * @throws KotapayException
     */
    public function getCorrectionsReport(string $startDate, ?string $endDate = null): array
    {
        $params = [
            'startDate' => $startDate.'T00:00:00',
            'ReportFormat' => 'JSON',
            'IsTest' => false,
        ];

        if ($endDate !== null) {
            $params['endDate'] = $endDate.'T23:59:59';
        }

        $response = $this->runReport('cor', $params);

        $responseStatus = $response['status'] ?? null;
        if ($responseStatus === 'fail' || $responseStatus === 'error') {
            $message = $response['message'] ?? 'Unknown error';

            throw new KotapayException("Corrections report failed: {$message}");
        }

        return $this->parseReportResponse($response);
    }

    // =========================================================================
    // Processed Batches Report (pbr)
    // =========================================================================

    /**
     * Get the Processed Batches Summary Report from Kotapay.
     *
     * Returns a list of processed batches with summary data. Each row contains
     * a BatchUniqueID that can be used with getProcessedBatchDetail() to
     * retrieve individual entries.
     *
     * @param  string  $startDate  Start date in Y-m-d format
     * @param  string  $endDate  End date in Y-m-d format
     * @return array Structured report data with 'rowCount' and 'rows' keys
     *
     * @throws KotapayException
     */
    public function getProcessedBatchesSummary(string $startDate, string $endDate): array
    {
        $params = [
            'startDate' => $startDate.'T00:00:00',
            'endDate' => $endDate.'T23:59:59',
        ];

        $response = $this->runReport('pbr', $params);

        $responseStatus = $response['status'] ?? null;
        if ($responseStatus === 'fail' || $responseStatus === 'error') {
            $message = $response['message'] ?? 'Unknown error';

            throw new KotapayException("Processed batches report failed: {$message}");
        }

        return $this->parseReportResponse($response);
    }

    /**
     * Get the Processed Batch Detail Report from Kotapay.
     *
     * Returns individual entries within a specific batch. Each entry contains
     * EntryID (AccountNameId), amounts, routing/account info, etc.
     *
     * @param  int  $batchUniqueId  The BatchUniqueID from the summary report
     * @return array Structured report data with 'rowCount' and 'rows' keys
     *
     * @throws KotapayException
     */
    public function getProcessedBatchDetail(int $batchUniqueId): array
    {
        $params = [
            'BatchUniqueID' => $batchUniqueId,
        ];

        $response = $this->runReport('pbr', $params);

        $responseStatus = $response['status'] ?? null;
        if ($responseStatus === 'fail' || $responseStatus === 'error') {
            $message = $response['message'] ?? 'Unknown error';

            throw new KotapayException("Processed batch detail failed: {$message}");
        }

        return $this->parseReportResponse($response);
    }

    // =========================================================================
    // File Acknowledgement Report (far)
    // =========================================================================

    /**
     * Get the File Acknowledgement Report (FAR) from Kotapay.
     *
     * Tries multiple report type codes since the exact code is not documented.
     * Returns the first successful response.
     *
     * @param  string  $startDate  Start date in Y-m-d format
     * @param  string  $endDate  End date in Y-m-d format
     * @return array Structured report data with 'rowCount' and 'rows' keys
     *
     * @throws KotapayException If all report type attempts fail
     */
    public function getFileAcknowledgementReport(string $startDate, string $endDate): array
    {
        $params = [
            'startDate' => $startDate.'T00:00:00',
            'endDate' => $endDate.'T23:59:59',
            'isTest' => false,
        ];

        $lastException = null;
        $attemptedTypes = [];

        foreach (self::FAR_REPORT_TYPES as $type) {
            $attemptedTypes[] = $type;

            try {
                $response = $this->runReport($type, $params);

                $responseStatus = $response['status'] ?? null;

                // If the API says 'fail' or 'error', the type might be wrong
                if ($responseStatus === 'fail' || $responseStatus === 'error') {
                    $message = $response['message'] ?? 'Unknown error';

                    Log::info('Kotapay FAR report type rejected', [
                        'type' => $type,
                        'message' => $message,
                    ]);

                    $lastException = new KotapayException("Report type '{$type}' failed: {$message}");

                    continue;
                }

                Log::info('Kotapay FAR report type accepted', ['type' => $type]);

                return $this->parseReportResponse($response);
            } catch (KotapayException $e) {
                Log::info('Kotapay FAR report type failed', [
                    'type' => $type,
                    'error' => $e->getMessage(),
                    'code' => $e->getCode(),
                ]);

                $lastException = $e;

                // If it's a 404 or 400, the type is probably wrong — try the next one
                // If it's a 401/403/500, something else is wrong — stop trying
                $code = $e->getCode();
                if ($code === 401 || $code === 403 || $code === 500) {
                    throw $e;
                }

                continue;
            }
        }

        throw new KotapayException(
            'Failed to fetch FAR report. Tried types: '.implode(', ', $attemptedTypes)
            .'. Last error: '.($lastException ? $lastException->getMessage() : 'Unknown'),
            [],
            0,
            $lastException
        );
    }

    /**
     * Get the File Acknowledgement Detail Report from Kotapay.
     *
     * Returns individual entries within a specific file. Each entry contains
     * EntryID (AccountNameId), amounts, routing/account info, etc.
     *
     * Uses the same FAR endpoint but with a FileUniqueID parameter instead
     * of a date range.
     *
     * @param  int  $fileUniqueId  The FileUniqueID from the summary report
     * @return array Structured report data with 'rowCount' and 'rows' keys
     *
     * @throws KotapayException
     */
    public function getFileAcknowledgementDetail(int $fileUniqueId): array
    {
        $params = [
            'FileUniqueID' => $fileUniqueId,
        ];

        // Use the same type-discovery approach as the summary report
        $lastException = null;
        $attemptedTypes = [];

        foreach (self::FAR_REPORT_TYPES as $type) {
            $attemptedTypes[] = $type;

            try {
                $response = $this->runReport($type, $params);

                $responseStatus = $response['status'] ?? null;

                if ($responseStatus === 'fail' || $responseStatus === 'error') {
                    $message = $response['message'] ?? 'Unknown error';
                    $lastException = new KotapayException("Report type '{$type}' detail failed: {$message}");

                    continue;
                }

                return $this->parseReportResponse($response);
            } catch (KotapayException $e) {
                $lastException = $e;

                $code = $e->getCode();
                if ($code === 401 || $code === 403 || $code === 500) {
                    throw $e;
                }

                continue;
            }
        }

        throw new KotapayException(
            'Failed to fetch FAR detail. Tried types: '.implode(', ', $attemptedTypes)
            .'. Last error: '.($lastException ? $lastException->getMessage() : 'Unknown'),
            [],
            0,
            $lastException
        );
    }

    // =========================================================================
    // Processor Billing Amounts (ProcessorBillingAmounts endpoint)
    // =========================================================================

    /**
     * Get the Processor Billing Amounts report from Kotapay.
     *
     * Calls POST /v1/Reports/ProcessorBillingAmounts with a bill date
     * and master key. This is a dedicated endpoint (not a /{type} call),
     * so it's wrapped separately from runReport().
     *
     * @param  string  $billDate  Bill date in Y-m-d format
     * @param  int  $masterKey  Master key identifying the processor account
     * @return array Structured report data with 'rowCount' and 'rows' keys
     *
     * @throws KotapayException
     */
    public function getProcessorBillingAmounts(string $billDate, int $masterKey): array
    {
        $params = [
            'billDate' => $billDate.'T00:00:00',
            'masterKey' => $masterKey,
        ];

        try {
            $response = $this->api->post('/v1/Reports/ProcessorBillingAmounts', $params);

            Log::info('Kotapay ProcessorBillingAmounts executed', [
                'params' => $params,
                'response_status' => $response['status'] ?? null,
            ]);
        } catch (KotapayException $e) {
            Log::error('Kotapay ProcessorBillingAmounts failed', [
                'params' => $params,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $responseStatus = $response['status'] ?? null;
        if ($responseStatus === 'fail' || $responseStatus === 'error') {
            $message = $response['message'] ?? 'Unknown error';

            throw new KotapayException("ProcessorBillingAmounts report failed: {$message}");
        }

        return $this->parseReportResponse($response);
    }

    // =========================================================================
    // Total Company Returns (tcr)
    // =========================================================================

    /**
     * Get the Total Company Returns report from Kotapay.
     *
     * Aggregated return totals across the entire company for the date
     * range. One row per company.
     *
     * @param  string  $startDate  Y-m-d
     * @param  string  $endDate  Y-m-d
     * @return array{rowCount:int, rows:array<int, array<string, mixed>>, raw:array}
     *
     * @throws KotapayException
     */
    public function getTotalCompanyReturns(string $startDate, string $endDate): array
    {
        $resp = $this->runReport('tcr', [
            'startDate' => $startDate.'T00:00:00',
            'endDate' => $endDate.'T23:59:59',
            'isTest' => false,
            'reportFormat' => 'JSON',
        ]);
        $this->assertNotError($resp, 'Total Company Returns');

        return $this->parseReportResponse($resp);
    }

    // =========================================================================
    // Client Address (cad)
    // =========================================================================

    /**
     * Get the Client Address report from Kotapay.
     *
     * One row per company contact with their on-file mailing address.
     * Does NOT require a date range — Kotapay returns the current
     * address roster regardless of dates, but the API still accepts them.
     *
     * @return array{rowCount:int, rows:array<int, array<string, mixed>>, raw:array}
     *
     * @throws KotapayException
     */
    public function getClientAddresses(): array
    {
        $resp = $this->runReport('cad', [
            'isTest' => false,
            'reportFormat' => 'JSON',
        ]);
        $this->assertNotError($resp, 'Client Address');

        return $this->parseReportResponse($resp);
    }

    // =========================================================================
    // Company Applications (cap)
    // =========================================================================

    /**
     * Get the Company Applications report from Kotapay.
     *
     * One row per ACH application (CCD, PPD, WEB, etc.) configured for
     * the company, with batch/entry/daily limits and funding window
     * settings.
     *
     * @return array{rowCount:int, rows:array<int, array<string, mixed>>, raw:array}
     *
     * @throws KotapayException
     */
    public function getCompanyApplications(): array
    {
        $resp = $this->runReport('cap', [
            'isTest' => false,
            'reportFormat' => 'JSON',
        ]);
        $this->assertNotError($resp, 'Company Applications');

        return $this->parseReportResponse($resp);
    }

    // =========================================================================
    // Active Company (car)
    // =========================================================================

    /**
     * Get the Active Company report from Kotapay.
     *
     * Company activity status — last activity date, start date, contract
     * type, TIN verification flag, inactive flag, etc. One row per
     * company on the login.
     *
     * @return array{rowCount:int, rows:array<int, array<string, mixed>>, raw:array}
     *
     * @throws KotapayException
     */
    public function getActiveCompany(): array
    {
        $resp = $this->runReport('car', [
            'isTest' => false,
            'reportFormat' => 'JSON',
        ]);
        $this->assertNotError($resp, 'Active Company');

        return $this->parseReportResponse($resp);
    }

    // =========================================================================
    // Monthly Billing Detail (mbd)
    // =========================================================================

    /**
     * Get the Monthly Billing Detail report from Kotapay.
     *
     * Detailed statement of monthly billing charges. Updated several
     * times a day on the Kotapay side. The endpoint may return a
     * server-side generation error if called outside their daily
     * generation window — the failure is propagated as a KotapayException.
     *
     * @param  string  $startDate  Y-m-d
     * @param  string  $endDate  Y-m-d
     * @return array{rowCount:int, rows:array<int, array<string, mixed>>, raw:array}
     *
     * @throws KotapayException
     */
    public function getMonthlyBillingDetail(string $startDate, string $endDate): array
    {
        $resp = $this->runReport('mbd', [
            'startDate' => $startDate.'T00:00:00',
            'endDate' => $endDate.'T23:59:59',
            'isTest' => false,
            'reportFormat' => 'JSON',
        ]);
        $this->assertNotError($resp, 'Monthly Billing Detail');

        return $this->parseReportResponse($resp);
    }

    // =========================================================================
    // Monthly Billing Summary (mbs)
    // =========================================================================

    /**
     * Get the Monthly Billing Summary report from Kotapay.
     *
     * Summary of monthly billing charges. Updated several times a day.
     * Same caveat as getMonthlyBillingDetail() — Kotapay may return a
     * generation error outside their update window.
     *
     * @param  string  $startDate  Y-m-d
     * @param  string  $endDate  Y-m-d
     * @return array{rowCount:int, rows:array<int, array<string, mixed>>, raw:array}
     *
     * @throws KotapayException
     */
    public function getMonthlyBillingSummary(string $startDate, string $endDate): array
    {
        $resp = $this->runReport('mbs', [
            'startDate' => $startDate.'T00:00:00',
            'endDate' => $endDate.'T23:59:59',
            'isTest' => false,
            'reportFormat' => 'JSON',
        ]);
        $this->assertNotError($resp, 'Monthly Billing Summary');

        return $this->parseReportResponse($resp);
    }

    /**
     * Throw a KotapayException when the API responded with status=fail
     * or status=error. Centralized so every wrapper method handles it
     * the same way.
     *
     * @throws KotapayException
     */
    protected function assertNotError(array $response, string $reportLabel): void
    {
        $status = $response['status'] ?? null;
        if ($status === 'fail' || $status === 'error') {
            $msg = $response['message'] ?? null;
            if ($msg === null || $msg === '') {
                $data = $response['data'] ?? null;
                if (is_array($data) && isset($data[0]['message'])) {
                    $msg = $data[0]['message'];
                } elseif (is_array($data) && isset($data['message'])) {
                    $msg = $data['message'];
                }
            }
            throw new KotapayException("{$reportLabel} report failed: ".($msg ?: 'Unknown error'));
        }
    }

    // =========================================================================
    // Response Parsing
    // =========================================================================

    /**
     * Parse the raw report API response into structured data.
     *
     * The API returns { status, message, data, code } where 'data' may be
     * a JSON string, a CSV string, or an array depending on the report type.
     *
     * @param  array  $response  Raw API response
     * @return array Structured data with 'rowCount' and 'rows' keys
     */
    protected function parseReportResponse(array $response): array
    {
        $data = $response['data'] ?? null;

        // If data is null or empty
        if (empty($data)) {
            return [
                'rowCount' => 0,
                'rows' => [],
                'raw' => $response,
            ];
        }

        // If data is already an array of rows
        if (is_array($data)) {
            // Check if it's a single row (associative array) or multiple rows
            if (isset($data[0]) && is_array($data[0])) {
                return [
                    'rowCount' => count($data),
                    'rows' => $data,
                    'raw' => $response,
                ];
            }

            // Could be a structured response with its own rowCount
            if (isset($data['rowCount'])) {
                return [
                    'rowCount' => $data['rowCount'],
                    'rows' => $data['rows'] ?? [],
                    'raw' => $response,
                ];
            }

            // Single associative array — wrap it
            return [
                'rowCount' => 1,
                'rows' => [$data],
                'raw' => $response,
            ];
        }

        // If data is a string, try to decode it as JSON
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $this->parseReportResponse(['data' => $decoded, 'status' => $response['status'] ?? null]);
            }

            // Could be CSV or another format — return raw
            return [
                'rowCount' => 0,
                'rows' => [],
                'raw_data' => $data,
                'raw' => $response,
            ];
        }

        return [
            'rowCount' => 0,
            'rows' => [],
            'raw' => $response,
        ];
    }
}
