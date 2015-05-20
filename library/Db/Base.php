<?php
class Db_Base
{
    protected $url = 'https://play.google.com/store/apps/details?id=';//跳转URL路径
    protected $site_root = '';//跳转URL路径
    protected $redis;//缓存标示
    protected $expire = '86400';//缓存的更新过期时间
    protected $page = 0;//传递过来的条件    
    protected $language;
    protected $is_true = 0;
    protected $pageNum = 10;
    protected $ver_code = 8;

	public function __construct() 
	{
        error_reporting(E_ERROR);
		$this->_db = Yaf_Registry::get("Db");
        $this->site_root="http://".$_SERVER['SERVER_NAME'].($_SERVER['SERVER_PORT'] == 80 ? '' : ':'.$_SERVER['SERVER_PORT']);
        $this->is_true = 0;
        $this->ver_code = isset($_GET['ver_code']) ? $_GET['ver_code'] : 8;
        //如果所在国家没有开通此语言，则默认显示英语
        $language = isset($_GET['language']) && $_GET['language'] ? $_GET['language'] : 'en';
        $sql = "select status from appbox_app_language where flag='".$language."' and status=1";
        $status = $this->_db->getRow($sql);
        if(!$status['status'])
            $this->language='en';
        else
            $this->language = $language;
        //使用redis缓存
        $this->redis = new Redis();
        $this->redis->connect('127.0.0.1',6379);
    }

    /**
    * 	1、在更新的时候获取所有模板
    *   2、将获取到的模板解析返回
    */
    public function getTemplate($templateUpdateTime = '')
    {

        $sql = "select templateUpdateTime from appbox_template";
        $time = $this->_db->getRow($sql);//如果时间和原来的时间不一致，则把所有模板再次进行发放
        if($time['templateUpdateTime'] != $templateUpdateTime && $time)
        { 
            $return = array();
            //应用查出最新的模板 
            $sql = "select id,templateName,releaseTime,templateUpdateTime,content from appbox_template order by releaseTime desc";
            $data = $this->_db->getAll($sql);
            $return['templateUpdateTime'] = $data[0]['templateUpdateTime'];
            foreach($data as $key=>$val)
            {
                $data[$key]['content'] = htmlspecialchars_decode($data[$key]['content']);//decode
                $data[$key]['content'] = str_replace(array("\r\n", "\r", "\n")," ",$data[$key]['content']);//替换换行
                $data[$key]['content'] = preg_replace("/\s+/"," ",$data[$key]['content']);//替换空格，也可以不开启
                $return['template'][$val['templateName']] = $data[$key]['content'];
            }
            return $return;  
        }
        else
        {
            return array();
        }
    }

    /**
    *   递归获取应用所有的分类
    */
    public function getAppsCategory($cid,$arr = array())
    {
        $this->redis->select(1);
        $cSql = "select alias_name as typeName,category_id as categoryId,bg as iconUrl from appbox_google_category where parent_id=$cid and status=1 and language=";
        $sql = $cSql."'".$this->language."' order by sort asc";
        $data = $this->_db->getAll($sql);
        if(!$data) {
            $sql = $cSql."'en' order by sort asc";
            $data = $this->_db->getAll($sql);
        }
        if(!$data){
            return $arr;
        }
        foreach($data as $key=>$val){
            $val['typeName'] = htmlspecialchars_decode($val['typeName']);
            $arr[] = $val;
            $arr = $this->getAppsCategory($val['categoryId'],$arr);
        }
        $cSql = "select alias_name as typeName,category_id as categoryId,bg as iconUrl from appbox_google_category where category_id=$cid and status=1 and language=";
        $sql = $cSql."'".$this->language."'";
        $tempData = $this->_db->getRow($sql);//获取本身
        if(!$tempData) {
            $sql = $cSql."'en'";
            $tempData = $this->_db->getRow($sql);//获取本身
            $tempData['typeName'] = htmlspecialchars_decode($tempData['typeName']);
        }
        $self[] = $tempData;
        $data = array_merge($self,$arr);
        if($data && !empty($data)){
            $this->redis->set('appbox_categorys_'.$this->language.'_'.$cid,json_encode($data));
            $this->redis->expire('appbox_categorys_'.$this->language.'_'.$cid,$this->expire);
        }        
        return $data;
    }	

