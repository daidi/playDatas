<?php
/**
*   杂项
*/
class OtherModel extends Db_Base
{

    public function __construct($language='')
    {
        parent::__construct();
    }

    /**
    * 赞或者啋
    */
    public function handleLike($sql,$packageName)
    {
        if($this->_db->query($sql))
        {
            $this->redis->select(7);
            if($jsonData = $this->redis->get('appboxD_' . $packageName . '_' . $this->language)) {//重置赞啋数
                $dataArr = json_decode($jsonData,true);
                $sql = "select likeCount,hateCount from appbox_app where package_name='$packageName' and
                language='{$this->language}'";
                $count = $this->_db->getRow($sql);
                $dataArr['data']['treadCount'] = $count['hateCount'];
                $dataArr['data']['praiseCount'] = $count['likeCount'];
                $this->redis->getSet('appboxD_' . $packageName . '_' . $this->language,json_encode($dataArr));
            }
            return array('status'=>1,'info'=>'设置成功！');
        }
        else
        {
            return array('status'=>0,'info'=>'设置失败！');
        }
    }

    /**
    *   是否更新 9版本之前的更新通知
    */
    public function getUpdate($giftUpdateTime,$spreadUpdateTime,$dayilyUpdateTime,$ver_code)
    {
        if(!$ver_code) {
            echo '参数错误！';exit;
        }
        $arr = array('status'=>1);//返回的json

        //精选有无更新
        $sql = "select count(*) as num from appbox_dayily where addTime>$dayilyUpdateTime and status=1 and releaseTime<".time();
        $dayily = $this->_db->getAll($sql);
        $arr['update']['dayily'] = $dayily[0]['num'] ? $dayily[0]['num'] : 0;

        //专题有无更新
        $sql = "select count(*) as num from appbox_spread where addTime>$spreadUpdateTime and status=1 and releaseTime<".time();
        $spread = $this->_db->getAll($sql);
        $arr['update']['spread'] = $spread[0]['num'] ? $spread[0]['num']: 0;

        //礼包有无更新
        $sql = "select count(*) as num from appbox_gift where add_time>$giftUpdateTime and status=1 and start_time<".time();
        $gift = $this->_db->getAll($sql);
        $arr['update']['gift'] = $gift[0]['num'] ? $gift[0]['num'] : 0;

        //通知内容
        $sql = "select app.package_name as id,typeName as title from appbox_announce as an left join appbox_app as app on app.package_id=an.typeId where an.addTime>$dayilyUpdateTime and an.type='app' and an.status=1 and app.language='".$this->language."' order by an.status desc,an.sort desc,an.id asc";
        $app = $this->_db->getRow($sql);//应用游戏

        if($app)
        {
            $app['content'] = '应用'.$app['title'].'有了更新！';        
            $arr['notice']['app'][] = $app;
        }

        $sql = "select typeId as id,typeName as title from appbox_announce where addTime>$spreadUpdateTime and type='spread' and status=1 order by status desc,sort desc,id asc";
        $spread = $this->_db->getRow($sql);//专题
        if($spread)
        {
            $spread['content'] = '专题'.$spread['title'].'有了更新！';
            $arr['notice']['subject'][] = $spread;
        }

        $sql = "select typeId as id,typeName as title from appbox_announce where addTime>$giftUpdateTime and type='gift' and status=1 order by status desc,sort desc,id asc";
        $gift = $this->_db->getAll($sql);//礼包
        if($gift)
        {
            $count = count($gift);
            if($count > 2)
            {
                $data['id'] = 0;
                $data['title'] = '礼包有了更新！';
                $data['content'] = '礼包有了'.$count.'条更新！';
                $arr['notice']['gift'][] = $data;
            }  
            else
            {
                foreach($gift as $key=>$val)
                {
                    $sql = "select package_name from appbox_gift where id={$val['id']}";
                    $packageName = $this->_db->getRow($sql);
                    $gifts['pkg_name'] = $packageName['package_name'];
                    $gifts['id'] = $val['id'];
                    $gifts['title'] = $val['title'];
                    $gifts['content'] = '礼包'.$val['title'].'有了更新！';
                    $arr['notice']['gift'][] = $gifts;
                }
            }
        }

        //检查自身版本是后否有更新
        $arr['appUpdate'] = $this->getVersion();
     
        return json_encode($arr);
    }    

