# tplink
Automatic TP-LINK TL-WR940N router provision, experiment with selenium and facebook webdriver.

## Installation
```bash
composer install
wget http://selenium-release.storage.googleapis.com/3.3/selenium-server-standalone-3.3.1.jar
```
### Usage
```
There is no configuration, you need to edit main class located in ./src/Router/Tplink.php to set or disable options
```

#### Running

> Before you run main script run selenium-server
```
java -jar selenium-server-standalone-3.3.1.jar 
```
> Script
```
./bin/tplink MY_NETWORK
``` 
