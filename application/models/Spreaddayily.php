<?php
class SpreadDayilyModel extends RedisModel
{
    public static $asb;
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
        $this->redis->select(5);
        //获取推广列表
        $key = 'appbox_dayily_info_'.$this->language;
        if($redis_data = $this->redis->get($key)) {
            $arr['data'] = json_decode($redis_data);
            $arr['dataRedis'] = 'from redis';
        } else {
           $spread = $this->getList($page,'is_jx');
            if(!$spread) return json_encode(array('status'=>$this->is_true));
            foreach($spread as $key=>$val) {//将精选推入到数组
                $tempData = $this->getDayilySpreadDetail($val);
                if($tempData) $arr['data'][] = $tempData;
            }
            if($arr['data']){//推入缓存中
                $this->redis->select(5);
                $this->redis->set($key,json_encode($arr['data']),'1800');
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
        if($val['expand'] != false){//代表是展开的形式
            $name = json_decode(htmlspecialchars_decode($val['name']),true);
            $datas['subject_title'] = $name[$this->language];
            $datas['status'] = 'expand';
            $datas['data'] = $this->getDetailJson($val['id'],$val['expand']);
        } else {//非展开的形式就是发放一张图片,即原来的专题
            $datas['status'] = 'collapse';
            $datas['data'][] = $this->getSpreadDetail($val['releaseTime'],$val['id']);
        }

        //文章类型自动插入采集过来的信息
        if($val['spread_type'] == 1 && $val['expand'] != false){
            $datas['is_news'] = true;
            $tempData = $this->getCollectNews($val['expand']);
            $datas['data'] = array_merge($datas['data'],$tempData);
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
}
