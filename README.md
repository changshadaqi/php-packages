所有php仓库，适合项目本地安装，部是是自己写的

# 本地安装

## 本地安装先修改composer.json
```bash
# 进入项目所在目录
composer config minimum-stability dev # 原来为stable
# "minimum-stability": "stable",

# 仓库地址
PHP_DIR="/var/www/php-packages"

# yousheng
composer config repositories.yousheng "{\"type\":\"path\",\"url\":\"$PHP_DIR/yousheng/**/\",\"options\":{\"symlink\":true}}"

composer config repositories.others "{\"type\":\"path\",\"url\":\"$PHP_DIR/others/**/\",\"options\":{\"symlink\":true}}"
```
## 安装yousheng/laravel-dev-helper
```bash
composer require yousheng/laravel-dev-helper
composer require stancl/tenancy
composer require nwidart/laravel-modules
```
# 备份到github.com

```bash
$ git remote -v
origin  git@github01:changshadaqi/php-packages.git (fetch)
origin  git@github01:changshadaqi/php-packages.git (push)

git push # 推送到github01
```
# 备份到腾讯云服务器
```json
{
    "name": "腾讯云上海",
    "host": "101.43.45.182",
    "protocol": "sftp",
    "port": 22,
    "username": "ubuntu",
    "privateKeyPath": "C:\\Users\\wangq\\.ssh\\1-tengxunyun-shanghai-fengguande_20261104.pem",
    "remotePath": "/var/www/php-packages/",
    "uploadOnSave": true,
    "downloadOnOpen": false,
    "ignore": [
        ".git",
        "node_modules",
        ".env",
        "vendor",
        "composer.lock"
    ]
}

```
同步到remote,`Sync Local->Remote`

