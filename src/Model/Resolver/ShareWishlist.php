<?php

/**
 * @category    ScandiPWA
 * @package     ScandiPWA_WishlistGraphQl
 * @copyright   Copyright © Scandiweb, Inc. All rights reserved.
 * @copyright   Modifications © Selveq. All rights reserved.
 * @license     OSL-3.0 (Open Software License ("OSL") v. 3.0)
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace ScandiPWA\WishlistGraphQl\Model\Resolver;

use Laminas\Validator\EmailAddress;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\State;
use Magento\Framework\Escaper;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Registry;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\LayoutFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Wishlist\Model\Config as WishlistConfig;
use Magento\Wishlist\Model\Validator\MessageValidator;
use Magento\Wishlist\Model\Wishlist;
use ScandiPWA\WishlistGraphQl\Model\WishlistLoader;

class ShareWishlist implements ResolverInterface
{
    /**
     * @param Escaper $escaper
     * @param UrlInterface $url
     * @param LayoutFactory $layoutFactory
     * @param ScopeConfigInterface $scopeConfig
     * @param TransportBuilder $transportBuilder
     * @param StoreManagerInterface $storeManager
     * @param CustomerRepositoryInterface $customerRepository
     * @param EmailAddress $emailValidator
     * @param MessageValidator $messageValidator
     * @param WishlistConfig $wishlistConfig
     * @param WishlistLoader $wishlistLoader
     * @param State $appState
     * @param Registry $registry
     */
    public function __construct(
        private readonly Escaper $escaper,
        private readonly UrlInterface $url,
        private readonly LayoutFactory $layoutFactory,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly TransportBuilder $transportBuilder,
        private readonly StoreManagerInterface $storeManager,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly EmailAddress $emailValidator,
        private readonly MessageValidator $messageValidator,
        private readonly WishlistConfig $wishlistConfig,
        private readonly WishlistLoader $wishlistLoader,
        private readonly State $appState,
        private readonly Registry $registry
    ) {}

    /**
     * {@inheritdoc}
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        $customerId = (int)$context->getUserId();

        if (!$customerId) {
            throw new GraphQlAuthorizationException(__('Authorization unsuccessful'));
        }

        // a customer who never saved an item has no row and no sharing code, so the list is created here
        $wishlist = $this->wishlistLoader->loadByCustomer($customerId, true);
        $emails = $this->validEmails($args['input']['emails'] ?? [], $wishlist);
        $message = $this->validMessage((string)($args['input']['message'] ?? ''));

        $customer = $this->customerRepository->getById($customerId);
        $customerName = $customer->getFirstname() . ' ' . $customer->getLastname();
        $items = $this->getWishlistItems($wishlist);
        $sent = 0;

        try {
            foreach ($emails as $email) {
                $transport = $this->transportBuilder->setTemplateIdentifier(
                    $this->scopeConfig->getValue('wishlist/email/email_template', ScopeInterface::SCOPE_STORE)
                )->setTemplateOptions(
                    [
                        'area' => Area::AREA_FRONTEND,
                        'store' => $this->storeManager->getStore()->getId(),
                    ]
                )->setTemplateVars(
                    [
                        'customer' => $customer,
                        'customerName' => $customerName,
                        'salable' => $wishlist->isSalable() ? 'yes' : '',
                        'items' => $items,
                        'viewOnSiteLink' => $this->getWebsiteLink((string)$wishlist->getSharingCode()),
                        'message' => $message,
                        'store' => $this->storeManager->getStore(),
                    ]
                )->setFromByScope(
                    // SenderResolver reads trans_email/ident_<code> for the store id it is given, never a scope
                    $this->scopeConfig->getValue('wishlist/email/email_identity', ScopeInterface::SCOPE_STORE),
                    $this->storeManager->getStore()->getId()
                )->addTo(
                    $email
                )->getTransport();

                $transport->sendMessage();
                $sent++;
            }
        } finally {
            $wishlist->setShared($wishlist->getShared() + $sent);
            $wishlist->save();
        }

        return true;
    }

    /**
     * every address is checked before the first message goes out, and the call is refused when it
     * would take the list past the allowance core counts in the `shared` column
     * @param array $emails
     * @param Wishlist $wishlist
     * @return string[]
     * @throws GraphQlInputException
     */
    protected function validEmails(array $emails, Wishlist $wishlist): array
    {
        $emails = array_map(static fn($email) => trim((string)$email), $emails);

        if (!$emails) {
            throw new GraphQlInputException(__('Please enter an email address.'));
        }

        $emailsLeft = (int)$this->wishlistConfig->getSharingEmailLimit() - (int)$wishlist->getShared();

        if (count($emails) > $emailsLeft) {
            throw new GraphQlInputException(__('Maximum of %1 emails can be sent.', $emailsLeft));
        }

        foreach ($emails as $email) {
            if (!$this->emailValidator->isValid($email)) {
                throw new GraphQlInputException(__('Please enter a valid email address.'));
            }
        }

        return array_unique($emails);
    }

    /**
     * @param string $message
     * @return string
     * @throws GraphQlInputException
     */
    protected function validMessage(string $message): string
    {
        if (!$this->messageValidator->isValid($message)) {
            throw new GraphQlInputException(
                __('Invalid content detected in message. Please remove any special codes or scripts.')
            );
        }

        $textLimit = (int)$this->wishlistConfig->getSharingTextLimit();

        if (strlen($message) > $textLimit) {
            throw new GraphQlInputException(__('Message length must not exceed %1 symbols', $textLimit));
        }

        return nl2br($this->escaper->escapeHtml($message));
    }

    /**
     * the items table the mail template prints, rendered once per call rather than once per recipient
     * @param Wishlist $wishlist
     * @return string
     * @throws LocalizedException
     */
    protected function getWishlistItems(Wishlist $wishlist): string
    {
        // Wishlist\Helper\Data reads the list to print from this registry key, as core's shared pages do
        $this->registry->unregister('shared_wishlist');
        $this->registry->register('shared_wishlist', $wishlist);

        try {
            return (string)$this->appState->emulateAreaCode(
                Area::AREA_FRONTEND,
                function (): string {
                    $layout = $this->layoutFactory->create();
                    $layout->getUpdate()->load(['wishlist_email_items']);
                    $layout->generateXml();
                    $layout->generateElements();
                    $block = $layout->getBlock('wishlist.email.items');

                    return $block ? $block->toHtml() : '';
                }
            );
        } finally {
            $this->registry->unregister('shared_wishlist');
        }
    }

    /**
     * @param string $sharingCode
     * @return string
     */
    protected function getWebsiteLink(string $sharingCode): string
    {
        return $this->url->getBaseUrl() . "wishlist/shared/$sharingCode";
    }
}
