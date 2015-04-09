<?php
class SpreadModel extends RedisModel
{
    protected $pageNum = 10;
    //protected $url = 'market://details?id=';//跳转URL路径

    public function __construct($language='') 
    {
        parent::__construct();
        $this->redis->select(5);
    }

    /**
     *   获取应用json
     * @param int|string $page int 模板更新时间
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
        $sql = "select count(*) as num from appbox_spread where status=1 and releaseTime<=".time();
        $arr['hasNextPage'] = $this->getPage($sql,$this->page,$this->pageNum);
        $page = $this->page*$this->pageNum;//初始化页数

        //获取推广列表
        if($redis_data = $this->redis->get('appboxsL_' . $this->language . '_' . $this->page)) {
            $arr['data'] = $this->getSpreadRedis($redis_data);
            $arr['dataRedis'] = 'from redis';
        } else {
            $spread = $this->getList($page);
            if(!$spread) return json_encode(array('status'=>$this->is_true));
            foreach($spread as $val) {//将app推入到数组
                $tempData = $this->getSpreadDetail($val['releaseTime'],$val['id']);
                if($tempData) $arr['data'][] = $tempData;
                else continue;                
            }
            if($arr['data'] && !empty($arr['data'])) {
                $this->setSpreadRedis($arr['data']);
            }         
        }
        //print_r($arr);exit;
        return json_encode($arr);
    }

    /*
     * 获取应用
     */
    public function getList($p,$position="is_zt")
    {
        //获取应用app
        $sql = "select spread.id,spread.releaseTime
                    from appbox_spread as spread
                    where spread.status=1 and releaseTime<=".time()." and $position=1 
                    order by spread.sort desc,spread.id desc
                    limit $p,10";
		$info = $this->_db->getAll($sql);
/*		$sql = "UPDATE appbox_spread as spread SET spread.sort = ABS(spread.sort - 1) where spread.status=1 and releaseTime<=".time()."
                    order by spread.sort desc,spread.id desc
                    limit 5";;
		$this->_db->execute($sql);*/
		
        return $info;
    }

    //专题详情
    public function getDetailJson($id,$page='')
    {
        $this->page = isset($page) && $page ? (int)$page : 0;
        $arr = array();
        $arr['status'] = 1;
        $is_news = isset($_REQUEST['is_news']) ? $_REQUEST['is_news'] : 0;//是否显示新闻
        $is_images = isset($_REQUEST['is_images']) ? $_REQUEST['is_images'] : 0;//是否显示采集过来的图片
        $type = isset($_REQUEST['type']) ? $_REQUEST['type'] : '';//是否显示采集过来的图片
        if($is_news || $type == 'is_news'){//判断是否采集过来的新闻
            $this->redis->select('5');
            $arr['hasNextPage'] = false;
            $arr['data'] = $this->getCollectNews(100);
            return json_encode($arr);
        } elseif($is_images || $type == 'is_images'){
            return $this->getCollectData('appbox_collect_colsplay',$page);
        }

        switch($type){
            case 'gifs':
                return $this->getCollectData('appbox_collect_gifs',$page);
            break;
        }

        //初始化页数
        $sql = "select count(*) as num from appbox_spread_list where spreadId=$id";
        $arr['hasNextPage'] = $this->getPage($sql,$this->page,$this->pageNum);
        $page = $this->page*$this->pageNum;

        //原先的模板数据重新获取
        $sql = "select releaseTime,id,name,description from appbox_spread where id=$id";
        $data = $this->_db->getRow($sql);
        if($data)
        {
            $firstData = array();
            if($this->page == 0)
            {
                //获取名称和描述
                $json_title = json_decode(htmlspecialchars_decode($data['name']),true);
                $json_description = json_decode(htmlspecialchars_decode($data['description']),true);
                $arr['title'] = $json_title[$this->language];
                $arr['description'] = $json_description[$this->language];
                $datas = $this->getSpreadDetail($data['releaseTime'],$data['id']);
                foreach($datas['view'] as $key=>$val)
                {
                    unset($datas['view'][$key]['processType']);
                }
                $firstData[] = $datas;                
            }
            //从redis中获取数据
            $this->redis->select(5);
            if($redis_datas = $this->redis->get('appboxsDL_'.$this->language.'_'.$this->page.'_'.$id)) {
                $data = $this->getSpreadDetailRedis($redis_datas);
                if($firstData && !empty($firstData)) $arr['data'] = array_merge($firstData,$data);
                else $arr['data'] = $data;
                $arr['dataRedis'] = 'from redis';
                return json_encode($arr);
            }
            //当前专题里所有的信息，从mysql中获取数据
            $arr['data'] = $firstData;
            $sql = "select * from appbox_spread_list where spreadId=$id order by sort desc,id asc limit $page,".$this->pageNum;
            $spreadList = $this->_db->getAll($sql);
            if(!$spreadList) return json_encode(array('status'=>$this->is_true));
            foreach($spreadList as $val)
            {
                $temp = $this->parseType($val);
                if($temp)
                    $arr['data'][] = $temp;
            }
            if($arr['data'] && !empty($arr['data'])) {//设置缓存
                $this->setSpreadDetailRedis($arr['data'],$id);                
            }
            return json_encode($arr);
        } else {
            return json_encode(array('status'=>$this->is_true));
        }
    }
/**
*   返回采集的colsplay或者是gif
*   @param $key string gif的key或者colsplay的key
*   @param $page int 页数
*   @return array
*/
    private function getCollectData($key,$page){
        $this->redis->select('10');
        $start = $page*50;
        $end = $start+50-1;
        $articleDatas = $this->redis->lRange($key,$start,$end);

        //判断是否还有下一页
        $nextStart = ($page+1)*50;
        $nextEnd = $nextStart+50-1;
        $arr['hasNextPage'] = $this->redis->lRange($key,$nextStart,$nextEnd) ? true : false;

        foreach($articleDatas as $key=>$val){
            $arr['data'][] = json_decode($val,true);
        }
        $arr = json_encode($arr);
        return $arr;
    }

