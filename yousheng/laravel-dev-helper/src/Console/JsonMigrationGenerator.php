<?php

namespace Yousheng\LaravelDevHelper\Console;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;

/**
 * 把 MakeMigrationJson 解析出的表结构渲染成迁移、模型、工厂与种子。
 */
class JsonMigrationGenerator
{
    /** @var Filesystem */
    protected $filesystem;

    /** @var JsonFieldCompiler */
    protected $compiler;

    /** @var string */
    protected $basePath;

    /** @var array */
    protected $generated = [];

    /** @var string */
    protected $modelNamespace = 'App\\Models';

    public function __construct(?Filesystem $filesystem = null, $basePath = null, ?JsonFieldCompiler $compiler = null)
    {
        $this->filesystem = $filesystem ?: new Filesystem();
        $this->compiler = $compiler ?: new JsonFieldCompiler();

        if ($basePath === null) {
            $basePath = function_exists('base_path') ? base_path() : getcwd();
        }
        $this->basePath = rtrim($basePath, '/\\');
    }

    /**
     * @param array $tables MakeMigrationJson::parseTables() 的结果（已按依赖排序）
     * @param bool $withModels
     * @param bool $withFactories
     * @param bool $withSeeders
     * @return array 生成文件的相对路径
     */
    public function generateTables(array $tables, $withModels = false, $withFactories = false, $withSeeders = false)
    {
        $this->generated = [];
        $this->modelNamespace = $this->filesystem->isDirectory($this->path('app/Models'))
            ? 'App\\Models'
            : 'App';

        $models = $this->buildModelMap($tables);
        $relations = $this->buildRelations($tables, $models);
        $timestamp = time();
        $offset = 0;

        foreach ($tables as $spec) {
            $plan = $this->buildTablePlan($spec);
            $contents = $this->renderStub('json.migration.stub', [
                '{{ class }}' => Str::studly($spec['migration']),
                '{{ up }}' => $plan['up'],
                '{{ down }}' => $plan['down'],
            ]);

            do {
                $filename = date('Y_m_d_His', $timestamp + $offset).'_'.$spec['migration'].'.php';
                $relative = 'database/migrations/'.$filename;
                $offset++;
            } while ($this->filesystem->exists($this->path($relative)) && $offset < 120);

            $this->write($relative, $contents);

            if ($withModels) {
                $this->writeTableModel($spec, $relations[$spec['table']] ?? []);
            }

            if ($withFactories) {
                $this->writeFactory($spec, $tables, $models);
            }

            if ($withSeeders) {
                $this->writeSeeder($spec, $models);
            }
        }

        return $this->generated;
    }

    /**
     * @return array<string, string>
     */
    protected function buildModelMap(array $tables)
    {
        $models = [];
        foreach ($tables as $spec) {
            $models[$spec['table']] = $this->modelNamespace.'\\'.$this->modelClass($spec['table']);
        }

        return $models;
    }

    /**
     * @return array<string, array>
     */
    protected function buildRelations(array $tables, array $models)
    {
        $relations = [];
        foreach ($tables as $spec) {
            $relations[$spec['table']] = [
                'belongsTo' => [],
                'hasOne' => [],
                'hasMany' => [],
                'belongsToMany' => [],
            ];
        }

        foreach ($tables as $spec) {
            $table = $spec['table'];

            if ($this->isPivotTable($spec)) {
                $this->buildPivotRelations($spec, $models, $relations);

                continue;
            }

            foreach ($spec['foreignKeys'] as $foreignKey) {
                $target = $foreignKey['on'];
                $targetClass = $models[$target] ?? $this->modelFqcn($target);
                $sourceClass = $models[$table] ?? $this->modelFqcn($table);

                $foreignColumn = $foreignKey['columns'][0] ?? null;
                $ownerColumn = $foreignKey['references'][0] ?? 'id';

                if ($foreignColumn === null) {
                    continue;
                }

                $name = $this->uniqueMethodName($relations[$table]['belongsTo'], Str::camel(Str::singular($target)));
                $relations[$table]['belongsTo'][$name] = [
                    'type' => 'belongsTo',
                    'model' => $targetClass,
                    'foreignKey' => $foreignColumn,
                    'ownerKey' => $ownerColumn,
                ];

                if (isset($relations[$target])) {
                    $type = $this->isUniqueColumns($spec, $foreignKey['columns']) ? 'hasOne' : 'hasMany';
                    $name = $this->uniqueMethodName($relations[$target][$type], Str::camel(Str::plural($table)));
                    $relations[$target][$type][$name] = [
                        'type' => $type,
                        'model' => $sourceClass,
                        'foreignKey' => $foreignColumn,
                        'localKey' => $ownerColumn,
                    ];
                }
            }
        }

        return $relations;
    }

