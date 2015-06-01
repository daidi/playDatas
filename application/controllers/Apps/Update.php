<?php

/**
 *   是否更新接口
 *    收藏接口
 *    关键词发送接口
 */
class Apps_UpdateController extends Yaf_Controller_Abstract {
    //更新接口 9版本之前的更新通知
    public function indexAction() {
        $giftUpdateTime = isset($_GET['giftUpdateTime']) ? $_GET['giftUpdateTime'] / 1000 : 0;
        $spreadUpdateTime = isset($_GET['spreadUpdateTime']) ? $_GET['spreadUpdateTime'] / 1000 : 0;
        $dayilyUpdateTime = isset($_GET['dayilyUpdateTime']) ? $_GET['dayilyUpdateTime'] / 1000 : 0;
        $ver_code = isset($_GET['ver_code']) ? $_GET['ver_code'] : '';
        $language = isset($_GET['language']) ? $_GET['language'] : 'en';
        $other_mod = new OtherModel($language);
        $json = $other_mod->getUpdate($giftUpdateTime, $spreadUpdateTime, $dayilyUpdateTime, $ver_code);
        file_put_contents('../json.json', $json);//推入文件，方便查看
        echo $json;
    }

    //9版本之后的更新通知接口
    public function announceAction() {
        $currentTime = date('Y-m-d',time());
        $time = strtotime($currentTime);
        $timeArr['giftUpdateTime'] = isset($_GET['giftUpdateTime']) ? ($_GET['giftUpdateTime'] < $time ? $time : $_GET['giftUpdateTime']) : $time;
        $timeArr['spreadUpdateTime'] = isset($_GET['spreadUpdateTime']) ? ($_GET['spreadUpdateTime'] < $time ? $time : $_GET['spreadUpdateTime']) : $time;
        $timeArr['appUpdateTime'] = isset($_GET['appUpdateTime']) ? ($_GET['appUpdateTime'] < $time ? $time : $_GET['appUpdateTime']) : $time;
        $timeArr['gameUpdateTime'] = isset($_GET['gameUpdateTime']) ? ($_GET['gameUpdateTime'] < $time ? $time : $_GET['gameUpdateTime']) : $time;
        $timeArr['articleUpdateTime'] = isset($_GET['articleUpdateTime']) ? ($_GET['articleUpdateTime'] < $time ? $time : $_GET['articleUpdateTime']) : $time;
        $ver_code = isset($_GET['ver_code']) ? $_GET['ver_code'] : '';
        $other_mod = new OtherModel();
        $json = $other_mod->getAnnounce($timeArr, $ver_code);
        file_put_contents('../json.json', $json);//推入文件，方便查看
        echo $json;
    }

    public function timerAction() {
        $other_mod = new OtherModel();
        $json = $other_mod->getTimer();
        file_put_contents('../json.json', $json);//推入文件，方便查看
        echo $json;
    }

    //用户添加到收藏接口
    public function addFavoriteAction() {
        $uuid = isset($_GET['uuid']) ? $_GET['uuid'] : exit;
        $packageName = isset($_GET['packageName']) ? $_GET['packageName'] : exit;
        $cancel = isset($_GET['cancel']) ? $_GET['cancel'] : '';
        $other_mod = new OtherModel();
        $json = $other_mod->getFavorite($uuid, $packageName, $cancel);
        file_put_contents('../json.json', $json);//推入文件，方便查看
        echo $json;
    }

    //关键词发送接口
    public function sendKeywordsAction() {
        $other_mod = new OtherModel();
        $json = $other_mod->getKeywords();
        file_put_contents('../json.json', $json);//推入文件，方便查看
        echo $json;
    }

    //用户反馈取标签接口
    public function feedbackTagAction(){
        $other_mod = new OtherModel();
        $json = $other_mod->sendFeedbackTag();
        file_put_contents('../json.json', $json);//推入文件，方便查看
        echo $json;
    }
    //用户反馈接口
    public function feedbackAction() {
        $other_mod = new OtherModel();
        $json = $other_mod->setFeedback();
        echo $json;
    }

    //获取rom版本的更新
    public function romUpdateAction() {
        $language = isset($_GET['language']) ? $_GET['language'] : 'en';
        $ver_code = isset($_GET['ver_code']) ? $_GET['ver_code'] : die('缺少参数！');
        $channelId = isset($_GET['channelId']) ? $_GET['channelId'] : die('缺少参数！');
        $packageNameSelf = isset($_GET['packageNameSelf']) ? $_GET['packageNameSelf'] : die('缺少参数！');
        $other_mod = new OtherModel();
        $json = $other_mod->getRomUpdate($channelId, $language, $ver_code, $packageNameSelf);
        echo $json;
    }
}
