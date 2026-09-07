<?php
/**
 * @author  Anze Voh - Degriz <https://www.degriz.net/>
 * @license MIT
 */
declare(strict_types=1);

namespace Degriz\OpenAiAds\Model;

class Money
{
    /**
     * ISO 4217 exponents that are not 2. Everything else uses cents.
     */
    private const EXPONENTS = [
        'BIF' => 0, 'CLP' => 0, 'DJF' => 0, 'GNF' => 0, 'JPY' => 0, 'KMF' => 0,
        'KRW' => 0, 'MGA' => 0, 'PYG' => 0, 'RWF' => 0, 'UGX' => 0, 'VND' => 0,
        'VUV' => 0, 'XAF' => 0, 'XOF' => 0, 'XPF' => 0,
        'BHD' => 3, 'IQD' => 3, 'JOD' => 3, 'KWD' => 3, 'LYD' => 3, 'OMR' => 3, 'TND' => 3,
    ];

    /**
     * Money to the integer minor units the API wants.
     */
    public function toMinorUnits(float|int|string|null $amount, string $currency): int
    {
        $exponent = self::EXPONENTS[strtoupper($currency)] ?? 2;

        return (int) round((float) $amount * (10 ** $exponent));
    }
}
