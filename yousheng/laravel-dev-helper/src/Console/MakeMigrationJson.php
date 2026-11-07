<?php

namespace Yousheng\LaravelDevHelper\Console;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Grammars\MySqlGrammar;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Yousheng\LaravelDevHelper\Support\Json5;

/**
 * 解析 make:migration:json 的 schema 文件。
 *
 * 格式：
 * {
 *   "users": {
 *     "table": "comment(用户表):index(name):constraint(roles|id|role_id|cascade|set null)",
 *     "email": "string:nullable:index"
 *   }
 * }
 *
 * 每张表的第一个键是 table（可选），其余键全部是列定义。
 */
class MakeMigrationJson
{
    private const COLUMN_TYPES = [
        'bigIncrements', 'bigInteger', 'binary', 'boolean', 'char', 'date', 'dateTime',
        'dateTimeTz', 'decimal', 'double', 'enum', 'float', 'foreignId', 'foreignUlid',
        'foreignUuid', 'geography', 'geometry', 'id', 'increments', 'integer', 'ipAddress',
        'json', 'jsonb', 'longText', 'macAddress', 'mediumIncrements', 'mediumInteger',
        'mediumText', 'morphs', 'numericMorphs', 'nullableMorphs', 'nullableNumericMorphs',
        'nullableTimestamps', 'nullableTimestampsTz', 'nullableUlidMorphs',
        'nullableUuidMorphs', 'rememberToken', 'set', 'smallIncrements', 'smallInteger',
        'softDeletes', 'softDeletesDatetime', 'softDeletesTz', 'string', 'text', 'time',
        'timeTz', 'timestamp', 'timestampTz', 'timestamps', 'timestampsTz', 'tinyIncrements',
        'tinyInteger', 'tinyText', 'tsvector', 'ulid', 'ulidMorphs', 'unsignedBigInteger',
        'unsignedInteger', 'unsignedMediumInteger', 'unsignedSmallInteger',
        'unsignedTinyInteger', 'uuid', 'uuidMorphs', 'vector', 'year',
    ];

    private const COLUMN_MODIFIERS = [
        'after', 'always', 'autoIncrement', 'autoincrement', 'cascadeOnDelete',
        'cascadeOnUpdate', 'change', 'charset', 'collate', 'collation', 'comment',
        'constrained', 'default', 'first', 'foreign', 'from', 'fulltext', 'generatedAs',
        'index', 'instant', 'invisible', 'lock', 'noActionOnDelete', 'noActionOnUpdate',
        'nullOnDelete', 'nullOnUpdate', 'nullable', 'on', 'onDelete', 'onUpdate',
        'persisted', 'primary', 'references', 'restrictOnDelete', 'restrictOnUpdate',
        'spatialIndex', 'startingValue', 'storedAs', 'unsigned', 'unique', 'useCurrent',
        'useCurrentOnUpdate', 'using', 'vectorIndex', 'virtualAs',
    ];

    private const TABLE_DIRECTIVES = [
        'comment', 'index', 'unique', 'primary', 'constraint', 'engine', 'charset',
        'collation', 'timestamps', 'softDeletes',
    ];

    /** @var Filesystem */
    protected $filesystem;

    /** @var string|null */
    protected $blueprintClass;

    public function __construct(?Filesystem $filesystem = null)
    {
        $this->filesystem = $filesystem ?: new Filesystem();
        $this->blueprintClass = class_exists(Blueprint::class) ? Blueprint::class : null;
    }

