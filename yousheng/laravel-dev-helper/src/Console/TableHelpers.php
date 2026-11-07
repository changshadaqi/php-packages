<?php

namespace Yousheng\LaravelDevHelper\Console;

use Illuminate\Support\Facades\DB;

/**
 * 基于原生 SQL 从 information_schema 读取表名的辅助 trait。
 */
trait TableHelpers
{
    // 查询指定数据库下所有基表表名的 SQL 模板。
    protected static $templateGetTables = "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_TYPE='BASE TABLE' AND TABLE_SCHEMA='%s'";

    /**
     * 从数据库中读取所有的表
     *
     * @return mixed
     */
    public function tables($databaseName)
    {
        return DB::select(sprintf(static::$templateGetTables, $databaseName));
    }
}
