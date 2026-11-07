# Laravel Dev Helper

Development helpers for Laravel applications: debugging utilities, database model/migration/Nova
generators, JSON-driven migrations and query scopes.

## Requirements

- PHP `^8.3`
- Laravel `13.x`

## Installation

```bash
composer require yousheng/laravel-dev-helper
```

The service provider and `Help` facade alias are auto-discovered.

## Commands

| Command | Description |
| --- | --- |
| `php artisan db:migrate {database}` | Generate migrations from an existing MySQL database |
| `php artisan yousheng:model` | Generate Eloquent models from database tables (optional `--nova`) |
| `php artisan yousheng:nova` | Generate Nova resources from database tables |
| `php artisan yousheng:one` | Generate models and optionally Nova resources in one command |
| `php artisan make:scope Name` | Create an Eloquent scope class |
| `php artisan make:migration:json --file=schema.json` | Generate migrations, models, factories and seeders from a JSON/JSON5 schema |

See [README.cn.md](README.cn.md) for the full Chinese documentation, JSON/JSON5 schema format and
debugging helpers (`p2f()`, `d()`, `?debug=routes`, ...). A complete table-level schema is
available in [schema.example.json](schema.example.json), and a commented JSON5 example in
[schema.example.json5](schema.example.json5).

## Testing

```bash
composer install
composer test
```
