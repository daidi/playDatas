<?php

class AppsModel extends RedisModel
{
    protected $category = 1;//分类，1，应用，2游戏
    protected $is_game = 2;//是游戏还是应用，1，应用 2,游戏，
    protected $pos = 2;//推广所在位置，1：精选，2：游戏，3：应用，4礼包
    protected $pageNum = 25;//每页的数量
    protected $order = 'app.is_top desc,app.sort desc,app.id desc';//默认的排序
    protected $cid = 0;//排序
    protected $where = 'where';//传递过来的条件
    protected $templateType = 1;//模板类型。1：应用/游戏模板，2下载排行模板，3评分排行模板，4专题模板，5礼包模板,6URL模板

    public function __construct($category = '', $is_game = '', $pos = '', $order = '', $language = '', $cid = '', $where = '',$templateType='')
    {
        parent::__construct();
        //$this->redis->flushall();exit;
        $this->category = isset($category) && $category ? $category : $this->category;
        $this->is_game = isset($is_game) && $is_game ? $is_game : $this->is_game;
        $this->pos = isset($pos) && $pos ? $pos : $this->pos;
        $this->order = isset($order) && $order ? $order : $this->order;
        $this->cid = isset($cid) && $cid ? $cid : $this->cid;
        $this->where = isset($where) && $where ? $where : $this->where;
        $this->templateType = isset($templateType) && $templateType ? $templateType : $this->templateType;
    }

    /**
     *   获取应用json
     * @param $page int 客户端出来的分类
     * @param $templateUpdateTime int 模板更新的时间
     * @param $type string redis缓存标示，new:前台中的最新，download:下载排行，score:评分排行
     * @return string
     */
    public function getJson($page = 0, $templateUpdateTime = 0, $type)
    {
        //获取模板内容，如果模板未更新，则什么都不返回
        $arr = $this->getTemplate($templateUpdateTime);
        $arr['status'] = 1;//状态
        $arr['currentTime'] = time();

        $this->page = isset($page) ? (int)$page : 0;
        $sql = "select count(*) as num from appbox_app where status=1 and is_game=" . $this->is_game . " and language='" . $this->language . "'";
        $arr['hasNextPage'] = $this->getPage($sql, $this->page, $this->pageNum);
        $page = $this->page * $this->pageNum;//初始化页数

        //获取应用所有的分类
        if ($this->page == 0)//第一页的时候发放所有的分类
        {
            if (!$this->cid) {
                $this->redis->select(1);
                if ($categorys = $this->redis->get('appbox_categorys_' . $this->language . '_' . $this->category))//从缓存中取数据
                {
                    $arr['Category'] = json_decode($categorys, true);
                    $arr['categoryRedis'] = 'from redis';
                } else {
                    $arr['Category'] = $this->getAppsCategory($this->category, array());//将分类推入到数组
                    $arr['categoryRedis'] = 'from mysql';
                }
            }
        }

        $this->redis->select(2);
        $is_game = $this->is_game == 1 ? 'app' : 'game';
        $sort = isset($_GET['sort']) ? $_GET['sort'] : '';
        //获取应用app
        if ($this->cid){
            $cidKey = 'appbox_'.$is_game.'_cid'.$this->cid.'_'.$this->language.'_'.$this->page.'_'.$sort;
            $redis_datas = $this->redis->get($cidKey);
        }else{
            $key = 'appbox_'.$is_game.'_'.$type.'_'.$this->language.'_'.$this->page;
            $redis_datas = $this->redis->get($key);
        }

        if (isset($redis_datas) && $redis_datas && !empty($redis_datas)) {//从缓存中取数据
            $this->_parseEtags($redis_datas,$this->page);//从查询第一页缓存是否有更新
            $arr['data'] = $this->getAppRedis($redis_datas);
            $arr['dataRedis'] = 'from redis';
        } else {
            $apps = $this->getList($page, $this->order,$sort);
            if (!$apps) return json_encode(array('status' => $this->is_true));
            foreach ($apps as $val) { //将app推入到数组
                $tempData = $this->getAppDetail($val['releaseTime'], $val['id'],$this->templateType);
                if($tempData) $arr['data'][] = $tempData;
                else continue;
            }
            if ($arr['data'] && !empty($arr['data'])) {//缓存到redis
                if ($this->cid) {//每个分类进行缓存
                    $this->setAppRedis($is_game,'',$arr['data'],$sort);
                }else {//最新，下载，评分缓存
                    $this->setAppRedis($is_game,$type,$arr['data']);
                }
            }
        }

        //获取推广图
        if ($this->page == 0) {//第一页的时候发放所有的广告
            $this->redis->select(6);
            if (!$this->cid) {//分类查询不下发广告
                if ($banners = $this->redis->get('appboxbL_' .$this->language.'_'. $this->pos)) {//从缓存中取数据
                    $arr['banners'] = json_decode($banners, true);
                    $arr['bannersRedis'] = 'from redis';
                } else {
                    $arr['banners'] = $this->getBanners($this->pos);//推广所在位置，1：精选，2：游戏，3：应用，4礼包
                    $arr['bannersRedis'] = 'from mysql';
                }
            }
        }        

        return json_encode($arr);
    }

