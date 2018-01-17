<?php

namespace Router\Tplink;

use Facebook\WebDriver\Exception\ElementNotVisibleException;
use Facebook\WebDriver\Exception\NoAlertOpenException;
use Facebook\WebDriver\Exception\NoSuchElementException;
use Facebook\WebDriver\WebDriverBy;
use Facebook\WebDriver\WebDriverExpectedCondition;
use Router\Router;

class AC750 extends Router
{
    const MODEL = 'TL-C20I';
    /**
     * @var RemoteWebDriver
     */
    protected $webDriver;
    protected $wifiPassword = '';
    private $config;
    private $mainFrameId = null;
    private $bottomLeftFrameId = null;

    /**
     * AC750 constructor.
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
     * Main.
     */
    public function main()
    {
        $this->webDriver->get(self::DEFAULT_URL);
        if (!$this->config['use_default_wifi_password']) {
            // generate wifi password
            $this->wifiPassword = $this->generatePassword(15);
        }

        $this->login();

        $this->configureWAN();
        $this->configureWLAN24();
        if ($this->config['disable_wps']) {
            $this->disableWPS('menu_wlqss');
        }
        if (!$this->config['use_default_wifi_password']) {
            $this->setWifiPassword('menu_wlsec', $this->wifiPassword);
        }
        $this->configureWLAN5();
        if ($this->config['disable_wps']) {
            $this->disableWPS('menu_wlqss5g'); // 5g
        }
        if (!$this->config['use_default_wifi_password']) {
            $this->setWifiPassword('menu_wlsec5g', $this->wifiPassword); // 5g
        }
        if ($this->config['remote_management_enable']) {
            $this->enableRemoteManagement();
        }
        $this->configureTimeSettings($this->config['ntp_server']);
        $this->changePassword('admin', 'admin', 'admin', $this->config['admin_password']);
        $this->login('admin', $this->config['admin_password']);
        // rebooting to be sure config is saved
        $this->reboot();
        $this->webDriver->close();

        if (!$this->config['use_default_wifi_password']) {
            printf(
                "\033[0;31mResult:\033[0m: \nWireless name 2.4Ghz: %s\nWireless name 5G: %s\nWireless Password: %s\n",
                $this->wifiName,
                $this->wifiName . "_5G",
                $this->wifiPassword
            );
        }
    }

    /**
     * Login.
     *
     * @param string $login
     * @param string $password
     */
    public function login($login = 'admin', $password = 'admin')
    {
        $this->webDriver->wait(10, 1000)->until(
            WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::id('userName'))
        );
        $this->webDriver->findElement(WebDriverBy::id('userName'))->clear()->sendKeys($login);
        $this->webDriver->findElement(WebDriverBy::id('pcPassword'))->clear()->sendKeys($password);
        $this->webDriver->findElement(WebDriverBy::id('loginBtn'))->click();
        try {
            $incorrectPassword = $this->webDriver->findElement(WebDriverBy::id('tip'));
            if ($incorrectPassword->getText() === 'The username or password is incorrect, please input again.') {
                $this->debug(__FUNCTION__, 'Password Incorrect!');
                $this->webDriver->close();
                exit();
            }
        } catch (NoSuchElementException $e) {
        }