    //推广图详情
    public function bannerDetailJson($id,$page='')
    {  
        $this->page = isset($page) ? (int)$page : 0;
        $arr = array();
        $arr['status'] = 1;
        //初始化页数
        $sql = "select count(*) as num from appbox_banner_list where bannerId=$id";
        $arr['hasNextPage'] = $this->getPage($sql,$this->page,$this->pageNum);
        $page = $this->page*$this->pageNum;

        //原先的模板数据重新获取
        $sql = "select releaseTime,id,name,description,imgHeight,imgWidth from appbox_banner where id=$id";
        $data = $this->_db->getRow($sql);
        
        $template = $this->getSelfTemplate($data['releaseTime'],4);//获取与其时间对应的模板
        if($template)
        {
            $firstData = array();
            if($this->page == 0)//第一页的时候发放原来模板的数据
            {
                //获取名称和描述
                $json_title = json_decode(htmlspecialchars_decode($data['name']),true);
                $json_description = json_decode(htmlspecialchars_decode($data['description']),true);
                $arr['title'] = isset($json_title[$this->language]) ? $json_title[$this->language] : $json_title['en'];
                $arr['description'] = isset($json_description[$this->language]) ? $json_description[$this->language] : $json_description['en'];
                $field = $this->getField($template['id'],'banner');//取出与其对应模板的内容
                $banner = $this->getBanner($data['id'],$field['field'],$this->language);//获取这条专题的内容  
                $view = $this->getView($field['data'],$banner);
                //json格式中的extraData
                $extraData = array('bannerId'=>$data['id'],'imgWidth'=>$data['imgWidth'],'imgHeight'=>$data['imgHeight']);
                //合成一条app数据
                $datas = array('xmlType'=>$template['templateName'],'view'=>$view,'extraData'=>$extraData);
                foreach($datas['view'] as $key=>$val)
                {
                    unset($datas['view'][$key]['processType']);
                }            
                $firstData[] = $datas;
            }  
            //从redis中获取数据 
            $this->redis->select(6);         
            if($redis_datas = $this->redis->get('appboxbDL_'.$this->language.'_'.$this->page.'_'.$id)) {
                $data = $this->getSpreadDetailRedis($redis_datas,'appbox_banner_url');
                if($firstData && !empty($firstData)) $arr['data'] = array_merge($firstData,$data);
                else $arr['data'] = $data;
                $arr['dataRedis'] = 'from redis';
                return json_encode($arr);
            }
            //当前模板里所有的信息，从mysql中获取数据
            $arr['data'] = $firstData;
            $sql = "select * from appbox_banner_list where bannerId=$id order by sort desc,id asc limit $page,".$this->pageNum;
            $bannerList = $this->_db->getAll($sql);
            foreach($bannerList as $val){
                $arr['data'][] = $this->parseType($val,'appbox_banner_url');
            }
            if($arr['data'] && !empty($arr['data'])) {
                $this->setSpreadDetailRedis($arr['data'],$id,'banner');                
            }
            return json_encode($arr);
        } else {
            return json_encode(array('status'=>$this->is_true));
        }
    }

    public function parseType($val,$table='appbox_spread_url'){
        $arr = array();
        switch($val['type'])
        {
            case 'url':
                $sql = "select releaseTime,id from $table where id={$val['typeId']}";
                $url = $this->_db->getRow($sql);
                $tempArr = $this->getUrlDetail($url['releaseTime'],$url['id'],$table);
                if($tempArr)                            
                   $arr = $tempArr;
                break;
            case 'gift':
                 $sql = "select gift.start_time,gift.id from appbox_gift as gift left join appbox_app as app on app.package_id=gift.package_id where gift.id={$val['typeId']}";
                 $gift = $this->_db->getRow($sql);
                 $tempArr = $this->getGiftDetail($gift['start_time'],$val['typeId']);
                 if($tempArr)
                    $arr = $tempArr;
                break;
            case 'app':
                 $sql = "select releaseTime,id,package_name from appbox_app where package_id={$val['typeId']} and language='".$this->language."'";
                 $app = $this->_db->getRow($sql);
                 $tempArr = $this->getAppDetail($app['releaseTime'],$val['typeId'],1);
                 if($tempArr)
                    $arr = $tempArr;
                break;
            case 'news':
                 $sql = "select release_time,id from appbox_news where id={$val['typeId']}";
                 $news = $this->_db->getRow($sql);
                 $tempArr = $this->getNewsDetail($news['release_time'],$val['typeId']);
                 if($tempArr)
                    $arr = $tempArr;
                break;
        } 
        return $arr;       
    }
    /**
    *   获取单个banner里需要的字段数据
    */
    public function getBanner($id,$field,$language)
    {
        $sql = "select $field
                    from appbox_banner as banner
                    where banner.id=$id";
        $data = $this->_db->getRow($sql);    
        if(isset($data['name']) && $data['name'])
        {
            $arr = json_decode(htmlspecialchars_decode($data['name']),true);
            $data['name'] = isset($arr[$language]) && $arr[$language] ? $arr[$language] : $arr['en'];
            unset($arr);
        }
        if(isset($data['description'])&& $data['description'])
        {
            $arr = json_decode(htmlspecialchars_decode($data['description']),true);
            $data['description'] = isset($arr[$language]) && $arr[$language] ? $arr[$language] : $arr['en'];
            unset($arr);
        }
        return $data;
    }

}