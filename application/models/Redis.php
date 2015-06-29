<?php

class RedisModel extends Db_Base {
    public function __construct() {
        parent::__construct();
    }

    //设置应用列表缓存
    public function setAppRedis($is_game, $type = '', $arr, $sort = '') {
        $tempArr = array();
        $this->redis->select(8);
        foreach ($arr as $key => $val) {
            $key = 'appboxL_' . $val['extraData']['appId'] . '_' . $this->language . '_' . $val['extraData']['templateId'] . '_' . $this->ver_code;
            $key .= '_'.$_REQUEST['is_rom'];
            $tempArr[] = $key;
            if (!$this->redis->exists($key)) {
                $this->redis->set($key, json_encode($val), $this->expire);
            }
        }
        $this->redis->select(2);
        $time = time();
        $tempArr[] = $time;
        if (!$this->cid && $type) {//最新，下载，评分缓存
            $key = 'appbox_' . $is_game . '_' . $type . '_' . $this->language . '_' . $this->page . '_' . $this->ver_code;
            $key .= '_'.$_REQUEST['is_rom'];
            $this->redis->set($key, json_encode($tempArr), $this->expire);//键app_new_0
        } else {//每个分类进行缓存
            $cidKey = 'appbox_' . $is_game . '_cid' . $this->cid . '_' . $this->language . '_' . $this->page . '_' . $sort . '_' . $this->ver_code;
            $cidKey .= '_'.$_REQUEST['is_rom'];
            $this->redis->set($cidKey, json_encode($tempArr), $this->expire);//键app_cid3_0
        }
        $this->_parseEtags(0, 0, $time);//从查询第一页缓存是否有更新
    }

    //获取应用列表缓存
    public function getAppRedis($redis_datas) {
        $arr = json_decode($redis_datas, true);
        array_pop($arr);
        $return = array();
        $this->redis->select(8);
        foreach ($arr as $key => $val) {
            $data = $this->redis->get($val);
            if ($data) {
                $return[] = json_decode($data, true);
            } else { //如果在缓存中查不到，则重新生成缓存
                $redisData = $this->setSingleAppRedis($val);
                if ($redisData) { //如果模板存在
                    $return[] = $redisData;
                } else { //否则跳过这条信息
                    continue;
                }
            }
        }
        return $return;
    }

    /**
     * 设置单个应用列表缓存
     * @param string $key 对应redis中的键值
     * @param int $templateType 模板的类型，1：应用/游戏模板，2下载排行模板，3评分排行模板...
     * @return array
     */
    public function setSingleAppRedis($key, $templateType = '') {
        $this->redis->select(8);
        $split = explode('_', $key);
        $packageId = $split[1];
        $sql = "select releaseTime from appbox_app where package_id='$packageId' and language='" . $this->language . "'";
        $data = $this->_db->getRow($sql);
        $templateType = isset($templateType) && $templateType ? $templateType : $this->templateType;
        $redisData = $this->getAppDetail($data['releaseTime'], $packageId, $templateType);
        if ($redisData) {
            $this->redis->set($key, json_encode($redisData), $this->expire);
            return $redisData;
        }
        return false;
    }

    //设置专题列表缓存
    public function setSpreadRedis($arr) {
        $tempArr = array();
        $this->redis->select(5);
        foreach ($arr as $key => $val) {
            $key = 'appboxL_s_' . $val['extraData']['spreadId'] . '_' . $this->language;
            $tempArr[] = $key;
            if (!$this->redis->exists($key)) {
                $this->redis->set($key, json_encode($val), $this->expire);
            }
        }
        $time = time();
        $tempArr[] = $time;
        $key = 'appboxsL_' . $this->language . '_' . $this->page;
        $this->redis->set($key, json_encode($tempArr), $this->expire);
        $this->_parseEtags(0, 0, $time);//从查询第一页缓存是否有更新
    }

