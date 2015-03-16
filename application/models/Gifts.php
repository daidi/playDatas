<?php

class GiftsModel extends RedisModel
{
    protected $pageNum = 10;

    public function __construct($language = '')
    {
        parent::__construct();
    }

    /**
     * 获取应用json
     * @param int|string $page int 客户端出来的分类
     * @param string $templateUpdateTime 模板更新时间
     * @return string $json@date 2014/12/20 11:11
     */
    public function getJson($page = '', $templateUpdateTime = '')
    {
        //获取模板内容，如果模板未更新，则什么都不返回
        $arr = $this->getTemplate($templateUpdateTime);
        $arr['status'] = 1;//状态
        $this->page = isset($page) ? (int)$page : 0;
        $sql = "select count(*) as num from appbox_gift where status=1 and start_time<=".time()." ";
        $sql .= "and end_time>=" . time() . " and package_id>0 ";
        $arr['hasNextPage'] = $this->getPage($sql, $this->page, $this->pageNum);
        $page = $this->page * $this->pageNum;//初始化页数

        //获取礼包
        $this->redis->select(4);
        if (false && $redis_datas = $this->redis->get('appboxgL_' . $this->language . '_' . $this->page)) {
            $arr['data'] = $this->getGiftRedis($redis_datas);
            $arr['dataRedis'] = 'from redis';
        } else {
            $gifts = $this->getList($page);
            if (!$gifts) return json_encode(array('status' => $this->is_true));
            foreach ($gifts as $val) {//将app推入到数组
               $tempData = $this->getGiftDetail($val['start_time'],$val['id']);
               if($tempData) $arr['data'][] = $tempData;
               else continue;               
            }
            if ($arr['data'] && !empty($arr['data'])) {
                $this->setGiftRedis($arr['data']);
            }
        }

        //获取推广图
        if ($this->page == 0)//第一页的时候发放所有的广告
        {
            $this->redis->select(6);
            if (false && $banners = $this->redis->get('appboxbL_'.$this->language.'_4'))//从缓存中取数据
            {
                $banners = json_decode($banners, true);
                $arr['bannersRedis'] = 'from redis';
            } else {
                $banners = $this->getBanners(4);//推广所在位置，1：精选，2：游戏，3：应用，4礼包
                $arr['bannersRedis'] = 'from mysql';
            }
            //将广告推到data中
            if(isset($banners) && $banners){
                $arr['data'] = array_merge($banners,$arr['data']);
            }
        }        
        return json_encode($arr);
    }

    /*
     * 获取礼包
     */
    public function getList($p)
    {
        //获取礼包
        $sql = "select gift.start_time,gift.id
                    from appbox_gift as gift
                    left join appbox_app as app on app.package_id=gift.package_id
                    where gift.status=1 and app.language='" . $this->language . "' and gift.start_time<=" . time() . " and
                    gift.end_time>=" . time() . " and gift.package_id>0
                    order by gift.sort desc,gift.id desc
                    limit $p," . $this->pageNum;
        return $this->_db->getAll($sql);
    }

