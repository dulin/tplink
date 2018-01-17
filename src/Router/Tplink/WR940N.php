<?php

namespace Router\Tplink;

use Facebook\WebDriver\Exception\ElementNotVisibleException;
use Facebook\WebDriver\WebDriverBy;
use Router\Router;

class WR940N extends Router
{
    const MODEL = 'TL-WR940N';
    protected $wifiPassword = '';
    private $config;
    private $mainFrameId = null;
    private $bottomLeftFrameId = null;

    /**
     * WR940N constructor.
     *
     * @param $config
     *
     * @throws \Facebook\WebDriver\Exception\WebDriverException
     */
    public function __construct($config)
    {
        $this->config = $config;
        $this->debugging = $config['debug'];
        $this->initWebdriver();
    }

    /**
     * Main
     */
    public function main()
    {
        $this->webDriver->get(self::DEFAULT_URL);
        $this->login();

        if (!$this->config['use_default_password']) {
            // generate wifi password
            $this->wifiPassword = $this->generatePassword(15);
        }

        $this->disableWPS();
        $this->configureWAN();
        $this->configureWLAN();
        if (!$this->config['use_default_password']) {
            $this->setWifiPassword($this->wifiPassword);
        }
        if($this->config['remote_management_enable']) {
            $this->enableRemoteManagement();
        }
        $this->configureTimeSettings($this->config['ntp_server']);
        $this->changePassword('admin', 'admin', 'admin', $this->config['admin_password']);
        $this->login('admin', $this->config['admin_password']);
        // rebooting to be sure config is saved
        $this->reboot();


        if (!$this->config['use_default_password']) {
            printf(
                "\033[0;31mResult:\033[0m: \nWireless name: %s\nWireless Password: %s\n",
                $this->wifiName,
                $this->wifiPassword
            );
        }
    }

    /**
     * Logging into the router
     *
     * @param string $login
     * @param string $password
     */
    public function login($login, $password)
    {
        $this->webDriver->findElement(WebDriverBy::id('userName'))->clear()->sendKeys($login);
        $this->webDriver->findElement(WebDriverBy::id('pcPassword'))->clear()->sendKeys($password);
        $this->webDriver->findElement(WebDriverBy::id('loginBtn'))->click();


        $this->mainFrameId = $this->webDriver->findElement(WebDriverBy::id('mainFrame'));
        $this->bottomLeftFrameId = $this->webDriver->findElement(WebDriverBy::id('bottomLeftFrame'));
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
        $centerFrame->findElement(WebDriverBy::name('ip'))->clear()->sendKeys($this->config['wan_ip_address']);
        $centerFrame->findElement(WebDriverBy::name('mask'))->clear()->sendKeys($this->config['wan_netmask']);
        $centerFrame->findElement(WebDriverBy::name('gateway'))->clear()->sendKeys($this->config['wan_gateway']);
        $centerFrame->findElement(WebDriverBy::name('dnsserver'))->clear()->sendKeys($this->config['wan_dns1']);
        $centerFrame->findElement(WebDriverBy::name('dnsserver2'))->clear()->sendKeys($this->config['wan_dns2']);
        $centerFrame->findElement(WebDriverBy::name('Save'))->click();
        $this->webDriver->switchTo()->defaultContent();
        // give some time for save...
        sleep(2);
        $this->debug(__FUNCTION__, 'done');
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

        $centerFrame->findElement(WebDriverBy::name('port'))->clear()->sendKeys(
            $this->config['remote_management_port']
        );
        $centerFrame->findElement(WebDriverBy::name('ip'))->clear()->sendKeys(
            $this->config['remote_management_ip']
        );
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
