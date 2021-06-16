<?php

/**
 * FunAdmin
 * ============================================================================
 * 版权所有 2017-2028 FunAdmin，并保留所有权利。
 * 网站地址: https://www.FunAdmin.com
 * ----------------------------------------------------------------------------
 * 采用最新Thinkphp6实现
 * ============================================================================
 * Author: yuege
 * Date: 2017/8/2
 */

namespace app\backend\service;

use app\backend\model\Admin as AdminModel;
use app\backend\model\AuthGroup as AuthGroupModel;
use app\backend\model\AuthRule;
use app\common\traits\Jump;
use fun\helper\SignHelper;
use think\facade\Cache;
use think\facade\Config;
use think\facade\Cookie;
use think\facade\Request;
use think\facade\Session;

class AuthService
{
    use Jump;

    /**
     * @var object 对象实例
     */

    /**
     * 当前请求实例
     * @var Request
     */
    protected $request;

    protected $controller;

    protected $action;

    protected $requesturl;
    /**
     * @var array
     * config
     */
    protected $config = [];
    /**
     * @var $hrefId ;
     */
    protected $hrefId;

    public function __construct()
    {
        if ($auth = Config::get('auth')) {
            $this->config = array_merge($this->config, $auth);
        }
        // 初始化request
        $this->request = Request::instance();
        $this->controller = parse_name($this->request->controller(), 1);
        $this->action = parse_name($this->request->action(), 1);
        $this->action = $this->action ? $this->action : 'index';
        $this->controller = strtolower($this->controller ? $this->controller : 'index');
        $url = $this->controller . '/' . $this->action;
        $pathurl = $this->request->url();
        $pathurl = explode('?', trim($pathurl, '/'))[0];
        $this->requesturl = strtolower($url);
        if (substr($pathurl, 0,7) === 'addons/') {
            $this->requesturl = $pathurl;
        }else{
            $this->requesturl = str_replace(Config::get('backend.backendEntrance'),'',$this->requesturl);
        }
    }
    //获取左侧主菜单
    public function authMenuNode($menu, $pid = 0, $rules = [])
    {
        $authrules = explode(',', session('admin.rules'));
        $authopen = AuthRule::where('auth_verify', 0)
            ->where('type', 1)->where('status', 1)->column('id');
        if ($authopen) {
            $authrules = array_unique(array_merge($authrules, $authopen));
        }
        $list = array();
        foreach ($menu as $k => $v) {
            if ($v['menu_status'] == 1) {
                $v['href'] = trim($v['href'], '/') . '/index';
            }
            if ($v['module'] !== 'addon') {
                $v['href'] = (parse_name(__u(trim($v['href'], ' ')), 1));
            } else {
                $v['href'] = (parse_name('/' . trim($v['href'], '/'), 1));
            }
            if ($v['pid'] == $pid) {
                if (session('admin.id') != 1) {
                    if (in_array($v['id'], $authrules)) {
                        //假如pid 在数组内，且
                        $allchildids = $this->getallIdsBypid($v['id']);
                        //把下级没有list 的菜单全部删除
                        if ($allchildids) {
                            $allchildids = trim($allchildids, ',');
                            $allIndexChild = AuthRule::field('href,id')
//                                ->where('href', 'like', '%/index')
                                ->where('id', 'in', $authrules)
                                ->where('id', 'in', $allchildids)
                                ->where('status', 1)
                                ->find();
                            if (!$allIndexChild) {
                                unset($menu[$k]);
                                continue;
                            }
                        }
                        $child = AuthRule::field('href,id')
//                            ->where('href', 'like', '%/index')
                            ->where('status', 1)
                            ->where('pid', $v['id'])->find();
                        //删除下级没有list的菜单权限
//                        if ($child && !in_array($child['id'], $authrules)) {
                        if (!$child) {
                            unset($menu[$k]);
                            continue;
                        } else {
                            $v['child'] = self::authMenuNode($menu, $v['id']);
                            $list[] = $v;
                        }
                    }
                } else {
                    $v['child'] = self::authMenuNode($menu, $v['id']);
                    $list[] = $v;
                }
            }
        }
        return $list;
    }
    /**
     * 获取所有子id
     */
    protected function getallIdsBypid($pid)
    {
        $res = AuthRule::where('pid', $pid)->where('status', 1)->select();
        $str = '';
        if (!empty($res)) {
            foreach ($res as $k => $v) {
                $str .= "," . $v['id'];
                $str .= $this->getallIdsBypid($v['id']);
            }
        }
        return $str;
    }