    //获取礼包详情
    public function getDetailJson($id='',$packageName='',$chargePoint='')
    {
        if($id) {
            $where = "where gift.id=$id";
        } elseif($packageName && $chargePoint) {
            $where = "where gift.package_name='$packageName' and gift.fee_node=$chargePoint";
        } else {
            echo '参数不合法！';exit;
        }        

        $this->redis->select(4);
        if ($data = $this->redis->get('appboxgD_' . $this->language . '_' . $id)) {
            return $data;
        }

        $sql = "select app.app_name as name,app.package_name as pkg_name,app.icon as icon_url,app.price,app.score as rating_num,app.install_count as download_num,descs.description as giftDesc,descs.name as giftName,descs.manual as giftUsageDesc,descs.gid 
                from appbox_gift as gift 
                left join appbox_app as app on app.package_id=gift.package_id left join appbox_gift_desc as descs on descs.gid=gift.id
                $where and descs.language='" . $this->language . "'";
        $gift = $this->_db->getRow($sql);
        if (!$gift['giftDesc'] && $this->language != 'en')//如果对应语言没有取到,则取默认语言英语
        {
            $sql = "select app.app_name as name,app.package_name as pkg_name,app.icon as icon_url,app.price,app.score as rating_num,app.install_count as download_num,descs.description as giftDesc,descs.name as giftName,descs.manual as giftUsageDesc,descs.gid
                    from appbox_gift as gift 
                    left join appbox_app as app on app.package_id=gift.package_id left join appbox_gift_desc as descs on descs.gid=gift.id
                    $where and descs.language='en'";
            $gift = $this->_db->getRow($sql);
        }
        if (!$gift) return json_encode(array('status' => $this->is_true));

        //将同包名所有的礼包查询出来
        $sql = "select descs.gid,descs.name from appbox_gift as gift left join appbox_gift_desc as descs on descs.gid=gift.id
            where gift.package_name='{$gift['pkg_name']}' and descs.language='".$this->language."' and gift.id!={$gift['gid']}";
        $siblings = $this->_db->getAll($sql);
        foreach($siblings as $key=>$val) {
            if(!$val['name']) {
                $sql = "select name from appbox_gift_desc where gid=".$val['gid']." and language='en'";
                $data = $this->_db->getRow($sql);
                $siblings[$key]['name'] = $data['name'];
            }
        }
        if($siblings) {
            $gift['gift'] = $siblings;    
        }
        
        $gift['market_url'] = 'http://play.google.com/store/apps/details?id=' . $gift['pkg_name'];
        $return = array();
        $return['data'] = $gift;
        $return['status'] = 1;
        if($id) {
            $this->redis->set('appboxgD_' . $this->language . '_' . $id, json_encode($return));
            $this->redis->expire('appboxgD_' . $this->language . '_' . $id, $this->expire);
        }
        return json_encode($return);
    }

    //获取本地礼包列表
    public function getLocal($packageName, $releaseTime)
    {
        $arr = array('status' => 1);
        if (!$packageName) return json_encode(array('status' => $this->is_true));
        $data = explode(',', $packageName);

        $temp = array();
        foreach ($data as $k => $v)//查询包名对应的包id
        {
            $sql = "select package_id,icon,app_name,id
                    from appbox_app
                    where package_name='$v' and language='" . $this->language . "'
            ";
            $temp[] = $this->_db->getRow($sql);
        }
        // print_R($temp);
        foreach ($temp as $k => $v) {
            $sql = "select descs.name as giftName,gift.id as giftId,gift.package_name
                    from appbox_gift as gift 
                    left join appbox_gift_desc as descs on descs.gid=gift.id
                    where descs.language='" . $this->language . "' and gift.package_id='{$v['package_id']}'
            ";
            $arr1 = $this->_db->getAll($sql);
            if ($arr1) {
                $template = $this->getSelfTemplate($releaseTime, 7);//获取与其时间对应的模板
                $extral = $this->getLocalExtral($arr1, $template['templateName']);
                $field = $this->getField($template['id'], 'app');//取出与其对应模板的内容
                $app = $this->getLocalData($v['id'], $field['field']);//获取这条专题的内容
                //json格式中的view
                $view['view'] = $this->getView($field['data'], $app);
                $arr['data'][] = array_merge($view, $extral);
            }
        }
        return json_encode($arr);
    }

    //获取包名
    public function getLocalExtral($arr, $templateName)
    {
        $return = array('xmlType' => $templateName);
        $return['extraData']['packageName'] = $arr[0]['package_name'];
        foreach ($arr as $k => $v) {
            unset($arr[$k]['package_name']);
            $return['extraData']['gifts'][] = $arr[$k];
        }
        return $return;
    }