    /**
    *   是否更新 9版本之后的更新通知接口
    */
    public function getAnnounce($timeArr,$ver_code){
        $arr = array('status'=>1,'updateTime'=>'60 6:00;12:00;18:00');//返回的json
        $currentTime = time();
        $arr['currentTime'] = $currentTime;
        $cSql = "select typeId from appbox_announce where status=1 and releaseTime<$currentTime and releaseTime>";
        foreach($timeArr as $key=>$val){
            $sql = $cSql."$val and ";
            switch($key){
                case 'giftUpdateTime':
                    $sql .= "type='gift'";
                    $data = $this->_db->getAll($sql);
                    if($data){
                        foreach($data as $v){
                            $sql = "select g.logo as imageUrl,g.package_name as pkg_name,g.id,descs.name as title,descs.description as content from appbox_gift as g left join appbox_gift_desc as descs on g.id=descs.gid where g.id={$v['typeId']} and g.status=1 and descs.language='".$this->language."'";
                            $gift = $this->_db->getRow($sql);
                            if($gift) $arr['notice']['gift'][] = $gift;
                        }
                    }
                    break;
                case 'spreadUpdateTime':
                    $sql .= "type='spread'";
                    $data = $this->_db->getAll($sql);
                    if($data){
                        foreach($data as $v){
                            $sql = "select name,img as imageUrl,description,id,spread_type as type from appbox_spread where id={$v['typeId']} and status=1";
                            $spread = $this->_db->getRow($sql);
                            if($spread) {
                                $title = json_decode(htmlspecialchars_decode($spread['name']),true);
                                $spread['title'] = $title[$this->language];
                                unset($spread['name']);
                                $content = json_decode(htmlspecialchars_decode($spread['description']),true);
                                $spread['content'] = $content[$this->language];
                                unset($spread['description']);
                                $arr['notice']['subject'][] = $spread;
                            }
                        }
                    }                    
                    break;
                case 'appUpdateTime':
                    $sql .= "type='app'";
                    $app = $this->_parseApp($sql);
                    if($app) $arr['notice']['app'] =  $app;
                    break;
                case 'gameUpdateTime':
                    $sql .= "type='game'";
                    $game = $this->_parseApp($sql);
                    if($game) $arr['notice']['game'] =  $game;
                    break;
                case 'articleUpdateTime':
                    $sql .= "type='article'";
                    $data = $this->_db->getAll($sql);
                    if($data){
                        foreach($data as $v){
                            $sql = "select n.img as imageUrl,n.id,descs.title,descs.description as content,descs.jump_url as newsUrl from appbox_news as n left join appbox_news_data as descs on n.id=descs.news_id where n.id={$v['typeId']} and descs.language='".$this->language."'";
                            $news = $this->_db->getRow($sql);
                            if($news) $arr['notice']['news'][] = $news;
                        }
                    }                    
                    break;
            }
            unset($sql);
            unset($data);
        }
        //检查自身版本是后否有更新
        $arr['appUpdate'] = $this->getVersion($ver_code);
        $arr['update']['spread'] = isset($arr['notice']['subject']) ? count($arr['notice']['subject']) : 0;
        $arr['update']['gift'] = isset($arr['notice']['gift']) ? count($arr['notice']['gift']) : 0;
        $arr['update']['news'] = isset($arr['notice']['news']) ? count($arr['notice']['news']) : 0;
        $arr['update']['app'] = isset($arr['notice']['app']) ? count($arr['notice']['app']) : 0;
        $arr['update']['game'] = isset($arr['notice']['game']) ? count($arr['notice']['game']) : 0;
        return json_encode($arr);
    }
/**
*   版本自身的更新情况
*/
    public function getVersion($ver_code){
        $arr = array();
        $sql = "select max(versionCode) as id from appbox_update_version where status=1";
        $maxVersionCode = $this->_db->getRow($sql);
        if($maxVersionCode['id'] > $ver_code) {
            $sql = "select * from appbox_update_version where versionCode=".$maxVersionCode['id'];
            $data = $this->_db->getRow($sql);
            $arr['hasNew'] = true;
            $arr['versionCode'] = $data['versionCode'];
            $arr['dialogContent'] = $data['dialogContent'];
            $arr['downloadUrl'] = $data['downloadUrl'];
            $arr['title'] = $data['title'];
            $arr['content'] = $data['content'];
            $arr['forcing'] = $data['force'];
        }
        return $arr;          
    }