    /**
     * 权限节点
     */
    public function nodeList()
    {
        $allAuthNode = [];
        if (session('admin')) {
            $cacheKey = 'allAuthNode_' . session('admin.id');
            $allAuthNode = Cache::get($cacheKey);
            if (empty($allAuthNode)) {
                $allAuthIds = session('admin.rules');
                if (session('admin.id') == 1) {
                    $allAuthNode = AuthRule::where('status', 1)->column('href', 'href');
                } else {
                    $allAuthNode = AuthRule::where('status', 1)->whereIn('id', ($allAuthIds))->cache($cacheKey)->column('href', 'href');
                }
                foreach ($allAuthNode as $k => $v) {
                    $allAuthNode[$k] = (parse_name($v, 1));
                }
                $allAuthNode = array_flip($allAuthNode);
            }
        }
        return $allAuthNode;

    }

    /*
    * 菜单排列
    */
    public function treemenu($cate, $lefthtml = '├─', $pid = 0, $lvl = 0, $leftpin = 0)
    {
        $arr = array();
        foreach ($cate as $v) {
            if ($v['pid'] == $pid) {
                $v['lvl'] = $lvl + 1;
                $v['leftpin'] = $leftpin + 0;
                $v['lefthtml'] = str_repeat($lefthtml, $lvl);
                $v['ltitle'] = $v['lefthtml'] . $v['title'];
                $arr[] = $v;
                $arr = array_merge($arr, self::treemenu($cate, $lefthtml, $v['id'], $lvl + 1, $leftpin + 20));
            }
        }

        return $arr;
    }

    /*
     * 权限
     */
    public function auth($cate, $rules, $pid = 0)
    {
        $arr = array();
        $rulesArr = explode(',', $rules);
        foreach ($cate as $v) {
            if ($v['pid'] == $pid) {
                if (in_array($v['id'], $rulesArr)) {
                    $v['checked'] = true;
                }
                $v['open'] = true;
                $arr[] = $v;
                $arr = array_merge($arr, self::auth($cate, $v['id'], $rules));
            }
        }
        return $arr;
    }

    /**
     * 权限设置选中状态
     * @param $cate  栏目
     * @param int $pid 父ID
     * @param $rules 规则
     * @return array
     */
    public function authChecked(array $cate, int $pid, string $rules, int $group_id)
    {
        $list = [];
        $rulesArr = explode(',', $rules);
        foreach ($cate as $v) {
            if ($v['pid'] == $pid) {
                $v['spread'] = true;
                $v['title'] = lang($v['title']);
                if (self::authChecked($cate, $v['id'], $rules, $group_id)) {
                    $v['children'] = self::authChecked($cate, $v['id'], $rules, $group_id);
                } else {
                    if (in_array($v['id'], $rulesArr) || $group_id == 1) {
                        $v['checked'] = true;
                    }
                }
                $list[] = $v;
            }
        }
        return $list;
    }

    /**
     * 权限多维转化为二维
     * @param $cate  栏目
     * @param int $pid 父ID
     * @param $rules 规则
     * @return array
     */
    public function authNormal($cate)
    {
        $list = [];
        foreach ($cate as $v) {
            $list[]['id'] = $v['id'];
//        $list[]['title'] = $v['title'];
//        $list[]['pid'] = $v['pid'];
            if (!empty($v['children'])) {
                $listChild = self::authNormal($v['children']);
                $list = array_merge($list, $listChild);
            }
        }
        return $list;
    }

