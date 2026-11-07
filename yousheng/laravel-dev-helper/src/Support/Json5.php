<?php

namespace Yousheng\LaravelDevHelper\Support;

/**
 * 轻量 JSON5 解析器。
 *
 * 支持 schema 文件最常用的 JSON5 特性：
 * - // 与 /* *\/ 注释
 * - 尾随逗号
 * - 单引号字符串
 * - 未加引号的对象键
 * - 十六进制、正负号、小数、科学计数法
 * - \xNN、\uNNNN 转义
 *
 * 不依赖外部包，解析结果可直接交给 MakeMigrationJson 使用。
 */
class Json5
{
    /** @var string */
    private $input;

    /** @var int */
    private $length;

    /** @var int */
    private $position = 0;

    /** @var array */
    private $tokens = [];

    /** @var int */
    private $index = 0;

    /** @var bool */
    private $assoc;

    public static function decode($input, $assoc = true)
    {
        $parser = new self($input, $assoc);

        return $parser->parseDocument();
    }

    private function __construct($input, $assoc)
    {
        $this->input = (string) $input;
        $this->length = strlen($this->input);
        $this->assoc = (bool) $assoc;

        if (strncmp($this->input, "\xEF\xBB\xBF", 3) === 0) {
            $this->position = 3;
        }

        $this->tokens = $this->tokenize();
    }

    private function parseDocument()
    {
        if ($this->current()['type'] === 'eof') {
            throw new \JsonException('JSON5 document is empty.');
        }

        $value = $this->parseValue();

        if ($this->current()['type'] !== 'eof') {
            throw new \JsonException('Unexpected token after JSON5 value.');
        }

        return $value;
    }

    private function parseValue()
    {
        $token = $this->current();

        if ($token['type'] === 'string' || $token['type'] === 'number') {
            $this->advance();

            return $token['value'];
        }

        if ($token['type'] === 'identifier') {
            $this->advance();

            switch ($token['value']) {
                case 'true':
                    return true;
                case 'false':
                    return false;
                case 'null':
                    return null;
                case 'Infinity':
                    return INF;
                case 'NaN':
                    return NAN;
            }

            throw new \JsonException("Unexpected identifier [{$token['value']}].");
        }

        if ($token['type'] === 'punct') {
            if ($token['value'] === '{') {
                return $this->parseObject();
            }
            if ($token['value'] === '[') {
                return $this->parseArray();
            }
        }

        throw new \JsonException('Unexpected token ['.$token['value'].'].');
    }

    private function parseObject()
    {
        $this->expectPunct('{');
        $object = [];

        if ($this->matchPunct('}')) {
            return $this->assoc ? $object : (object) $object;
        }

        while (true) {
            $keyToken = $this->current();

            if ($keyToken['type'] === 'string' || $keyToken['type'] === 'identifier') {
                $key = (string) $keyToken['value'];
            } elseif ($keyToken['type'] === 'number') {
                $key = (string) $keyToken['value'];
            } else {
                throw new \JsonException('Invalid JSON5 object key.');
            }

            $this->advance();
            $this->expectPunct(':');
            $object[$key] = $this->parseValue();

            if ($this->matchPunct(',')) {
                if ($this->checkPunct('}')) {
                    $this->advance();
                    break;
                }
                continue;
            }

            $this->expectPunct('}');
            break;
        }

        return $this->assoc ? $object : (object) $object;
    }

    private function parseArray()
    {
        $this->expectPunct('[');
        $array = [];

        if ($this->matchPunct(']')) {
            return $array;
        }

        while (true) {
            $array[] = $this->parseValue();

            if ($this->matchPunct(',')) {
                if ($this->checkPunct(']')) {
                    $this->advance();
                    break;
                }
                continue;
            }

            $this->expectPunct(']');
            break;
        }

        return $array;
    }

    private function current()
    {
        return $this->tokens[$this->index] ?? ['type' => 'eof', 'value' => ''];
    }

    private function advance()
    {
        if ($this->index < count($this->tokens) - 1) {
            $this->index++;
        }
    }

    private function matchPunct($value)
    {
        if ($this->checkPunct($value)) {
            $this->advance();

            return true;
        }

        return false;
    }

    private function checkPunct($value)
    {
        $token = $this->current();

        return $token['type'] === 'punct' && $token['value'] === $value;
    }

    private function expectPunct($value)
    {
        if (! $this->matchPunct($value)) {
            throw new \JsonException("Expected [{$value}].");
        }
    }