    private function _parseApp($sql){
        $arr = array();
        $data = $this->_db->getAll($sql);
        if($data){
            foreach($data as $v){
                $sql = "select id,package_name as pkg_name,app_name as title,icon as imageUrl from appbox_app where id={$v['typeId']} and language='".$this->language."' and status=1";
                $app = $this->_db->getRow($sql);
                if($app) $arr[] = $app;
            }
        } 
        return $arr;
    }
/**
*   添加取消收藏
*/
    public function getFavorite($uuid,$packageName,$cancel)
    {
        if($cancel == 1)//取消收藏
        {
            $sql = "delete from appbox_user_favorite where uuid=$uuid and packageName='$packageName'";
            if($this->_db->query($sql))
                return json_encode(array('status'=>1,'info'=>'取消收藏成功!~'));
            else
                return json_encode(array('status'=>$this->is_true,'info'=>'取消收藏失败!~'));
        }
        $sql = "insert into appbox_user_favorite values('$uuid','$packageName')";
        if($this->_db->query($sql))
            return json_encode(array('status'=>1,'info'=>'添加收藏成功!~'));
        else
            return json_encode(array('status'=>0,'info'=>'添加收藏失败!~'));
    }
/**
*   获取关键词
*/
    public function getKeywords()
    {
        $arr = array('status'=>1);
        //自定义关键词
        $sql = "select keywords from appbox_keywords where status=1 order by sort desc";
        $data = $this->_db->getAll($sql);
        if(!$data) return json_encode(array('status'=>$this->is_true));
        $temp = array();
        foreach($data as $key=>$val)
        {
            $temp[] = $val['keywords'];
        }
        $arr['data'] = $temp;
        //从googleplay抓取过来的关键词
        $this->redis->select(0);
        if(!$this->redis->exists('appbox_keywords')) {
            file_get_contents('http://play.mobappbox.com/index.php?m=Admin&c=ApplicationRepertory&a=annie&type=keywords&flag=1');
        }
        $keywords = $this->redis->get('appbox_keywords');
        $keywords = json_decode($keywords,true);
        $currentKeywords = isset($keywords[strtoupper($this->language)]) ? $keywords[strtoupper($this->language)] : $keywords['en'];
        $value = array_rand($currentKeywords,5);
        foreach($value as $val) {
            $arr['keywords'][] = $currentKeywords[$val];
        }
        //print_R($arr);exit;
        return json_encode($arr);
    }

    //意见反馈接口
    public function setFeedback()
    {
        $uuid = isset($_REQUEST['uuid']) ? $_REQUEST['uuid'] : '';
        $feedback = isset($_REQUEST['feedback']) ? $_REQUEST['feedback'] : '';
        $language = isset($_REQUEST['language']) ? $_REQUEST['language'] : '';
        $country = isset($_REQUEST['country']) ? $_REQUEST['country'] : '';
        $android_version = isset($_REQUEST['android_version']) ? $_REQUEST['android_version'] : '';
        $manufacture = isset($_REQUEST['manufacture']) ? $_REQUEST['manufacture'] : '';
        $ver_code = isset($_REQUEST['ver_code']) ? $_REQUEST['ver_code'] : '';
        $model = isset($_REQUEST['model']) ? $_REQUEST['model'] : '';
        $email = isset($_REQUEST['email']) ? $_REQUEST['email'] : '';

        if(!$uuid || !$feedback || !$language || !$country || !$android_version || !$manufacture || !$ver_code || !$model)  {
            echo '参数错误！';
        }     
        $sql = "insert into appbox_feedback values('','$uuid','$email','$language','$country','$android_version','$ver_code','$manufacture','$model','".time()."','$feedback')";
        $is_true = $this->_db->query($sql);
        if($is_true) {
            return json_encode(array('info'=>'留言成功！','status'=>1));
        } else {
            return json_encode(array('info'=>'留言失败，请稍后重试！','status'=>$this->is_true));
        }
    }

    public function getRomUpdate($channelId,$language,$ver_code){
        //检查自身版本是后否有更新
        $sql = "select max(version) as id from appbox_rom where status=1 and channel_id='$channelId'";
        $maxVersionCode = $this->_db->getRow($sql);
        $arr['status'] = 1;
        //echo $sql;
        //print_r($maxVersionCode);
        if($maxVersionCode['id'] > $ver_code) {
            $sql = "select * from appbox_rom where version=".$maxVersionCode['id']." and channel_id='$channelId'";
            $data = $this->_db->getRow($sql);
            $arr['data']['hasNew'] = true;
            $arr['data']['downloadUrl'] = $data['download_url'];
            $arr['data']['versionCode'] = $data['version'];
            $arr['data']['isMobile'] = $data['is_mobile'];
            $arr['data']['isWifi'] = $data['is_wifi'];
            $arr['data']['forcing'] = $data['is_forcing'];
            $content = json_decode(htmlspecialchars_decode($data['dialog_content']),true);
            $arr['data']['content'] = isset($content[$language]) ? $content[$language] : $content['en'];
            $arr['data']['auto_install'] = $data['is_auto_install'];
            $arr['data']['auto_download'] = $data['is_auto_download'];
        } else {
            $arr['data']['hasNew'] = false;
        }
        return json_encode($arr);
    }
}
