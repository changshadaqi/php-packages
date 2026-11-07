<?php

namespace Yousheng\LaravelDevHelper\Console;

use Illuminate\Database\Query\Expression;
use Illuminate\Support\Str;

/**
 * 代码生成器共用的工具方法 trait（表名/模型名转换、是否可生成等）。
 */
trait UtilsTrait
{
    /**
     * 判断目标文件能否生成：已存在时默认跳过，只有 --overwrite 才先删除再重写。
     *
     * @return bool 可以继续生成返回 true
     */
    private function canGenerate($filePathToGenerate, $table) {
        $canOverWrite = $this->option('overwrite');
        if(file_exists($filePathToGenerate)) {
            $this->error(str_replace(base_path(),'',$filePathToGenerate)."已存在，为了安全暂不支持覆盖功能，请先手工删除后再执行db:models");
            if($canOverWrite) {
                $deleted = unlink($filePathToGenerate);
                if(!$deleted) {
                    $this->warn("Failed to delete existing model $filePathToGenerate");
                    return false;
                }
            } else {
                $this->warn("Skipped model generation, file already exists. (force using --overwrite) $table -> $filePathToGenerate");
                return false;
            }
        }
        return true;
    }
    // 由关联方法命名需要的复数形式（首字母小写 + 复数）。
    private function getPluralFunctionName($modelName)
    {
        $modelName = lcfirst($modelName);
        return Str::plural($modelName);
    }

    // 由关联方法命名需要的单数形式（首字母小写 + 单数）。
    private function getSingularFunctionName($modelName)
    {
        $modelName = lcfirst($modelName);
        return Str::singular($modelName);
    }

    // 表名 → 模型类名（单数 + studly）。
    private function generateModelNameFromTableName($table)
    {
        return Str::studly(Str::singular($this->resolveTableName($table)));
    }

    /**
     * Laravel 13 的 Expression::getValue() 需要 Grammar 参数，且不再实现 __toString()，
     * 因此这里统一把 Expression 还原成表名字符串。
     */
    private function resolveTableName($value)
    {
        if ($value instanceof Expression) {
            return $value->getValue(app('db')->connection()->query()->getGrammar());
        }

        return $value;
    }
}