    private function tokenize()
    {
        $tokens = [];

        while ($this->position < $this->length) {
            $this->skipWhitespaceAndComments();

            if ($this->position >= $this->length) {
                break;
            }

            $char = $this->input[$this->position];

            if (strpos('{}[]:,', $char) !== false) {
                $tokens[] = ['type' => 'punct', 'value' => $char];
                $this->position++;
                continue;
            }

            if ($char === "'" || $char === '"') {
                $tokens[] = ['type' => 'string', 'value' => $this->readString($char)];
                continue;
            }

            if (($char >= '0' && $char <= '9')
                || $char === '.'
                || (($char === '+' || $char === '-') && $this->looksLikeNumber($this->position))) {
                $tokens[] = ['type' => 'number', 'value' => $this->readNumber()];
                continue;
            }

            if (preg_match('/^[$_\p{L}][$_\p{L}\p{N}]*/u', substr($this->input, $this->position), $matches)) {
                $tokens[] = ['type' => 'identifier', 'value' => $matches[0]];
                $this->position += strlen($matches[0]);
                continue;
            }

            throw new \JsonException("Unexpected character [{$char}] in JSON5.");
        }

        $tokens[] = ['type' => 'eof', 'value' => ''];

        return $tokens;
    }

    private function skipWhitespaceAndComments()
    {
        while ($this->position < $this->length) {
            $char = $this->input[$this->position];

            if (strpos(" \t\r\n\v\f", $char) !== false) {
                $this->position++;
                continue;
            }

            if ($char === '/' && $this->position + 1 < $this->length) {
                $next = $this->input[$this->position + 1];

                if ($next === '/') {
                    $this->position += 2;
                    while ($this->position < $this->length
                        && $this->input[$this->position] !== "\n"
                        && $this->input[$this->position] !== "\r") {
                        $this->position++;
                    }
                    continue;
                }

                if ($next === '*') {
                    $end = strpos($this->input, '*/', $this->position + 2);
                    if ($end === false) {
                        throw new \JsonException('Unterminated JSON5 block comment.');
                    }
                    $this->position = $end + 2;
                    continue;
                }
            }

            break;
        }
    }

    private function looksLikeNumber($position)
    {
        $remaining = substr($this->input, $position + 1, 2);

        return $remaining !== ''
            && (ctype_digit($remaining[0]) || $remaining[0] === '.' || $remaining === 'In' || $remaining === 'Na');
    }

    private function readNumber()
    {
        $remaining = substr($this->input, $this->position);

        if (! preg_match('/^[+-]?(?:Infinity|NaN|0[xX][0-9a-fA-F]+|(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?)/', $remaining, $matches)) {
            throw new \JsonException('Invalid JSON5 number.');
        }

        $raw = $matches[0];
        $this->position += strlen($raw);
        $sign = 1;
        $body = $raw;

        if ($body[0] === '+' || $body[0] === '-') {
            $sign = $body[0] === '-' ? -1 : 1;
            $body = substr($body, 1);
        }

        if ($body === 'Infinity') {
            return $sign === -1 ? -INF : INF;
        }

        if ($body === 'NaN') {
            return NAN;
        }

        if (stripos($body, '0x') === 0) {
            $value = hexdec(substr($body, 2));

            return $sign === -1 ? -$value : $value;
        }

        if (strpbrk($body, '.eE') !== false) {
            return (float) $raw;
        }

        return $sign === -1 ? -(int) $body : (int) $body;
    }

    private function readString($quote)
    {
        $this->position++;
        $result = '';

        while ($this->position < $this->length) {
            $char = $this->input[$this->position++];

            if ($char === $quote) {
                return $result;
            }

            if ($char === '\\') {
                $result .= $this->readEscape();
                continue;
            }

            if ($char === "\n" || $char === "\r") {
                throw new \JsonException('Unescaped line break in JSON5 string.');
            }

            $result .= $char;
        }

        throw new \JsonException('Unterminated JSON5 string.');
    }

    private function readEscape()
    {
        if ($this->position >= $this->length) {
            throw new \JsonException('Unterminated JSON5 escape.');
        }

        $char = $this->input[$this->position++];

        switch ($char) {
            case 'b':
                return "\x08";
            case 'f':
                return "\x0C";
            case 'n':
                return "\n";
            case 'r':
                return "\r";
            case 't':
                return "\t";
            case 'v':
                return "\x0B";
            case '0':
                return "\0";
            case "\n":
                return '';
            case "\r":
                if ($this->position < $this->length && $this->input[$this->position] === "\n") {
                    $this->position++;
                }
                return '';
            case 'x':
                return mb_chr(hexdec($this->readHex(2)), 'UTF-8');
            case 'u':
                $code = hexdec($this->readHex(4));

                // 处理 UTF-16 代理对
                if ($code >= 0xD800 && $code <= 0xDBFF
                    && substr($this->input, $this->position, 2) === '\\u') {
                    $saved = $this->position;
                    $this->position += 2;
                    $low = hexdec($this->readHex(4));
                    if ($low >= 0xDC00 && $low <= 0xDFFF) {
                        $code = 0x10000 + (($code - 0xD800) << 10) + ($low - 0xDC00);
                    } else {
                        $this->position = $saved;
                    }
                }

                return mb_chr($code, 'UTF-8');
            default:
                return $char;
        }
    }

    private function readHex($length)
    {
        $hex = substr($this->input, $this->position, $length);
        if (strlen($hex) !== $length || ! ctype_xdigit($hex)) {
            throw new \JsonException('Invalid JSON5 hex escape.');
        }

        $this->position += $length;

        return $hex;
    }
}