        $this->bottomLeftFrameId = $this->webDriver->findElement(WebDriverBy::id('frame1'));
        $this->mainFrameId = $this->webDriver->findElement(WebDriverBy::id('frame2'));

    }

    /**
     * @param string $name
     * @param string $status
     */
    public function debug($name = '', $status = '')
    {
        if ($this->debugging) {
            printf("\033[0;31mDebugging:\033[0m' %s part: %s\n", $name, $status);
        }
    }

    /**
     * Configuring WAN.
     */
    public function configureWAN()
    {
        $this->debug(__FUNCTION__, 'start');
        $menu = $this->webDriver->switchTo()->frame($this->bottomLeftFrameId);
        $menu->findElement(WebDriverBy::linkText('Network'))->click();
        $this->webDriver->switchTo()->defaultContent();
        $centerFrame = $this->webDriver->switchTo()->frame($this->mainFrameId);
        $this->webDriver->wait(10, 1000)->until(
            WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::id('link_type'))
        );
        $select = $centerFrame->findElement(WebDriverBy::id('link_type'));
        $staticIp = $select->findElement(WebDriverBy::id('staticIp'));
        if (!$staticIp->isSelected()) {
            $staticIp->click();
        }
        $centerFrame->findElement(WebDriverBy::id('ip_address'))->clear()->sendKeys($this->config['wan_ip_address']);
        $centerFrame->findElement(WebDriverBy::id('netmask'))->clear()->sendKeys($this->config['wan_netmask']);
        $centerFrame->findElement(WebDriverBy::id('ip_gateway'))->clear()->sendKeys($this->config['wan_gateway']);
        $centerFrame->findElement(WebDriverBy::id('dns_address'))->clear()->sendKeys($this->config['wan_dns1']);
        $centerFrame->findElement(WebDriverBy::id('second_dns'))->clear()->sendKeys($this->config['wan_dns2']);
        $centerFrame->findElement(WebDriverBy::id('saveBtn'))->click();
        $this->webDriver->switchTo()->defaultContent();
        $this->debug(__FUNCTION__, 'done');
    }

    /**
     * @param string $password
     */
    public function setWifiPassword($loc, $password = '')
    {
        $this->debug(__FUNCTION__, 'start');
        $menu = $this->webDriver->switchTo()->frame($this->bottomLeftFrameId);
        $menu->findElement(WebDriverBy::id($loc))->click();
        $this->webDriver->switchTo()->defaultContent();
        $centerFrame = $this->webDriver->switchTo()->frame($this->mainFrameId);
        $this->webDriver->wait(20, 1000)->until(
            WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::id('pskSecret'))
        );
        $centerFrame->findElement(WebDriverBy::id('pskSecret'))->clear()->sendKeys($password);
        $centerFrame->findElement(
            WebDriverBy::cssSelector('input[value="Save"]')
        )->click();
        $this->webDriver->switchTo()->defaultContent();
        $this->debug(__FUNCTION__, 'done');
    }

    /**
     * Configure WLAN.
     */
    public function configureWLAN24()
    {
        $this->debug(__FUNCTION__, 'start');
        $menu = $this->webDriver->switchTo()->frame($this->bottomLeftFrameId);
        $menu->findElement(WebDriverBy::linkText('Wireless 2.4GHz'))->click();
        $this->webDriver->switchTo()->defaultContent();
        $centerFrame = $this->webDriver->switchTo()->frame($this->mainFrameId);
        $this->webDriver->wait(20, 1000)->until(
            WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::id('ssid'))
        );
        $centerFrame->findElement(WebDriverBy::id('ssid'))->clear()->sendKeys($this->wifiName);

        $channelWidth = $centerFrame->findElement(
            WebDriverBy::cssSelector('select[id="region"] > option[value="94"]')
        );
        // select if is not selected! Sometimes selecting wrong, that fix issue.
        if (!$channelWidth->isSelected()) {
            $channelWidth->click();
        }

        $channels = ['3', '7', '11'];
        $selected = array_rand($channels, 1);
        $channel = $centerFrame->findElement(
            WebDriverBy::cssSelector('select[name="channel"] > option[value="'.$channels[$selected].'"]')
        );
        // select if is not selected! Sometimes selecting wrong, that fix issue.
        if (!$channel->isSelected()) {
            $channel->click();
        }

        $channelWidth = $centerFrame->findElement(
            WebDriverBy::cssSelector('select[id="bandWidth"] > option[value="20M"]')
        );
        if (!$channelWidth->isSelected()) {
            $channelWidth->click();
        }

        $centerFrame->findElement(
            WebDriverBy::cssSelector('input[value="Save"]')
        )->click();

        try {
            $this->webDriver->switchTo()->alert()->accept();
        } catch (NoAlertOpenException $ex) {
        }

        $this->webDriver->switchTo()->defaultContent();
        $this->debug(__FUNCTION__, 'done');
    }

    /**
     * Disable WPS.
     */
    public function disableWPS($loc)
    {
        $this->debug(__FUNCTION__, ' '. $loc. ' - start');
        $wpaEnabled = false;
        $menu = $this->webDriver->switchTo()->frame($this->bottomLeftFrameId);
        $menu->findElement(WebDriverBy::id($loc))->click();
        $this->webDriver->switchTo()->defaultContent();
        $centerFrame = $this->webDriver->switchTo()->frame($this->mainFrameId);
        $this->webDriver->wait(20, 1000)->until(
            WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::id('addNew'))
        );

        $isEnabled = $centerFrame->findElement(
            WebDriverBy::cssSelector('b[id="isQSSEn"] > span')
        );

        if ($isEnabled->getText() == "Enabled") {
            $wpaEnabled = true;
        }

        if ($wpaEnabled) {
            try {
                $disableWPS = $centerFrame->findElement(WebDriverBy::id('qssSwitch'))->click();
            } catch (ElementNotVisibleException $ex) {
                printf('%s: Got exception possibly already disabled, error: %s', __FUNCTION__, $ex->getMessage());
            }
        }
        $this->webDriver->switchTo()->defaultContent();
        $this->debug(__FUNCTION__, ' '. $loc. ' - done');
    }

    /**
     * Configure WLAN.
     */
    public function configureWLAN5()
    {
        $this->debug(__FUNCTION__, 'start');
        $menu = $this->webDriver->switchTo()->frame($this->bottomLeftFrameId);
        $menu->findElement(WebDriverBy::linkText('Wireless 5GHz'))->click();
        $this->webDriver->switchTo()->defaultContent();
        $centerFrame = $this->webDriver->switchTo()->frame($this->mainFrameId);
        $this->webDriver->wait(20, 1000)->until(
            WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::id('ssid'))
        );
        $centerFrame->findElement(WebDriverBy::id('ssid'))->clear()->sendKeys($this->wifiName."_5G");

        $channelWidth = $centerFrame->findElement(
            WebDriverBy::cssSelector('select[id="region"] > option[value="94"]')
        );
        // select if is not selected! Sometimes selecting wrong, that fix issue.
        if (!$channelWidth->isSelected()) {
            $channelWidth->click();
        }

        $channel = $centerFrame->findElement(
            WebDriverBy::cssSelector('select[name="channel"] > option[value="36"]')
        );
        // select if is not selected! Sometimes selecting wrong, that fix issue.
        if (!$channel->isSelected()) {
            $channel->click();
        }

        $channelWidth = $centerFrame->findElement(
            WebDriverBy::cssSelector('select[id="bandWidth"] > option[value="40M"]')
        );
        if (!$channelWidth->isSelected()) {
            $channelWidth->click();
        }

        $centerFrame->findElement(
            WebDriverBy::cssSelector('input[value="Save"]')
        )->click();

        try {
            $this->webDriver->switchTo()->alert()->accept();
        } catch (NoAlertOpenException $ex) {
        }

        $this->webDriver->switchTo()->defaultContent();
        $this->debug(__FUNCTION__, 'done');
    }


    /**
     * Enable Remote Management.
     */
    public function enableRemoteManagement()
    {
        $this->debug(__FUNCTION__, 'start');
        $menu = $this->webDriver->switchTo()->frame($this->bottomLeftFrameId);
        $menu->findElement(WebDriverBy::linkText('Security'))->click();
        $menu->findElement(WebDriverBy::linkText('- Remote Management'))->click();
        $this->webDriver->switchTo()->defaultContent();
        $centerFrame = $this->webDriver->switchTo()->frame($this->mainFrameId);
        $this->webDriver->wait(10, 1000)->until(
            WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::id('r_http_port'))
        );
        $centerFrame->findElement(WebDriverBy::id('r_http_port'))->clear()->sendKeys(
            $this->config['remote_management_port']
        );
        $centerFrame->findElement(WebDriverBy::id('r_host'))->clear()->sendKeys(
            $this->config['remote_management_ip']
        );
        $centerFrame->findElement(
            WebDriverBy::cssSelector('input[value="Save"]')
        )->click();
        $this->webDriver->switchTo()->defaultContent();
        $this->debug(__FUNCTION__, 'done');
    }

    /**
     * Configure Time settings.
     *
     * @param $ntp_server
     */
    public function configureTimeSettings($ntp_server)
    {
        $this->debug(__FUNCTION__, 'start');
        $menu = $this->webDriver->switchTo()->frame($this->bottomLeftFrameId);
        $menu->findElement(WebDriverBy::linkText('System Tools'))->click();
        $this->webDriver->switchTo()->defaultContent();
        $centerFrame = $this->webDriver->switchTo()->frame($this->mainFrameId);
        $this->webDriver->wait(10, 1000)->until(
            WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::id('ntpA'))
        );
        $select = $centerFrame->findElement(
            WebDriverBy::cssSelector('select[id="timezone"] > option[value="+00:00"]')
        );

        if (!$select->isSelected()) {
            $select->click();
        }
        $centerFrame->findElement(WebDriverBy::id('ntpA'))->clear()->sendKeys($ntp_server);
        $centerFrame->findElement(
            WebDriverBy::cssSelector('input[value="Save"]')
        )->click();
        $this->webDriver->switchTo()->defaultContent();
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
    public function changePassword($login, $password, $newLogin, $newPassword)
    {
        $this->debug(__FUNCTION__, 'start');
        $menu = $this->webDriver->switchTo()->frame($this->bottomLeftFrameId);
        $menu->findElement(WebDriverBy::linkText('System Tools'))->click();
        $menu->findElement(WebDriverBy::linkText('- Password'))->click();
        $this->webDriver->switchTo()->defaultContent();
        $centerFrame = $this->webDriver->switchTo()->frame($this->mainFrameId);
        $this->webDriver->wait(15, 1000)->until(
            WebDriverExpectedCondition::visibilityOfElementLocated(WebDriverBy::id('curName'))
        );
        $centerFrame->findElement(WebDriverBy::id('curName'))->clear()->sendKeys($login);
        $centerFrame->findElement(WebDriverBy::id('curPwd'))->clear()->sendKeys($password);
        $centerFrame->findElement(WebDriverBy::id('newName'))->clear()->sendKeys($newLogin);
        $centerFrame->findElement(WebDriverBy::id('newPwd'))->clear()->sendKeys($newPassword);
        $centerFrame->findElement(WebDriverBy::id('cfmPwd'))->clear()->sendKeys($newPassword);
        $centerFrame->findElement(
            WebDriverBy::cssSelector('input[value="Save"]')
        )->click();
        $this->webDriver->switchTo()->defaultContent();
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
        $this->webDriver->wait(10, 1000)->until(
            WebDriverExpectedCondition::visibilityOfElementLocated(
                WebDriverBy::cssSelector('input[value="Reboot"]')
            )
        );
        $centerFrame->findElement(
            WebDriverBy::cssSelector('input[value="Reboot"]')
        )->click();
        $this->webDriver->switchTo()->alert()->accept();
        sleep(2);
        $this->debug(__FUNCTION__, 'done');
    }
}