    /*
     * 获取应用
     */
    public function getList($p, $order,$sort='')
    {
        if ($this->cid == 0)//如果不存在分类查询
        {
            $where = $this->where . " app.status=1 and app.is_game=" . $this->is_game;
        } else {//存在分类查询
            $sql = "select `where` from appbox_google_category where category_id=".$this->cid;
            $other_where = $this->_db->getRow($sql);
            if($other_where['where']){//如果是自定义分类，则查询用户设置的条件
                $wheres = json_decode($other_where['where'],true);
                $where = $this->where . " 1=1 " ;
                foreach($wheres as $key=>$val){
                    if($val)
                    {
                        switch($key){
                            case 'team_type':
                                $where .= " and app.team_type != ''";
                                break;
                            case 'score':
                                $where .= " and app.score >= $val";
                                break;
                            case 'install_count':
                                $where .= " and app.install_count >= $val ";
                                break;
                            case 'google_recommend':
                                $where .= " and app.google_recommend != ''";
                                break;
                            case 'keywords':
                                $where .= " and app.app_name like '%$val%'";
                                break;
                        }
                    }
                }
            } else {//不是自定义分类，则递归取出所有分类
                $cid = $this->cursive($this->cid);//递归查出所有子分类
                $cid[] = $this->cid;
                $cid = implode(',', $cid);
                $where = $this->where . " app.status=1 and app.is_game=" . $this->is_game . "  and app.google_category in ($cid)";
            }
        }
        //
        if($sort && $this->cid != 0){
            switch($sort){
                case 'rate':
                    $order = "app.score_sort desc,app.score desc,app.install_count desc,app.id desc";
                    break;
                case 'download':
                    $order = "app.download_sort desc,app.install_count desc,app.score desc,app.id desc";
                    break;
            }
        }

        //获取应用app
        $sql = "select app.package_id as id,app.releaseTime
                    from appbox_app as app
                    $where group by app.package_id
                    order by $order
                    limit $p," . $this->pageNum;
        $info = $this->_db->getAll($sql);
		return $info;
    }

    /**
     *   递归查询谷歌分类
     */
    public function cursive($cid, $arr = array())
    {
        $sql = "select id,category_id from appbox_google_category where parent_id=$cid and language='" . $this->language . "' order by sort asc,id asc";
        $ids = $this->_db->getAll($sql);
        if (!$ids) {
            return $arr;
        }
        foreach ($ids as $val) {
            $arr[] = $val['category_id'];
            $arr = $this->cursive($val['category_id'], $arr);
        }
        return $arr;
    }