    //获取礼包列表缓存
    public function getSpreadRedis($redis_datas) {
        $arr = json_decode($redis_datas, true);
        $return = array();
        $this->redis->select(5);
        foreach ($arr as $key => $val) {
            $data = $this->redis->get($val);
            if ($data) {
                $return[] = json_decode($data, true);
            } else { //如果在缓存中查不到，则重新生成缓存
                $redisData = $this->setSingleSpreadRedis($val);
                if ($redisData) { //如果模板存在
                    $return[] = $redisData;
                } else { //否则跳过这条信息
                    continue;
                }
            }
        }
        return $return;
    }

    //设置单个专题列表缓存
    public function setSingleSpreadRedis($key) {
        $this->redis->select(5);
        $split = explode('_', $key);
        $id = $split[2];
        $sql = "select id,releaseTime from appbox_spread where id=$id";
        $data = $this->_db->getRow($sql);
        $redisData = $this->getSpreadDetail($data['releaseTime'], $data['id']);
        if ($redisData) {
            $this->redis->set($key, json_encode($redisData), $this->expire);
            return $redisData;
        }
        return false;
    }

    public function setSingleArticelRedis($key) {
        $this->redis->select(5);
        $split = explode('_', $key);
        $id = $split[2];
        $sql = "select id,release_time from appbox_news where id=$id";
        $data = $this->_db->getRow($sql);
        $redisData = $this->getNewsDetail($data['release_time'], $data['id']);
        if ($redisData) {
            $this->redis->set($key, json_encode($redisData), $this->expire);
            return $redisData;
        }
        return false;
    }

    //设置单个专题列表详情中的url缓存
    public function setSingleUrlRedis($key, $type) {
        $this->redis->select(5);
        $split = explode('_', $key);
        $id = $split[2];
        $sql = "select releaseTime from $type where id=$id";
        $data = $this->_db->getRow($sql);

        $redisData = $this->getUrlDetail($data['releaseTime'], $id, $type);
        if ($redisData) {
            $this->redis->set($key, json_encode($redisData), $this->expire);
            return $redisData;
        }
        return false;
    }

    /**
     * 设置专题详情列表缓存
     * @param array $arr 要缓存的数据
     * @param int $id 专题/广告位id
     * @param string $is_banner 专题或广告位标示
     */
    public function setSpreadDetailRedis($arr, $id, $is_banner = '') {
        $tempArr = array();
        foreach ($arr as $key => $val) {
            if (isset($val['extraData']['giftId'])) {
                $this->redis->select(4);
                $key = 'appboxL_g_' . $val['extraData']['giftId'] . '_' . $this->language;//礼包列表详情
                $tempArr[] = $key;
                if (!$this->redis->exists($key)) {
                    $this->redis->set($key, json_encode($val), $this->expire);
                }
            } elseif (isset($val['extraData']['appId'])) {
                $this->redis->select(8);
                $key = 'appboxL_' . $val['extraData']['appId'] . '_' . $this->language . '_' . $val['extraData']['templateId'];//应用列表详情
                $tempArr[] = $key;
                if (!$this->redis->exists($key)) {
                    $this->redis->set($key, json_encode($val), $this->expire);
                }
            } elseif (isset($val['extraData']['urlId'])) {
                $this->redis->select(5);
                $key = 'appboxU_u_' . $val['extraData']['urlId'] . '_' . $this->language . '_' . $this->ver_code;//专题url详情
                $tempArr[] = $key;
                if (!$this->redis->exists($key)) {
                    $this->redis->set($key, json_encode($val), $this->expire);
                }
            } elseif (isset($val['extraData']['newsId'])) {
                $this->redis->select(5);
                $key = 'appboxL_a_' . $val['extraData']['newsId'] . '_' . $this->language;//文章类型
                $tempArr[] = $key;
                if (!$this->redis->exists($key)) {
                    $this->redis->set($key, json_encode($val), $this->expire);
                }
            } elseif (isset($val['extraData']['spreadId'])) {
                $this->redis->select(5);
                $key = 'appboxL_s_' . $val['extraData']['spreadId'] . '_' . $this->language;//文章类型
                $tempArr[] = $key;
                if (!$this->redis->exists($key)) {
                    $this->redis->set($key, json_encode($val), $this->expire);
                }
            }
        }
        $time = time();
        $tempArr[] = $time;
        if ($is_banner && $is_banner == 'banner') {
            $this->redis->select(6);
            $key = 'appboxbDL_' . $this->language . '_' . $this->page . '_' . $id . '_' . $this->ver_code.'_'.$_REQUEST['is_rom'];
            $this->redis->set($key, json_encode($tempArr), $this->expire);
        } else {
            $this->redis->select(5);
            $key = 'appboxsDL_' . $this->language . '_' . $this->page . '_' . $id . '_' . $this->ver_code.'_'.$_REQUEST['is_rom'];
            $this->redis->set($key, json_encode($tempArr), $this->expire);
        }
        $this->_parseEtags(0, 0, $time);//从查询第一页缓存是否有更新
    }

