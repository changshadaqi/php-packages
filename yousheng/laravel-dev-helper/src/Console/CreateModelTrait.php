<?php

namespace Yousheng\LaravelDevHelper\Console;

/**
 * 根据数据库表结构生成 Eloquent 模型 trait。
 *
 * 从外键/唯一索引/主键的组合关系中推断 hasOne / hasMany /
 * belongsTo / belongsToMany，并渲染到模型 stub 中。
 * 依赖 ColumnsTrait 提供的 prep 结构（columns/index/foreign/primary）。
 */
trait CreateModelTrait
{
    // 这些列不应出现在 $fillable 中。
    private $notFillable = ['created_at', 'updated_at', 'deleted_at', 'remember_token', 'id'];

    private function getModelsConfig($tables)
    {
        $configs = [];
        foreach ($tables as $table => $config) {
            $configs[$table] = [
                'hasMany' => [],
                'hasOne' => [],
                'belongsTo' => [],
                'belongsToMany' => [],
                'fillable' => [],
            ];
        }

        // 判断多对多关系，1：中间件表有两个外键，2：没有很好的方法，只能判断 join 表名，
        // 但实际工作中中间表名可以任取，因此目前只保留占位配置。
        return $configs;
    }

    /**
     * 从表结构推导 Eloquent 关系规则（hasMany/hasOne/belongsTo/belongsToMany/fillable）。
     *
     * 推断思路：
     *  - 外键列同时是唯一索引 => 一对一（hasOne / belongsTo）；
     *  - 外键列非唯一      => 一对多（hasMany / belongsTo）；
     *  - 外键列集合与主键集合一致 => 多对多中间表（belongsToMany）。
     *
     * @param  array $tables 表名列表
     * @param  array $prep   getColumnsPrimaryAndForeignKeysPerTable() 的结果
     * @return array 以表名为键的关系规则
     */
    private function getEloquentRules($tables, $prep)
    {
        $rules = [];
        foreach ($prep as $table => $properties) {
            $rules[$table] = [
                'hasMany' => [],
                'hasOne' => [],
                'belongsTo' => [],
                'belongsToMany' => [],
                'fillable' => [],
            ];
        }

        foreach ($prep as $table => $properties) {
            $this->setFillableProperties($table, $rules, $properties['columns']);

            // 如果某列是 foreign key，又是 unique 索引，那么就是一对一
            // 如果某列是 foreign key，不是 unique 索引，那么就是一对多
            // 如果外键组合与主键组合一致，那么就是多对多中间表
            $uniqueKeys = collect($properties['index'] ?? [])->map(function ($v) {
                if ($v->get('name') === 'unique') {
                    return $v->get('columns');
                }
                return null;
            })->flatten()->filter()->values()->all();

            $foreignKeyDefinitions = $properties['foreign'] ?? [];
            $primary = $properties['primary'] ?? null;
            $primaryColumns = $primary !== null ? (array) $primary->get('columns', []) : [];
            $foreignColumns = collect($foreignKeyDefinitions)->map(function ($v) {
                return $v->get('columns');
            })->flatten()->values()->all();

            if (empty($foreignKeyDefinitions)) {
                continue;
            }

            $sortedForeignColumns = $foreignColumns;
            $sortedPrimaryColumns = $primaryColumns;
            sort($sortedForeignColumns);
            sort($sortedPrimaryColumns);

            if (! empty($primaryColumns) && $sortedForeignColumns === $sortedPrimaryColumns) {
                $this->info("开始向表 $table 添加多对多规则");
                //$FK1 belongsToMany $FK2
                //$FK2 belongsToMany $FK1
                $fk1 = $foreignKeyDefinitions[0];
                $fk2 = $foreignKeyDefinitions[1] ?? null;

                $fk1Table = $this->resolveTableName($fk1->get('on'));
                $fk1Field = head((array) $fk1->get('columns', []));

                if ($fk2 !== null) {
                    $fk2Table = $this->resolveTableName($fk2->get('on'));
                    $fk2Field = head((array) $fk2->get('columns', []));

                    if ($fk1Table !== null && isset($rules[$fk1Table])) {
                        $rules[$fk1Table]['belongsToMany'][] = [$fk2Table, $table, $fk1Field, $fk2Field];
                    }
                    if ($fk2Table !== null && isset($rules[$fk2Table])) {
                        $rules[$fk2Table]['belongsToMany'][] = [$fk1Table, $table, $fk2Field, $fk1Field];
                    }
                }

                continue;
            }

            foreach ($foreignKeyDefinitions as $foreignKeyDefinition) {
                $foreignKey = head((array) $foreignKeyDefinition->get('columns', [])); // 子表外键
                $on = $this->resolveTableName($foreignKeyDefinition->get('on'));        // 父表
                $reference = head((array) $foreignKeyDefinition->get('references', [])); // 父表主键

                // 一对一
                if (in_array($foreignKey, $uniqueKeys, true)) {
                    $this->info("开始向表 $table 添加一对一规则");
                    if ($on !== null && isset($rules[$on])) {
                        $rules[$on]['hasOne'][] = [$table, $foreignKey, $reference];
                    }
                    $rules[$table]['belongsTo'][] = [$on, $foreignKey, $reference];
                } // 一对多
                else {
                    $this->info("开始向表 $table 添加一对多规则");
                    if ($on !== null && isset($rules[$on])) {
                        $rules[$on]['hasMany'][] = [$table, $foreignKey, $reference];
                    }
                    $rules[$table]['belongsTo'][] = [$on, $foreignKey, $reference];
                }
            }
        }

        return $rules;
    }