    /**
    *   1、获取对应模板里的所有数据
    *   2、获取对应模板里所有的查询字段
    *	@param $id int 模板id
    * 	@param $alias string 数据库对应的别名
    *   @return array
    */
    public function getField($id,$alias)
    {
        //查询模板里的所有属性
        $sql = "select name,attrValue,jump from appbox_template_attr where templateId=$id";
        $data = $this->_db->getAll($sql);
        $arr = array('data'=>$data);
        $field = '';
        $tempArr = array();//用户可能设置多个重复的字段，排除重复的字段
        foreach($data as $val)
        {
            if($val['attrValue'] && !in_array($val['attrValue'],$tempArr))
            {
                $tempArr[] = $val['attrValue'];
                $field .= $alias.'.'.$val['attrValue'].',';
            }
        }
        unset($tempArr);
        $field = trim($field,',');
        $arr['field'] = $field;
        return $arr;    
    }
/**
*   json格式中的view
*   @param array $data  从模板获取的对应数据
*   @param array $item  获取的单条数据，与上面的数据项对应
*   @return array
*/
    public function getView($data,$item)
    {
        $view = array();
        foreach($data as $k=>$v)
        {
            $tempArr = array();
            if($v['attrValue'] || $v['jump'])
            {
                $tempArr['text'] = isset($v['attrValue']) && $v['attrValue'] ? $item[$v['attrValue']] : '';
                $tempArr['processType'] = isset($v['jump']) ? $v['jump'] : '';
            }
            $view[$v['name']] = $tempArr;
        }
        return $view;        
    }

    /**
     * 获取每天数据与时间相对应的模板
     * @param $releaseTime
     * @param int $type 模板类型。1：应用/游戏模板，2下载排行模板，3评分排行模板，4专题模板，5礼包模板
     * @internal param int $releasetTime 与模板对应的时间
     * @return array
     */
    public function getSelfTemplate($releaseTime,$type)
    {
        $sql = "select id,templateName,downIcon as downIconUrl,openIcon as openIconUrl from appbox_template where $releaseTime < releaseTime and type=$type order by releaseTime asc";
        return $this->_db->getRow($sql);        
    }

    public function getMytemplate($id){
        $sql = "select templateName,type from appbox_template where id=$id";
        return $this->_db->getRow($sql);
    }
    /**
     *   返回推广位置1：精选，2：游戏，3：应用，4礼包
     *   @param int $pos 推广位置
     *   @return array
     */
    public function getBanners($pos)
    {
        $this->redis->select(6);
        $sql = "select img as iconUrl,id,imgWidth,imgHeight from appbox_banner where position=$pos and status=1 and releaseTime<=".time()." order by sort desc,id desc limit 0,4";
        $data = $this->_db->getAll($sql);
        if(!$data) return '';
        foreach($data as $key=>$val){
            $sql = "select type,typeId from appbox_banner_list where bannerId={$val['id']}";
            $banner = $this->_db->getAll($sql);
            $count = count($banner);
            if($count > 1){//如果推广位是多个的话，就让他跳到推广列表
                $data[$key]['processType'] = 106; //100下载应用 101 启动应用 102 礼包详情 103 应用详情 104 专题详情 105 打开网址 106 banner跳转到列表详情
                $data[$key]['url'] = $this->site_root."/Apps_Spread/bannerDetail?id={$val['id']}";
            } else {//如果推广位是单个的话，就让他调到对应的当个应用上去
                foreach($banner as $k=>$v){
                    switch($v['type']) {
                        case 'url':
                            $data[$key]['processType'] = 104;
                            $data[$key]['urlId'] = $v['typeId'];
                            break;
                        case 'gift':
                            $data[$key]['processType'] = 102;
                            $data[$key]['giftId'] = $v['typeId'];
                            break;
                        case 'app':
                            $data[$key]['processType'] = 103;
                            $sql = "select package_name as packageName from appbox_app where package_id={$v['typeId']}";
                            $app = $this->_db->getRow($sql);
                            $data[$key]['packageName'] = $app['packageName'];
                            break;
                    }                    
                }
            }
        }
        if($data && !empty($data)){//缓存到redis
            $this->redis->set('appboxbL_'.$this->language.'_'.$pos,json_encode($data));
            $this->redis->expire('appboxbL_'.$this->language.'_'.$pos,$this->expire);
        }
        return $data;
    }

