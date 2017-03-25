<?php

namespace Router;

use Facebook\WebDriver\Exception\ElementNotVisibleException;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Facebook\WebDriver\Remote\WebDriverCapabilityType;
use Facebook\WebDriver\WebDriverBy;

class Tplink
{
    const DEBUG = false;
    const BROWSER = 'firefox';
    const MODEL = 'TL-WR940N';
    /**
     * @var RemoteWebDriver
     */
    protected $webDriver;
    protected $url = 'http://192.168.0.1';
    protected $wifiName = 'BLEBLE';
    protected $wifiPassword = 'password';
    private $mainFrameId = null;
    private $bottomLeftFrameId = null;

    /**
     * Tplink constructor.
     */
    public function __construct()
    {
        $capabilities = array(WebDriverCapabilityType::BROWSER_NAME => self::BROWSER);
        $this->webDriver = RemoteWebDriver::create('http://localhost:4444/wd/hub', $capabilities);
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

    /**
     * Main
     */
    public function main()
    {
        $this->webDriver->get($this->url);
        $this->login();

        // generating password
        $this->wifiPassword = $this->generatePassword(15);


        $this->disableWPS();
        $this->configureWAN();
        $this->configureWLAN();
        $this->setWifiPassword($this->wifiPassword);
        $this->enableRemoteManagement();
        $this->configureTimeSettings('0.uk.pool.ntp.org');
        $this->changePassword('admin', 'admin', 'admin', 'pa55w0rd');
        $this->login('admin', 'pa55w0rd');
        // rebooting to be sure config is saved
        $this->reboot();

        printf(
            "\033[0;31mResult:\033[0m: \nWireless name: %s\nWireless Password: %s\n",
            $this->wifiName,
            $this->wifiPassword
        );

    }

    /**
     * Logging into the router
     *
     * @param string $login
     * @param string $password
     */
    public function login($login = 'admin', $password = 'admin')
    {
        $this->webDriver->findElement(WebDriverBy::id('userName'))->clear()->sendKeys($login);
        $this->webDriver->findElement(WebDriverBy::id('pcPassword'))->clear()->sendKeys($password);
        $this->webDriver->findElement(WebDriverBy::id('loginBtn'))->click();

        sleep(2);
        $this->mainFrameId = $this->webDriver->findElement(WebDriverBy::id('mainFrame'));
        $this->bottomLeftFrameId = $this->webDriver->findElement(WebDriverBy::id('bottomLeftFrame'));
    }

    /**
     * Generate random password.
     *
     * @param int $char
     *
     * @return string
     */
    public function generatePassword($charLength = 12)
    {
        $char = "abcefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890-";

        return substr(str_shuffle($char), 0, $charLength);
    }

    /**
     * Disable WPS
     */
    public function disableWPS()
    {
        $menu = $this->webDriver->switchTo()->frame($this->bottomLeftFrameId);
        $menu->findElement(WebDriverBy::linkText('WPS'))->click();
        sleep(1);
        $this->webDriver->switchTo()->defaultContent();
        $centerFrame = $this->webDriver->switchTo()->frame($this->mainFrameId);

        try {
            $disableWPS = $centerFrame->findElement(WebDriverBy::name('DisWps'))->click();
        } catch (ElementNotVisibleException $ex) {
            printf("%s: Got exception possibly already disabled, error: %s", __FUNCTION__, $ex->getMessage());
        }
        sleep(2);
        $this->webDriver->switchTo()->defaultContent();
    }

    /**
     * Configuring WAN
     */
    public function configureWAN()
    {
        $this->debug(__FUNCTION__, 'start');
        $menu = $this->webDriver->switchTo()->frame($this->bottomLeftFrameId);
        $menu->findElement(WebDriverBy::linkText('Network'))->click();
        sleep(1);
        $this->webDriver->switchTo()->defaultContent();
        $centerFrame = $this->webDriver->switchTo()->frame($this->mainFrameId);

        $select = $centerFrame->findElement(WebDriverBy::name('wantype'));
        $staticIp = $select->findElement(WebDriverBy::id('t_stat_ip'));
        if (!$staticIp->isSelected()) {
            $staticIp->click();
        }
        // wait for load page...
        sleep(1);
        $centerFrame->findElement(WebDriverBy::name('ip'))->clear()->sendKeys('192.168.3.2');
        $centerFrame->findElement(WebDriverBy::name('mask'))->clear()->sendKeys('255.255.255.0');
        $centerFrame->findElement(WebDriverBy::name('gateway'))->clear()->sendKeys('192.168.3.1');
        $centerFrame->findElement(WebDriverBy::name('dnsserver'))->clear()->sendKeys('8.8.8.8');
        $centerFrame->findElement(WebDriverBy::name('dnsserver2'))->clear()->sendKeys('8.8.4.4');
        $centerFrame->findElement(WebDriverBy::name('Save'))->click();
        $this->webDriver->switchTo()->defaultContent();
        // give some time for save...
        sleep(2);
        $this->debug(__FUNCTION__, 'done');
    }

    /**
     * @param string $name
     * @param string $status
     */
    public function debug($name = '', $status = '')
    {
        if (self::DEBUG) {
            printf("\033[0;31mDebug:\033[0m %s part: %s\n", $name, $status);
        }
    }

    /**
     * Configure WLAN.
     */
    public function configureWLAN()
    {
        $this->debug(__FUNCTION__, 'start');
        $menu = $this->webDriver->switchTo()->frame($this->bottomLeftFrameId);
        $menu->findElement(WebDriverBy::linkText('Wireless'))->click();
        sleep(1);
        $this->webDriver->switchTo()->defaultContent();
        $centerFrame = $this->webDriver->switchTo()->frame($this->mainFrameId);
        $centerFrame->findElement(WebDriverBy::id('ssid1'))->clear()->sendKeys($this->wifiName);

        /**
         * Change channel width to 20mhz
         * values = [
         *  0 => auto, // not sure!
         *  1 => 20mhz,
         *  2 => 40mhz
         * ];
         *
         */
        $channelWidth = $centerFrame->findElement(
            WebDriverBy::cssSelector('select[name="chanWidth"] > option[value="1"]')
        );
        // select if is not selected! Sometimes selecting wrong, that fix issue.
        if (!$channelWidth->isSelected()) {
            $channelWidth->click();
        }
        /**
         * disable auto channel and select 3, 7 or 11
         * before that make some delay 0.5sec to run js.
         *
         * chan 15 = auto?
         *
         */
        $channels = ['3', '7', '11'];
        $selected = array_rand($channels, 1);
        usleep(500000);
        $channel = $centerFrame->findElement(
            WebDriverBy::cssSelector('select[name="channel"] > option[value="'.$channels[$selected].'"]')
        );
        // select if is not selected! Sometimes selecting wrong, that fix issue.
        if (!$channel->isSelected()) {
            $channel->click();
        }
        $centerFrame->findElement(WebDriverBy::name('Save'))->click();
        $this->webDriver->switchTo()->defaultContent();
        // give some time for save...
        sleep(3);
        $this->debug(__FUNCTION__, 'done');
    }

    /**
     * @param string $password
     */
    public function setWifiPassword($password = '')
    {
        $this->debug(__FUNCTION__, 'start');
        $menu = $this->webDriver->switchTo()->frame($this->bottomLeftFrameId);
        $menu->findElement(WebDriverBy::linkText('Wireless'))->click();
        $menu->findElement(WebDriverBy::linkText('- Wireless Security'))->click();
        $this->webDriver->switchTo()->defaultContent();
        $centerFrame = $this->webDriver->switchTo()->frame($this->mainFrameId);
        // wait for page load...
        sleep(1);
        $centerFrame->findElement(WebDriverBy::name('pskSecret'))->clear()->sendKeys($password);
        $centerFrame->findElement(WebDriverBy::name('Save'))->click();
        $this->webDriver->switchTo()->defaultContent();
        // give some time for save...
        sleep(2);
        $this->debug(__FUNCTION__, 'done');
    }

    /**
     * Enable Remote Management
     */
    public function enableRemoteManagement()
    {
        $this->debug(__FUNCTION__, 'start');
        $menu = $this->webDriver->switchTo()->frame($this->bottomLeftFrameId);
        $menu->findElement(WebDriverBy::linkText('Security'))->click();
        $menu->findElement(WebDriverBy::linkText('- Remote Management'))->click();
        $this->webDriver->switchTo()->defaultContent();
        $centerFrame = $this->webDriver->switchTo()->frame($this->mainFrameId);
        // wait for page load...
        sleep(1);
        $centerFrame->findElement(WebDriverBy::name('port'))->clear()->sendKeys('8080');
        $centerFrame->findElement(WebDriverBy::name('ip'))->clear()->sendKeys('255.255.255.255');
        $centerFrame->findElement(WebDriverBy::name('Save'))->click();
        $this->webDriver->switchTo()->defaultContent();
        // give some time for save...
        sleep(2);
        $this->debug(__FUNCTION__, 'done');
    }

    /**
     * Configure Time settings.
     *
     * @param string $ntp
     */
    public function configureTimeSettings($ntp = '')
    {
        $this->debug(__FUNCTION__, 'start');
        $menu = $this->webDriver->switchTo()->frame($this->bottomLeftFrameId);
        $menu->findElement(WebDriverBy::linkText('System Tools'))->click();
        usleep(500000);
        $this->webDriver->switchTo()->defaultContent();
        $centerFrame = $this->webDriver->switchTo()->frame($this->mainFrameId);
        $select = $centerFrame->findElement(WebDriverBy::id('timezone'));
        $staticIp = $select->findElement(WebDriverBy::id('t_timezone720'));
        if (!$staticIp->isSelected()) {
            $staticIp->click();
        }
        $centerFrame->findElement(WebDriverBy::id('ntpA'))->clear()->sendKeys($ntp);
        $centerFrame->findElement(WebDriverBy::name('Save'))->click();
        $this->webDriver->switchTo()->defaultContent();
        // give some time for save...
        sleep(3);
        $this->debug(__FUNCTION__, 'done');
    }

    /**
     * Change Password.
     *
     * @param string $login
     * @param string $password
     * @param string $newLogin
     * @param string $newPassword
     */
    public function changePassword($login = 'admin', $password = 'admin', $newLogin, $newPassword)
    {
        $this->debug(__FUNCTION__, 'start');
        $menu = $this->webDriver->switchTo()->frame($this->bottomLeftFrameId);
        $menu->findElement(WebDriverBy::linkText('System Tools'))->click();
        $menu->findElement(WebDriverBy::linkText('- Password'))->click();
        $this->webDriver->switchTo()->defaultContent();
        $centerFrame = $this->webDriver->switchTo()->frame($this->mainFrameId);
        // wait for page load...
        sleep(1);
        $centerFrame->findElement(WebDriverBy::name('oldname'))->clear()->sendKeys($login);
        $centerFrame->findElement(WebDriverBy::name('oldpassword'))->clear()->sendKeys($password);
        $centerFrame->findElement(WebDriverBy::name('newname'))->clear()->sendKeys($newLogin);
        $centerFrame->findElement(WebDriverBy::name('newpassword'))->clear()->sendKeys($newPassword);
        $centerFrame->findElement(WebDriverBy::name('newpassword2'))->clear()->sendKeys($newPassword);
        $centerFrame->findElement(WebDriverBy::name('Save'))->click();
        $this->webDriver->switchTo()->defaultContent();
        // give some time for save...
        sleep(2);
        $this->debug(__FUNCTION__, 'done');
    }

    /**
     * Reboot device.
     */
    public function reboot()
    {
        $this->debug(__FUNCTION__, 'start');
        $menu = $this->webDriver->switchTo()->frame($this->bottomLeftFrameId);
        $menu->findElement(WebDriverBy::linkText('System Tools'))->click();
        $menu->findElement(WebDriverBy::linkText('- Reboot'))->click();
        $this->webDriver->switchTo()->defaultContent();
        $centerFrame = $this->webDriver->switchTo()->frame($this->mainFrameId);
        // wait for page load...
        sleep(1);
        $centerFrame->findElement(WebDriverBy::name('Reboot'))->click();
        $this->webDriver->switchTo()->alert()->accept();
        $this->debug(__FUNCTION__, 'done');
    }


}
