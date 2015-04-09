<?php
class DayilyModel extends RedisModel
{
    protected $category = 1;//分类，1，应用，2游戏
    protected $is_game = 2;//是游戏还是应用，1,游戏，2，应用
    protected $pageNum = 10;

    //如果开启了自动插入
    protected $spreadCount;//不在精选表专题的总数
    protected $giftCount;//不在精选表礼包的总数
    protected $nums;//精选表置顶的总数
    protected $spreadInterval;//专题插入的间隔
    protected $giftInterval;//礼包插入的间隔
    protected $hasNextPage = false;//是否还有下一页

    public function __construct($language='') 
    {
        parent::__construct();
    }

    /**
     *   获取应用json
     * @param int|string $page int 客户端出来的分类
     * @param int $templateUpdateTime
     * @date 2014/12/20 11:11
     * @return string
     */
    public function getJson($page='',$templateUpdateTime=0)
    {
        //获取模板内容，如果模板未更新，则什么都不返回
        $arr = $this->getTemplate($templateUpdateTime);
        $arr['status'] = 1;//状态
        $this->page = isset($page) ? (int)$page : 0;
        $sql = "select count(*) as num from appbox_dayily where status=1 and releaseTime<=".time();
        $arr['hasNextPage'] = $this->getPage($sql,$this->page,$this->pageNum);
        $page = $this->page*$this->pageNum;//初始化页数

        //获取推广图
        if($this->page == 0) {//第一页的时候发放所有的广告
            $this->redis->select(6);
            if($banners = $this->redis->get('appboxbL_'.$this->language.'_1')) {//从缓存中取数据
                $arr['banners'] = json_decode($banners,true);
                $arr['bannerRedis'] = 'from redis';            
            } else {
                $arr['banners'] = $this->getBanners(1);//推广所在位置，1：精选，2：游戏，3：应用，4礼包
                $arr['bannerRedis'] = 'from mysql';                        
            }
        }

        //查找是否开启了自动推入功能
        $sql = "select * from appbox_auto_insert where status=1";
        $value = $this->_db->getRow($sql);

        $this->redis->select(3);
        if($value && !empty($value)){
            //获取缓存精选中的数据
            if($redis_datas = $this->redis->get('appboxdL_'.$this->language.'_'.$this->page)) {
                if($this->redis->get('appboxdL_'.$this->language.'_'.($this->page+1)) || $this->havePage()) $arr['hasNextPage'] = true;
                $arr['data'] = $this->getDayilyRedis($redis_datas);
                $arr['dataRedis'] = 'from redis';
            } else {
                $datas = $this->autoInsert($value);
                $datas = $this->makeData($datas);

                $count = count($datas);
                $pages = ceil($count/10);//当前缓存的页数
                $currentPage = $this->page;

                if($currentPage >= $pages) {
                    $arr['data'] = $this->otherPageData($currentPage);
                    $arr['hasNextPage'] = $this->hasNextPage;
                } else {
                    $this->redis->delete($this->redis->keys('appboxdL_'.$this->language.'_*'));//清除一下当前缓存，从新生成;
                    $arr['hasNextPage'] = $this->hasNextPage;
                    $j = 0;
                    $temp = array();
                    foreach($datas as $key=>$val) {
                        $temp[] = $val;
                        if(count($temp) == 10 || $key == ($count-1)){
                            if($currentPage == $j) $arr['data'] = $temp;//返回当前页的数据
                            $this->page = $j;
                            $this->setDayilyRedis($temp);
                            unset($temp);
                            $j++;
                        }
                    }
                }      
            }
            return json_encode($arr);           
        } else {
            //获取缓存精选中的数据
            if($redis_datas = $this->redis->get('appboxdL_'.$this->language.'_'.$this->page)) {
                $arr['data'] = $this->getDayilyRedis($redis_datas);
                $arr['dataRedis'] = 'from redis';
            } else {
                $dayily = $this->getList($page);
                if(!$dayily) return json_encode(array('status'=>$this->is_true));
                $arr['data'] = $this->makeData($dayily);
            }
            if($arr['data'] && !empty($arr['data'])) {
                $this->setDayilyRedis($arr['data']);
            }   
            return json_encode($arr);
        }
    }