    /**
    *   判断是否还存在分页
    *   @param $sql string sql查询语句
    *   @param $page int 客户端发来的当前分页
    *   @param $num int 当前每页有多少条数据
    *   @return array
    */
    public function getPage($sql,$page,$num)
    {
        $count = $this->_db->getRow($sql);
        //echo ($page+1)*$num;exit;
        //echo $count['num'];
        if(($page+1)*$num >= $count['num']){//如果当前页数乘以每页条数加1大于总条数
            $hasNextPage = false;//没有分页了        
        } else {
            $hasNextPage = true;//还有分页
        }
        return $hasNextPage;            
    }


    /**
    *   获取单个app里需要的字段数据
    */
    public function getApp($packageId,$field,$language)
    {
        $cSql = "select $field,app.package_name
                    from appbox_app as app
                    where app.package_id=$packageId and app.language=";
        $sql = $cSql."'{$language}'";
        $data = $this->_db->getRow($sql);
        if(!$data){
            $sql = $cSql."'en'";
            $data = $this->_db->getRow($sql);
        }
        return $data;
    }

    //查询一个包名是否存在礼包
    public function haveGift($packageName)
    {
        $sql = "select id from appbox_gift where package_name='$packageName' and status=1";
        $data = $this->_db->getRow($sql);
        if($data['id']) {
            return $data['id'];
        }
        return false;
    }
    /**
    *   得到单条app json格式数组
    *   @param $template 模板内容
    *   @param $appId int 应用id
    *   @param $language string 语言
    *   @return array
    */
    public function getAppDetail($releaseTime, $packageId, $templatePos = ''){
        $templatePos = isset($templatePos) && $templatePos ? $templatePos : 1;
        $template = $this->getSelfTemplate($releaseTime, $templatePos);//获取与其时间对应的模板
        if ($template) {//如果模板存在
            $field = $this->getField($template['id'],'app');//取出与其对应模板的内容
            $app = $this->getApp($packageId,$field['field'],$this->language);//获取这条app的内容
            if($app) {
                $haveGift = $this->haveGift($app['package_name']);
                $app['app_name'] = htmlspecialchars_decode($app['app_name']);
                $app['desc'] = htmlspecialchars_decode($app['desc']);
                
                $app['icon'] = $this->getGooglePic($app['icon']);
                //json格式中的view
                $view = $this->getView($field['data'],$app);
                //json格式中的extraData
                $extraData = array('appId'=>$packageId,'packageName'=>$app['package_name'],'market_url'=>$this->url.$app['package_name'],'downIconUrl'=>$template['downIconUrl'],'openIconUrl'=>$template['openIconUrl'],'downloadCount'=>$app['install_count'],'templateId'=>$template['id'],'haveGift'=>$haveGift,'iconUrl'=>$app['icon'],
                    'appName'=>$app['app_name'],'rateScore'=>$app['score']);
                //合成一条app数据
                $datas = array('xmlType'=>$template['templateName'],'view'=>$view,'extraData'=>$extraData);
                return $datas;
            }
            return false;
        }
        return false;         
    }

    /**
    *   得到单条新闻 json格式数组
    *   @param $template 对应模板时间
    *   @param $newsId int 新闻id
    *   @return array
    */
    public function getNewsDetail($releaseTime, $newsId,$templatePos = ''){
        $templatePos = isset($templatePos) && $templatePos ? $templatePos : 14;
        $template = $this->getSelfTemplate($releaseTime, $templatePos);//获取与其时间对应的模板
        if ($template) {//如果模板存在
            $field = $this->getNewsField($template['id'],'news');//取出与其对应模板的内容
            $news = $this->getNews($newsId,$field['field']);//获取这条app的内容
            if($news) {
                $view = $this->getView($field['data'],$news);
                //json格式中的extraData
                $images[] = array('url'=>$news['img'],'width'=>$news['imgWidth'],'height'=>$news['imgHeight']);
                $extraData = array('newsId'=>$newsId,'url'=>$news['jump_url'],'tag'=>explode(',',$news['keywords']),'images'=>$images,'title'=>$news['title'],'source'=>'','processType'=>110);
                //合成一条app数据
                $datas = array('xmlType'=>$template['templateName'],'view'=>$view,'extraData'=>$extraData);
                return $datas;
            }
            return false;
        }
        return false;   
    }