    protected function buildPivotRelations(array $spec, array $models, array &$relations)
    {
        $table = $spec['table'];
        $first = $spec['foreignKeys'][0];
        $second = $spec['foreignKeys'][1];

        $firstTable = $first['on'];
        $secondTable = $second['on'];
        $firstColumn = $first['columns'][0] ?? null;
        $secondColumn = $second['columns'][0] ?? null;

        if ($firstColumn === null || $secondColumn === null) {
            return;
        }

        if (isset($relations[$firstTable])) {
            $name = $this->uniqueMethodName($relations[$firstTable]['belongsToMany'], Str::camel(Str::plural($secondTable)));
            $relations[$firstTable]['belongsToMany'][$name] = [
                'type' => 'belongsToMany',
                'model' => $models[$secondTable] ?? $this->modelFqcn($secondTable),
                'table' => $table,
                'foreignPivotKey' => $firstColumn,
                'relatedPivotKey' => $secondColumn,
            ];
        }

        if (isset($relations[$secondTable])) {
            $name = $this->uniqueMethodName($relations[$secondTable]['belongsToMany'], Str::camel(Str::plural($firstTable)));
            $relations[$secondTable]['belongsToMany'][$name] = [
                'type' => 'belongsToMany',
                'model' => $models[$firstTable] ?? $this->modelFqcn($firstTable),
                'table' => $table,
                'foreignPivotKey' => $secondColumn,
                'relatedPivotKey' => $firstColumn,
            ];
        }

        if (isset($relations[$table])) {
            foreach ([$first, $second] as $foreignKey) {
                $target = $foreignKey['on'];
                $name = $this->uniqueMethodName($relations[$table]['belongsTo'], Str::camel(Str::singular($target)));
                $relations[$table]['belongsTo'][$name] = [
                    'type' => 'belongsTo',
                    'model' => $models[$target] ?? $this->modelFqcn($target),
                    'foreignKey' => $foreignKey['columns'][0] ?? 'id',
                    'ownerKey' => $foreignKey['references'][0] ?? 'id',
                ];
            }
        }
    }

    protected function isPivotTable(array $spec)
    {
        if (count($spec['foreignKeys']) !== 2) {
            return false;
        }

        $foreignColumns = [];
        foreach ($spec['foreignKeys'] as $foreignKey) {
            foreach ($foreignKey['columns'] as $column) {
                $foreignColumns[] = $column;
            }
        }

        $primary = $spec['primary'];
        sort($foreignColumns);
        sort($primary);

        return ! empty($primary) && $foreignColumns === $primary;
    }

