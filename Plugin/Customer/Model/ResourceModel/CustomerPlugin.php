<?php
/**
 * Copyright © 2016 X2i.
 */

namespace Gigya\GigyaIM\Plugin\Customer\Model\ResourceModel;

use Gigya\CmsStarterKit\sdk\GSApiException;
use Gigya\CmsStarterKit\user\GigyaUser;
use Gigya\GigyaIM\Api\GigyaAccountRepositoryInterface;
use Gigya\GigyaIM\Helper\GigyaMageHelper;
use Gigya\GigyaIM\Helper\Mapping\GigyaAccountMapper;
use Magento\Customer\Model\Backend\Customer;

/**
 * CustomerPlugin
 *
 * This plugin take in charge the transactional update of a customer to Gigya and Magento storage.
 *
 * When a Magento Customer entity is to be saved we ensure that the Magento database will be updated only if the data have correctly been forwarded first to the Gigya service.
 *
 * @author      vlemaire <info@x2i.fr>
 *
 * CATODO : Backend error message if Gigya update success but M2 update failed
 *
 */
class CustomerPlugin
{
    /** @var Customer */
    private $customer = null;

    /** @var GigyaUser */
    private $gigyaAccount = null;

    /** @var  GigyaMageHelper */
    protected $gigyaMageHelper;

    /** @var  GigyaAccountMapper */
    protected $gigyaAccountMapper;

    /** @var  GigyaAccountRepositoryInterface */
    protected $gigyaAccountRepository;

    /**
     * CustomerPlugin constructor.
     *
     * @param GigyaMageHelper $gigyaMageHelper
     * @param GigyaAccountMapper $gigyaAccountMapper
     * @param GigyaAccountRepositoryInterface $gigyaAccountRepository
     */
    public function __construct(
        GigyaMageHelper $gigyaMageHelper,
        GigyaAccountMapper $gigyaAccountMapper,
        GigyaAccountRepositoryInterface $gigyaAccountRepository
    )
    {
        $this->customer = null;
        $this->gigyaAccount = null;

        $this->gigyaMageHelper = $gigyaMageHelper;
        $this->gigyaAccountMapper = $gigyaAccountMapper;
        $this->gigyaAccountRepository = $gigyaAccountRepository;
    }

    /**
     * Check if a Magento customer entity's data is to be forwarded to Gigya service.
     *
     * @param Customer $customer
     * @return bool True if the customer is not null, not flagged as deleted, and not flagged has already synchronized.
     */
    protected function shallUpdateGigyaWithMagentoCustomerData($customer)
    {
        return
            $customer != null && !$customer->isDeleted()
            && (empty($customer->getIsSynchronizedToGigya()) || $customer->getIsSynchronizedToGigya() !== true);
    }

    /**
     * Set the value of $this->customer to the customer being saved, for further use.
     *
     * @see \Magento\Customer\Model\ResourceModel\Customer::save()
     *
     * @param \Magento\Customer\Model\ResourceModel\Customer $subject
     * @param Customer $object
     * @return void
     */
    public function beforeSave(
        $subject,
        $object
    ) {
        $this->customer = $object;
    }

    /**
     * Forward to the Gigya service the customer data, if necessary.
     *
     * Forwarding is done if $this->shallUpdateGigyaWithMagentoCustomerData() returns true.
     * Once Gigya service updated on this account, the customer attribute 'is_synchronized_to_gigya' is set to true.
     *
     * @see \Magento\Customer\Model\ResourceModel\Customer::beginTransaction()
     *
     * @param \Magento\Customer\Model\ResourceModel\Customer $subject
     * @param \Magento\Customer\Model\ResourceModel\Customer $result
     * @return \Magento\Customer\Model\ResourceModel\Customer
     */
    public function afterBeginTransaction(
        $subject,
        $result
    ) {
        $this->gigyaAccount = null;

        if ($this->shallUpdateGigyaWithMagentoCustomerData($this->customer)) {
            try {
                $this->gigyaAccount = $this->gigyaAccountMapper->enrichGigyaAccount($this->customer);
                $this->gigyaAccountRepository->update($this->gigyaAccount);
                $this->customer->setIsSynchronizedToGigya(true);
            } catch (\Exception $e) {
                throw $e;
            }
            // For security we set to null the attribute customer. So that if a subsequent nested transaction is opened we don't re sync with Gigya.
            $this->customer = null;
        }

        return $result;
    }
}