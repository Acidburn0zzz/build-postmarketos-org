# Build queue manager

## Installation

```shell-session
$ sudo apt install composer mysql-server mysql-client php libapache2-mod-php php-mysql
$ git clone https://gitlab.com/postmarketOS/build.postmarketos.org
$ cd build.postmarketos.org
$ composer install
(now place your mysql credentials in .env)
$ bin/console doctrine:database:create
$ bin/console doctrine:migrations:migrate
```

## Running

```shell-session
$ bin/console server:start
```

## API

### Submitting task to queue
```http
POST /api/task-submit
Content-Type: application/json
X-Secret: very-secret-token

{
    "package": "linux-postmarketos-qcom",
    "pkgver": "4.18.0",
    "pkgrel": 4,
    "commit": "123456788abcdef",
    "arch": "armhf",
    "branch": "master"
}
```

Response

```http
Content-Type: application/json

{
    "status": "ok"
}
```

## Database status values

* WAITING not running yet
* BUILDING currently building on sr.ht
* FAILED build failed on sr.ht
* SUPERSEDED a job for a newer version of the package has been submitted
* DONE everything build successfully
