<?php
class SpreaddayilyModel extends RedisModel
{
    public static $asb;
    public $ver_code;
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
        $this->ver_code = isset($_GET['ver_code']) ? $_GET['ver_code'] : 8;//获取版本号
        //获取模板内容，如果模板未更新，则什么都不返回
        $arr = $this->getTemplate($templateUpdateTime);
        $arr['status'] = 1;//状态
        $this->redis->select(5);
        //获取推广列表
        $keys = 'appbox_dayily_info_'.$this->language.'_'.$this->ver_code;
        if($redis_data = $this->redis->get($keys)) {
            $arr['data'] = json_decode($redis_data);
            $arr['dataRedis'] = 'from redis';
        } else {
            $arr['banner'] = $this->getNav();//获取导航顺序列表
           $spread = $this->getList($page,'is_jx');
            if(!$spread) return json_encode(array('status'=>$this->is_true));
            foreach($spread as $key=>$val) {//将精选推入到数组
                $tempData = $this->getDayilySpreadDetail($val);
                if($tempData) $arr['data'][] = $tempData;
            }
            if($arr['data']){//推入缓存中
                $this->redis->select(5);
                $this->redis->set($keys,json_encode($arr['data']),'1800');
            }
        }
        return json_encode($arr);
    }

    public function getList($p,$position="is_jx")
    {
        //获取应用app
        $sql = "select spread.id,spread.releaseTime,spread.expand,spread.spread_type,spread.name
                    from appbox_spread as spread
                    where spread.status=1 and releaseTime<=".time()." and $position=1 
                    order by spread.dayily_sort desc,spread.id desc
                    limit $p,10";
        $info = $this->_db->getAll($sql);
        return $info;
    }

    public function getDayilySpreadDetail($val){
        $datas['subject_id'] = $val['id'];
        $name = json_decode(htmlspecialchars_decode($val['name']),true);
        if($val['expand'] != false && $val['spread_type'] != 2){//代表是展开的形式
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
            $datas['data'] = array_merge($datas['data'],$tempData);
        } elseif($val['spread_type'] == 2 && $this->ver_code > 8) {//图片类型自动采集填充
            $tempData = $this->getCollectImages();
            if($tempData){
                $datas['is_images'] = true;
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
        $sql = "select title,process_type as processType,icon_url as iconUrl,bg_color as bgColor,img as imageUrl spread_id as spreadId from appbox_nav where status=1 order by sort desc";
        $data = $this->_db->getAll($sql);
        foreach($data as $key=>$val){
            $title = json_decode(htmlspecialchars_decode($val['title']),true);
            $data[$key]['title'] = $title[$this->language];
        }
        return $data;
    }
}
