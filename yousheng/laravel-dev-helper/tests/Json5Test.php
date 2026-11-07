<?php

namespace Yousheng\LaravelDevHelper\Console\Tests;

use JsonException;
use PHPUnit\Framework\TestCase;
use Yousheng\LaravelDevHelper\Support\Json5;

class Json5Test extends TestCase
{
    public function testItParsesCommentsTrailingCommasAndUnquotedKeys()
    {
        $value = Json5::decode(<<<'JSON5'
{
    // 行注释
    users: {
        table: 'comment(用户表)',
        email: "string:nullable", /* 块注释 */
        active: true,
    },
}
JSON5);

        $this->assertSame('comment(用户表)', $value['users']['table']);
        $this->assertSame('string:nullable', $value['users']['email']);
        $this->assertTrue($value['users']['active']);
    }

    public function testItDoesNotStripCommentLikeTextInsideStrings()
    {
        $value = Json5::decode(<<<'JSON5'
{
    url: "http://example.com//not-a-comment/*still-not*/",
    // 真正的注释
    single: 'a // b /* c */',
}
JSON5);

        $this->assertSame('http://example.com//not-a-comment/*still-not*/', $value['url']);
        $this->assertSame('a // b /* c */', $value['single']);
    }

    public function testItSupportsEscapesAndUnicode()
    {
        $value = Json5::decode(<<<'JSON5'
{
    chinese: "\u4f60\u597d",
    hex: "\x41\x42",
    escaped: 'it\'s ok',
    multiline: 'line1\
line2',
}
JSON5);

        $this->assertSame('你好', $value['chinese']);
        $this->assertSame('AB', $value['hex']);
        $this->assertSame("it's ok", $value['escaped']);
        $this->assertSame('line1line2', $value['multiline']);
    }

    public function testItSupportsNumbersAndArrays()
    {
        $value = Json5::decode(<<<'JSON5'
{
    int: 42,
    negative: -7,
    plus: +3,
    float: .5,
    hex: 0x1A,
    list: [1, 2, 3,],
}
JSON5);

        $this->assertSame(42, $value['int']);
        $this->assertSame(-7, $value['negative']);
        $this->assertSame(3, $value['plus']);
        $this->assertSame(0.5, $value['float']);
        $this->assertSame(26, $value['hex']);
        $this->assertSame([1, 2, 3], $value['list']);
    }

    public function testItThrowsOnInvalidJson5()
    {
        $this->expectException(JsonException::class);

        Json5::decode('{ "users": { ');
    }

    public function testItThrowsOnUnterminatedBlockComment()
    {
        $this->expectException(JsonException::class);

        Json5::decode('{ a: 1 /* no end }');
    }
}
