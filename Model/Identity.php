<?php
/**
 * Normalises and hashes the customer data used for conversion matching.
 *
 * Hashing happens here, in PHP. Raw e-mail addresses, phone numbers and names
 * never reach the browser or the API.
 *
 * @author  Anze Voh - Degriz <https://www.degriz.net/>
 * @license MIT
 */
declare(strict_types=1);

namespace Degriz\OpenAiAds\Model;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Math\Random;
use Magento\Framework\Stdlib\Cookie\CookieMetadataFactory;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Sales\Model\OrderFactory;
use Magento\Store\Model\StoreManagerInterface;

class Identity
{
    public const EXTERNAL_ID_COOKIE = 'oaiq_eid';
    public const OPPREF_COOKIE = '__oppref';
    public const OBREF_COOKIE = '__obref';

    private const COOKIE_LIFETIME = 31536000;

    /**
     * Calling codes for the countries this module is realistically deployed in.
     * Unknown countries simply get no prefix.
     */
    private const CALLING_CODES = [
        'SI' => '386', 'HR' => '385', 'AT' => '43', 'DE' => '49', 'IT' => '39',
        'NL' => '31', 'BE' => '32', 'FR' => '33', 'ES' => '34', 'HU' => '36',
        'RS' => '381', 'BA' => '387', 'MK' => '389', 'GB' => '44', 'US' => '1',
        'CH' => '41', 'CZ' => '420', 'SK' => '421', 'PL' => '48', 'DK' => '45',
        'SE' => '46', 'NO' => '47', 'FI' => '358', 'IE' => '353', 'PT' => '351',
    ];

    public function __construct(
        private readonly Config $config,
        private readonly CustomerSession $customerSession,
        private readonly CheckoutSession $checkoutSession,
        private readonly OrderFactory $orderFactory,
        private readonly CookieManagerInterface $cookieManager,
        private readonly CookieMetadataFactory $cookieMetadataFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly HttpRequest $request,
        private readonly Random $random
    ) {
    }

    /**
     * Hashed data for the pixel init call. Singular keys, as the SDK expects.
     *
     * @return array<string, string>
     */
    public function getPixelUserData(?OrderInterface $order = null): array
    {
        if (!$this->config->isMatchingEnabled()) {
            return [];
        }

        $source = $this->collect($order);
        $user = [];

        foreach (['email', 'phone_number', 'external_id', 'first_name', 'last_name'] as $key) {
            if (!empty($source[$key])) {
                $user[$key . '_sha256'] = $source[$key];
            }
        }

        foreach (['country', 'city', 'region', 'postal_code'] as $key) {
            if (!empty($source[$key])) {
                $user[$key] = $source[$key];
            }
        }

        return $user;
    }

    /**
     * The same identity, shaped for the Conversions API: hashed fields are
     * arrays, and the request carries IP and user agent as well.
     *
     * @return array<string, mixed>
     */
    public function getApiUserData(?OrderInterface $order = null): array
    {
        $source = $this->collect($order);
        $user = [];

        $hashed = [
            'email' => 'emails_sha256',
            'phone_number' => 'phone_numbers_sha256',
            'external_id' => 'external_ids_sha256',
            'first_name' => 'first_names_sha256',
            'last_name' => 'last_names_sha256',
        ];

        foreach ($hashed as $key => $apiKey) {
            if (!empty($source[$key])) {
                $user[$apiKey] = [$source[$key]];
            }
        }

        $plain = [
            'city' => 'cities',
            'region' => 'regions',
            'postal_code' => 'postal_codes',
            'country' => 'countries',
        ];

        foreach ($plain as $key => $apiKey) {
            if (!empty($source[$key])) {
                $user[$apiKey] = [$source[$key]];
            }
        }

        $ip = $this->request->getClientIp();
        if ($ip) {
            $user['ip_address'] = $ip;
        }

        $userAgent = $this->request->getHeader('User-Agent');
        if ($userAgent) {
            $user['user_agent'] = (string) $userAgent;
        }

        $obref = $this->cookieManager->getCookie(self::OBREF_COOKIE);
        if ($obref) {
            $user['obref'] = $obref;
        }

        return $user;
    }