    //获取专题详情列表缓存
    public function getSpreadDetailRedis($redis_datas, $type = 'appbox_spread_url') {
        $arr = json_decode($redis_datas, true);
        $return = array();
        foreach ($arr as $key => $val) {
            $tempArr = explode('_', $val);
            switch ($tempArr[1]) {
                case 'u'://专题url缓存
                    $this->redis->select(5);
                    $data = $this->redis->get($val);
                    if ($data) {
                        $return[] = json_decode($data, true);
                    } else { //如果在缓存中查不到，则重新生成缓存
                        $redisData = $this->setSingleUrlRedis($val, $type);
                        if ($redisData) { //如果模板存在
                            $return[] = $redisData;
                        } else { //否则跳过这条信息
                            continue;
                        }
                    }
                    break;
                case 's'://专题缓存
                    $this->redis->select(5);
                    $data = $this->redis->get($val);
                    if ($data) {
                        $return[] = json_decode($data, true);
                    } else { //如果在缓存中查不到，则重新生成缓存
                        $redisData = $this->setSingleSpreadRedis($val);
                        if ($redisData) { //如果模板存在
                            $return[] = $redisData;
                        } else { //否则跳过这条信息
                            continue;
                        }
                    }
                    break;
                case 'a'://文章缓存
                    $this->redis->select(5);
                    $data = $this->redis->get($val);
                    if ($data) {
                        $return[] = json_decode($data, true);
                    } else { //如果在缓存中查不到，则重新生成缓存
                        $redisData = $this->setSingleArticelRedis($val);
                        if ($redisData) { //如果模板存在
                            $return[] = $redisData;
                        } else { //否则跳过这条信息
                            continue;
                        }
                    }
                    break;
                case 'g'://礼包缓存
                    $this->redis->select(4);
                    $data = $this->redis->get($val);
                    if ($data) {
                        $return[] = json_decode($data, true);
                    } else { //如果在缓存中查不到，则重新生成缓存
                        $redisData = $this->setSingleGiftRedis($val);
                        if ($redisData) { //如果模板存在
                            $return[] = $redisData;
                        } else { //否则跳过这条信息
                            continue;
                        }
                    }
                    break;
                default://应用/游戏缓存
                    $this->redis->select(8);
                    $data = $this->redis->get($val);
                    if ($data) {
                        $return[] = json_decode($data, true);
                    } else { //如果在缓存中查不到，则重新生成缓存
                        $redisData = $this->setSingleAppRedis($val, 1);
                        if ($redisData) { //如果模板存在
                            $return[] = $redisData;
                        } else { //否则跳过这条信息
                            continue;
                        }
                    }
                    break;
            }
        }
        return $return;
    }

    //设置礼包列表缓存
    public function setGiftRedis($arr) {
        $tempArr = array();
        $this->redis->select(4);
        foreach ($arr as $key => $val) {
            $key = 'appboxL_g_' . $val['extraData']['giftId'] . '_' . $this->language;
            $tempArr[] = $key;
            if (!$this->redis->exists($key)) {
                $this->redis->set($key, json_encode($val), $this->expire);
            }
        }
        $time = time();
        $tempArr[] = $time;
        $key = 'appboxgL_' . $this->language . '_' . $this->page;
        $this->redis->set($key, json_encode($tempArr), $this->expire);
        $this->_parseEtags(0, 0, $time);//从查询第一页缓存是否有更新
    }