    //从模板中生成数据,并且生成缓存
    public function makeData($dayily) {
        $return = array();
        foreach($dayily as $val)//将精选推入到数组
        {
            switch($val['type'])//根据不同类型获取不同模板内容
            {
                case 'app':
                    $tempData = $this->getAppDetail($val['releaseTime'], $val['typeId'],'1');
                    if($tempData) $return[] = $tempData;
                    break;
                case 'gift':
                    $tempData = $this->getGiftDetail($val['releaseTime'],$val['typeId']);
                    if($tempData) $return[] = $tempData;
                    break;
                case 'spread':
                    $tempData = $this->getSpreadDetail($val['releaseTime'],$val['typeId']);
                    if($tempData) $return[] = $tempData;
                    break;
            }
        }
        return $return;   
    }

    /*
     * 获取应用
     */
    public function getList($p)
    {
        //获取应用app
        $sql = "select dayily.releaseTime,dayily.type,dayily.typeId
                    from appbox_dayily as dayily
                    where dayily.status=1 and releaseTime<=".time()."
                    order by dayily.is_top desc,dayily.sort desc,dayily.id desc
                    limit $p,".$this->pageNum;
		$info = $this->_db->getAll($sql);
		
/*		$sql = "UPDATE appbox_dayily SET sort = ABS(sort - 1) where status=1 and releaseTime<=".time()."
                    order by sort desc,id desc
                    limit 5";
		$this->_db->execute($sql);*/
		
        return $info;
    }

    //如果开启自动插入，则拼接所有的礼包和专题到精选中
    public function autoInsert($value){
            $interval = $value['interval'];//礼包或专题取得总数
            $this->giftInterval = $value['gift'];//礼包间隔数
            $this->spreadInterval = $value['spread'];//专题间隔数
            //所有未插入精选的礼包
            $sql = "select gift.start_time as releaseTime,gift.id as typeId from appbox_gift as gift where gift.id not in ";
            $sql .= "(select typeId from appbox_dayily as dayily where dayily.type='gift') and gift.status=1 and ";
            $sql .= "gift.start_time<=" . time() . " and gift.end_time>=" . time() . " and gift.package_id>0 ";
            $sql .= "order by gift.sort desc,gift.id desc limit 0," . $interval;
            $gift = $this->_db->getAll($sql);
            //所有未插入精选的专题
            $sql = "select spread.id as typeId,spread.releaseTime from appbox_spread as spread where spread.id not in ";
            $sql .= "(select typeId from appbox_dayily as dayily where dayily.type='spread') and spread.status=1 and ";
            $sql .= "spread.releaseTime<=".time()." and spread.is_zt=1 ";
            $sql .= "order by spread.sort desc,spread.id desc limit 0,".$interval;
            $spread = $this->_db->getAll($sql);
            $data = $this->mergeGift($gift,$spread);//组合的礼包和专题
            //print_r($data);exit;
            //计算出来一次性推入redis的数据
            $this->pageNum = $this->giftCount*$this->giftInterval+$this->spreadCount*$this->spreadInterval;//最少从精选表中取得条数
            $sql = "select count(*) as num from appbox_dayily where is_top!=0 and status=1 and releaseTime<=".time()."";
            $num = $this->_db->getAll($sql);//查到的精选表中置顶的条数
            $this->nums = isset($num[0]['num']) ? $num[0]['num'] : 0;
            $this->pageNum += $this->nums;//加上置顶的数值

            $mod = ($this->pageNum+count($data)) % 10;
            if($mod != 0){//取精选整数
                $this->pageNum += (10-$mod);//pageNum+要插入精选的礼包和专题加起来正好是10的倍数
            }
            $this->redis->select(3);
            if($this->redis->exists('appbox_Nums')) $this->redis->delete('appbox_Nums');
            $this->redis->set('appbox_Nums',$this->pageNum.','.((ceil($this->pageNum+count($data))/10)));
            $dayily = $this->getList(0);
            if($this->getList($this->pageNum)) $this->hasNextPage = true;
            return $this->mergeAutoInsert($data,$dayily);
    }

