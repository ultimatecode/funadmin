<?php
/**
 * FunAadmin
 * ============================================================================
 * 版权所有 2017-2028 FunAadmin，并保留所有权利。
 * 网站地址: https://www.FunAadmin.com
 * ----------------------------------------------------------------------------
 * 采用最新Thinkphp6实现
 * ============================================================================
 * Author: yuege
 * Date: 2017/8/2
 */


return [
     \think\middleware\LoadLangPack::class,

     \think\middleware\SessionInit::class,
    //访问频率
    \think\middleware\Throttle::class,
    //跨域
    \think\middleware\AllowCrossDomain::class

];

