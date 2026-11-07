
# 环境要求

- PHP `^8.3`
- Laravel `13.x`（`illuminate/*` 组件均为 `^13.0`）

> 1.1.0 起适配 Laravel 13；更早的 1.0.x 面向 Laravel 5.x 时代，不再维护。

# 一：安装
## 本地安装先修改composer.json
```bash
# 进入项目所在目录
composer config minimum-stability dev # 原来为stable
# "minimum-stability": "stable",

# 仓库地址
PHP_DIR="/var/www/php-packages"

# yousheng
composer config repositories.yousheng "{\"type\":\"path\",\"url\":\"$PHP_DIR/yousheng/**/\",\"options\":{\"symlink\":true}}"
```
## 安装yousheng/laravel-dev-helper
```bash
composer require yousheng/laravel-dev-helper
```


Laravel 会自动发现 `HelperServiceProvider`，并注册 `Help` 别名。若关闭了包自动发现，
请在 `bootstrap/providers.php`（Laravel 11+）或 `config/app.php` 中手动注册：

```php
Yousheng\LaravelDevHelper\HelperServiceProvider::class,
```

# 二：命令一览

| 命令 | 说明 |
| --- | --- |
| `php artisan db:migrate {database}` | 从现有 MySQL 数据库生成迁移文件 |
| `php artisan yousheng:model` | 根据数据库表生成 Eloquent 模型（可选 `--nova`） |
| `php artisan yousheng:nova` | 根据数据库表生成 Nova Resource |
| `php artisan yousheng:one` | 一条命令生成模型，可选同时生成 Nova Resource |
| `php artisan make:scope Name` | 生成 Eloquent Scope 类 |
| `php artisan make:migration:json --file=schema.json` | 从 JSON/JSON5 批量生成迁移、模型、工厂与种子 |



# 三：用法

 ### 路由部分

假设网站主机名为 http://www.abcd.com，那么 http://www.abcd.com/yousheng/list-routes 会查看当前所有解析后的路由

- list-routes
- list-langs
- list-view-paths
- list-aliases
- list-publishes
- list-sessions

### 调试部分
http://nova2.demo.bendi/nova/dashboards/main?debug=xxx

如：http://backend.0he1.bendi/?debug=aliases             显示所有的别名

如：http://backend.0he1.bendi/?debug=middleware    显示所有中间件

- routes
- views
- aliases
- sessions
- publishes

##  生成迁移文件

```sh
php artisan db:migrate your_database --ignore="contents, contents_category"  # 排除这两个表
php artisan db:migrate yousheng_0he1_com --only="option,sells"              # 只有这两个表生成迁移文件
php artisan db:migrate yousheng_0he1_com
# 迁移整个yousheng_0he1_com数据库
# 生成文件放在 database/migrations/2020_12_25_034543_create_yousheng0he1com_database.php
```

不但可以生成表，同时也生成约束。

## 生成Scope

```sh
php artisan make:scope PostNewest
```





## 从 JSON/JSON5 批量生成迁移、模型、工厂和种子

把整库结构写在一个 JSON 或 JSON5 文件里，一条命令生成迁移；通过 `-m` / `-f` / `-s` 决定是否同时生成 Model、Factory、Seeder。

可直接复制 [schema.example.json](schema.example.json) 作为模板；带注释的版本见
[schema.example.json5](schema.example.json5)。

需要查看所有列类型、列修饰符、索引和约束写法时，请参考
[full-schema.json5](tests/fixtures/full-schema.json5)（该文件是语法参考，部分类型/修饰符不能同时使用）。

### JSON5 与注释

支持 `.json` 和 `.json5` 文件。解析器会先按严格 JSON 解析，失败后再按 JSON5 解析，因此
`.json` 文件里也可以写注释、尾随逗号、单引号字符串和未加引号的对象键：

```json5
{
    // 用户表
    users: {
        table: 'comment(用户表):index(name)',
        id: 'id',
        email: "string(255):unique", /* 块注释 */
        active: 'boolean:default(true)',
    },
}
```

也可以直接使用 `.json5` 后缀：

```bash
php artisan make:migration:json --file=schema.json5 -mfs
```

### 格式规则

- 顶层键是表名。
- 每张表的第一个键是 `table`（可选），存放表级定义。
- 除 `table` 外的所有键都是列名。
- 被引用的表会自动排在前面，保证迁移时先建被依赖的表。
- 不再支持 `tables` 包装、`columns` 嵌套，也不在 JSON 里配置 Model / Factory / Seeder。

```json
{
  "roles": {
    "table": "comment(角色表)",
    "id": "id",
    "name": "string(50)"
  },
  "users": {
    "table": "comment(用户表):index(name):constraint(roles|id|role_id|cascade|set null)",
    "id": "id",
    "role_id": "unsignedBigInteger:nullable",
    "name": "string(50)",
    "email": "string(255):unique",
    "password": "string(255)"
  },
  "posts": {
    "table": "comment(文章表):index(title):unique(slug):constraint(users|id|user_id|cascade|restrict):constraint(categories|id|category_id|set null|restrict):softDeletes",
    "id": "id",
    "user_id": "unsignedBigInteger",
    "category_id": "unsignedBigInteger:nullable",
    "title": "string(200)",
    "slug": "string(200)",
    "status": "enum('draft','published'):default('draft'):index"
  }
}
```