    /**
     *   获取应用详细的信息
     */
    public function getDetailJson($packageName,$language = '')
    {
		$language && $this->language = $language;
		
        $this->redis->select(7);
        if ($data = $this->redis->get('appboxD_' . $packageName . '_' . $this->language)) {
            return $data;
        } else {
            $arr = array('status' => 1);
            $sql = "
                select app.package_id,app.package_name as packageName,app.comment_nums as commentCounts,app.app_name as name,app.icon as iconUrl,app.current_version as versionName,app.install_count as downloadCount,app.updated as lastUpdateTime,app.score as rate,app.size,app.hateCount as treadCount,app.likeCount as praiseCount,app.desc as description,app.price as paymentAmount,app.screen_shots,app.comment_detail,app.google_category,app.extend_info,app.status,app.releaseTime
                from appbox_app as app
                where app.package_name='$packageName' and app.language='{$this->language}'";
				
            $data = $this->_db->getRow($sql);
            if ($data) {
                
/*                if (time() - $data['releaseTime'] >= 86400)//检查更新，如果更新时间大于一天，则去更新应用,重新获取数据
                {
                    file_get_contents('http://play.mobappbox.com/index.php?m=Admin&c=Application&a=getAppInfo&flag=1&language='.$this->language.'&package_name=' . $packageName);
                    $redisData = $data;
                    $data = $this->_db->getRow($sql);
                    if(!$this->updateRedis($redisData,$data)) {
                        //echo '更新失败！';exit;
                    }
                }*/
                $data['description'] = htmlspecialchars_decode($data['description']);
                $data['name'] = htmlspecialchars_decode($data['name']);
                if ($data['status'] == 0 || !$data['status'])//如果app已经下线
                    return json_encode(array('status' => 2));

                $data['marketUrl'] = $this->url . $data['packageName'];
                //获取前五条评论
                $comments = json_decode(htmlspecialchars_decode($data['comment_detail']), true);
                $data['lastUpdateTime'] = strtotime($data['lastUpdateTime']) * 1000;
                if(!$data['lastUpdateTime']) {
                    $data['lastUpdateTime'] = $data['releaseTime']*1000;
                }
                if ($comments) {
                    $comment = array();
                    $num = count($comments) > 5 ? 5 : count($comments);
                    for ($i = 0; $i < $num; $i++) {
                        $comment[$i]['userName'] = $comments[$i]['nick_name'];
                        $comment[$i]['commentContent'] = $comments[$i]['contents'];
                        $comment[$i]['rateScore'] = $comments[$i]['score']*2;
                    }
                    unset($data['comment_detail']);
                    $data['comments'] = $comment;
                }
                $data['haveGift'] = $this->haveGift($data['packageName']);
                //获取所有的应用截图
                $screen_shots = json_decode($data['screen_shots'], true);
                $data['screenshotUrls'] = $screen_shots;
                unset($data['screen_shots']);
                //获取同分类下的四个产品
                $data['recommendation'] = isset($data['extend_info']) && $data['extend_info'] ? json_decode($data['extend_info'], true) : '';
                unset($data['extend_info']);
                unset($data['google_category']);
                $arr['data'] = $data;
                if ($arr && !empty($arr)) {//缓存到redis
                    $this->redis->select(7);
                    $this->redis->set('appboxD_' . $packageName . '_' . $this->language, json_encode($arr), $this->expire);
                }
                return json_encode($arr);
            } else {
                file_get_contents('http://play.mobappbox.com/index.php?m=Admin&c=Application&a=getAppInfo&flag=1&language='.$this->language.'&package_name=' . $packageName);
                return $this->getDetailJson($packageName,'en');
            }
        }
        return json_encode(array('status' => $this->is_true));
    }