    protected function isUniqueColumns(array $spec, array $columns)
    {
        $columns = array_values($columns);
        sort($columns);

        foreach ($spec['indexes'] as $index) {
            if ($index['type'] !== 'unique') {
                continue;
            }
            $indexColumns = $index['columns'];
            sort($indexColumns);
            if ($indexColumns === $columns) {
                return true;
            }
        }

        $primary = $spec['primary'];
        sort($primary);
        if (! empty($primary) && $primary === $columns) {
            return true;
        }

        if (count($columns) === 1) {
            foreach ($spec['columns'] as $name => $definition) {
                if ($name !== $columns[0]) {
                    continue;
                }
                $parsed = $this->compiler->parse("{$name}:{$definition}");
                foreach ($parsed[0]['modifiers'] ?? [] as $modifier) {
                    if (strtolower($modifier['name']) === 'unique') {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    protected function uniqueMethodName(array $existing, $base)
    {
        $base = $base !== '' ? $base : 'relation';
        $name = $base;
        $index = 2;
        while (array_key_exists($name, $existing)) {
            $name = $base.$index;
            $index++;
        }

        return $name;
    }

    /**
     * @return array {up: string, down: string}
     */
    protected function buildTablePlan(array $spec)
    {
        $definitions = $this->columnDefinitions($spec['columns']);
        $parsed = $this->compiler->compile($definitions);
        $fields = $this->compiler->parse($definitions);
        $names = array_column($fields, 'name');

        if ($spec['operation'] === 'add') {
            return $this->addTablePlan($spec, $parsed, $names);
        }

        if ($spec['operation'] === 'remove') {
            return $this->removeTablePlan($spec, $names);
        }

        return $this->createTablePlan($spec, $parsed, $fields, $names);
    }

    protected function createTablePlan(array $spec, array $parsed, array $fields, array $names)
    {
        $lines = [];

        if ($spec['engine']) {
            $lines[] = '$table->engine = '.$this->compiler->quote($spec['engine']).';';
        }
        if ($spec['charset']) {
            $lines[] = '$table->charset = '.$this->compiler->quote($spec['charset']).';';
        }
        if ($spec['collation']) {
            $lines[] = '$table->collation = '.$this->compiler->quote($spec['collation']).';';
        }

        $columnPrimary = $this->hasPrimaryColumn($fields);

        if (! $columnPrimary && empty($spec['primary'])) {
            $lines[] = '$table->id();';
        }

        $lines = array_merge($lines, $parsed['lines']);

        if ($spec['timestamps'] && ! in_array('created_at', $names, true) && ! in_array('updated_at', $names, true)) {
            $lines[] = '$table->timestamps();';
        }

        if ($spec['softDeletes'] && ! in_array('deleted_at', $names, true)) {
            $lines[] = '$table->softDeletes();';
        }

        $lines = array_merge(
            $lines,
            $this->indexLines($spec['indexes'], $columnPrimary ? [] : $spec['primary']),
            $this->foreignKeyLines($spec['foreignKeys'])
        );

        if ($spec['comment']) {
            $lines[] = '$table->comment('.$this->compiler->quote($spec['comment']).');';
        }

        return [
            'up' => $this->schemaBlock('create', $spec['table'], $lines),
            'down' => "Schema::dropIfExists('".$spec['table']."');",
        ];
    }

    protected function addTablePlan(array $spec, array $parsed, array $names)
    {
        $lines = array_merge(
            $parsed['lines'],
            $this->indexLines($spec['indexes'], []),
            $this->foreignKeyLines($spec['foreignKeys'])
        );

        if ($spec['comment']) {
            $lines[] = '$table->comment('.$this->compiler->quote($spec['comment']).');';
        }

        if (empty($lines)) {
            $lines[] = '// TODO: 在此补充需要新增的字段。';
        }

        return [
            'up' => $this->schemaBlock('table', $spec['table'], $lines),
            'down' => $this->dropTablePlan($spec, $names),
        ];
    }

    protected function removeTablePlan(array $spec, array $names)
    {
        $lines = $this->dropTablePlan($spec, $names);
        if (empty($lines)) {
            $lines = ['// TODO: 在此补充需要删除的字段。'];
        }

        return [
            'up' => $this->schemaBlock('table', $spec['table'], $lines),
            'down' => '// TODO: 如需回滚，请在此重新添加被删除的字段。',
        ];
    }

    protected function dropTablePlan(array $spec, array $names)
    {
        $lines = [];

        foreach ($spec['foreignKeys'] as $foreignKey) {
            if (! empty($foreignKey['name'])) {
                $lines[] = "\$table->dropForeign(".$this->compiler->quote($foreignKey['name']).');';
            } else {
                $lines[] = '$table->dropForeign('.$this->compiler->quoteList($foreignKey['columns']).');';
            }
        }

        foreach ($spec['indexes'] as $index) {
            $method = match ($index['type']) {
                'unique' => 'dropUnique',
                'primary' => 'dropPrimary',
                'fulltext' => 'dropFullText',
                'spatial' => 'dropSpatialIndex',
                default => 'dropIndex',
            };

            if (! empty($index['name'])) {
                $lines[] = "\$table->{$method}(".$this->compiler->quote($index['name']).');';
            } else {
                $lines[] = "\$table->{$method}(".$this->compiler->quoteList($index['columns']).');';
            }
        }

        if (! empty($names)) {
            $lines[] = '$table->dropColumn('.$this->compiler->quoteList($names).');';
        }

        return $lines;
    }

    protected function indexLines(array $indexes, array $primary)
    {
        $lines = [];
        $hasPrimary = false;

        foreach ($indexes as $index) {
            $columns = $this->compiler->quoteList($index['columns']);
            $name = $index['name'] ? ', '.$this->compiler->quote($index['name']) : '';

            if ($index['type'] === 'primary') {
                $lines[] = "\$table->primary({$columns}{$name});";
                $hasPrimary = true;
                continue;
            }

            $method = match ($index['type']) {
                'unique' => 'unique',
                'fulltext' => 'fullText',
                'spatial' => 'spatialIndex',
                default => 'index',
            };

            $lines[] = "\$table->{$method}({$columns}{$name});";
        }

        if (! $hasPrimary && ! empty($primary)) {
            $lines[] = '$table->primary('.$this->compiler->quoteList($primary).');';
        }

        return $lines;
    }

    protected function foreignKeyLines(array $foreignKeys)
    {
        $lines = [];

        foreach ($foreignKeys as $foreignKey) {
            $columns = $this->compiler->quoteList($foreignKey['columns']);
            $references = $this->compiler->quoteList($foreignKey['references']);
            $name = $foreignKey['name'] ? ', '.$this->compiler->quote($foreignKey['name']) : '';

            $line = "\$table->foreign({$columns}{$name})->references({$references})->on("
                .$this->compiler->quote($foreignKey['on']).')';

            if ($foreignKey['onUpdate']) {
                $line .= '->onUpdate('.$this->compiler->quote($foreignKey['onUpdate']).')';
            }
            if ($foreignKey['onDelete']) {
                $line .= '->onDelete('.$this->compiler->quote($foreignKey['onDelete']).')';
            }

            $lines[] = $line.';';
        }

        return $lines;
    }

    protected function hasPrimaryColumn(array $fields)
    {
        $primaryTypes = ['id', 'increments', 'bigIncrements', 'mediumIncrements', 'smallIncrements', 'tinyIncrements'];

        foreach ($fields as $field) {
            if (in_array($field['type'], $primaryTypes, true)) {
                return true;
            }
            foreach ($field['modifiers'] as $modifier) {
                if ($modifier['name'] === 'primary') {
                    return true;
                }
            }
        }

        return false;
    }

    protected function columnDefinitions(array $columns)
    {
        $definitions = [];
        foreach ($columns as $name => $definition) {
            $definitions[] = "{$name}:{$definition}";
        }

        return implode(', ', $definitions);
    }

    protected function writeTableModel(array $spec, array $relations)
    {
        $namespace = $this->modelNamespace;
        $directory = $this->modelDirectory($namespace);
        $class = $this->modelClass($spec['table']);

        $fields = $this->compiler->parse($this->columnDefinitions($spec['columns']));
        $fillable = [];
        $skip = ['id', 'created_at', 'updated_at', 'deleted_at', 'remember_token'];
        foreach ($fields as $field) {
            if (! in_array($field['name'], $skip, true)) {
                $fillable[] = $field['name'];
            }
        }

        $casts = $this->deriveCasts($fields);
        $softDeletes = $spec['softDeletes'];
        $uses = $softDeletes ? "use Illuminate\\Database\\Eloquent\\SoftDeletes;" : '';
        $traits = $softDeletes ? "\n    use SoftDeletes;\n" : '';

        $fieldNames = array_column($fields, 'name');
        $timestampsBlock = '';
        if (! $spec['timestamps']
            && ! in_array('created_at', $fieldNames, true)
            && ! in_array('updated_at', $fieldNames, true)) {
            $timestampsBlock = "\n    public \$timestamps = false;\n";
        }

        $castsBlock = '';
        if (! empty($casts)) {
            $castsBlock = "\n    /**\n     * The attributes that should be cast.\n     *\n     * @var array<string, string>\n     */\n"
                .'    protected $casts = '.$this->exportMap($casts).";\n";
        }

        $contents = $this->renderStub('json.model.full.stub', [
            '{{ namespace }}' => $namespace,
            '{{ class }}' => $class,
            '{{ table }}' => $spec['table'],
            '{{ fillable }}' => $this->exportList($fillable),
            '{{ uses }}' => $uses,
            '{{ traits }}' => $traits,
            '{{ timestamps }}' => $timestampsBlock,
            '{{ casts }}' => $castsBlock,
            '{{ relations }}' => $this->renderRelations($relations),
        ]);

        $this->write($directory.'/'.$class.'.php', $contents);
    }

    protected function deriveCasts(array $fields)
    {
        $casts = [];

        foreach ($fields as $field) {
            $type = $field['type'];
            $name = $field['name'];

            if (in_array($name, ['created_at', 'updated_at', 'deleted_at'], true)) {
                continue;
            }

            if (in_array($type, ['integer', 'bigInteger', 'mediumInteger', 'smallInteger', 'tinyInteger', 'unsignedBigInteger', 'unsignedInteger', 'unsignedMediumInteger', 'unsignedSmallInteger', 'unsignedTinyInteger'], true)) {
                $casts[$name] = 'integer';
            } elseif ($type === 'boolean') {
                $casts[$name] = 'boolean';
            } elseif (in_array($type, ['decimal', 'float', 'double'], true)) {
                $casts[$name] = $type === 'decimal' ? 'decimal:2' : 'float';
            } elseif (in_array($type, ['json', 'jsonb'], true)) {
                $casts[$name] = 'array';
            } elseif ($type === 'date') {
                $casts[$name] = 'date';
            } elseif (in_array($type, ['dateTime', 'dateTimeTz', 'timestamp', 'timestampTz'], true)) {
                $casts[$name] = 'datetime';
            }
        }

        return $casts;
    }

    protected function renderRelations(array $relations)
    {
        $methods = '';

        foreach (['belongsTo', 'hasOne', 'hasMany', 'belongsToMany'] as $type) {
            foreach ($relations[$type] ?? [] as $name => $relation) {
                $methods .= "\n".$this->renderRelationMethod($name, $relation);
            }
        }

        return $methods;
    }

    protected function renderRelationMethod($name, array $relation)
    {
        $model = '\\'.ltrim($relation['model'], '\\');

        switch ($relation['type']) {
            case 'belongsTo':
                $arguments = [$model.'::class'];
                if ($relation['foreignKey']) {
                    $arguments[] = $this->compiler->quote($relation['foreignKey']);
                }
                if ($relation['ownerKey']) {
                    $arguments[] = $this->compiler->quote($relation['ownerKey']);
                }
                $call = 'belongsTo('.implode(', ', $arguments).')';
                break;

            case 'hasOne':
            case 'hasMany':
                $arguments = [$model.'::class'];
                if ($relation['foreignKey']) {
                    $arguments[] = $this->compiler->quote($relation['foreignKey']);
                }
                if ($relation['localKey']) {
                    $arguments[] = $this->compiler->quote($relation['localKey']);
                }
                $call = $relation['type'].'('.implode(', ', $arguments).')';
                break;

            default:
                $arguments = [$model.'::class'];
                if ($relation['table']) {
                    $arguments[] = $this->compiler->quote($relation['table']);
                }
                if ($relation['foreignPivotKey']) {
                    $arguments[] = $this->compiler->quote($relation['foreignPivotKey']);
                }
                if ($relation['relatedPivotKey']) {
                    $arguments[] = $this->compiler->quote($relation['relatedPivotKey']);
                }
                $call = 'belongsToMany('.implode(', ', $arguments).')';
                break;
        }

        return "    public function {$name}()\n    {\n        return \$this->{$call};\n    }\n";
    }

    protected function writeFactory(array $spec, array $tables, array $models)
    {
        $model = $models[$spec['table']] ?? $this->modelFqcn($spec['table']);
        $class = $this->modelClass($spec['table']).'Factory';

        $availableTables = [];
        foreach ($tables as $tableSpec) {
            $availableTables[$tableSpec['table']] = true;
        }

        $fields = $this->compiler->parse($this->columnDefinitions($spec['columns']));
        $foreignKeys = [];
        foreach ($spec['foreignKeys'] as $foreignKey) {
            foreach ($foreignKey['columns'] as $column) {
                $foreignKeys[$column] = $foreignKey;
            }
        }

        $lines = [];
        foreach ($fields as $field) {
            $name = $field['name'];
            if (in_array($name, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
                continue;
            }

            if (isset($foreignKeys[$name])) {
                $foreignKey = $foreignKeys[$name];
                $target = $foreignKey['on'];
                $targetModel = $models[$target] ?? null;

                if ($target === $spec['table']) {
                    // 自引用外键不能继续调用自身 Factory，否则会无限递归。
                    $value = $this->isNullable($field) ? 'null' : '1';
                } elseif ($targetModel !== null && isset($availableTables[$target])) {
                    $value = '\\'.ltrim($targetModel, '\\').'::factory()';
                } elseif ($this->isNullable($field)) {
                    $value = 'null';
                } else {
                    $value = '1';
                }

                $lines[] = '            '.$this->compiler->quote($name).' => '.$value.',';
                continue;
            }

            $lines[] = '            '.$this->compiler->quote($name).' => '.$this->factoryValue($field).',';
        }

        $contents = $this->renderStub('json.factory.stub', [
            '{{ class }}' => $class,
            '{{ model }}' => '\\'.ltrim($model, '\\'),
            '{{ definition }}' => implode("\n", $lines),
        ]);

        $this->write('database/factories/'.$class.'.php', $contents);
    }

    protected function writeSeeder(array $spec, array $models)
    {
        $model = $models[$spec['table']] ?? $this->modelFqcn($spec['table']);
        $class = $this->modelClass($spec['table']).'Seeder';

        $contents = $this->renderStub('json.seeder.stub', [
            '{{ class }}' => $class,
            '{{ body }}' => '        \\'.ltrim($model, '\\').'::factory()->count(10)->create();',
        ]);

        $this->write('database/seeders/'.$class.'.php', $contents);
    }

    protected function factoryValue(array $field)
    {
        $name = $field['name'];
        $type = $field['type'];
        $modifiers = array_map(function ($modifier) {
            return strtolower($modifier['name']);
        }, $field['modifiers']);

        if ($name === 'email' || (in_array('unique', $modifiers, true) && str_contains($name, 'email'))) {
            return 'fake()->unique()->safeEmail()';
        }
        if ($name === 'password') {
            return '\\Illuminate\\Support\\Facades\\Hash::make(\'password\')';
        }
        if ($name === 'remember_token') {
            return '\\Illuminate\\Support\\Str::random(10)';
        }
        if (in_array($name, ['name', 'full_name', 'username'], true)) {
            return 'fake()->name()';
        }
        if (in_array($name, ['phone', 'mobile', 'telephone'], true)) {
            return 'fake()->phoneNumber()';
        }
        if ($name === 'address') {
            return 'fake()->address()';
        }
        if ($name === 'city') {
            return 'fake()->city()';
        }
        if ($name === 'country') {
            return 'fake()->country()';
        }
        if (in_array($name, ['url', 'website', 'website_url'], true)) {
            return 'fake()->url()';
        }
        if ($name === 'slug') {
            return 'fake()->unique()->slug()';
        }
        if ($name === 'title') {
            return 'fake()->sentence(3)';
        }
        if (in_array($name, ['description', 'body', 'content', 'remark', 'comment'], true)) {
            return 'fake()->paragraph()';
        }

        if ($type === 'uuid') {
            return '(string) \\Illuminate\\Support\\Str::uuid()';
        }
        if ($type === 'ulid') {
            return '(string) \\Illuminate\\Support\\Str::ulid()';
        }
        if ($type === 'enum') {
            $argumentString = trim($field['arguments']);
            if (str_starts_with($argumentString, '[') && str_ends_with($argumentString, ']')) {
                $argumentString = trim(substr($argumentString, 1, -1));
            }
            $values = $this->compiler->splitArguments($argumentString);

            return $values === [] ? 'fake()->word()' : 'fake()->randomElement(['.implode(', ', $values).'])';
        }

        $map = [
            'string' => 'fake()->word()',
            'char' => 'fake()->word()',
            'text' => 'fake()->paragraph()',
            'mediumText' => 'fake()->paragraph()',
            'longText' => 'fake()->paragraph()',
            'tinyText' => 'fake()->sentence()',
            'boolean' => 'fake()->boolean()',
            'integer' => 'fake()->numberBetween(1, 1000)',
            'bigInteger' => 'fake()->numberBetween(1, 1000)',
            'mediumInteger' => 'fake()->numberBetween(1, 1000)',
            'smallInteger' => 'fake()->numberBetween(1, 1000)',
            'tinyInteger' => 'fake()->numberBetween(1, 1000)',
            'unsignedBigInteger' => 'fake()->numberBetween(1, 1000)',
            'unsignedInteger' => 'fake()->numberBetween(1, 1000)',
            'unsignedMediumInteger' => 'fake()->numberBetween(1, 1000)',
            'unsignedSmallInteger' => 'fake()->numberBetween(1, 1000)',
            'unsignedTinyInteger' => 'fake()->numberBetween(1, 1000)',
            'foreignId' => 'fake()->numberBetween(1, 10)',
            'decimal' => 'fake()->randomFloat(2, 0, 1000)',
            'float' => 'fake()->randomFloat(2, 0, 1000)',
            'double' => 'fake()->randomFloat(2, 0, 1000)',
            'date' => 'fake()->date()',
            'dateTime' => 'fake()->dateTime()',
            'dateTimeTz' => 'fake()->dateTime()',
            'timestamp' => 'fake()->dateTime()',
            'timestampTz' => 'fake()->dateTime()',
            'time' => 'fake()->time()',
            'timeTz' => 'fake()->time()',
            'json' => '[]',
            'jsonb' => '[]',
            'ipAddress' => 'fake()->ipv4()',
            'macAddress' => 'fake()->macAddress()',
            'binary' => 'random_bytes(16)',
            'year' => 'fake()->year()',
        ];

        return $map[$type] ?? 'fake()->word()';
    }

    protected function isNullable(array $field)
    {
        foreach ($field['modifiers'] as $modifier) {
            if (strtolower($modifier['name']) === 'nullable') {
                return true;
            }
        }

        return false;
    }

    protected function exportList(array $values)
    {
        if (empty($values)) {
            return '[]';
        }

        return '['.implode(', ', array_map(function ($value) {
            return "'".str_replace("'", "\\'", (string) $value)."'";
        }, $values)).']';
    }

    protected function exportMap(array $values)
    {
        if (empty($values)) {
            return '[]';
        }

        $lines = [];
        foreach ($values as $key => $value) {
            $lines[] = '        '.$this->compiler->quote((string) $key).' => '.$this->compiler->quote((string) $value).',';
        }

        return "[\n".implode("\n", $lines)."\n    ]";
    }

    protected function modelClass($table)
    {
        return Str::studly(Str::singular($table));
    }

    protected function modelFqcn($table)
    {
        return '\\'.ltrim($this->modelNamespace.'\\'.$this->modelClass($table), '\\');
    }

    protected function modelDirectory($namespace)
    {
        if ($namespace === 'App') {
            return 'app';
        }

        if (str_starts_with($namespace, 'App\\')) {
            return 'app/'.str_replace('\\', '/', substr($namespace, 4));
        }

        return 'app/Models';
    }

    protected function schemaBlock($method, $tableName, array $lines)
    {
        $opening = $method === 'create'
            ? "Schema::create('{$tableName}', function (Blueprint \$table) {"
            : "Schema::table('{$tableName}', function (Blueprint \$table) {";
        $body = empty($lines) ? '            //' : '            '.implode("\n            ", $lines);

        return $opening."\n".$body."\n        });";
    }

    protected function renderStub($name, array $replacements)
    {
        $stub = $this->filesystem->get(__DIR__.'/stubs/'.$name);

        return str_replace(array_keys($replacements), array_values($replacements), $stub);
    }

    protected function write($relative, $contents)
    {
        $absolute = $this->path($relative);
        if ($this->filesystem->exists($absolute)) {
            return false;
        }

        $directory = dirname($absolute);
        if (! $this->filesystem->isDirectory($directory)) {
            $this->filesystem->makeDirectory($directory, 0755, true);
        }
        $this->filesystem->put($absolute, $contents);
        $this->generated[] = $relative;

        return true;
    }

    protected function path($relative)
    {
        return $this->basePath.'/'.ltrim(str_replace('\\', '/', $relative), '/');
    }
}
