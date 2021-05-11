<?php

/**
 * FunAdmin
 * ============================================================================
 * 版权所有 2017-2028 FunAdmin，并保留所有权利。
 * 网站地址: http://www.FunAdmin.com
 * ----------------------------------------------------------------------------
 * 采用最新Thinkphp6实现
 * ============================================================================
 * Author: yuege
 * Date: 2017/8/2
 */
namespace app\backend\model;

class Admin extends BackendModel {

    public function __construct(array $data = [])
    {
        parent::__construct($data);
    }

    /**
     * @return \think\model\relation\BelongsTo
     */
    public function authGroup(){
        return  $this->belongsTo(AuthGroup::class,'group_id','id');
    }


}