    /**
     * 验证权限
     */
    public function checkNode()
    {
        $cfg = config('backend');
        if ($this->requesturl === '/') {
            $this->error(lang('Login again'), __u('login/index'));
        }
        $adminId = session('admin.id');
        if (
            !in_array($this->controller, $cfg['noLoginController'])
            && !in_array($this->requesturl, $cfg['noLoginNode'])
        ) {
            empty($adminId) && $this->error(lang('Please Login First'), __u('login/index'));
            if (!$this->isLogin()) {
                $this->error(lang('Please Login Again'), __u('login/index'));
            }
            if ($adminId && $adminId != $cfg['superAdminId']) {
                if (!in_array($this->controller, $cfg['noRightController']) && !in_array($this->requesturl, $cfg['noRightNode'])) {
                    if ($this->request->isPost() && $cfg['isDemo'] == 1) {
                        $this->error(lang('Demo is not allow to change data'));
                    }
                    $this->hrefId = AuthRule::where('href', $this->requesturl)
                        ->where('status', 1)
                        ->value('id');
                    //当前管理员权限
                    $rules = AuthGroupModel::where('id', 'in', session('admin.group_id'))
                        ->where('status', 1)->value('rules');
                    //用户权限规则id
                    $adminRules = explode(',', $rules);
                    // 不需要权限的规则id;
                    $noruls = AuthRule::where('auth_verify', 0)->where('status', 1)->column('id');
                    $this->adminRules = array_merge($adminRules, $noruls);
                    if ($this->hrefId) {
                        if (!in_array($this->hrefId, $this->adminRules)) {
                            $this->error(lang('Permission Denied'));
                        }
                    } else {
                        if (!in_array($this->requesturl, $cfg['noRightNode'])) {
                            $this->error(lang('Permission Denied2'));
                        }
                    }
                }
            } else {
                if (!in_array($this->controller, $cfg['noRightController']) && !in_array($this->requesturl, $cfg['noRightNode'])) {
                    if ($this->request->isPost() && $cfg['isDemo'] == 1) {
                        $this->error(lang('Demo is not allow to change data'));
                    }
                }
            }
        } elseif (
            //不需要登录
            in_array($this->controller, $cfg['noLoginController'])
            //不需要登录
            && in_array($this->requesturl, $cfg['noLoginNode'])
        ) {
            if ($this->isLogin()) {
                $this->redirect(__u('index/index'));
            }
        }
    }

    /**
     * @param $cate
     * @return string
     * 帅刷新菜单；
     */
    public function menuhtml($cate, $force = true)
    {
        if ($force) {
            Cache::delete('adminmenushtml' . session('admin.id'));
        }
        $list = $this->authMenuNode($cate);
        $html = '';
        foreach ($list as $key => $val) {
            $html .= '<li class="layui-nav-item">';
            $badge = '';
            if (strtolower($val['title']) === 'addon') {
                $badge = '<span class="layui-badge" style="text-align: right;float: right;position: absolute;right: 10%;">new</span>';
            }
            if ($val['child'] and count($val['child']) > 0) {
                $html .= '<a href="javascript:;" lay-id="' . $val['id'] . '" data-id="' . $val['id'] . '" title="' . lang($val['title']) . '" data-tips="' . lang($val['title']) . '"><i class="' . $val['icon'] . '"></i><cite> ' . lang($val['title']) . '</cite>' . $badge . '<span class="layui-nav-more"></span></a>';
                $html = $this->childmenuhtml($html, $val['child']);
            } else {
                $target = $val['target'] ? $val['target'] : '_self';
                $html .= '<a href="javascript:;" lay-id="' . $val['id'] . '"  data-id="' . $val['id'] . '" title="' . lang($val['title']) . '" data-tips="' . lang($val['title']) . '" data-url="' . $val['href'] . '" target="' . $target . '"><i class="' . $val['icon'] . '"></i><cite> ' . lang($val['title']) . '</cite>' . $badge . '</a>';
            }
            $html .= '</li>';
        }
        $html .= '<span class="layui-nav-bar" style="top: 22.5px; height: 0px; opacity: 0;"></span>';
        return $html;

    }

    /**
     * @param $html
     * @param $child
     * @return string
     * 获取子菜单html
     */
    public function childmenuhtml($html, $child)
    {
        $html .= '<dl class="layui-nav-child">';
        foreach ($child as $k => $v) {
            $html .= '<dd>';
            if ($v['child'] and count($v['child']) > 0) {
                $html .= '<a href="javascript:;" lay-id="' . $v['id'] . '"  data-id="' . $v['id'] . '" title="' . lang($v['title']) . '"  data-tips="' . lang($v['title']) . '"><i class="' . $v['icon'] . '"></i><cite> ' . lang($v['title']) . '</cite></a>';
                $html = self::childmenuhtml($html, $v['child']);
            } else {
                $v['target'] = $v['target'] ? $v['target'] : '_self';
                $html .= '<a href="javascript:;" lay-id="' . $v['id'] . '"   data-id="' . $v['id'] . '" title="' . lang($v['title']) . '" data-tips="' . lang($v['title']) . '" data-url="' . $v['href'] . '" target="' . $v['target'] . '"><i class="' . $v['icon'] . '"></i><cite> ' . lang($v['title']) . '</cite></a>';
            }
            $html .= '</dd>';
        };
        $html .= '</dl>';
        return $html;
    }

