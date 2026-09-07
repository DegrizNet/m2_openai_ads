<?php
/**
 * Support notice shown at the top of the module configuration.
 *
 * @author  Anze Voh - Degriz <https://www.degriz.net/>
 * @license MIT
 */
declare(strict_types=1);

namespace Degriz\OpenAiAds\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Module\ModuleListInterface;

class Support extends Field
{
    public function __construct(
        Context $context,
        private readonly ModuleListInterface $moduleList,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        return '';
    }

    public function render(AbstractElement $element): string
    {
        $module = $this->moduleList->getOne('Degriz_OpenAiAds');
        $version = $module['setup_version'] ?? '';

        return '<tr><td colspan="4" style="padding:0 0 12px;">'
            . '<div style="border:1px solid #d6d6d6;border-left:4px solid #1a7f64;'
            . 'background:#f7f7f7;padding:12px 15px;line-height:1.6;">'
            . '<strong>' . $this->escapeHtml(__('OpenAI Ads Pixel for Magento 2')) . '</strong> '
            . '<span style="color:#777;">v' . $this->escapeHtml($version) . '</span><br/>'
            . $this->escapeHtml(
                __('Measurement pixel and Conversions API for ChatGPT advertising. Free and open source.')
            )
            . '<br/><br/>'
            . __(
                'Technical support, custom Magento development and setup: %1',
                '<a href="https://www.degriz.net/" target="_blank" rel="noopener">'
                . '<strong>Degriz &ndash; Magento eCommerce Development</strong></a>'
            )
            . '<br/>'
            . __(
                'Documentation and issues: %1',
                '<a href="https://github.com/DegrizNet/m2_openai_ads" target="_blank" rel="noopener">'
                . 'github.com/DegrizNet/m2_openai_ads</a>'
            )
            . '</div></td></tr>';
    }
}
