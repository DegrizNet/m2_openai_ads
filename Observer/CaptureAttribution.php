<?php
/**
 * @author  Anze Voh - Degriz <https://www.degriz.net/>
 * @license MIT
 */
declare(strict_types=1);

namespace Degriz\OpenAiAds\Observer;

use Degriz\OpenAiAds\Model\Config;
use Degriz\OpenAiAds\Model\Identity;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * Keeps the attribution reference from the ad click for the whole session,
 * including the server-side calls the SDK cannot make.
 */
class CaptureAttribution implements ObserverInterface
{
    private const LIFETIME = 7776000;

    public function __construct(
        private readonly Config $config,
        private readonly Identity $identity,
        private readonly RequestInterface $request
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled()) {
            return;
        }

        $params = [
            'oppref' => Identity::OPPREF_COOKIE,
            'obref' => Identity::OBREF_COOKIE,
        ];

        foreach ($params as $param => $cookie) {
            $value = $this->request->getParam($param);
            if (is_string($value) && $value !== '') {
                $this->identity->setCookie($cookie, $value, self::LIFETIME);
            }
        }
    }
}