### 列定义

```text
列名:类型(参数):修饰符:修饰符
```

例如：

```json
"email": "string(255):unique",
"is_guest": "boolean:index",
"active_at": "timestamp:nullable:index",
"status": "enum('draft','published'):default('draft')"
```

- 列类型取自 `Illuminate\Database\Schema\Blueprint` 的方法。
- 列级 `index` / `unique` 仍然生成列级索引。
- `enum()` / `set()` 支持 `enum('a','b')` 写法，会自动转换为数组参数。

### table 表级定义

`table` 的值是指令串，指令之间用 `:` 分隔：

```text
comment(用户表):index(name):constraint(roles|id|role_id|cascade|set null)
```

支持的指令：

| 指令 | 说明 |
| --- | --- |
| `comment(用户表)` | 表注释 |
| `index(name)` | 普通索引，括号内是列名 |
| `index(name,email|users_name_email_index)` | 复合索引并指定索引名 |
| `unique(email)` | 唯一索引 |
| `primary(id)` | 主键，可写 `primary(a,b)` |
| `constraint(roles|id|role_id|cascade|set null)` | 外键 |
| `engine(InnoDB)` | 表引擎 |
| `charset(utf8mb4)` | 字符集 |
| `collation(utf8mb4_unicode_ci)` | 排序规则 |
| `timestamps` / `timestamps(false)` | 是否自动补时间戳，默认 true |
| `softDeletes` / `softDeletes(false)` | 是否自动补 `deleted_at`，默认 false |

`table` 必须是表对象的第一个键；`comment` 内可以包含冒号。

### constraint 外键

参数顺序：

```text
constraint(被引用表|被引用列|本表列|onDelete|onUpdate|约束名)
```

示例：

```text
constraint(roles|id|role_id|cascade|set null)
constraint(users|id|user_id|cascade|restrict)
constraint(posts|id,tenant_id|post_id,tenant_id|cascade|cascade)
```

- 前 3 段必填。
- `onDelete` / `onUpdate` / 约束名可选。
- 只指定 `onUpdate` 时，`onDelete` 留空：`constraint(roles|id|role_id||set null)`。
- 被引用表使用实际表名，例如 `roles`，不做单复数自动转换。
- 复合外键的列用逗号分隔。

### 迁移顺序

生成器会根据 `constraint` 自动拓扑排序：

- 被引用的表先创建。
- 自引用（例如 `categories.parent_id` 指向 `categories`）允许。
- 循环依赖会直接报错，并输出环。
- 引用了 JSON 中不存在的表时，不参与排序，需要自行保证该表迁移更早执行。

### 命令

```sh
# 只生成迁移
php artisan make:migration:json --file=schema.json

# 生成迁移 + Model
php artisan make:migration:json --file=schema.json -m
php artisan make:migration:json --file=schema.json --model

# 生成迁移 + Model + Factory
php artisan make:migration:json --file=schema.json -f
php artisan make:migration:json --file=schema.json --factory

# 生成迁移 + Model + Factory + Seeder
php artisan make:migration:json --file=schema.json -s
php artisan make:migration:json --file=schema.json --seeder

# 组合短参数
php artisan make:migration:json --file=schema.json -mfs

# 只生成指定表
php artisan make:migration:json --file=schema.json --only=posts,tags

# 只校验
php artisan make:migration:json --file=schema.json --validate

# 撤销上一次生成的全部文件
php artisan make:migration:json --file=schema.json --undo
```

参数隐含规则：

- `-s` 自动包含 `-f`。
- `-f` 自动包含 `-m`。
- 因此 `-s` 等价于 `-mfs`。

### 生成内容

| 文件 | 说明 |
| --- | --- |
| `database/migrations/*_create_*_table.php` | 迁移 |
| `app/Models/*.php` | Model，含 fillable、casts 和自动关系 |
| `database/factories/*Factory.php` | Factory，自动生成 fake 数据和目标表 Factory 引用 |
| `database/seeders/*Seeder.php` | Seeder，固定 `factory()->count(10)->create()` |

Model 会根据外键自动生成：

- 当前表：`belongsTo`
- 被引用表：`hasMany`，外键列唯一时生成 `hasOne`
- 中间表：两侧 `belongsToMany`

Factory 遇到自引用外键（如 `categories.parent_id`）时不会递归调用自身 Factory。

### 校验

```sh
php artisan make:migration:json --file=schema.json --validate
```

会检查：

- `table` 是否为第一个键
- 指令是否合法、括号是否配对
- `constraint` 段数与本表列是否存在
- `index` / `unique` / `primary` 的列是否存在
- 列类型和修饰符是否合法
- 是否存在循环依赖

有错误时退出码为 1。

### 测试

```sh
composer install
composer test
```
## 下载B站视频

目标

- 获取指定url的链接和标题
- 将所有链接生成一txt文件，如abc.txt
- 使用you-get -I abc.txt 下载所有视频