    public function jsonFileToArray($path)
    {
        if (! is_file($path)) {
            return null;
        }

        $contents = $this->filesystem->get($path);
        $data = json_decode($contents, true);

        if (json_last_error() === JSON_ERROR_NONE) {
            return $data;
        }

        // 严格 JSON 解析失败时按 JSON5 再解析，支持注释、尾随逗号、单引号等。
        try {
            return Json5::decode($contents, true);
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * 解析为生成器所需的表结构。
     *
     * @param array $data
     * @param array $only
     * @return array
     */
    public function parseTables(array $data, array $only = [])
    {
        $tables = [];

        foreach ($data as $key => $definition) {
            if (! is_string($key)) {
                continue;
            }

            $spec = $this->normalizeTable($key, $definition);

            if (! empty($only)
                && ! in_array($key, $only, true)
                && ! in_array($spec['table'], $only, true)
                && ! in_array($spec['migration'], $only, true)) {
                continue;
            }

            $tables[$spec['migration']] = $spec;
        }

        return $this->sortByDependencies($tables);
    }

    /**
     * 校验 schema，返回 [表名 => [字段/区段 => [类型 => 说明]]]。
     */
    public function validateSchema(array $data)
    {
        $errors = [];

        foreach ($data as $key => $definition) {
            if (! is_string($key)) {
                $errors['__root__'][(string) $key]['tableName'] = 'table name must be a string';
                continue;
            }

            try {
                $spec = $this->normalizeTable($key, $definition);
            } catch (\Throwable $e) {
                $errors[$key]['__table__']['error'] = $e->getMessage();
                continue;
            }

            foreach ($spec['columns'] as $column => $definitionString) {
                $this->validateColumnDefinition($key, $column, $definitionString, $errors);
            }

            foreach ($spec['primary'] as $column) {
                if (! array_key_exists($column, $spec['columns'])) {
                    $errors[$key]['__primary__']['primary'] = "primary column [{$column}] does not exist";
                }
            }

            foreach ($spec['indexes'] as $index) {
                foreach ($index['columns'] as $column) {
                    if (! array_key_exists($column, $spec['columns'])) {
                        $errors[$key]['__indexes__']['index'] = "index column [{$column}] does not exist";
                    }
                }
            }

            foreach ($spec['foreignKeys'] as $foreignKey) {
                foreach ($foreignKey['columns'] as $column) {
                    if (! array_key_exists($column, $spec['columns'])) {
                        $errors[$key]['__foreignKeys__']['constraint'] = "foreign key column [{$column}] does not exist";
                    }
                }
            }
        }

        try {
            $this->parseTables($data);
        } catch (\Throwable $e) {
            $errors['__dependencies__']['__cycle__']['error'] = $e->getMessage();
        }

        return $errors;
    }

    public function parseProperty($type)
    {
        $type = explode('(', $type);

        return trim($type[0]);
    }

    public function isValidColumnType($type)
    {
        if (! is_string($type) || ! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $type)) {
            return false;
        }

        if ($this->blueprintClass !== null && method_exists($this->blueprintClass, $type)) {
            return true;
        }

        return in_array($type, self::COLUMN_TYPES, true);
    }

    public function getColumnIndexes()
    {
        return ['primary', 'unique', 'index', 'foreign'];
    }

    public function getColumnModifiers()
    {
        $modifiers = self::COLUMN_MODIFIERS;

        if (class_exists(MySqlGrammar::class)) {
            try {
                $reflection = new \ReflectionClass(MySqlGrammar::class);
                if ($reflection->hasProperty('modifiers')) {
                    $property = $reflection->getProperty('modifiers');
                    $property->setAccessible(true);
                    $values = $property->getValue($reflection->newInstanceWithoutConstructor());
                    if (is_array($values)) {
                        $modifiers = array_merge($modifiers, $values);
                    }
                }
            } catch (\Throwable $e) {
                // 反射不可用时只用内置白名单。
            }
        }

        return array_values(array_unique(array_map('strtolower', $modifiers)));
    }

    /**
     * 单张表标准化。
     *
     * @return array
     */
    protected function normalizeTable($key, $definition)
    {
        $operation = 'create';
        $table = Str::snake($key);
        $migration = "create_{$table}_table";

        if (preg_match('/^create_(.+)_table$/', $key, $matches)) {
            $table = $matches[1];
            $migration = $key;
        } elseif (preg_match('/^add_(.+)_to_(.+)_table$/', $key, $matches)) {
            $table = $matches[2];
            $operation = 'add';
            $migration = $key;
        } elseif (preg_match('/^remove_(.+)_from_(.+)_table$/', $key, $matches)) {
            $table = $matches[2];
            $operation = 'remove';
            $migration = $key;
        }

        if (! is_array($definition)) {
            throw new \InvalidArgumentException("Table [{$key}] must be an object.");
        }

        $keys = array_keys($definition);
        $hasTableDirective = array_key_exists('table', $definition);

        if ($hasTableDirective && $keys[0] !== 'table') {
            throw new \InvalidArgumentException("Table [{$key}]: [table] must be the first key.");
        }

        if ($hasTableDirective && ! is_string($definition['table'])) {
            throw new \InvalidArgumentException("Table [{$key}]: [table] must be a string.");
        }

        $directives = $hasTableDirective
            ? $this->parseTableDirectives($table, $definition['table'])
            : $this->emptyDirectives();

        $columns = [];
        foreach ($definition as $column => $columnDefinition) {
            if ($column === 'table') {
                continue;
            }
            if (! is_string($columnDefinition)) {
                throw new \InvalidArgumentException("Table [{$key}], column [{$column}]: definition must be a string.");
            }
            $columns[$column] = $columnDefinition;
        }

        return [
            'table' => $table,
            'migration' => $migration,
            'operation' => $operation,
            'columns' => $columns,
            'comment' => $directives['comment'],
            'engine' => $directives['engine'],
            'charset' => $directives['charset'],
            'collation' => $directives['collation'],
            'timestamps' => $directives['timestamps'],
            'softDeletes' => $directives['softDeletes'],
            'primary' => $directives['primary'],
            'indexes' => $directives['indexes'],
            'foreignKeys' => $directives['foreignKeys'],
        ];
    }

    protected function emptyDirectives()
    {
        return [
            'comment' => null,
            'engine' => null,
            'charset' => null,
            'collation' => null,
            'timestamps' => true,
            'softDeletes' => false,
            'primary' => [],
            'indexes' => [],
            'foreignKeys' => [],
        ];
    }

    /**
     * 解析 table 字段。
     *
     * @return array
     */
    protected function parseTableDirectives($table, $value)
    {
        $result = $this->emptyDirectives();

        foreach ($this->splitTopLevel($value, ':') as $directive) {
            $directive = trim($directive);
            if ($directive === '') {
                continue;
            }

            if (! preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s*(?:\((.*)\))?$/s', $directive, $matches)) {
                throw new \InvalidArgumentException("Table [{$table}]: invalid table directive [{$directive}].");
            }

            $name = $matches[1];
            $arguments = $matches[2] ?? null;

            if (! in_array($name, self::TABLE_DIRECTIVES, true)) {
                throw new \InvalidArgumentException("Table [{$table}]: unknown table directive [{$name}].");
            }

            switch ($name) {
                case 'comment':
                    $result['comment'] = $arguments !== null ? trim($arguments) : null;
                    break;

                case 'engine':
                    $result['engine'] = $this->requiredArgument($table, $name, $arguments);
                    break;

                case 'charset':
                    $result['charset'] = $this->requiredArgument($table, $name, $arguments);
                    break;

                case 'collation':
                    $result['collation'] = $this->requiredArgument($table, $name, $arguments);
                    break;

                case 'timestamps':
                    $result['timestamps'] = $this->booleanArgument($arguments, true);
                    break;

                case 'softDeletes':
                    $result['softDeletes'] = $this->booleanArgument($arguments, true);
                    break;

                case 'index':
                case 'unique':
                case 'primary':
                    $index = $this->parseIndex($table, $name, $arguments);
                    if ($name === 'primary') {
                        $result['primary'] = $index['columns'];
                    } else {
                        $result['indexes'][] = $index;
                    }
                    break;

                case 'constraint':
                    $result['foreignKeys'][] = $this->parseConstraint($table, $arguments);
                    break;
            }
        }

        return $result;
    }

    protected function requiredArgument($table, $directive, $arguments)
    {
        if ($arguments === null || trim($arguments) === '') {
            throw new \InvalidArgumentException("Table [{$table}]: directive [{$directive}] requires an argument.");
        }

        return trim($arguments);
    }

    protected function booleanArgument($arguments, $default)
    {
        if ($arguments === null || trim($arguments) === '') {
            return $default;
        }

        $value = strtolower(trim($arguments));
        if (in_array($value, ['false', '0', 'no', 'off'], true)) {
            return false;
        }
        if (in_array($value, ['true', '1', 'yes', 'on'], true)) {
            return true;
        }

        return $default;
    }

    /**
     * index(column1,column2|index_name)
     */
    protected function parseIndex($table, $type, $arguments)
    {
        if ($arguments === null || trim($arguments) === '') {
            throw new \InvalidArgumentException("Table [{$table}]: directive [{$type}] requires columns.");
        }

        $parts = explode('|', $arguments, 2);
        $columns = array_values(array_filter(array_map('trim', explode(',', $parts[0]))));
        if (empty($columns)) {
            throw new \InvalidArgumentException("Table [{$table}]: directive [{$type}] requires columns.");
        }

        return [
            'type' => $type === 'primary' ? 'primary' : ($type === 'unique' ? 'unique' : 'index'),
            'columns' => $columns,
            'name' => isset($parts[1]) && trim($parts[1]) !== '' ? trim($parts[1]) : null,
        ];
    }

    /**
     * constraint(roles|id|role_id|cascade|set null)
     */
    protected function parseConstraint($table, $arguments)
    {
        if ($arguments === null || trim($arguments) === '') {
            throw new \InvalidArgumentException("Table [{$table}]: directive [constraint] requires arguments.");
        }

        $parts = array_map('trim', explode('|', $arguments));
        if (count($parts) < 3 || count($parts) > 6) {
            throw new \InvalidArgumentException(
                "Table [{$table}]: constraint must have 3 to 6 segments (referencedTable|referencedColumns|localColumns|onDelete|onUpdate|name)."
            );
        }

        $referencedTable = $parts[0];
        $referencedColumns = array_values(array_filter(array_map('trim', explode(',', $parts[1]))));
        $localColumns = array_values(array_filter(array_map('trim', explode(',', $parts[2]))));

        if ($referencedTable === '' || empty($referencedColumns) || empty($localColumns)) {
            throw new \InvalidArgumentException("Table [{$table}]: constraint requires referenced table, referenced columns and local columns.");
        }

        return [
            'columns' => $localColumns,
            'references' => $referencedColumns,
            'on' => $referencedTable,
            'onDelete' => $this->nullablePart($parts[3] ?? null),
            'onUpdate' => $this->nullablePart($parts[4] ?? null),
            'name' => $this->nullablePart($parts[5] ?? null),
        ];
    }

    protected function nullablePart($value)
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * 按依赖排序：被引用表先出现。
     *
     * @param array $tables
     * @return array
     */
    protected function sortByDependencies(array $tables)
    {
        $byTable = [];
        foreach ($tables as $spec) {
            $byTable[$spec['table']] = $spec;
        }

        $dependencies = [];
        foreach ($byTable as $table => $spec) {
            $dependencies[$table] = [];
            foreach ($spec['foreignKeys'] as $foreignKey) {
                $referenced = $foreignKey['on'];
                if ($referenced !== $table && isset($byTable[$referenced])) {
                    $dependencies[$table][$referenced] = true;
                }
            }
        }

        $sorted = [];
        $state = [];
        $stack = [];

        $visit = function ($table) use (&$visit, &$state, &$sorted, &$stack, $dependencies) {
            if (($state[$table] ?? 0) === 2) {
                return;
            }

            if (($state[$table] ?? 0) === 1) {
                $position = array_search($table, $stack, true);
                $cycle = $position === false ? [$table] : array_slice($stack, $position);
                $cycle[] = $table;

                throw new \InvalidArgumentException('Circular foreign key dependency: '.implode(' -> ', $cycle));
            }

            $state[$table] = 1;
            $stack[] = $table;

            foreach (array_keys($dependencies[$table]) as $dependency) {
                $visit($dependency);
            }

            array_pop($stack);
            $state[$table] = 2;
            $sorted[] = $table;
        };

        foreach (array_keys($byTable) as $table) {
            $visit($table);
        }

        $result = [];
        foreach ($sorted as $table) {
            $spec = $byTable[$table];
            $result[$spec['migration']] = $spec;
        }

        return $result;
    }

    protected function validateColumnDefinition($tableKey, $column, $definition, array &$errors)
    {
        $validModifiers = array_map('strtolower', array_merge(
            $this->getColumnIndexes(),
            $this->getColumnModifiers()
        ));

        $segments = $this->splitTopLevel($definition, ':');
        $type = $this->parseProperty($segments[0] ?? '');

        if (! $this->isValidColumnType($type)) {
            $errors[$tableKey][$column]['columnType'] = "'{$type}' is not a valid column type";
        }

        foreach (array_slice($segments, 1) as $modifier) {
            if (! in_array(strtolower($this->parseProperty($modifier)), $validModifiers, true)) {
                $errors[$tableKey][$column]['columnModifier'] = "'{$modifier}' is not a valid column modifier";
            }
        }
    }

    /**
     * 按分隔符切分，忽略括号和引号内部的分隔符。
     */
    protected function splitTopLevel($value, $delimiter)
    {
        $parts = [];
        $buffer = '';
        $depth = 0;
        $quote = null;
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $char = $value[$i];

            if ($quote !== null) {
                $buffer .= $char;
                if ($char === '\\' && $i + 1 < $length) {
                    $buffer .= $value[++$i];
                } elseif ($char === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }

            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth = max(0, $depth - 1);
            } elseif ($char === $delimiter && $depth === 0) {
                $parts[] = trim($buffer);
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        if (trim($buffer) !== '') {
            $parts[] = trim($buffer);
        }

        return $parts;
    }
}
