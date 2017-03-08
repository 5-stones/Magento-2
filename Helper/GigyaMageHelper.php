<?php
/**
 * Gigya IM Helper
 */
namespace Gigya\GigyaIM\Helper;

use \Magento\Framework\App\Helper\AbstractHelper;
use \Magento\Framework\App\Helper\Context;
use \Gigya\GigyaIM\Logger\Logger;
use \Magento\Framework\App\Config\ScopeConfigInterface;
use \Gigya\CmsStarterKit\GigyaApiHelper;
use Magento\Framework\Module\ModuleListInterface;

class GigyaMageHelper extends AbstractHelper
{
    const MODULE_NAME = 'Gigya_GigyaIM';
    private $apiKey;
    private $apiDomain;
    private $appKey;
    private $keyFileLocation;
    private $debug;

    private $appSecret;

    protected $gigyaApiHelper;
    protected $settingsFactory;
    protected $_moduleList;

    public $_logger;

    const CHARS_PASSWORD_LOWERS = 'abcdefghjkmnpqrstuvwxyz';
    const CHARS_PASSWORD_UPPERS = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    const CHARS_PASSWORD_DIGITS = '23456789';
    const CHARS_PASSWORD_SPECIALS = '!$*-.=?@_';

    public function __construct(
        \Gigya\GigyaIM\Model\SettingsFactory $settingsFactory, // virtual class
        Context $context,
        Logger $logger,
        ScopeConfigInterface $scopeConfig,
        ModuleListInterface $moduleList
    ) {
        parent::__construct($context);
        $this->settingsFactory = $settingsFactory;
        $this->_logger = $logger;
        $this->scopeConfig = $scopeConfig;
        $this->setGigyaSettings();
        $this->appSecret = $this->decAppSecret();
        $this->gigyaApiHelper = $this->getGigyaApiHelper();
        $this->_moduleList = $moduleList;
    }

    public function getGigyaApiHelper()
    {
        return new GigyaApiHelper($this->apiKey, $this->appKey, $this->appSecret, $this->apiDomain);
    }

    public function userObjFromArr($userArray)
    {
        $obj = $this->gigyaApiHelper->userObjFromArray($userArray);
        return $obj;
    }

    /**
     * Gigya settings are set in Stores->configuration->Gigya Identity management
     */
    private function setGigyaSettings()
    {
        $settings = $this->scopeConfig->getValue("gigya_section/general");
        $this->apiKey = $settings['api_key'];
        $this->apiDomain = $settings['domain'];
        $this->appKey = $settings['app_key'];
        $this->keyFileLocation = $_SERVER["DOCUMENT_ROOT"] . DIRECTORY_SEPARATOR . $settings['key_file_location'];
        $this->debug = $settings['debug_mode'];
    }

    /**
     * @return string decrypted app secret
     */
    private function decAppSecret()
    {
        // get encrypted app secret from DB
        $settings = $this->settingsFactory->create();
        $settings = $settings->load(1);
        $encrypted_secret = $settings->getData('app_secret');
        if (strlen($encrypted_secret) < 5 ) {
            $this->gigyaLog(__FUNCTION__ . " No valid secret key found in DB.");
        }

        $key = $this->getEncKey();
        $dec = GigyaApiHelper::decrypt($encrypted_secret, $key);
        return $dec;
    }

    /**
     * @return string encryption key from file
     */
    private function getEncKey()
    {
        $key = null;
        if ($this->keyFileLocation != '') {
            if (file_exists($this->keyFileLocation)) {
                $key = file_get_contents($this->keyFileLocation);
            } else {
                $this->gigyaLog(__FUNCTION__
                    . ": Could not find key file as defined in Gigya system config : " . $this->keyFileLocation);
            }
        } else {
            $this->gigyaLog(__FUNCTION__
                . ": KEY_PATH is not set in Gigya system config.");
        }
        return $key;
    }

    /**
     * CMS+Gigya environment params to send with Gigya API request
     * @return array CMS+Gigya environment params tro send with Gigya API request
     */
    protected function createEnvironmentParam() {
        // get Magento version
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $productMetadata = $objectManager->get('Magento\Framework\App\ProductMetadataInterface');
        $magento_version = $productMetadata->getVersion();

        // get Gigya version
        $gigya_version = $this->_moduleList->getOne(self::MODULE_NAME)['setup_version'];

        $org_params = array();
        $org_params["environment"] = "cms_version:Magento_{$magento_version},gigya_version:Gigya_module_{$gigya_version}";
        return $org_params;
    }

    /**
     * @param $UID
     * @param $UIDSignature
     * @param $signatureTimestamp
     * @return bool|\Gigya\CmsStarterKit\user\GigyaUser
     */
    public function validateAndFetchRaasUser($UID, $UIDSignature, $signatureTimestamp)
    {
        $org_params = $this->createEnvironmentParam();
        $valid = $this->gigyaApiHelper->validateUid($UID, $UIDSignature, $signatureTimestamp, null, null, $org_params);
        if (!$valid) {
            $this->gigyaLog(__FUNCTION__ .
                ": Raas user validation failed. make sure to check your gigya config values. including encryption key location, and Database gigya settings");
        }
        return $valid;
    }

    /**
     * @param $gigya_user_account
     * @return array $message (validation errors messages)
     */
    public function verifyGigyaRequiredFields($gigya_user_account)
    {
        $message = [];
        $loginId = $gigya_user_account->getGigyaLoginId();
        if (empty($loginId)) {
            $this->gigyaLog(__FUNCTION__ . "Gigya user does not have email in [loginIDs][emails] array");
            array_push($message, __('Email not supplied. please make sure that your social account provides an email, or contact our support'));
        }
        $profile = $gigya_user_account->getProfile();
        if (!$profile->getFirstName()) {
            $this->gigyaLog(__FUNCTION__ . "Gigya Required field missing - first name. check that your gigya screenset has the correct required fields/complete registration settings.");
            array_push($message, __('Required field missing - first name'));
        }
        if (!$profile->getLastName()) {
            $this->gigyaLog(__FUNCTION__ . "Gigya Required field missing - last name. check that your gigya screenset has the correct required fields/complete registration settings.");
            array_push($message, __('Required field missing - last name'));
        }
        return $message;
    }

    public function generatePassword($len = 8) {
        $chars = self::CHARS_PASSWORD_LOWERS
            . self::CHARS_PASSWORD_UPPERS
            . self::CHARS_PASSWORD_DIGITS
            . self::CHARS_PASSWORD_SPECIALS;
        $str = $this->getRandomString($len, $chars);
        return 'Gigya_' . $str;
    }

    /**
     * Taken from magento 1 helper core
     * @param $length
     * @param $chars
     * @return mixed
     */
    private function getRandomString($len, $chars)
    {
        if (is_null($chars)) {
            $chars = self::CHARS_LOWERS . self::CHARS_UPPERS . self::CHARS_DIGITS;
        }
        for ($i = 0, $str = '', $lc = strlen($chars)-1; $i < $len; $i++) {
            $str .= $chars[mt_rand(0, $lc)];
        }
        return $str;
    }

    public function gigyaLog($message) {
        if ($this->debug) {
            $this->_logger->info($message);
        }
    }

}