    /**
    *   获取单个app里需要的字段数据
    */
    public function getNews($id,$field)
    {
        $sql = "select $field,datas.jump_url,news.imgWidth,news.imgHeight,news.img,datas.keywords
                    from appbox_news as news left join appbox_news_data as datas on news.id=datas.news_id
                    where news.id=$id and datas.language='".$this->language."'";
        return $this->_db->getRow($sql);
    }


    public function getNewsField($templateId,$alias){
        //查询模板里的所有属性
        $sql = "select name,attrValue,jump from appbox_template_attr where templateId=$templateId";
        $data = $this->_db->getAll($sql);
        $arr = array('data'=>$data);
        $field = '';
        $datas = array('title','description','keywords','content');
        $news = array('img','imgWidth','imgHeight','release_time');
        $tempArr = array();//用户可能设置多个重复的字段，排除重复的字段
        foreach($data as $val)
        {
            if($val['attrValue'] && !in_array($val['attrValue'],$tempArr))
            {
                if(in_array($val['attrValue'],$datas))
                {
                    $tempArr[] = $val['attrValue'];
                    $field .= 'datas.'.$val['attrValue'].',';
                }
                elseif(in_array($val['attrValue'],$news))
                {
                    $tempArr[] = $val['attrValue'];
                    $field .= 'news.'.$val['attrValue'].',';
                }
            }
        }
        unset($tempArr);
        $field = trim($field,',');
        $arr['field'] = $field;
        return $arr;     
    }

    /**
    *   获取单个礼包里需要的字段数据
    */
    public function getGift($id,$field,$language)
    {
        $cSql = "select $field,app.id as appId,gift.logo,gift.get_count,gift.start_time,gift.package_name
                    from appbox_gift as gift
                    left join appbox_app as app on app.package_name=gift.package_name left join appbox_gift_desc as descs on gift.id=descs.gid
                    where gift.id=$id and ";
        $sql = $cSql."descs.language='{$language}' and app.language = '{$language}'";
        $datas = $this->_db->getRow($sql);
        if(!$datas['name'] && $language != 'en'){
            $sql = $cSql."descs.language='en' and app.language = 'en'";
            return $this->_db->getRow($sql);
        }
        return $datas;
    }

    /**
    *   1、获取对应礼包模板里的所有数据
    *   2、获取礼包对应模板里所有的查询字段
    *   @param $id int 模板id
    *   @return array
    */
    public function getGiftField($id)
    {
        //查询模板里的所有属性
        $sql = "select name,attrValue,jump from appbox_template_attr where templateId=$id";
        $data = $this->_db->getAll($sql);
        $arr = array('data'=>$data);
        $field = '';
        $descs = array('description','name','keywords');
        $app = array('app_name','install_count','score','icon');
        $tempArr = array();//用户可能设置多个重复的字段，排除重复的字段
        foreach($data as $val){
            if($val['attrValue'] && !in_array($val['attrValue'],$tempArr)){
                if(in_array($val['attrValue'],$descs)){
                    $tempArr[] = $val['attrValue'];
                    $field .= 'descs.'.$val['attrValue'].',';
                }elseif(in_array($val['attrValue'],$app)) {
                    $tempArr[] = $val['attrValue'];
                    $field .= 'app.'.$val['attrValue'].',';
                }
            }
        }
        unset($tempArr);
        $field = trim($field,',');
        $arr['field'] = $field;
        return $arr;    
    }

    /**
     *   得到单条app json格式数组
     *   @param $template
     *   @param $giftId int 礼包id
     *   @param $language string 语言
     *   @internal param array $templae 模板内容
     *   @return array
     */
    public function getGiftDetail($releaseTime, $giftId,$templatePos = '')
    {
        $templatePos = isset($templatePos) && $templatePos ? $templatePos : 5;
        $template = $this->getSelfTemplate($releaseTime, 5);//获取与其时间对应的模板
        if ($template) {//如果模板存在
            $field = $this->getGiftField($template['id']);//取出与其对应模板的内容
            $gift = $this->getGift($giftId,$field['field'],$this->language);//获取这条app的内容
            if($gift) {
                $gift['install_count'] = isset($gift['install_count']) ? $gift['install_count'] : '0';
                $gift['icon'] = $this->getGooglePic($gift['icon']);
                //json格式中的view
                $view = $this->getView($field['data'],$gift);
                //json格式中的extraData
                $is_hot = $this->giftHot($giftId);
                $is_new = false;
                if(time() - $gift['start_time'] <= 259200){
                    $is_new = true;
                }
                $extraData = array('giftId'=>$giftId,'appId'=>$gift['appId'],'downIconUrl'=>$template['downIconUrl'],'openIconUrl'=>$template['openIconUrl'],'download_count'=>$gift['install_count'],'acquireTime'=>$gift['get_count'],'isHot'=>$is_hot,'isNew'=>$is_new,'pkg_name'=>$gift['package_name']);
                //合成一条app数据
                $datas = array('xmlType'=>$template['templateName'],'view'=>$view,'extraData'=>$extraData);
                return $datas;            
            }
            return false;
        }
        return false;
    }