    //将要插入的礼包和专题进行隔行组合
    public function mergeGift($gift,$spread){
        $this->spreadCount = count($spread);
        $this->giftCount = count($gift);
        $i=0;//取第几个礼包
        $data = array();//放回的礼包专题组合数据
        if($this->spreadCount >= $this->giftCount) {
            foreach($spread as $key=>$val){
                if(isset($gift[$i])) {
                    $gift[$i]['type'] = 'gift';
                    $gift[$i]['auto'] = 'auto';
                    $data[] = $gift[$i];
                    $i++;
                }
                $val['type'] = 'spread';
                $val['auto'] = 'auto';
                $data[] = $val;
            }        
        }else {
            foreach($gift as $key=>$val){
                $val['type'] = 'gift';
                $val['auto'] = 'auto';
                $data[] = $val;
                if(isset($spread[$i])) {
                    $spread[$i]['type'] = 'spread';
                    $spread[$i]['auto'] = 'auto';
                    $data[] = $spread[$i];
                    $i++;
                }
            }        
        }
        return $data;
    }

    //将插入的数据和原来的数据进行组合插入
    public function mergeAutoInsert($data,$dayily) {
        $return = array();
        if($this->nums>0){//先将精选置顶的数据拿出来
            foreach($dayily as $key=>$val) {
                if($key < $this->nums){
                    $return[] = $val;
                    array_splice($dayily,0,1);   
                } else {
                    break;
                }
            }            
        }
        //合并构造返回
        foreach($data as $key=>$val) {
            if($val['type'] == 'gift') {
                $return[] = $val;
                if(!empty($dayily)) {//如果精选中还存在数据
                    for($i=0; $i<$this->spreadInterval; $i++) {
                        $return[] = $dayily[$i];
                    }                    
                    array_splice($dayily,0,$this->spreadInterval);   
                }
            } elseif($val['type'] == 'spread') {
                $return[] = $val;
                if(!empty($dayily)) {//如果精选中还存在数据
                    for($i=0; $i<$this->giftInterval; $i++) {
                        $return[] = $dayily[$i];
                    }                      
                    array_splice($dayily,0,$this->giftInterval);   
                }
            }
        }
        //如果精选数据有剩余，则将其全部推入放回数组存放
       if(!empty($dayily)) {
            $return = array_merge($return,$dayily);
        }
        return $return;
    }

    public function otherPageData($currentPage){
        $this->redis->select(3);
        $nums = $this->redis->get('appbox_Nums');
        if(!$nums) die('开启了自动插入，但是缓存中没有保存先前的数据！');
        $arr = explode(',',$nums);
        $start = $arr[0];//从第几条开始取
        $page = $arr[1];//已经分了多少页
        $interval = $currentPage-$page;
        $start += $interval*10;
        $dayily = $this->getList($start);
        $start += ($interval+1)*10;
        if($this->getList($start)){
            $this->hasNextPage = true;  
        } else {
            $this->hasNextPage = false;
        }        
        if(!$dayily) return json_encode(array('status'=>$this->is_true));

        $data = $this->makeData($dayily);
        if($data&& !empty($data)) {
            $this->setDayilyRedis($data);
        }
        return $data;
    }

    //是否存在分页
    public function havePage(){
        $this->redis->select(3);
        $nums = $this->redis->get('appbox_Nums');
        $arr = explode(',',$nums);
        $start = $arr[0];//从第几条开始取
        $start += $this->pageNum;
        if($this->getList($start)) {
            return true;
        }
    }
}