    private function setFillableProperties($table, &$rules, $columns)
    {
        $fillable = [];
        foreach ($columns as $columnDefinition) {
            $name = $columnDefinition->get('name');
            if (! in_array($name, $this->notFillable, true)) {
                $fillable[] = "'$name'";
            }
        }
        $rules[$table]['fillable'] = $fillable;
    }

    private function generateBelongsToManyFunctions($rulesContainer)
    {
        $functions = '';
        foreach ($rulesContainer as $rules) {
            $belongsToManyModel = $this->generateModelNameFromTableName($rules[0]);
            $through = $rules[1];
            $key1 = $rules[2];
            $key2 = $rules[3];

            $belongsToManyFunctionName = $this->getPluralFunctionName($belongsToManyModel);

            $function = "
    public function $belongsToManyFunctionName() {".'
        return $this->belongsToMany'."(\\App\\Models\\$belongsToManyModel::class, '$through', '$key1', '$key2');
    }
";
            $functions .= $function;
        }

        return $functions;
    }

    private function generateBelongsToFunctions($rulesContainer)
    {
        $functions = '';
        foreach ($rulesContainer as $rules) {
            $belongsToModel = $this->generateModelNameFromTableName($rules[0]);
            $key1 = $rules[1];
            $key2 = is_array($rules[2]) ? $rules[2][0] : $rules[2];
            $belongsToFunctionName = $this->getSingularFunctionName($belongsToModel);
            $function = "
    public function $belongsToFunctionName() {".'
        return $this->belongsTo'."(\\App\\Models\\$belongsToModel::class, '$key1', '$key2');
    }
";
            $functions .= $function;
        }

        return $functions;
    }

    private function generateHasOneFunctions($rulesContainer)
    {
        $functions = '';
        foreach ($rulesContainer as $rules) {
            $hasOneModel = $this->generateModelNameFromTableName($rules[0]);
            $key1 = $rules[1];
            $key2 = $rules[2];

            $hasOneFunctionName = $this->getSingularFunctionName($hasOneModel);

            $function = "
    public function $hasOneFunctionName() {".'
        return $this->hasOne'."(\\App\\Models\\$hasOneModel::class, '$key1', '$key2');
    }
";
            $functions .= $function;
        }

        return $functions;
    }

    private function generateHasManyFunctions($rulesContainer)
    {
        $functions = '';
        foreach ($rulesContainer as $rules) {
            $hasManyModel = $this->generateModelNameFromTableName($rules[0]);
            $key1 = $rules[1];
            $key2 = $rules[2];

            $hasManyFunctionName = $this->getPluralFunctionName($hasManyModel);

            $function = "
    public function $hasManyFunctionName() {".'
        return $this->hasMany'."(\\App\\Models\\$hasManyModel::class, '$key1', '$key2');
    }
";
            $functions .= $function;
        }

        return $functions;
    }

    /**
     * 渲染单个模型：拼接关系方法与 $fillable，填充 stub 模板并写入 app/Models。
     */
    private function generateEloquentModel($table, $rules)
    {
        //1. Determine path where the file should be generated
        $destinationFolder = app_path('Models');
        $modelName = $this->generateModelNameFromTableName($table);
        $filePathToGenerate = $destinationFolder . '/'.$modelName.'.php';

        $canContinue = $this->canGenerate($filePathToGenerate, $table);
        if (! $canContinue) {
            return;
        }

        //2. generate relationship functions and fillable array
        $hasMany = $rules['hasMany'];
        $hasOne = $rules['hasOne'];
        $belongsTo = $rules['belongsTo'];
        $belongsToMany = $rules['belongsToMany'];

        $fillable = implode(', ', $rules['fillable']);

        $belongsToFunctions = $this->generateBelongsToFunctions($belongsTo);
        $belongsToManyFunctions = $this->generateBelongsToManyFunctions($belongsToMany);
        $hasManyFunctions = $this->generateHasManyFunctions($hasMany);
        $hasOneFunctions = $this->generateHasOneFunctions($hasOne);

        $functions = $this->generateFunctions([
            $belongsToFunctions,
            $belongsToManyFunctions,
            $hasManyFunctions,
            $hasOneFunctions,
        ]);

        //3. prepare template data
        $templateData = [
            '{{NAMESPACE}}' => 'App\\Models',
            '{{NAME}}' => $modelName,
            '{{TABLENAME}}' => $table,
            '{{FILLABLE}}' => $fillable,
            '{{FUNCTIONS}}' => $functions,
        ];

        $templatePath = $this->getTemplatePath();
        $this->info("表 ".$table."'s 模型路径是: ".$filePathToGenerate);
        if(!is_dir($destinationFolder)) {
            mkdir($destinationFolder, 0755, true);
        }
        // 文件的 owner 是 root
        file_put_contents($filePathToGenerate, str_replace(array_keys($templateData), array_values($templateData), file_get_contents($templatePath)));
        $this->info("Generated model for table $table");
    }

    private function generateEloquentModels($eloquentRules)
    {
        foreach ($eloquentRules as $table => $rules) {
            $this->info("\n开始生成表".$table."对应模型");
            try {
                $this->generateEloquentModel($table, $rules);
            } catch (\Throwable $e) {
                $this->error($table."生成模型时失败，原因是".$e);
                return;
            }
        }
    }
}