    public function giftHot($id){
        $sql = "select id from appbox_gift order by get_count limit 3";
        $ids = $this->_db->getAll($sql);
        $return = false;
        foreach($ids as $val){
            if($id == $val['id']){
                $return = true;
                break;
            }
        }
        return $return;
    }

    public function getUrl($id,$field,$type,$language)
    {
        $sql = "select $field,url.imgHeight,url.imgWidth
                    from $type as url
                    where url.id=$id";
        $data = $this->_db->getRow($sql);    
        if(isset($data['content']) && $data['content'])
        {
            $arr = json_decode($data['content'],true);
            $data['content'] = isset($arr[$language]) ? $arr[$language] : $arr['en'];
            unset($arr);
        }
        return $data;
    }

    public function getUrlDetail($releaseTime,$urlId,$type='appbox_spread_url',$templatePos = '')
    {
        $templatePos = isset($templatePos) && $templatePos ? $templatePos : 6;
        $template = $this->getSelfTemplate($releaseTime,$templatePos);//获取与其时间对应的模板  
        if($template) {
            $field = $this->getField($template['id'],'url');
            $url = $this->getUrl($urlId,$field['field'],$type,$this->language);
            if($url) {
                //json格式中的view
                $view = $this->getView($field['data'],$url);
                //json格式中的extraData
                $extraData = array('urlId'=>$urlId,'url'=>$url['url'],'downIconUrl'=>$template['downIconUrl'],'openIconUrl'=>$template['openIconUrl'],'imgWidth'=>$url['imgWidth'],'imgHeight'=>$url['imgHeight'],'imageUrl'=>$url['banner']);
                //合成一条app数据
                $datas = array('xmlType'=>$template['templateName'],'view'=>$view,'extraData'=>$extraData);
                return $datas; 
            }
            return false;
        }      
        return false;
    }

    /**
     *   获取单个专题里需要的字段数据
     * @param $id int 专题的id
     * @param $field string 表中的字段
     * @param $language
     * @internal param string $langauge 客户端传递过来的语言
     * @return array
     */
    public function getSpread($id,$field,$language)
    {
        $sql = "select $field,spread.imgHeight,spread.imgWidth
                    from appbox_spread as spread
                    where spread.id=$id";
        $data =  $this->_db->getRow($sql);
        if(isset($data['name']) && $data['name'])
        {
            $arr = json_decode(htmlspecialchars_decode($data['name']),true);
            $data['name'] = isset($arr[$language]) && $arr[$language] ? $arr[$language] : $arr['en'];
            unset($arr);
        }
        if(isset($data['description'])&& $data['description']){
            $arr = json_decode(htmlspecialchars_decode($data['description']),true);
            $data['description'] = isset($arr[$language]) && $arr[$language] ? $arr[$language] : $arr['en'];
            unset($arr);
        }
        return $data;
    }

