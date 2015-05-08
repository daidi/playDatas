<?php
class SpreaddayilyModel extends RedisModel
{
    public $ver_code;
    public $pageNum = 100;
    public $expire = '1800';
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
    public function getJson($page='',$templateUpdateTime=0){
        $this->ver_code = isset($_GET['ver_code']) ? $_GET['ver_code'] : 8;//获取版本号
        //获取模板内容，如果模板未更新，则什么都不返回
        $arr = $this->getTemplate($templateUpdateTime);
        $arr['status'] = 1;//状态
        $arr['currentTime'] = time();
        $this->page = isset($page) ? (int)$page : 0;
        $sql = "select count(*) as num from appbox_spread where status=1 and dayily_sort!=0 and is_jx=1 and releaseTime<=".time();
        $arr['hasNextPage'] = $this->getPage($sql,$this->page,$this->pageNum);
        $page = $this->page*$this->pageNum;//初始化页数

        $arr['defaultSearchWord'] = $this->getNewKeywords();
        $this->redis->select(5);
        //获取推广列表
        $keys = 'appbox_dayily_info_'.$this->language.'_'.$this->ver_code.'_'.$this->page;//推入缓存的key
        $bannerKeys = 'banner_'.$this->language.'_'.$this->ver_code;
        if($redis_data = $this->redis->get($keys)) {
            $this->_parseEtags($redis_data,$this->page);//从查询第一页缓存是否有更新
            $myData = json_decode($redis_data,true);
            array_pop($myData);//删除数组最后一个元素，即设置的etag缓存时间
            if($this->page == 0) $arr['banner'] = $banner ? json_decode($banner,true) : $this->getNav();
            $arr['data'] = $myData;
            $banner = $this->redis->get($bannerKeys);
            $arr['dataRedis'] = 'from redis';
        } else {
            if($this->page == 0) $arr['banner'] = $this->getNav();//获取导航顺序列表
            $spread = $this->getList($page,'is_jx');
            if(!$spread) return json_encode(array('status'=>$this->is_true));
            foreach($spread as $key=>$val) {//将精选推入到数组
                $tempData = $this->getDayilySpreadDetail($val);
                if($tempData) $arr['data'][] = $tempData;
            }
            if($arr['data']){//推入缓存中
                $this->redis->select(5);
                $time = time();
                $arr['data'][] = $time;
                $this->_parseEtags(0,0,$time);//从查询第一页缓存是否有更新
                $this->redis->set($keys,json_encode($arr['data']),$this->expire);
                $this->redis->set($bannerKeys,json_encode($arr['banner']),$this->expire);
                array_pop($arr['data']);
            }
        }
        return json_encode($arr);
    }

    /**
    *   获取最新的关键词
    */
    public function getNewKeywords(){
        $sql = "select keywords from appbox_keywords where status=1 order by sort desc";
        $data = $this->_db->getRow($sql);
        if($data){
            $return = $data['keywords'];            
        } else {
            $this->redis->select(0);
            if($this->redis->exists('appbox_keywords')){
                $keywords = $this->redis->get('appbox_keywords');
                $keyArr = json_decode($keywords,true);
                $return = $keyArr[$this->language][0] ? $keyArr[$this->language][0] : $keyArr['en'][0];
            }
        }
        $return = $return ? $return : 'games';
        return $return;
    }

    public function getList($p,$position="is_jx")
    {
        //获取应用app
        $sql = "select id,releaseTime,expand,spread_type,name from appbox_spread
                    where status=1 and releaseTime<=".time()." and $position=1 and dayily_sort!=0
                    order by dayily_sort desc,id desc limit $p,".$this->pageNum;
        $info = $this->_db->getAll($sql);
        return $info;
    }

    public function getDayilySpreadDetail($val){
        $datas['subject_id'] = $val['id'];
        $name = json_decode(htmlspecialchars_decode($val['name']),true);
        if($val['expand'] != false && ($val['spread_type'] != 2 && $val['spread_type'] != 3)){//代表是展开的形式
            $datas['subject_title'] = $name[$this->language];
            $datas['status'] = 'expand';
            $datas['data'] = $this->getDetailJson($val['id'],$val['expand']);
        } elseif($val['spread_type'] != 2) {//非展开的形式就是发放一张图片,即原来的专题
            $datas['status'] = 'collapse';
            $datas['data'][] = $this->getSpreadDetail($val['releaseTime'],$val['id']);
        }

        if($val['spread_type'] == 1 && $val['expand'] != false){//文章类型自动插入采集过来的信息
            $datas['is_news'] = true;
            $tempData = $this->getCollectNews($val['expand']);
            if($datas['data']) $datas['data'] = array_merge($datas['data'],$tempData);
            else $datas['data'] = $tempData;
        //图片 gif类型自动采集填充
        } elseif(($val['spread_type'] == 2 || $val['spread_type'] == 3) && $this->ver_code > 8) {
            $keys = $val['spread_type'] == 2 ? 'appbox_collect_colsplay' : 'appbox_collect_gifs';
            $type = $val['spread_type'] == 2 ? 'is_images' : 'is_gifs';
            $tempData = $this->getCollectImages($keys);
            if($tempData){
                $datas[$type] = true;
                $datas['subject_title'] = $name[$this->language];
                $datas['data']= $tempData;
                $datas['status'] = 'expand';
            }
        }

        if(!$datas['data']){
            return false;
        }
        return $datas;
    }


    public function getDetailJson($id,$nums){
        $sql = "select * from appbox_spread_list where spreadId=$id order by sort desc,id asc limit ".$nums;
        $data = $this->_db->getAll($sql);
        $spread_mod = new SpreadModel($this->language);
        foreach($data as $key=>$val){
            $tempData = $spread_mod->parseType($val);
            if($tempData)
                $arr[] = $tempData;
        }
        return $arr;
    }

    public function getNav(){
        $sql = "select title,process_type as processType,icon_url as iconUrl,bg_color as bgColor,img as imageUrl,spread_id as spreadId from appbox_nav where status=1 order by sort desc";
        $data = $this->_db->getAll($sql);
        foreach($data as $key=>$val){
            $title = json_decode(htmlspecialchars_decode($val['title']),true);
            $data[$key]['title'] = isset($title[$this->language]) && !empty($title[$this->language]) ? $title[$this->language] : $title['en'];
        }
        return $data;
    }
}