    //获取app详细信息返回
    public function getLocalData($id, $field)
    {
        $sql = "select $field
                from appbox_app as app
                where app.id=$id
        ";
        return $this->_db->getRow($sql);
    }

    //本地礼包详情
    public function getLocalDetail($packageName)
    {
        $arr = array();//要返回的json
        $sql = "select icon as app_icon,app_name as app_name,package_name as packageName,screen_shots from appbox_app where package_name='$packageName' and language='" . $this->language . "'";
        $data = $this->_db->getRow($sql);//游戏详情
        if ($data) {
            $arr['status'] = 1;
            $data['screen_shots'] = json_decode($data['screen_shots'], true);
            $sql = "select descs.name as giftName,gift.id as giftId,gift.fee_node as chargePoint,gift.start_time as updateTime,descs.description as giftDesc
                    from appbox_gift as gift 
                    left join appbox_gift_desc as descs on descs.gid=gift.id
                    where descs.language='" . $this->language . "' and gift.package_name='$packageName'
            ";
            $gift = $this->_db->getAll($sql);//获取礼包列表
            foreach($gift as $key=>$val) {
                $sql = "select code from appbox_gift_code where gid={$val['giftId']}";
                $gift[$key]['codes'] = $this->_db->getAll($sql);
            }
            $data['gifts'] = $gift;
            $arr['data'] = $data;
            return json_encode($arr);
        } else {
            return json_encode(array('status' => $this->is_true));
        }
    }

    /**
     * 获取礼包兑换码
     * @param string $code 游戏兑换码
     * @param string $id 礼包的id
     * @param string $packageName 包名
     * @param string $chargePoint 计费点
     * @return string
     */
    public function getCode($code = '', $id = '', $packageName = '', $chargePoint = '')
    {
        $arr = array('status' => 1);
        if (($packageName && $chargePoint && $code) || ($id && $code)) {//如果存在计费点，兑换码，包名,或礼包id
            if($packageName && $chargePoint && $code)
                $sql = "select code_index,gid,package_name from appbox_gift_code where package_name='$packageName' and code='$code' and fee_node='$chargePoint'";
            elseif($id && $code)
                $sql = "select code_index,gid,package_name from appbox_gift_code where gid=$id and code='$code'";
            $data = $this->_db->getRow($sql);
            if ($data) {
                $index = $data['code_index'] + 1;
                $id = $data['gid'];
                $packageName = $data['package_name'];
                $sql = "select code.code,code.fee_node as chargePoint,code.package_name as packageName,code.gid as giftId,code.interval as nextTime from appbox_gift_code as code where code.package_name='$packageName' and code.code_index=$index and gid=$id";
                $code = $this->_db->getRow($sql);
                if ($code)
                    $arr['data'] = $code;
                else
                    $arr['status'] = $this->is_true;
            } else {
                $arr['status'] = $this->is_true;
            }
        } elseif($id || ($chargePoint && $packageName)) {
            if($id) {
                $sql = "select code.code,code.fee_node as chargePoint,code.package_name as packageName,";
                $sql .= "code.gid as giftId ,code.interval as nextTime from appbox_gift_code as code";
                $sql .= " where code.gid=$id and code_index=1";
            } elseif($chargePoint && $packageName) {
                $sql = "select code.code,code.fee_node as chargePoint,code.package_name as packageName,";
                $sql .= "code.gid as giftId ,code.interval as nextTime from appbox_gift_code as code";
                $sql .= " where code.package_name='$packageName' and code_index=1";
            }
            $code = $this->_db->getRow($sql);
            if ($code)
                $arr['data'] = $code;
            else
                $arr['status'] = $this->is_true;
        } else {
            echo '参数不合法！';exit;
        }
        return json_encode($arr);
    }