    /**
    *   获取单个推广所有的相关信息
    *   @param array $template 模板内容
    *   @param int $spreadId  模板的id
    *   @param string $language 取出模板中对应的语言
    *   @param int $ver_code 版本号
    *   @return  array
    */
    public function getSpreadDetail($releaseTime, $spreadId,$templatePos = '')
    {
        $templatePos = isset($templatePos) && $templatePos ? $templatePos : 4;
        $template = $this->getSelfTemplate($releaseTime,$templatePos);//获取与其时间对应的模板
        if ($template) {//如果模板存在
            $field = $this->getField($template['id'],'spread');//取出与其对应模板的内容
            $spread = $this->getSpread($spreadId,$field['field'],$this->language);//获取这条专题的内容
            if($spread) {
                //json格式中的view
                $view = $this->getView($field['data'],$spread);
                //json格式中的extraData
                $extraData = array('spreadId'=>$spreadId,'imgWidth'=>$spread['imgWidth'],'imgHeight'=>$spread['imgHeight'],'added_time'=>$releaseTime);
                //合成一条app数据
                $datas = array('xmlType'=>$template['templateName'],'view'=>$view,'extraData'=>$extraData);
                return $datas;           
            }
            return false;
        }
        return false;
    }
/**
*   返回采集过来的新闻
*/
    public function getCollectNews($nums){
        $this->redis->select(0);
        $time = $this->redis->get('appbox_article_info_time'.'_'.$this->language);
        $this->_parseEtags(0,0,$time);//查询此页缓存是否有更新

        $key = 'appbox_article_info';
        $collectNewsJson = $this->redis->get($key.'_'.$this->language);
        if(!$collectNewsJson){
            $collectNewsJson = $this->redis->get($key.'_en');
        }

        $collectNewsArr = json_decode($collectNewsJson,true);
        $collectNewsArr = array_slice($collectNewsArr,0,$nums);
        $sql = "select id,templateName from appbox_template where type=15";
        $template = $this->_db->getRow($sql);
        $datas = array();
        foreach($collectNewsArr as $key=>$val){
            $tempArr['xmlType'] = $template['templateName'];
            $tempArr['view'] = array("news_container"=>'');
            $val['processType'] = 110;
            $tempArr['extraData'] = $val;
            $datas[] = $tempArr;
        }
        return $datas;
    }

/**
*   返回采集的图片资源
*/
    public function getCollectImages($keys){
        $this->redis->select(10);
        $collectImagesJson = $this->redis->lRange($keys,0,5);
        if($collectImagesJson){
            $collectImagesTempArr = array_rand($collectImagesJson,5);
            $max = 0;
            $index = null;
            //取出宽度最宽的元素
            foreach($collectImagesTempArr as $key=>$val){
                $img = $collectImagesJson[$val];
                $imgArr = json_decode($img,true);
                if($max < $imgArr['imgWidth']){
                    $max = $imgArr['imgWidth'];
                    $index = $key;
                }
                $collectImagesMaxArr[$key] = $imgArr;
            }
            //判断返回四个元素还是五个元素
            if($max > 600){
                $collectImagesArr[] = $collectImagesMaxArr[$index];
                unset($collectImagesMaxArr[$index]);
                foreach($collectImagesMaxArr as $key=>$val){
                    if($val && count($collectImagesArr) < 4){
                        $collectImagesArr[] = $val;
                    }
                }
            } else {
                $collectImagesArr = $collectImagesMaxArr;
            }
            $sql = "select id,templateName from appbox_template where type=16";
            $template = $this->_db->getRow($sql);
            foreach($collectImagesArr as $key=>$val){
                $extraData[] = $val['url'];
            }
            $tempArr['xmlType'] = $template['templateName'];
            $tempArr['view'] = array("item_images"=>array('text'=>'','processType'=>114));
            $tempArr['extraData']['processType'] = 114;
            $tempArr['extraData']['images'] = $extraData;
            $return[] = $tempArr;
            return $return;        
        } else {
            return false;
        }

    }
    /**
    *   设置etag缓存
    *
    */
    public function _parseEtags($data,$page,$keys = false){
        if($page === 0){
           date_default_timezone_set('PRC');
           header('Cache-Control: max-age=86400,must-revalidate'); 
           header('Last-Modified: ' .gmdate('D, d M Y H:i:s') . ' GMT' ); 
           header('Expires: ' .gmdate ('D, d M Y H:i:s', time() + '86400' ). ' GMT');
           if($keys){
                $key = $keys;
           } else {
                $data = json_decode($data);
                $key = array_pop($data);
           }
            if (isset($_SERVER['HTTP_IF_NONE_MATCH'])  && $_SERVER['HTTP_IF_NONE_MATCH'] == $key){ 
                  header("Etag:".$key,true,304); 
                  exit; 
            } else {  
               header("Etag:".$key); 
            }            
        }

    }


    public function getGooglePic($val){
        $baseUrl = 'http://play.mobappbox.com/proxy.php?url=';
        $return = null;
        if(is_array($val)){
            foreach($val as $k=>$v){
                if(is_array($v)){
                    $val[$k]['logo'] = $baseUrl.urlencode($val[$k]['logo']);
                } else {
                    $val[$k] = $baseUrl.urlencode($v);
                }
            }
            $return = $val;
        } else {
            $return = $baseUrl.urlencode($val);
        }
        return $return;
    }
}