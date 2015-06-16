<?php

/**
 *   精选页面数据处理
 */
class SpreaddayilyModel extends RedisModel {
    public $ver_code;
    public $pageNum = 100;
    public $expire = '1800';

    public function __construct($language = '') {
        parent::__construct();
    }

    /**
     *   获取应用json
     * @param int $page int 分页
     * @param int $templateUpdateTime 客户端带来的上次模板更新时间
     * @return string
     */
    public function getJson($page = '', $templateUpdateTime = 0) {
        $this->ver_code = isset($_GET['ver_code']) ? $_GET['ver_code'] : 8;//获取版本号
        //获取模板内容，如果模板未更新，则什么都不返回
        $arr = $this->getTemplate($templateUpdateTime);
        $arr['status'] = 1;//状态
        $arr['currentTime'] = time();
        $this->page = isset($page) ? (int)$page : 0;
        $sql = "select count(*) as num from appbox_spread where status=1 and dayily_sort!=0 and is_jx=1 and releaseTime<=" . time();
        $arr['hasNextPage'] = $this->getPage($sql, $this->page, $this->pageNum);
        $page = $this->page * $this->pageNum;//初始化页数

        $arr['defaultSearchWord'] = $this->getNewKeywords();
        $this->redis->select(5);
        //获取推广列表
        $keys = 'appbox_dayily_info_' . $this->language . '_' . $this->ver_code . '_' . $this->page;//推入缓存的key
        $bannerKeys = 'banner_' . $this->language . '_' . $this->ver_code;
        if (($redis_data = $this->redis->get($keys)) && ($banner = $this->redis->get($bannerKeys))) {
            $this->_parseEtags($redis_data, $this->page);//从查询第一页缓存是否有更新
            $myData = json_decode($redis_data, true);
            array_pop($myData);//删除数组最后一个元素，即设置的etag缓存时间
            $arr['data'] = $myData;
            if ($this->page == 0) $arr['banner'] = json_decode($banner, true);
            $arr['dataRedis'] = 'from redis';
        } else {
            if ($this->page == 0) $arr['banner'] = $this->getNav();//获取导航顺序列表
            $spread = $this->getList($page, 'is_jx');
            if (!$spread) return json_encode(array('status' => $this->is_true));
            if ($this->ver_code >= 15) {//版本为15以上，数据格式改变
                if (isset($arr['banner'])) {//如果是在第一页的情况下
                    $banner = $arr['banner'];
                    unset($arr['banner']);
                    $arr['banner']['subjects'] = $banner;
                    $arr['banner']['promotions'] = $this->getBanners(1);//推广位置1：精选2：游戏3：应用，4礼包
                }
                foreach ($spread as $key => $val) {//将精选推入到数组
                    $tempData = $this->getNewDayilySpreadDetail($val);
                    if ($tempData) $arr['data'][] = $tempData;
                }
            } else {
                foreach ($spread as $key => $val) {//将精选推入到数组
                    $tempData = $this->getDayilySpreadDetail($val);
                    if ($tempData) $arr['data'][] = $tempData;
                }
            }
            if ($arr['data']) {//推入缓存中
                $this->redis->select(5);
                $time = time();
                $arr['data'][] = $time;
                $this->_parseEtags(0, 0, $time);//从查询第一页缓存是否有更新
                $this->redis->set($keys, json_encode($arr['data']), $this->expire);
                $this->redis->set($bannerKeys, json_encode($arr['banner']), $this->expire);
                array_pop($arr['data']);
            }
        }
        return json_encode($arr);
    }

    /**
     *   获取最新的关键词
     * @return string
     */
    public function getNewKeywords() {
        $sql = "select keywords from appbox_keywords where status=1 order by sort desc";
        $data = $this->_db->getRow($sql);
        if ($data) {
            $return = $data['keywords'];
        } else {
            $this->redis->select(0);
            if ($this->redis->exists('appbox_keywords')) {
                $keywords = $this->redis->get('appbox_keywords');
                $keyArr = json_decode($keywords, true);
                if ($keyArr[$this->language]) {
                    $index = array_rand($keyArr[$this->language]);
                    $return = $keyArr[$this->language][$index];
                } else {
                    $index = array_rand($keyArr['en']);
                    $return = $keyArr['en'][$index];
                }
            }
        }
        $return = $return ? $return : 'games';
        return $return;
    }

    /**
     *   获取专题的列表
     * @param $p int 页数
     * @param $position String 精选专题或者是普通专题
     * @return array
     */
    public function getList($p, $position = "is_jx") {
        //获取应用app
        $sql = "select id,releaseTime,expand,spread_type,name,show_mode,show_mode_num,img,imgWidth,imgHeight from appbox_spread
                    where status=1 and releaseTime<=" . time() . " and $position=1 and dayily_sort!=0
                    order by dayily_sort desc,id desc limit $p," . $this->pageNum;
        $info = $this->_db->getAll($sql);
        return $info;
    }

