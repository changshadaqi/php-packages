<?php


namespace Yousheng\LaravelDevHelper\Support;


class Str
{
    /**
     * 随机生成 $num 个中文汉字（基于 GB2312 双字节编码区），常用于造测试数据。
     *
     * @param  int $num 需要的汉字个数；小于 1 时返回默认占位词“有声有色”
     * @return string
     */
    public static function randomChineseChar($num)
    {
        $num=(int)$num;
        if($num<1){
            return '有声有色';
        }
        $b = '';
        for ($i=0; $i<$num; $i++) {
            // 使用chr()函数拼接双字节汉字，前一个chr()为高位字节，后一个为低位字节
            // GB2312编码的汉字偏僻字少
            $a = chr(mt_rand(0xB0,0xD0)).chr(mt_rand(0xA1, 0xF0));
            // 转码
            $b .= iconv('GB2312', 'UTF-8', $a);
        }
        return $b;
}
}