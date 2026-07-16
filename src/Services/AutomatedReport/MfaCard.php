<?php

declare(strict_types=1);

namespace FoleyBridgeSolutions\KotapayCashier\Services\AutomatedReport;

use FoleyBridgeSolutions\KotapayCashier\Exceptions\KotapayException;

/**
 * Kotapay automated-report multifactor "grid card" resolver.
 *
 * The Kotapay automated report API ({@see AutomatedReportClient}) authenticates
 * with a printed coordinate card. The first authentication hit returns three
 * challenge coordinates (e.g. "A1", "B2", "C3"); the caller must look each up
 * on the card and return the value at that cell on the second hit.
 *
 * A card has 10 columns (A-J) and 5 rows (1-5). Example layout:
 *
 *        A B C D E F G H I J
 *     1  V H 9 N F Q H 2 9 C
 *     2  2 P 2 1 8 8 D 2 1 4
 *     3  1 3 2 R J D P M T X
 *     4  N T 6 8 E D T 3 3 Y
 *     5  7 J Y K M 2 0 D W 6
 *
 * With that card, resolve("A1") === "V", resolve("B2") === "P",
 * resolve("C3") === "2".
 *
 * The card is an authentication secret and must never be committed; it is
 * supplied via the KOTAPAY_AUTOREPORT_MFA_CARD env var (JSON).
 */
final class MfaCard
{
    /** Valid column letters, in order. */
    public const COLUMNS = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J'];

    /** Valid row numbers, in order. */
    public const ROWS = ['1', '2', '3', '4', '5'];

    /**
     * @param  array<string,array<string,string>>  $grid  Map of row => (column => value).
     */
    public function __construct(private readonly array $grid)
    {
    }

    /**
     * Build a card from the JSON string stored in config/env.
     *
     * Expected shape: {"1":{"A":"V","B":"H",...,"J":"C"}, ... ,"5":{...}}
     *
     * @throws KotapayException If the JSON is missing or malformed.
     */
    public static function fromJson(?string $json): self
    {
        if ($json === null || trim($json) === '') {
            throw new KotapayException('Kotapay MFA card is not configured (KOTAPAY_AUTOREPORT_MFA_CARD).');
        }

        $decoded = json_decode($json, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            throw new KotapayException('Kotapay MFA card is not valid JSON.');
        }

        return self::fromArray($decoded);
    }

    /**
     * Build a card from a decoded grid array, normalizing keys.
     *
     * Accepts either row-major ({"1":{"A":..}}) input. Column and row keys are
     * upper-cased / string-cast so callers may pass loose types.
     *
     * @param  array<int|string,array<int|string,string>>  $grid
     *
     * @throws KotapayException
     */
    public static function fromArray(array $grid): self
    {
        $normalized = [];

        foreach ($grid as $row => $columns) {
            $rowKey = (string) $row;
            if (! in_array($rowKey, self::ROWS, true)) {
                throw new KotapayException("Kotapay MFA card has an invalid row '{$rowKey}'.");
            }
            if (! is_array($columns)) {
                throw new KotapayException("Kotapay MFA card row '{$rowKey}' must be an object of column => value.");
            }
            foreach ($columns as $col => $value) {
                $colKey = strtoupper((string) $col);
                if (! in_array($colKey, self::COLUMNS, true)) {
                    throw new KotapayException("Kotapay MFA card has an invalid column '{$colKey}'.");
                }
                $normalized[$rowKey][$colKey] = (string) $value;
            }
        }

        return new self($normalized);
    }

    /**
     * Resolve a challenge coordinate (e.g. "A1") to its card value.
     *
     * Coordinates are case-insensitive and may be given column-first ("A1")
     * or row-first ("1A").
     *
     * @throws KotapayException If the coordinate is malformed or the cell is empty.
     */
    public function resolve(string $coordinate): string
    {
        $coordinate = strtoupper(trim($coordinate));

        if (! preg_match('/^(?:([A-J])([1-5])|([1-5])([A-J]))$/', $coordinate, $m)) {
            throw new KotapayException("Invalid Kotapay MFA challenge coordinate '{$coordinate}'.");
        }

        // Column-first ("A1") populates $m[1]=column, $m[2]=row.
        // Row-first ("1A") populates $m[3]=row, $m[4]=column.
        $column = $m[1] !== '' ? $m[1] : $m[4];
        $row = $m[2] !== '' ? $m[2] : $m[3];

        $value = $this->grid[$row][$column] ?? null;
        if ($value === null || $value === '') {
            throw new KotapayException("Kotapay MFA card has no value at coordinate '{$column}{$row}'.");
        }

        return $value;
    }

    /**
     * Resolve a list of challenge coordinates to their values, in order.
     *
     * @param  array<int,string>  $coordinates
     * @return array<int,string>
     *
     * @throws KotapayException
     */
    public function resolveAll(array $coordinates): array
    {
        return array_map(fn (string $c): string => $this->resolve($c), $coordinates);
    }
}
