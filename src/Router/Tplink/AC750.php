<?php

namespace Router\Tplink;

use Facebook\WebDriver\Exception\ElementNotVisibleException;
use Facebook\WebDriver\Exception\NoAlertOpenException;
use Facebook\WebDriver\Exception\NoSuchElementException;
use Facebook\WebDriver\WebDriverBy;
use Router\Router;

class AC750 extends Router
{
    const MODEL = 'TL-C20I';
    /**
     * @var RemoteWebDriver
     */
    protected $webDriver;
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
        $this->login();


        $this->configureWAN();
        $this->configureWLAN24();
        sleep(7);
        $this->disableWPS('menu_wlqss');
        sleep(5);
        $this->configureWLAN5();
        sleep(5);
        $this->disableWPS('menu_wlqss5g');
        sleep(5);
        $this->enableRemoteManagement();
        sleep(2);
        $this->configureTimeSettings($this->config['ntp_server']);
        $this->changePassword('admin', 'admin', 'admin', $this->config['admin_password']);
        $this->login('admin', $this->config['admin_password']);
        sleep(3);
        // rebooting to be sure config is saved
        $this->reboot();

        sleep(3);
        $this->webDriver->close();
    }

    /**
     * Logging into the router.
     *
     * @param string $login
     * @param string $password
     */
    public function login($login = 'admin', $password = 'admin')
    {
        sleep(2);
        $this->webDriver->findElement(WebDriverBy::id('userName'))->clear()->sendKeys($login);
        $this->webDriver->findElement(WebDriverBy::id('pcPassword'))->clear()->sendKeys($password);
        $this->webDriver->findElement(WebDriverBy::id('loginBtn'))->click();

        sleep(2);

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
        if (self::DEBUG) {
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
        sleep(1);
        $this->webDriver->switchTo()->defaultContent();
        $centerFrame = $this->webDriver->switchTo()->frame($this->mainFrameId);

        $select = $centerFrame->findElement(WebDriverBy::id('link_type'));
        $staticIp = $select->findElement(WebDriverBy::id('staticIp'));
        if (!$staticIp->isSelected()) {
            $staticIp->click();
        }
        // wait for load page...
        sleep(1);
        $centerFrame->findElement(WebDriverBy::id('ip_address'))->clear()->sendKeys($this->config['wan_ip_address']);
        $centerFrame->findElement(WebDriverBy::id('netmask'))->clear()->sendKeys($this->config['wan_netmask']);
        $centerFrame->findElement(WebDriverBy::id('ip_gateway'))->clear()->sendKeys($this->config['wan_gateway']);
        $centerFrame->findElement(WebDriverBy::id('dns_address'))->clear()->sendKeys($this->config['wan_dns1']);
        $centerFrame->findElement(WebDriverBy::id('second_dns'))->clear()->sendKeys($this->config['wan_dns2']);
        $centerFrame->findElement(WebDriverBy::id('saveBtn'))->click();
        $this->webDriver->switchTo()->defaultContent();
        // give some time for save...
        sleep(2);
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
        sleep(1);
        $this->webDriver->switchTo()->defaultContent();
        $centerFrame = $this->webDriver->switchTo()->frame($this->mainFrameId);
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
        usleep(500000);
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
        // give some time for save...
        sleep(3);
        $this->debug(__FUNCTION__, 'done');
    }

    /**
     * Disable WPS.
     */
    public function disableWPS($loc)
    {
        $wpaEnabled = false;
        $menu = $this->webDriver->switchTo()->frame($this->bottomLeftFrameId);
        $menu->findElement(WebDriverBy::id($loc))->click();
        sleep(3);
        $this->webDriver->switchTo()->defaultContent();
        $centerFrame = $this->webDriver->switchTo()->frame($this->mainFrameId);

        $isEnabled = $centerFrame->findElement(
            WebDriverBy::cssSelector('b[id="isQSSEn"] > span')
        );

        if ($isEnabled->getText() == "Enabled") {
            $wpaEnabled = true;
        }


        // Getting current WPS pin
        /*
                $elements = $centerFrame->findElement(WebDriverBy::id('t_cur_pin'));
                foreach ($elements as $element) {
            dump($element->getText());
        }
                die();
        */
        // Checking if WPA button is enabled
        /*    try {
                $wpaEnabled = $centerFrame->findElement(WebDriverBy::name('EnWps'))->isEnabled();
            } catch(NoSuchElementException $e) {
              dump($e);
            }*/

        if ($wpaEnabled) {
            try {
                $disableWPS = $centerFrame->findElement(WebDriverBy::id('qssSwitch'))->click();
            } catch (ElementNotVisibleException $ex) {
                printf('%s: Got exception possibly already disabled, error: %s', __FUNCTION__, $ex->getMessage());
            }
        }
        sleep(2);
        $this->webDriver->switchTo()->defaultContent();
    }

    /**
     * Configure WLAN.
     */
    public function configureWLAN5()
    {
        $this->debug(__FUNCTION__, 'start');
        $menu = $this->webDriver->switchTo()->frame($this->bottomLeftFrameId);
        $menu->findElement(WebDriverBy::linkText('Wireless 5GHz'))->click();
        sleep(1);
        $this->webDriver->switchTo()->defaultContent();
        $centerFrame = $this->webDriver->switchTo()->frame($this->mainFrameId);
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
        // give some time for save...
        sleep(3);
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
        // wait for page load...
        sleep(2);
        $centerFrame->findElement(WebDriverBy::id('r_http_port'))->clear()->sendKeys('65532');
        $centerFrame->findElement(WebDriverBy::id('r_host'))->clear()->sendKeys('255.255.255.255');
        $centerFrame->findElement(
            WebDriverBy::cssSelector('input[value="Save"]')
        )->click();
        $this->webDriver->switchTo()->defaultContent();
        // give some time for save...
        sleep(2);
        $this->debug(__FUNCTION__, 'done');
    }

    /**
     * Configure Time settings.
     */
    public function configureTimeSettings($ntp_server)
    {
        $this->debug(__FUNCTION__, 'start');
        $menu = $this->webDriver->switchTo()->frame($this->bottomLeftFrameId);
        $menu->findElement(WebDriverBy::linkText('System Tools'))->click();
        usleep(500000);
        $this->webDriver->switchTo()->defaultContent();
        $centerFrame = $this->webDriver->switchTo()->frame($this->mainFrameId);
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
    public function changePassword($login, $password, $newLogin, $newPassword)
    {
        $this->debug(__FUNCTION__, 'start');
        $menu = $this->webDriver->switchTo()->frame($this->bottomLeftFrameId);
        $menu->findElement(WebDriverBy::linkText('System Tools'))->click();
        $menu->findElement(WebDriverBy::linkText('- Password'))->click();
        $this->webDriver->switchTo()->defaultContent();
        $centerFrame = $this->webDriver->switchTo()->frame($this->mainFrameId);
        // wait for page load...
        sleep(1);
        $centerFrame->findElement(WebDriverBy::id('curName'))->clear()->sendKeys($login);
        $centerFrame->findElement(WebDriverBy::id('curPwd'))->clear()->sendKeys($password);
        $centerFrame->findElement(WebDriverBy::id('newName'))->clear()->sendKeys($newLogin);
        $centerFrame->findElement(WebDriverBy::id('newPwd'))->clear()->sendKeys($newPassword);
        $centerFrame->findElement(WebDriverBy::id('cfmPwd'))->clear()->sendKeys($newPassword);
        $centerFrame->findElement(
            WebDriverBy::cssSelector('input[value="Save"]')
        )->click();
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
        $centerFrame->findElement(
            WebDriverBy::cssSelector('input[value="Reboot"]')
        )->click();
        $this->webDriver->switchTo()->alert()->accept();
        $this->debug(__FUNCTION__, 'done');
    }
}