    //搜索接口
    public function searchList($keywords, $page=0)
    {
        $arr = array('status' => 1);
        $arr['hasNextPage'] = false;
        $this->redis->select(9);
        $redisGoogle = $this->redis->get('appbox_g_'.$keywords);//已经从google中取来的数据
        if($redisGoogle) {
            $arr['data'] = json_decode($redisGoogle,true);
            $leaveTime = $this->redis->ttl('appbox_g_'.$keywords);
            $leaveTime += $leaveTime > 86400 ? 0 : 3600;
            $this->redis->expire('appbox_g_'.$keywords,$leaveTime);
            return json_encode($arr);
        }

        $redisData = $this->redis->get('appbox_'.$keywords.'_'.$page);//如果存在第一步缓存的数据
        if($redisData && $page < 9) {
            if($page == 0) {
                $leaveTime = $this->redis->ttl('appbox_'.$keywords.'_0');
                $leaveTime += $leaveTime > 86400 ? 0 : 3600;
                for($i=0; $i<10; $i++) {
                    if(!$this->redis->expire('appbox_'.$keywords.'_'.$i,$leaveTime)){
                        break;
                    }
                }
            }
            $arr['hasNextPage'] = $this->redis->get('appbox_'.$keywords.'_'.($page+1)) ? true : false;
            $arr['data'] = json_decode($redisData,true);
            $arr['dataReids'] = 'from redis';
            return json_encode($arr);
        }
        //取得礼包类似数据
        $sql = "select distinct gift.id,gift.start_time from appbox_gift as gift ";
        $sql .= "left join appbox_gift_desc as descs on descs.gid=gift.id ";
        $sql .= "left join appbox_app as app on app.package_id=gift.package_id where ";
        $sql .= "(descs.name like '%$keywords%' or app.app_name like '%$keywords%') and gift.status=1 and descs.language='".$this->language."' ";
        $sql .= "and gift.start_time<=".time()." and gift.end_time>=".time()." and gift.package_id>0 ";
        $sql .= "order by gift.sort desc,gift.id desc";
        $gifts = $this->_db->getAll($sql);
        $returnGifts = array();
        if(!empty($gifts) && $gifts) {
            foreach($gifts as $key=>$val) {
                $tempData = $this->getGiftDetail($val['start_time'],$val['id']);
                if($tempData) $returnGifts[] = $tempData;
            }
        }
        $giftsCount = count($returnGifts);
        $datas = array();//返回的数组
        if($giftsCount < 100 || $page > 10) {
            //取得app类似的数据
            if(!$giftsCount < 100 && $page < 10) {//最少一次性取100条数据，并且生成缓存
                $limit = 100-$giftsCount;
            } else {//取100条以外的数据,可能额外需要更多的时间去加载
                $limit = ($page+1)*10-$giftsCount;
            }
            $sql = "select app.package_id as id,app.releaseTime from appbox_app as app ";
            $sql .= "where app.app_name like '%$keywords%' and app.status=1 and language='".$this->language."' ";
            $sql .= "order by " . $this->order . " limit 0,$limit";
            $apps = $this->_db->getAll($sql);
            $returnApps = array();
            if(!empty($apps) && $apps) {
                foreach($apps as $key=>$val) {
                    $tempData = $this->getAppDetail($val['releaseTime'], $val['id'],'1');
                    if($tempData) $returnApps[] = $tempData;
                }
            }
            $appsCount = count($returnApps);
            if(($appsCount+$giftsCount) < 100 || $page > 10) {
                if(!($appsCount+$giftsCount) < 100 && $page < 10) {
                    $limit = 100-($appsCount+$giftsCount);
                } else {
                    $limit = ($page+1)*10-($giftsCount+$appsCount);
                }
                //取得专题类似数据
                $sql = "select spread.id,spread.releaseTime from appbox_spread as spread ";
                $sql .= "where spread.status=1 and releaseTime<=".time()." and spread.name like '%$keywords%' ";
                $sql .= "order by spread.sort desc,spread.id desc limit 0,$limit";
                $spreads = $this->_db->getAll($sql);
                $returnSpreads = array();
                if(!empty($spreads) && $spreads) {
                    foreach($spreads as $key=>$val) {
                        $tempData = $this->getSpreadDetail($val['releaseTime'],$val['id']);
                        if($tempData) $returnSpreads[] = $tempData;
                    }
                }
                $datas = array_merge($returnGifts,$returnApps,$returnSpreads);
            } else {
                $datas = array_merge($returnGifts,$returnApps);
            }
        } else {
            $datas = $returnGifts;
        }
        //print_r($datas);exit;
        if(!empty($datas) && $datas) {
            $count = count($datas);
            $j = 0;
            $temp = array();
            foreach($datas as $key=>$val) {
                $temp[] = $val;
                if(count($temp) == 10 || $key == ($count-1)){
                    if($page == $j) {
                        $arr['hasNextPage'] = ceil($count/10)-$j == 0 ? false : true;//计算是否还有下一页
                        $arr['data'] = $temp;//返回当前页的数据
                    }
                    if($j < 10) {//只缓存前十页
                        $this->redis->set('appbox_'.$keywords.'_'.$j,json_encode($temp));
                        $this->redis->expire('appbox_'.$keywords.'_'.$j,3600);
                    }
                    $j++;
                    unset($temp);
                }
            }        
        } else {
            $url = 'http://play.mobappbox.com/index.php?m=Admin&c=Api&a=searchApp&keywords='.$keywords.'&language='.$this->language;
            $datas = file_get_contents($url);
            $datas = $this->matchTemplate(json_decode($datas,true));
            $this->redis->set('appbox_g_'.$keywords,json_encode($datas),3600);
            $arr['data'] = $datas;
        }
        return json_encode($arr);
    }

