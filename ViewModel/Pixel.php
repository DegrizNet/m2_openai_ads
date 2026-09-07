<?php
/**
 * Everything the pixel template needs.
 *
 * Only public, page-specific information is exposed here, so the block stays
 * cacheable. Anything belonging to one visitor comes from the data controller.
 *
 * @author  Anze Voh - Degriz <https://www.degriz.net/>
 * @license MIT
 */
declare(strict_types=1);

namespace Degriz\OpenAiAds\ViewModel;

use Degriz\OpenAiAds\Model\Config;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Search\Model\QueryFactory;

class Pixel implements ArgumentInterface
{
    /** Present from Magento 2.4.7 onwards, and on every Hyvä install. */
    private const NONCE_PROVIDER = \Magento\Csp\Helper\CspNonceProvider::class;

    public function __construct(
        private readonly Config $config,
        private readonly Json $json,
        private readonly UrlInterface $url,
        private readonly Registry $registry,
        private readonly RequestInterface $request,
        private readonly QueryFactory $queryFactory,
        private readonly ObjectManagerInterface $objectManager
    ) {
    }

    /**
     * CSP nonce for the inline snippet, so the pixel survives a strict policy
     * (Hyvä ships one). Empty when the store has no CSP nonce provider.
     */
    public function getCspNonce(): string
    {
        if (!class_exists(self::NONCE_PROVIDER)) {
            return '';
        }

        try {
            return (string) $this->objectManager->get(self::NONCE_PROVIDER)->generateNonce();
        } catch (\Exception $e) {
            return '';
        }
    }

    public function isEnabled(): bool
    {
        return $this->config->isEnabled();
    }

    public function getConfigJson(): string
    {
        return $this->json->serialize([
            'pixelId' => $this->config->getPixelId(),
            'debug' => $this->config->isDebug(),
            'url' => $this->url->getUrl('openaiads/data/index'),
            'consent' => [
                'required' => $this->config->isConsentRequired(),
                'cookie' => $this->config->getConsentCookieName(),
                'value' => $this->config->getConsentCookieValue(),
            ],
        ]);
    }

    /**
     * Page context the data controller cannot work out on its own.
     */
    public function getContextJson(string $pageType = ''): string
    {
        $context = ['page_type' => $pageType];

        if ($pageType === 'product') {
            $product = $this->registry->registry('current_product');
            if ($product && $product->getId()) {
                $context['product_id'] = (int) $product->getId();
            }
        }

        if ($pageType === 'search') {
            try {
                $context['query'] = (string) $this->queryFactory->get()->getQueryText();
            } catch (\Exception $e) {
                $context['query'] = '';
            }
        }

        return $this->json->serialize($context);
    }
}
