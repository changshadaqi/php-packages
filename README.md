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
# 安装其它