    /**
     *   版本号在15以下的数据格式获取形式
     * @param $val array 单个专题数据携带的信息
     * @return array
     */
    public function getDayilySpreadDetail($val) {
        $datas['subject_id'] = $val['id'];
        $name = json_decode(htmlspecialchars_decode($val['name']), true);
        if ($val['expand'] != false && ($val['spread_type'] != 2 && $val['spread_type'] != 3)) {//代表是展开的形式
            $datas['subject_title'] = $name[$this->language];
            $datas['status'] = 'expand';
            $datas['data'] = $this->getDetailJson($val['id'], $val['expand']);
        } elseif ($val['spread_type'] != 2) {//非展开的形式就是发放一张图片,即原来的专题
            $datas['status'] = 'collapse';
            $datas['data'][] = $this->getSpreadDetail($val['releaseTime'], $val['id']);
        }

        if ($val['spread_type'] == 1 && $val['expand'] != false) {//文章类型自动插入采集过来的信息
            $datas['is_news'] = true;
            $tempData = $this->getCollectNews($val['expand']);
            if ($datas['data']) $datas['data'] = array_merge($datas['data'], $tempData);
            else $datas['data'] = $tempData;
            //图片 gif类型自动采集填充
        } elseif (($val['spread_type'] == 2 || $val['spread_type'] == 3) && $this->ver_code > 8) {
            $keys = $val['spread_type'] == 2 ? 'appbox_collect_colsplay' : 'appbox_collect_gifs';
            $type = $val['spread_type'] == 2 ? 'is_images' : 'is_gifs';
            $tempData = $this->getCollectImages($keys);
            if ($tempData) {
                $datas[$type] = true;
                $datas['subject_title'] = $name[$this->language];
                $datas['data'] = $tempData;
                $datas['status'] = 'expand';
            }
        }

        if (!$datas['data']) {
            return false;
        }
        return $datas;
    }

    /**
     *   获取一个专题里面的固定内容
     * @param $id int 专题id
     * @param $nums int 要获取专题中的几个内容
     * @return array
     */
    public function getDetailJson($id, $nums, $templatePos = '') {
        $sql = "select * from appbox_spread_list where spreadId=$id and type='app' order by sort desc,id desc limit " . $nums;
        $data = $this->_db->getAll($sql);
        $spread_mod = new SpreadModel($this->language);
        foreach ($data as $key => $val) {
            $tempData = $spread_mod->parseType($val, 'appbox_spread_url', $templatePos);
            if ($tempData)
                $arr[] = $tempData;
        }
        return $arr;
    }

    /**
     *   获取设置的导航
     */
    public function getNav() {
        $sql = "select title,process_type as processType,icon_url as iconUrl,bg_color as bgColor,img as imageUrl,spread_id as spreadId from appbox_nav where status=1 order by sort desc";
        $data = $this->_db->getAll($sql);
        foreach ($data as $key => $val) {
            $title = json_decode(htmlspecialchars_decode($val['title']), true);
            $data[$key]['title'] = isset($title[$this->language]) && !empty($title[$this->language]) ? $title[$this->language] : $title['en'];
        }
        return $data;
    }

    /**
     *   版本号为15以上版本的数据格式
     * @param $arr array 单个专题详情
     * @return array
     */
    public function getNewDayilySpreadDetail($arr) {
        $datas = array();//要返回的数据
        $datas['subject_id'] = $arr['id'];
        $datas['subjectImage'] = $arr['img'];
        $datas['imgWidth'] = $arr['imgWidth'];
        $datas['imgHeight'] = $arr['imgHeight'];//展示模式
        $datas['columnNum'] = $arr['show_mode_num'];//每行展示个数
        $name = json_decode(htmlspecialchars_decode($arr['name']), true);
        $datas['subject_title'] = $name[$this->language];
        $datas['status'] = 'expand';
        if ($arr['spread_type'] == 0) {//普通模式下调用不同的展示模式
            switch ($arr['show_mode']) {
                case 1:
                    $datas['data'] = $this->getDetailJson($arr['id'], $arr['expand'], 18);//一行三列的app item
                    break;
                case 2:
                    $datas['data'] = $this->getDetailJson($arr['id'], $arr['expand'], 19);//一行两列的app item
                    break;
                case 3:
                    $datas['layoutType'] = 'no_gap';
                    $datas['columnNum'] = 1;//当为展示形式三的情况下，每行展示个数固定为1
                    $data = $this->getDetailJson($arr['id'], $arr['expand']);//头部一张图片，跟着几个item形式
                    foreach ($data as $key => $val) {
                        if (isset($val['extraData']['giftId'])) {
                            $val['extraData']['processType'] = 102;
                        } elseif (isset($val['extraData']['appId'])) {
                            $val['extraData']['processType'] = 103;
                        }
                        $datas['data'][] = $val['extraData'];
                    }
                    break;
                case 4:
                    return $this->getDayilySpreadDetail($arr);
                    break;
                case 5:
                    $datas['status'] = 'hideHead';
                    $datas['data'] = $this->getDetailJson($arr['id'], $arr['expand'], 20);//带图片和描述的单个应用
                    $datas['data'][0]['extraData']['appImage'] = $arr['img'];
                    break;
                case 6:
                    $datas['status'] = 'hideHead';
                    $datas['layoutType'] = 'single_image';
                    $data = $this->getDetailJson($arr['id'], $arr['expand']);//一张图片的形式，可以是应用/游戏/礼包/新闻
                    $extraData = $data[0]['extraData'];
                    if (isset($extraData['giftId'])) {
                        $extraData['processType'] = 102;
                    } elseif (isset($extraData['appId'])) {
                        $extraData['processType'] = 103;
                    }
                    $extraData['subjectImage'] = $arr['img'];
                    $datas['data'][] = $extraData;
                    break;
                case 7:
                    $datas['layoutType'] = 'items_in_bg';
                    $data = $this->getDetailJson($arr['id'], $arr['expand']);//背景一张图片，跟着几个item形式
                    foreach ($data as $key => $val) {
                        if (isset($val['extraData']['giftId'])) {
                            $val['extraData']['processType'] = 102;
                        } elseif (isset($val['extraData']['appId'])) {
                            $val['extraData']['processType'] = 103;
                        }
                        $datas['data'][] = $val['extraData'];
                    }
                    break;
            }
        } else {
            return $this->getDayilySpreadDetail($arr);
        }
        return $datas;
    }
}   