    //验证礼包兑换码是否有效，并且存入数据库，同时返回下一个兑换码，
    public function verifyCode($packageName,$code)
    {
        $sql = "select fee_node from appbox_gift_code where package_name='$packageName' and code='$code'";
        $data = $this->_db->getRow($sql);
        if($data) {
            $sql = "select * from appbox_gift_code_verify where package_name='$packageName' and code='$code'";
            $verify = $this->_db->getRow($sql);
            if(!$verify) {//插入
                $sql = "insert into appbox_gift_code_verify values('$packageName','$code','1','".time()."')";
                $this->_db->query($sql);
            }else {//修改
                $sql = "update appbox_gift_code_verify set `count`='".$verify['count']."'+1 where package_name='$packageName' and code='$code'";
                $this->_db->query($sql);
            }
            return json_encode(array('status'=>true,'chgpt'=>$data['fee_node']));
        }
        else {
            return json_encode(array('status'=>false,'reason'=>'兑换码不存在或已过期！','chgpt'=>-1));
        }
    }

    public function getCircleGift($p){
        $arr['status'] = 1;
        $sql = "select count(*) as num from appbox_gift where status=1 and start_time<=".time()." ";
        $sql .= "and end_time>=" . time() . " and package_id>0 ";
        $arr['hasNextPage'] = $this->getPage($sql, $p, 3);
        $page = $p * 3;//初始化页数

        $sql = "select gift.package_id,gift.package_name as packageName,gift.get_count as downloadCount,app.score as rate,";
        $sql .= "app.icon as iconUrl,app.app_name as name,descs.name as description from appbox_gift as gift ";
        $sql .= "left join appbox_app as app on app.package_id=gift.package_id left join appbox_gift_desc as ";
        $sql .= "descs on descs.gid=gift.id where gift.status=1 and descs.language='".$this->language."' and ";
        $sql .= "gift.start_time<=" . time() . " and gift.end_time>=" . time() . " and gift.package_id>0 ";
        $sql .= "group by gift.package_id order by gift.sort desc,gift.id desc limit $page,3";
        $data = $this->_db->getAll($sql);
        $arr['data'] = $data;
       return json_encode($arr);
    }

    public function getRandGift($packageName){
        $return = array();
        $return['status'] = 1;
        $sql = "select gift.package_id,gift.id as giftId,gift.package_name as pkg_name,gift.get_count ";
        $sql .= "as acquireTime,app.score as rating_num,gift.logo as giftIconUrl,descs.keywords as giftTag,";
        $sql .= "app.icon as icon_url,app.app_name as name,descs.name as giftName from appbox_gift as gift ";
        $sql .= "left join appbox_app as app on app.package_id=gift.package_id left join appbox_gift_desc as ";
        $sql .= "descs on descs.gid=gift.id where gift.status=1 and descs.language='".$this->language."' and ";
        $sql .= "gift.start_time<=" . time() . " and gift.end_time>=" . time() . " and gift.package_id>0 ";
        
        $temp = array();
        //如果客服端发送包名,则找到对应的礼包
        if($packageName){
            $arr = explode(',',$packageName);
            shuffle($arr);//打乱数组
           foreach($arr as $key=>$val){
                $where = "and gift.package_name='$val'";
                $sqls = $sql.$where;
                $data = $this->_db->getRow($sqls);
                $count = count($temp);
                if($data && $count < 2) $temp[] = $data;
                unset($where);
           }
        }

        if(!empty($temp) && $temp) {
            $count = count($temp);
            if($count == 2){
                $return['data'] = $temp;
            } else {
                $packageName = $temp[0]['pkg_name'];
                $sql .= "and gift.package_name!= '$packageName' order by rand() limit 1";
                $data = $this->_db->getAll($sql);
                if($data) $temp[] = $data[0];
                $return['data'] = $temp;
            }
        } else {
            $sql .= "group by gift.package_id order by rand() limit 2";
            $return['data'] = $this->_db->getAll($sql);
        }
        return json_encode($return);
    }
}
