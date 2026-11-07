<?php

namespace Yousheng\LaravelDevHelper\Http\Middleware;

use Illuminate\Http\Request;
use Yousheng\LaravelDevHelper\Helper;

/**
 * 全局调试中间件（位于中间件栈末位）。
 *
 * 目前为空实现：为了不影响性能，handle() 首行直接 return $next($request)，
 * 下方的“去时/回时”标记代码仅为预留调试钩子（需配合 \d() 手工放开）。
 */
class Last
{
    public function handle(Request $request, $next)
    {
        // 直接放行；下方代码为预留的全栈末尾调试点，默认不执行。
        return $next($request);
        /**--------------全局中间件最后一个-----------------**/
        $GlobalMiddlewareLast = <<<'EOT'
<h2>最后一个全局中间件_去时</h2>
EOT;
//        Helper::isYoushengDebug()
        // YOUSHENG_DEBUG && \d($GlobalMiddlewareLast,$request,app('router'));
        /**--------------/全局中间件最后一个-----------------**/
        $response             = $next($request);

        /**--------------全局中间件最后一个-----------------**/
        $GlobalMiddlewareLast = <<<'EOT'
<h2>最后一个全局中间件_回时</h2>
EOT;
        //YOUSHENG_DEBUG && \d($GlobalMiddlewareLast,$response);
        /**--------------/全局中间件最后一个-----------------**/
        return $response;
    }
}
