<?php

namespace Router;

use Facebook\WebDriver\Firefox\FirefoxDriver;
use Facebook\WebDriver\Firefox\FirefoxProfile;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;

class Router
{
    const DEFAULT_URL = 'http://192.168.0.1';
    protected $debugging = false;
    /**
     * @var RemoteWebDriver
     */
    protected $webDriver;
    protected $wifiName = '';

    /**
     * @throws \Facebook\WebDriver\Exception\WebDriverException
     */
    public function initWebdriver()
    {
        $profile = new FirefoxProfile();

        $profile->setPreference(
            'profile.accept_untrusted_certs',
            true
        );

        $profile->setPreference(
            'security.insecure_password.ui.enabled',
            false
        );

        $profile->setPreference(
            'security.insecure_field_warning.contextual.enabled',
            false
        );

        $caps = DesiredCapabilities::firefox();
        $caps->setCapability(FirefoxDriver::PROFILE, $profile);

        $this->webDriver = RemoteWebDriver::create('http://localhost:4444/wd/hub', $caps);
    }


    /**
     * @param string $name
     * @param string $status
     */
    public function debug($name = '', $status = '')
    {
        if ($this->debugging) {
            printf("\033[0;31mDebug:\033[0m %s part: %s\n", $name, $status);
        }
    }

    /**
     * Generate random password.
     *
     * @param int $charLength
     *
     * @return string
     */
    public function generatePassword($charLength = 12)
    {
        $char = "abcefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890-";

        return substr(str_shuffle($char), 0, $charLength);
    }

    /**
     * Setting wifi name
     *
     * @param $wifiName
     *
     * @return $this
     */
    public function setWlanName($wifiName)
    {
        $this->wifiName = $wifiName;

        return $this;
    }
}

?>
