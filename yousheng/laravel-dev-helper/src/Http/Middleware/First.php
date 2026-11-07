<?php

namespace Yousheng\LaravelDevHelper\Http\Middleware;

use Help;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Route;
use Symfony\Component\VarDumper\VarDumper;
use Yousheng\LaravelDevHelper\Helper;

/**
 * 全局调试中间件（位于中间件栈首位）。
 *
 * 仅当 env('YS_DEBUG') 开启时生效：通过监听 request 重实例化（rebinding），
 * 在请求生命周期早期拦截带调试参数（如 ?yousheng=routes / ?debug / ?getpublic）
 * 的请求，直接输出别名、路由、中间件、视图、发布组、翻译等运行期信息。
 * 无调试参数时不拦截，正常传递 $next。
 */
class First
{
    /**
     * 拦截带调试参数的请求并输出对应统计信息。
     *
     * @param  Request $request
     * @param  callable $next
     * @return mixed 命中调试时返回已 send() 的响应；否则返回 $next($request)
     */
    public function handle(Request $request, $next)
    {
        if (Helper::isYoushengDebug()) {
            // request 实例在整个应用周期会被重新绑定，此时是注入调试逻辑的时机。
            app()->rebinding('request', function ($app, $request) {
                // request实例有两次 instance，第一次是在bootstrap()之前，第二次是在路由发布之前，第二次instance时会执行下面方法
                // ----------- 检测整个应用周期，重实例化request的次数--------
                // $from = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 3)[2];
                // $text='文件:'.Str::after($from['file'],base_path())."\n";
                // $text.="\t行：第".$from['line']."行，执行".$from['class']."->".$from['function']."()方法\n\n";
                // file_put_contents(public_path('实例化request.md'),$text,FILE_APPEND);

                // 可点击的调试入口链接（每个 ?yousheng=xxx 对应下方 switch 的一个分支）。
                $items       = [
                    'aliases'       => 'aliases',
                    'langs'         => 'langs',
                    'middlewares'   => 'middlewares',
                    'publishes'     => 'publishes',
                    'routes'        => 'routes',               // http://faculty.lms/?ys=routes
                    'routes-detail' => 'routes-detail',
                    'sessions'      => 'sessions',
                    'translations'  => 'translations',
                    'views'         => 'views',
                    'compiler'      => 'blade compiler',
                    'events'        => 'events',
                    'packages'      => 'installed packages',
                    'services'      => 'service provider',
                ];

                $path        = $request->url();
                $debugPara   = env('YS_DEBUG_PARA', 'yousheng');
                $urlTemplate = "<a style='text-decoration: none;' href='".$path.'?'.$debugPara."=%s'>%s</a> | ";
                $itemsString = array_reduce(array_keys($items), function ($carry, $key) use ($urlTemplate, $items) {
                    return $carry.sprintf($urlTemplate, $key, $items[$key]);
                }, 'debug support: <br/><div style="padding:5px;background-color: #eee">');

                //$str="deug support <span style='color:#f90;'>aliases | langs | middlewares | publishes | routes | routes-detail | sessions | translations | <a href='/?yousheng=views'>views</a>   | <a href='/?yousheng=compiler'>blade compiler </a></span><br/><hr/>\r";
                $str         = $itemsString.'</div>';
                $str .= '<br/><br/>debugging...<br/>current full url is:  <span style="color: #ff9900">'.$request->fullUrl()."</span><br/><hr/>\n";
                // $response = $next($request);
                $response    = response();

                if (version_compare(app()->version(), '5.8', '>=')) {
                    if (! $request->hasAny([env('YS_DEBUG_PARA'), 'yousheng', 'debug', 'getpublic'])) {
                        //return $response;
                        return;
                    }
                } else {
                    if (! $request->has('debug') && ! $request->has('getpublic') && ! $request->has('action')) {
                        // return $response;
                        return;
                    }
                }

                if ($intercept = $request->{$debugPara}) {
                    switch ($intercept) {
                        case 'aliases':
                            $str .= Helper::Array2str(\Illuminate\Foundation\AliasLoader::getInstance()->getAliases(), 'List All View Aliases 所有别名', 1);
                            break;
                        case 'langs':
                            $str .= Helper::Array2str(app('translation.loader')->namespaces(), 'List All Langs');
                            break;
                        case 'middlewares':
                            // middle group 路由中间件
                            //$routeMiddleware=app(\Illuminate\Routing\Route::class)->gatherMiddleware();
                            // sorted middleware 排序后的中间件

                            $router                    = app('router');
                            $currentRoute              = $router->getRoutes()->match($request);
                            $routeMiddleware           = $router->gatherRouteMiddleware($currentRoute);
                            // global middleware 全局中间件
                            $properties                = Help::getProperties(app(Kernel::class), 1, 'middleware');
                            $str .= Helper::Array2str($properties['middleware'], 'List Http Kernel Middleware(Global Middleware)', 1);
                            $str .= Helper::Array2str($routeMiddleware, 'List Route Middleware', 1);
                            break;
                        case 'publishes':
                            $reflectionServiceProvider = new \ReflectionClass(\Illuminate\Support\ServiceProvider::class);
                            $publishes                 = $reflectionServiceProvider->getProperty('publishes');
                            $publishGroups             = $reflectionServiceProvider->getProperty('publishGroups');
                            $publishes->setAccessible(true);
                            $publishGroups->setAccessible(true);
                            $publishesValue            = $publishes->getValue(\Illuminate\Support\ServiceProvider::class);
                            $publishGroupsValue        = $publishGroups->getValue(\Illuminate\Support\ServiceProvider::class);
                            $publishes                 = \Illuminate\Support\ServiceProvider::pathsToPublish();
                            $str .= Helper::Array2str($publishesValue, 'List Publishes', 1);
                            $str .= Helper::Array2str($publishes, 'List Publish Paths(from publishes)', 1);
                            $str .= Helper::Array2str($publishGroupsValue, 'List PublishGroups', 1);
                            break;
                        case 'routes':

                            $reflection                = new \ReflectionClass(Route::getRoutes());
                            $routes                    = $reflection->getProperty('routes');
                            $routes->setAccessible(true);
                            $routesValue               = $routes->getValue(Route::getRoutes());
                            $str .= Helper::Array2str(collect(Arr::dot($routesValue))->mapWithKeys(function ($route, $key) {
                                return [$key => $route->getActionName().'  ['.$route->getName().']'];
                            })->filter(function ($item, $key) {
                                if (! Str::startsWith($key, 'HEAD')) {
                                    return $item;
                                }
                            })->all(), 'List All Routes,format: method.uri=>action [name]');

                            /*
                             * // not include method
                             * $str .= Helper::Array2str(collect(Route::getRoutes()->getRoutes())->mapWithKeys(function($item){
                                return [$item->uri=>$item->getActionName()];
                            })->all(),"List All Routes");
                            */
                            break;
                        case 'routes-detail':
                            $routeCollection           = Route::getRoutes();
                            $routes                    = $routeCollection->getRoutesByMethod();
                            $allRoutes                 = Helper::getProperties($routeCollection, 1, 'allRoutes');
                            $nameList                  = $routeCollection->getRoutesByName();
                            $actionList                = Helper::getProperties($routeCollection, 1, 'actionList');
                            /* $str = Helper::Array2str($routes)->map(function($value,$key){
                                 return [$item->uri=>$item->getActionName()];
                             })->all(),"List All Routes");
                             //dddd(app('routes')->getRoutes());*/
                            break;
                        case 'sessions':
                            $str .= Helper::Array2str(session()->all(), 'List All Sessions', 1);
                            break;
                        case 'translations':
                            $fileLoader                = app('translation.loader');
                            // hints also via app('translation.loader')->namespace();
                            $arr                       = Helper::getProperty($fileLoader, ['path', 'jsonPaths', 'hints']);
                            $str .= Helper::Array2str(config('app.locale'), 'default App Locale');
                            $str .= Helper::Array2str($arr['path'], "List All translation's path");
                            $str .= Helper::Array2str($arr['jsonPaths'], "List All translation's jsonPaths");
                            $str .= Helper::Array2str($arr['hints'], "List All translation's namespace");
                            break;
                        case 'views':
                            $str .= Helper::Array2str(app('view')->getFinder()->getPaths(), 'List All View Paths');
                            $str .= Helper::Array2str(app('view')->getFinder()->getHints(), 'List All View Namespaces');
                            $str .= Helper::Array2str(app('view')->getFinder()->getViews(), 'List All Views');
                            break;

                        case 'compiler':
                            //customDirectives 自定义指令
                            $str .= Helper::Array2str(app('blade.compiler')->getCustomDirectives(), 'List All blade custom directives');
                            $str .= Helper::Array2str(app('blade.compiler')->getClassComponentAliases(), 'List All blade class components');
                            $str .= Helper::Array2str(app('blade.compiler')->getClassComponentNamespaces(), 'List All blade registered class component namespaces');
                            break;

                        case 'events':
                            $listeners                 = Helper::getProperties(app('events'), 1, 'listeners');
                            /**
                             * 待完善
                             * $listeners = collect($listeners)->map(function ($value,$key){
                             * foreach($value as $event_name=>$sub_listeners){
                             * foreach($sub_listeners as $index=>$listener){
                             * if($listener instanceof \Closure){
                             * $sub_listeners[$index]=Helper::closure_dump($listener);
                             * }
                             * }
                             * }
                             * return $value;
                             * });
                             * */
                            ob_start();
                            VarDumper::dump($listeners);
                            $a                         = ob_get_clean();
                            $str .= Helper::Array2str($a, 'List PublishGroups', 1);
                            break;
                        case 'services':
                            if (is_file($servicesPath = app()->getCachedServicesPath())) {
                                ob_start();
                                VarDumper::dump(file_get_contents($servicesPath));
                                $a = ob_get_clean();
                                $str .= Helper::Array2str($a, '所有服务:', 1);
                            } else {
                                $str .= '服务未缓存，todo';
                            }

                            break;
                        case 'packages':
                            if (is_file($packagesPath = app()->getCachedPackagesPath())) {
                                $str .= Helper::Array2str(array_keys(require($packagesPath)));
                            } else {
                                $str .= '包未缓存，todo';
                            }

                            break;
                        default:
                            $str .= 'no data to show';
                            break;
                    }
                }

                // get all public method  of a class
                // example: http://www.bucaoxin.bendi/?getpublic=\Illuminate\Auth\SessionGuard
                // 输出一个类（含继承链与 trait）的全部 public 方法，用于源码分析。
                if ($className = $request->getpublic) {
                    if (class_exists($className)) {
                        $str .= Helper::getAllPublicMethods($className);
                    }
                }
                // convenient than in console
                // 由于命令行不是很方便，改为这里处理一些动作，如清理缓存
                if ($action = $request->action) {
                    switch ($action) {
                        case 'clear-config':
                            app('files')->delete(app()->getCachedConfigPath());
                            $str .= app()->getCachedConfigPath().'目录下的缓存清理完成';
                            break;
                    }
                }

                return response()->make($str, 200)->send();
            });
        }

        return $next($request);
    }
}