    /**
     * 检测是否登录
     * @return boolean
     */
    public function isLogin()
    {
        $admin = session('admin');
        if (!$admin) {
            return false;
        }
        //判断是否同一时间同一账号只能在一个地方登录// 要是备份还原的话，这里会有点问题
        $me = AdminModel::find($admin['id']);
//        if (!$me || $me['token'] != $admin['token']) {
        if (!$me) {
            $this->logout();
            return false;
        }
//        }
        //过期
        if (!session('admin.expiretime') || session('admin.expiretime') < time()) {
            $this->logout();
            return false;
        }
//判断管理员IP是否变动
        if (config('app.ip_check') && !isset($admin['lastloginip']) || $admin['lastloginip'] != request()->ip()) {
            $this->logout();
            return false;
        }
        return true;
    }

    /**
     * 根据用户名密码，验证用户是否能成功登陆
     * @param string $username
     * @param string $password
     * @return mixed
     * @throws \Exception
     */
    public
    function checkLogin($username, $password, $rememberMe)
    {
        try {
            $where['username|email'] = strip_tags(trim($username));
            $password = strip_tags(trim($password));
            $admin = AdminModel::where($where)->find();
            if (!$admin) {
                throw new \Exception(lang('Please check username or password'));
            }
            if ($admin['status'] == 0) {
                throw new \Exception(lang('Account is disabled'));
            }
            if (!password_verify($password, $admin['password'])) {
                throw new \Exception(lang('Please check username or password'));
            }
            if (!$admin['group_id']) {
                throw new \Exception(lang('You dont have permission'));
            }
            $ip = request()->ip();
            $admin->lastloginip = $ip;
            $admin->ip = $ip;
            $admin->token = SignHelper::authSign($admin);
            $admin->save();
            $admin = $admin->toArray();
            $rules = AuthGroupModel::where('id', 'in', $admin['group_id'])
                ->value('rules');
            $admin['rules'] = $rules;
            if ($rememberMe) {
                $admin['expiretime'] = 30 * 24 * 3600 + time();
            } else {
                $admin['expiretime'] = config('session.expire') +time();
            }
            unset($admin['password']);
            Session::set('admin', $admin);
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
        return true;
    }
    /**
     * 注销登录
     */
    public function logout()
    {
        $admin = AdminModel::find(intval(\session('admin.id')));
        if ($admin) {
            $admin->token = '';
            $admin->save();
        }
        Session::clear();
        Cookie::delete("rememberMe");
        return true;
    }

    /**
     * 前台权限节点
     */
    public function authNode($url)
    {
        $urlArr = explode('/',$url);
        $this->controller =  parse_name($urlArr[0], 1);
        $cfg = config('backend');
        $this->requesturl = $url;
        if ($this->requesturl === '/') {
            return false;
        }
        $adminId = session('admin.id');
        if (
            !in_array($this->controller, $cfg['noLoginController'])
            && !in_array($this->requesturl, $cfg['noLoginNode'])
        ) {
            if(empty($adminId)) return false;;
            if (!$this->isLogin()) {
                return false;
            }
            if ($adminId && $adminId != $cfg['superAdminId']) {
                if (!in_array($this->controller, $cfg['noRightController']) && !in_array($this->requesturl, $cfg['noRightNode'])) {
                    if ($this->request->isPost() && $cfg['isDemo'] == 1) {
                        return false;
                    }
                    $this->hrefId = AuthRule::where('href', $this->requesturl)
                        ->where('status', 1)
                        ->value('id');
                    //当前管理员权限
                    $rules = AuthGroupModel::where('id', 'in', session('admin.group_id'))
                        ->where('status', 1)->value('rules');
                    //用户权限规则id
                    $adminRules = explode(',', $rules);
                    // 不需要权限的规则id;
                    $noruls = AuthRule::where('auth_verify', 0)->where('status', 1)->column('id');
                    $this->adminRules = array_merge($adminRules, $noruls);
                    if ($this->hrefId) {
                        if (!in_array($this->hrefId, $this->adminRules)) {
                            return false;
                        }
                    } else {
                        if (!in_array($this->requesturl, $cfg['noRightNode'])) {
                            return false;
                        }
                    }
                }
            } else {
                if (!in_array($this->controller, $cfg['noRightController']) && !in_array($this->requesturl, $cfg['noRightNode'])) {
                    if ($this->request->isPost() && $cfg['isDemo'] == 1) {
                        return false;
                    }
                }
                return true;
            }
        } elseif (
            //不需要登录
            in_array($this->controller, $cfg['noLoginController'])
            //不需要登录
            && in_array($this->requesturl, $cfg['noLoginNode'])
        ) {
            return true;
        }
        return true;
    }

}