    //点击应用进入详情，更新缓存
    public function updateRedis($redisData,$data)
    {
        if($redisData['name'] != $data['name'] || $redisData['downloadCount'] != $data['downloadCount'] || $redisData['rate'] != $data['rate']) {//如果评分，下载量，名称有改变，则更新缓存
            $this->redis->select(8);
            $is_delete_app = $this->redis->delete($this->redis->keys('appboxL_'.$data['package_id'].'_*'));

            //如果该应用有礼包，则对该删除对应的礼包
            $have_gift = true;
            $sql = "select id from appbox_gift where package_id=".$data['package_id'];
            $gift = $this->_db->getRow($sql);
            if($gift) {
                $this->redis->select(4);
                $have_gift = $this->redis->delete($this->redis->keys('appboxL_g_'.$gift['id'].'_*'));
            }
            if($is_delete_app && $have_gift) {
                return true;
            }
            return false;
        }
        return true;
    }

    //去google搜索到的结果匹配模板,并且返回完整数据
    public function matchTemplate($data) {
        $sql = "select t.id,t.templateName,attr.* from appbox_template as t ";
        $sql .= "left join appbox_template_attr as attr on attr.templateId=t.id ";
        $sql .= "where t.type=8";
        $template = $this->_db->getAll($sql);
        if(!$template) {
            die('没有找到对应的模板~');
        } else {
            $return = array();//要返回的数据
            $field = array();//要查询的字段
            foreach($data as $key=>$val) {
                $xmlType = $template[0]['templateName'];
                foreach($template as $k=>$v) {
                    if(isset($v['attrValue']) || $v['jump']){
                        $field[$v['name']]['text'] = $val[$v['attrValue']] ? $val[$v['attrValue']] : '';
                        $field[$v['name']]['processType'] = $v['jump'] ? $v['jump'] : 0;
                    }
                }
                $view['view'] = $field;
                $view['xmlType'] = $xmlType;
                $view['extraData']['packageName'] = $val['package_name'];
                $return[] = $view;
            }
            return $return;
        }
    }
}