    //获取礼包列表缓存
    public function getGiftRedis($redis_datas) {
        $arr = json_decode($redis_datas, true);
        $return = array();
        $this->redis->select(4);
        foreach ($arr as $key => $val) {
            $data = $this->redis->get($val);
            if ($data) {
                $return[] = json_decode($data, true);
            } else { //如果在缓存中查不到，则重新生成缓存
                $redisData = $this->setSingleGiftRedis($val);
                if ($redisData) { //如果缓存生成成功
                    $return[] = $redisData;
                } else { //否则跳过这条信息
                    continue;
                }
            }
        }
        return $return;
    }

    //设置单个应用列表缓存
    public function setSingleGiftRedis($key) {
        $this->redis->select(4);
        $split = explode('_', $key);
        $id = $split[2];
        $sql = "select start_time,id from appbox_gift where id='$id'";
        $data = $this->_db->getRow($sql);
        $redisData = $this->getGiftDetail($data['start_time'], $data['id']);
        if ($redisData) {
            $this->redis->set($key, json_encode($redisData), $this->expire);
            return $redisData;
        }
        return false;
    }

    //设置精选缓存
    public function setDayilyRedis($arr) {
        $tempArr = array();
        foreach ($arr as $key => $val) {
            if (isset($val['extraData']['giftId'])) {
                $this->redis->select(4);
                $key = 'appboxL_g_' . $val['extraData']['giftId'] . '_' . $this->language;//礼包列表详情
                $tempArr[] = $key;
                if (!$this->redis->exists($key)) {
                    $this->redis->set($key, json_encode($val), $this->expire);
                }
            } elseif (isset($val['extraData']['appId'])) {
                $this->redis->select(8);
                $key = 'appboxL_' . $val['extraData']['appId'] . '_' . $this->language . '_' . $val['extraData']['templateId'];//应用列表详情
                $tempArr[] = $key;
                if (!$this->redis->exists($key)) {
                    $this->redis->set($key, json_encode($val), $this->expire);
                }
            } elseif (isset($val['extraData']['spreadId'])) {
                $this->redis->select(5);
                $key = 'appboxL_s_' . $val['extraData']['spreadId'] . '_' . $this->language;//专题列表详情

                $tempArr[] = $key;
                if (!$this->redis->exists($key)) {
                    $this->redis->set($key, json_encode($val), $this->expire);
                }
            }
        }
        $this->redis->select(3);
        $key = 'appboxdL_' . $this->language . '_' . $this->page;
        $this->redis->set($key, json_encode($tempArr), $this->expire);
    }

    //获取精选缓存
    public function getDayilyRedis($redis_datas) {
        $arr = json_decode($redis_datas, true);
        $return = array();
        foreach ($arr as $key => $val) {
            $tempArr = explode('_', $val);
            switch ($tempArr[1]) {
                case 's':
                    $this->redis->select(5);
                    $data = $this->redis->get($val);
                    if ($data) {
                        $return[] = json_decode($data, true);
                    } else { //如果在缓存中查不到，则重新生成缓存
                        $redisData = $this->setSingleSpreadRedis($val);
                        if ($redisData) { //如果模板存在
                            $return[] = $redisData;
                        } else { //否则跳过这条信息
                            continue;
                        }
                    }
                    break;
                case 'g':
                    $this->redis->select(4);
                    $data = $this->redis->get($val);
                    if ($data) {
                        $return[] = json_decode($data, true);
                    } else { //如果在缓存中查不到，则重新生成缓存
                        $redisData = $this->setSingleGiftRedis($val);
                        if ($redisData) { //如果模板存在
                            $return[] = $redisData;
                        } else { //否则跳过这条信息
                            continue;
                        }
                    }
                    break;
                default:
                    $this->redis->select(8);
                    $data = $this->redis->get($val);
                    if ($data) {
                        $return[] = json_decode($data, true);
                    } else { //如果在缓存中查不到，则重新生成缓存
                        $redisData = $this->setSingleAppRedis($val, 1);
                        if ($redisData) { //如果模板存在
                            $return[] = $redisData;
                        } else { //否则跳过这条信息
                            continue;
                        }
                    }
                    break;
            }
        }
        return $return;
    }
}
