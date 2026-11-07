<?php

namespace Yousheng\LaravelDevHelper\Console;

/**
 * 把 "name:string(50):nullable" 形式的字段定义编译为 Blueprint 语句。
 *
 * 同时提供 parse() 结构化解析，供 Model / Factory 生成器复用。
 */
class JsonFieldCompiler
{
    /**
     * 这些修饰符属于外键约束，编译时需要拆成独立的 $table->foreign(...) 语句。
     */
    private const FOREIGN_MODIFIERS = ['references', 'on', 'onDelete', 'onUpdate'];

    /**
     * 这些 Blueprint 方法不需要把列名作为第一个参数。
     */
    private const NO_COLUMN_ARGUMENT = [
        'timestamps', 'timestampsTz', 'nullableTimestamps', 'nullableTimestampsTz', 'rememberToken',
    ];

    /**
     * @param string $definitions 逗号分隔的字段定义
     * @return array {lines: string[], names: string[]}
     */
    public function compile($definitions)
    {
        $lines = [];
        $names = [];

        foreach ($this->parse($definitions) as $field) {
            $names[] = $field['name'];
            $arguments = $this->normalizeArguments($field);

            if (in_array($field['type'], self::NO_COLUMN_ARGUMENT, true)) {
                $call = '$table->' . $field['type'] . '(' . $arguments . ')';
            } else {
                $call = '$table->' . $field['type'] . '(' . $this->quote($field['name'])
                    . ($arguments === '' ? '' : ', ' . $arguments) . ')';
            }

            $hasForeign = false;
            $foreignParts = [];
            foreach ($field['modifiers'] as $modifier) {
                if ($modifier['name'] === 'foreign') {
                    $hasForeign = true;
                    continue;
                }
                if (in_array($modifier['name'], self::FOREIGN_MODIFIERS, true)) {
                    $foreignParts[] = '->' . $modifier['name'] . '(' . $modifier['arguments'] . ')';
                    $hasForeign = true;
                    continue;
                }
                $call .= '->' . $modifier['name'] . '(' . $modifier['arguments'] . ')';
            }

            $lines[] = $call . ';';

            if ($hasForeign && ! empty($foreignParts)) {
                $lines[] = '$table->foreign(' . $this->quote($field['name']) . ')'
                    . implode('', $foreignParts) . ';';
            }
        }

        return ['lines' => $lines, 'names' => $names];
    }

    /**
     * 把字段定义解析成结构化数组。
     *
     * 返回示例：
     * [
     *     'name' => 'email',
     *     'type' => 'string',
     *     'arguments' => '255',
     *     'modifiers' => [
     *         ['name' => 'unique', 'arguments' => ''],
     *     ],
     * ]
     *
     * @param string $definitions
     * @return array<int, array{name: string, type: string, arguments: string, modifiers: array}>
     */
    public function parse($definitions)
    {
        $fields = [];

        foreach ($this->split((string) $definitions, ',') as $definition) {
            $definition = trim($definition);
            if ($definition === '') {
                continue;
            }

            $segments = $this->split($definition, ':');
            $name = trim((string) array_shift($segments));
            if (! $this->isIdentifier($name)) {
                continue;
            }

            list($type, $arguments) = $this->parseCall(array_shift($segments));
            if (! $this->isIdentifier($type)) {
                continue;
            }

            $modifiers = [];
            foreach ($segments as $segment) {
                list($modifier, $modifierArguments) = $this->parseCall($segment);
                if (! $this->isIdentifier($modifier)) {
                    continue;
                }
                $modifiers[] = [
                    'name' => $modifier,
                    'arguments' => $modifierArguments,
                ];
            }

            $fields[] = [
                'name' => $name,
                'type' => $type,
                'arguments' => $arguments,
                'modifiers' => $modifiers,
            ];
        }

        return $fields;
    }

    /**
     * enum('a','b') / set('a','b') 在 Laravel 中需要数组参数。
     */
    protected function normalizeArguments(array $field)
    {
        $arguments = $field['arguments'];

        if (! in_array($field['type'], ['enum', 'set'], true) || $arguments === '') {
            return $arguments;
        }

        $trimmed = ltrim($arguments);
        if (str_starts_with($trimmed, '[')) {
            return $arguments;
        }

        return '['.implode(', ', $this->splitArguments($arguments)).']';
    }

    /**
     * 解析 enum('a','b') 这类参数列表。
     *
     * @param string $arguments
     * @return array
     */
    public function splitArguments($arguments)
    {
        return $this->split((string) $arguments, ',');
    }

    /**
     * @param array $names
     * @return string PHP 数组字面量，如 ['city', 'state']
     */
    public function quoteList(array $names)
    {
        if (empty($names)) {
            return '';
        }

        return '[' . implode(', ', array_map([$this, 'quote'], $names)) . ']';
    }

    /**
     * 按分隔符切分，忽略括号与引号内部的分隔符。
     *
     * 例如 "price:decimal(8,2):default(0), name:string" 按逗号切分时
     * decimal 参数中的逗号不会被误认为字段边界。
     *
     * @param string $value
     * @param string $delimiter
     * @return array
     */
    protected function split($value, $delimiter)
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

    /**
     * 拆分 "string(50)" / "default('abc')" 为 [方法名, 参数]。
     *
     * @param string $token
     * @return array
     */
    protected function parseCall($token)
    {
        $token = trim((string) $token);
        if (preg_match('/^([a-zA-Z_][a-zA-Z0-9_]*)\s*\((.*)\)$/s', $token, $matches)) {
            return [$matches[1], trim($matches[2])];
        }

        return [$token, ''];
    }

    protected function isIdentifier($value)
    {
        return (bool) preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', (string) $value);
    }

    public function quote($value)
    {
        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
    }
}
