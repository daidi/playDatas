<?php

/**
 *   游戏接口
 */
class Apps_GamesController extends Yaf_Controller_Abstract {
    public function indexAction() {
        $page = isset($_GET['p']) ? (int)$_GET['p'] : '';
        $templateUpdateTime = isset($_GET['templateUpdateTime']) ? (int)$_GET['templateUpdateTime'] : '';
        $language = isset($_GET['language']) ? $_GET['language'] : '';
        $cid = isset($_GET['cid']) ? (int)$_GET['cid'] : '';
        $ver_code = isset($_GET['ver_code']) ? $_GET['ver_code'] : '';
        $templatePos = $ver_code >= 15 ? 21 : 1;
        $order = '';
        //分类1应用2游戏分类，判断是否游戏。1应用2游戏，推广图3应用2游戏,语言,分类id
        $app_mod = new AppsModel(2, 2, 2, '', $language, $cid, '', $templatePos);
        //当前页数，模板更新时间，模板类型，缓存标示
        $json = $app_mod->getJson($page, $templateUpdateTime, 'new');
        if ($_SERVER['REMOTE_ADDR'] == '127.0.0.1') file_put_contents('../json.json', $json);//推入文件，方便查看
        echo $json;
    }

    //评分排行
    public function scoreAction() {
        $page = isset($_GET['p']) ? (int)$_GET['p'] : '';
        $templateUpdateTime = isset($_GET['templateUpdateTime']) ? (int)$_GET['templateUpdateTime'] : '';
        $language = isset($_GET['language']) ? $_GET['language'] : '';
        $cid = isset($_GET['cid']) ? (int)$_GET['cid'] : '';
        $ver_code = isset($_GET['ver_code']) ? $_GET['ver_code'] : '';
        $templatePos = $ver_code >= 15 ? 23 : 3;
        //分类1应用2游戏分类，判断是否游戏。1应用2游戏，推广图3应用2游戏,语言,分类id
        $order = 'app.score_sort desc,app.score desc,app.install_avarage desc,app.id desc';
        $where = 'where app.install_avarage>=100000 and';
        $app_mod = new AppsModel(2, 2, 2, $order, $language, $cid, $where, $templatePos);
        //当前页数，模板更新时间，模板类型，缓存标示
        $json = $app_mod->getJson($page, $templateUpdateTime, 'score');
        if ($_SERVER['REMOTE_ADDR'] == '127.0.0.1') file_put_contents('../json.json', $json);//推入文件，方便查看
        echo $json;
    }

    //下载排行
    public function downloadAction() {
        $page = isset($_GET['p']) ? (int)$_GET['p'] : '';
        $templateUpdateTime = isset($_GET['templateUpdateTime']) ? (int)$_GET['templateUpdateTime'] : '';
        $language = isset($_GET['language']) ? $_GET['language'] : '';
        $cid = isset($_GET['cid']) ? (int)$_GET['cid'] : '';
        $ver_code = isset($_GET['ver_code']) ? $_GET['ver_code'] : '';
        $templatePos = $ver_code >= 15 ? 22 : 2;
        //分类1应用2游戏分类，判断是否游戏。1应用2游戏，推广图3应用2游戏,语言,分类id
        $order = 'app.download_sort desc,app.install_avarage desc,app.score desc,app.id desc';
        $app_mod = new AppsModel(2, 2, 2, $order, $language, $cid, '', $templatePos);
        //当前页数，模板更新时间，模板类型，缓存标示
        $json = $app_mod->getJson($page, $templateUpdateTime, 'download');
        if ($_SERVER['REMOTE_ADDR'] == '127.0.0.1') file_put_contents('../json.json', $json);//推入文件，方便查看
        echo $json;
    }
}
