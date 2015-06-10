<?php

/**
 *   礼包接口
 */
class Apps_GiftsController extends Yaf_Controller_Abstract {
    public function indexAction() {
        $page = isset($_GET['p']) ? $_GET['p'] : '';
        $language = isset($_GET['language']) ? $_GET['language'] : '';
        $templateUpdateTime = isset($_GET['templateUpdateTime']) ? $_GET['templateUpdateTime'] : '1419057214a';
        $gift_mod = new GiftsModel($language);
        $json = $gift_mod->getJson($page, $templateUpdateTime);
        if ($_SERVER['REMOTE_ADDR'] == '127.0.0.1') file_put_contents('../json.json', $json);//推入文件，方便查看
        echo $json;
    }

    public function GiftDetailAction() {
        $id = isset($_GET['id']) ? (int)$_GET['id'] : '';
        $packageName = isset($_GET['packageName']) ? $_GET['packageName'] : '';
        $chargePoint = isset($_GET['chargePoint']) ? $_GET['chargePoint'] : '';

        $language = isset($_GET['language']) ? $_GET['language'] : '';
        $gift_mod = new GiftsModel($language);
        $json = $gift_mod->getDetailJson($id, $packageName, $chargePoint);
        if ($_SERVER['REMOTE_ADDR'] == '127.0.0.1') file_put_contents('../json.json', $json);//推入文件，方便查看
        echo $json;
    }

    /**
     *   本地礼包列表
     */
    public function localGiftAction() {
        $packageName = isset($_GET['packageName']) ? trim($_GET['packageName']) : '';
        $language = isset($_GET['language']) ? trim($_GET['language']) : '';
        $templateUpdateTime = isset($_GET['templateUpdateTime']) ? trim($_GET['templateUpdateTime']) : time();
        $gift_mod = new GiftsModel($language);
        $json = $gift_mod->getLocal($packageName, $templateUpdateTime);
        if ($_SERVER['REMOTE_ADDR'] == '127.0.0.1') file_put_contents('../json.json', $json);//推入文件，方便查看
        echo $json;
    }

    /**
     * 本地礼包详情接口
     */
    public function localGiftDetailAction() {
        $packageName = isset($_GET['packageName']) ? trim($_GET['packageName']) : exit;
        $language = isset($_GET['language']) ? trim($_GET['language']) : '';
        $gift_mod = new GiftsModel($language);
        $json = $gift_mod->getLocalDetail($packageName);
        if ($_SERVER['REMOTE_ADDR'] == '127.0.0.1') file_put_contents('../json.json', $json);//推入文件，方便查看
        echo $json;
    }

    public function getCodeAction() {
        $code = isset($_GET['code']) ? $_GET['code'] : '';//兑换码
        $id = isset($_GET['id']) ? $_GET['id'] : '';// 礼包id
        $packageName = isset($_GET['packageName']) ? $_GET['packageName'] : '';//包名
        $chargePoint = isset($_GET['chargePoint']) ? $_GET['chargePoint'] : '';//计费点
        $gift_mod = new GiftsModel();
        $json = $gift_mod->getCode($code, $id, $packageName, $chargePoint);
        if ($_SERVER['REMOTE_ADDR'] == '127.0.0.1') file_put_contents('../json.json', $json);//推入文件，方便查看
        echo $json;
    }

    public function getCircleGiftAction() {
        $p = isset($_GET['p']) ? $_GET['p'] : 0;
        $gift_mod = new GiftsModel();
        $json = $gift_mod->getCircleGift($p);
        if ($_SERVER['REMOTE_ADDR'] == '127.0.0.1') file_put_contents('../json.json', $json);//推入文件，方便查看
        echo $json;
    }

    //获取随机礼包或指定礼包
    public function getRandGiftAction() {
        $packageName = isset($_REQUEST['packageName']) ? $_REQUEST['packageName'] : '';
        $gift_mod = new GiftsModel();
        $json = $gift_mod->getRandGift($packageName);
        if ($_SERVER['REMOTE_ADDR'] == '127.0.0.1') file_put_contents('../json.json', $json);//推入文件，方便查看
        echo $json;
    }
}
