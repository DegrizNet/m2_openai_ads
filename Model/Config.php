<?php
/**
 * @author  Anze Voh - Degriz <https://www.degriz.net/>
 * @license MIT
 */
declare(strict_types=1);

namespace Degriz\OpenAiAds\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;

class Config
{
    public const XML_PATH_ENABLED = 'degriz_openaiads/general/enabled';
    public const XML_PATH_PIXEL_ID = 'degriz_openaiads/general/pixel_id';
    public const XML_PATH_DEBUG = 'degriz_openaiads/general/debug';

    /**
     * The events the module can emit, mapped to the data "type" the API expects.
     */
    public const EVENT_TYPES = [
        'page_viewed' => 'contents',
        'contents_viewed' => 'contents',
        'items_added' => 'contents',
        'checkout_started' => 'contents',
        'order_created' => 'contents',
        'registration_completed' => 'customer_action',
        'lead_created' => 'customer_action',
        'search' => 'custom',
    ];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function isEnabled(?int $storeId = null): bool
    {
        return $this->flag(self::XML_PATH_ENABLED, $storeId) && $this->getPixelId($storeId) !== '';
    }

    public function getPixelId(?int $storeId = null): string
    {
        return trim((string) $this->value(self::XML_PATH_PIXEL_ID, $storeId));
    }

    public function isDebug(?int $storeId = null): bool
    {
        return $this->flag(self::XML_PATH_DEBUG, $storeId);
    }

    public function isEventEnabled(string $event, ?int $storeId = null): bool
    {
        return $this->flag('degriz_openaiads/events/' . $event, $storeId);
    }

    /* -------------------------------------------------- matching */

    public function isMatchingEnabled(?int $storeId = null): bool
    {
        return $this->flag('degriz_openaiads/matching/enabled', $storeId);
    }

    public function isExternalIdEnabled(?int $storeId = null): bool
    {
        return $this->flag('degriz_openaiads/matching/external_id', $storeId);
    }

    public function isAddressEnabled(?int $storeId = null): bool
    {
        return $this->flag('degriz_openaiads/matching/address', $storeId);
    }

    /* -------------------------------------------------- conversions api */

    public function isApiEnabled(?int $storeId = null): bool
    {
        return $this->flag('degriz_openaiads/capi/enabled', $storeId) && $this->getApiKey($storeId) !== '';
    }

    public function getApiKey(?int $storeId = null): string
    {
        $key = (string) $this->value('degriz_openaiads/capi/api_key', $storeId);

        return $key === '' ? '' : trim((string) $this->encryptor->decrypt($key));
    }

    public function isValidateOnly(?int $storeId = null): bool
    {
        return $this->flag('degriz_openaiads/capi/validate_only', $storeId);
    }

    public function getApiTimeout(?int $storeId = null): int
    {
        $timeout = (int) $this->value('degriz_openaiads/capi/timeout', $storeId);

        return $timeout > 0 ? $timeout : 5;
    }

    public function isApiEventEnabled(string $event, ?int $storeId = null): bool
    {
        if (!$this->isApiEnabled($storeId)) {
            return false;
        }

        $events = (string) $this->value('degriz_openaiads/capi/events', $storeId);

        return in_array($event, array_map('trim', explode(',', $events)), true);
    }

    /* -------------------------------------------------- consent */

    public function isConsentRequired(?int $storeId = null): bool
    {
        return $this->flag('degriz_openaiads/consent/enabled', $storeId);
    }

    public function getConsentCookieName(?int $storeId = null): string
    {
        return trim((string) $this->value('degriz_openaiads/consent/cookie_name', $storeId));
    }

    public function getConsentCookieValue(?int $storeId = null): string
    {
        return trim((string) $this->value('degriz_openaiads/consent/cookie_value', $storeId));
    }

    private function value(string $path, ?int $storeId): mixed
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }

    private function flag(string $path, ?int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag($path, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