    /**
     * Pulls whatever identity the request has: the order first, then the
     * logged in customer, then the quote, then the order placed this session.
     *
     * @return array<string, string>
     */
    private function collect(?OrderInterface $order = null): array
    {
        $email = null;
        $first = null;
        $last = null;
        $address = null;

        if ($order !== null) {
            $email = $order->getCustomerEmail();
            $first = $order->getCustomerFirstname();
            $last = $order->getCustomerLastname();
            $address = $order->getBillingAddress();
        } elseif ($this->customerSession->isLoggedIn()) {
            $customer = $this->customerSession->getCustomer();
            $email = $customer->getEmail();
            $first = $customer->getFirstname();
            $last = $customer->getLastname();
            $address = $customer->getDefaultBillingAddress() ?: null;
        } else {
            try {
                $quote = $this->checkoutSession->getQuote();
                if ($quote->getId()) {
                    $email = $quote->getCustomerEmail();
                    $first = $quote->getCustomerFirstname();
                    $last = $quote->getCustomerLastname();
                    $address = $quote->getBillingAddress();
                }
            } catch (\Exception $e) {
                $address = null;
            }
        }

        // On the success page the quote is already gone, so the order placed in
        // this session is the only identity left to match on.
        if (!$email) {
            $placed = $this->getLastPlacedOrder();
            if ($placed !== null) {
                $email = $placed->getCustomerEmail();
                $first = $placed->getCustomerFirstname();
                $last = $placed->getCustomerLastname();
                $address = $placed->getBillingAddress();
            }
        }

        if ($address !== null) {
            $first = $first ?: $address->getFirstname();
            $last = $last ?: $address->getLastname();
        }

        $identity = [
            'email' => $this->hashEmail($email),
            'first_name' => $this->hashName($first),
            'last_name' => $this->hashName($last),
        ];

        if ($address !== null) {
            $identity['phone_number'] = $this->hashPhone(
                (string) $address->getTelephone(),
                (string) $address->getCountryId()
            );

            if ($this->config->isAddressEnabled()) {
                $identity['city'] = (string) $address->getCity();
                $identity['region'] = (string) ($address->getRegion() ?: '');
                $identity['postal_code'] = (string) $address->getPostcode();
                $identity['country'] = (string) $address->getCountryId();
            }
        }

        $externalId = $this->getExternalId();
        if ($externalId !== null) {
            $identity['external_id'] = $this->hash($externalId);
        }

        return array_filter($identity, static fn ($value): bool => $value !== null && $value !== '');
    }

    public function getLastPlacedOrder(): ?OrderInterface
    {
        try {
            $incrementId = $this->checkoutSession->getLastRealOrderId();
        } catch (\Exception $e) {
            return null;
        }

        if (!$incrementId) {
            return null;
        }

        $order = $this->orderFactory->create()->loadByIncrementId((string) $incrementId);

        return $order->getId() ? $order : null;
    }

    /**
     * A stable identifier for the visitor, so repeat sessions and the
     * server-side events line up with the same person.
     */
    public function getExternalId(): ?string
    {
        if (!$this->config->isExternalIdEnabled()) {
            return null;
        }

        if ($this->customerSession->isLoggedIn()) {
            return 'customer-' . $this->customerSession->getCustomerId();
        }

        $value = $this->cookieManager->getCookie(self::EXTERNAL_ID_COOKIE);
        if ($value) {
            return $value;
        }

        $value = $this->random->getRandomString(32);
        $this->setCookie(self::EXTERNAL_ID_COOKIE, $value, self::COOKIE_LIFETIME);

        return $value;
    }

    /**
     * Server-side view of the same consent check the pixel does in the browser.
     * A Conversions API call is a marketing call like any other.
     */
    public function hasConsent(): bool
    {
        if (!$this->config->isConsentRequired()) {
            return true;
        }

        $name = $this->config->getConsentCookieName();
        if ($name === '') {
            return true;
        }

        $cookie = (string) $this->cookieManager->getCookie($name);
        if ($cookie === '') {
            return false;
        }

        $expected = $this->config->getConsentCookieValue();

        return $expected === '' || str_contains($cookie, $expected);
    }

    /**
     * Attribution reference the SDK drops in a first-party cookie.
     */
    public function getOppref(): ?string
    {
        return $this->cookieManager->getCookie(self::OPPREF_COOKIE) ?: null;
    }

    public function setCookie(string $name, string $value, int $lifetime): void
    {
        try {
            $metadata = $this->cookieMetadataFactory->createPublicCookieMetadata()
                ->setDuration($lifetime)
                ->setPath('/')
                ->setHttpOnly(false)
                ->setSecure($this->storeManager->getStore()->isCurrentlySecure())
                ->setSameSite('Lax');

            $this->cookieManager->setPublicCookie($name, $value, $metadata);
        } catch (\Exception $e) {
            // A cookie that cannot be set is not worth breaking a page for.
        }
    }

    /* -------------------------------------------------- hashing */

    /**
     * SHA-256 of an already normalised value. Values that are plainly hashes
     * are passed through, so nothing gets hashed twice.
     */
    public function hash(?string $value): ?string
    {
        $value = (string) $value;
        if ($value === '') {
            return null;
        }

        if (preg_match('/^[a-f0-9]{64}$/i', $value)) {
            return strtolower($value);
        }

        return hash('sha256', $value);
    }

    public function hashEmail(?string $email): ?string
    {
        $email = strtolower(trim((string) $email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $this->hash($email) : null;
    }

    /**
     * 8-15 digits, no plus sign, no leading zeros, no punctuation.
     */
    public function hashPhone(?string $phone, ?string $countryId = null): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $phone) ?? '';
        if ($digits === '') {
            return null;
        }

        $digits = ltrim($digits, '0');

        // A local number needs its country calling code to be matchable.
        if (strlen($digits) < 11 && $countryId !== null && isset(self::CALLING_CODES[$countryId])) {
            $prefix = self::CALLING_CODES[$countryId];
            if (!str_starts_with($digits, $prefix)) {
                $digits = $prefix . $digits;
            }
        }

        $length = strlen($digits);

        return ($length >= 8 && $length <= 15) ? $this->hash($digits) : null;
    }

    public function hashName(?string $name): ?string
    {
        $name = strtolower(trim((string) $name));
        $name = preg_replace('/[\s!"#$%&\'()*+,\-.\/:;<=>?@\[\\\\\]^_`{|}~]+/u', '', $name) ?? '';

        return $name === '' ? null : $this->hash($name);
    }
}
