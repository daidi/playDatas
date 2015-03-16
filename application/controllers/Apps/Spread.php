<?php
/**
*   专题接口
*/
class Apps_SpreadController extends Yaf_Controller_Abstract 
{
    public function indexAction() 
    {
        $page = isset($_GET['p']) ? $_GET['p'] : '';
        $language = isset($_GET['language']) ? $_GET['language'] : '';
        $templateUpdateTime = isset($_GET['templateUpdateTime']) ? $_GET['templateUpdateTime'] : '1419057214a';
        $spread_mod = new SpreadModel($language);
        $json = $spread_mod->getJson($page,$templateUpdateTime);
        file_put_contents('../json.json',$json);//推入文件，方便查看
        echo $json;    
    }
//获取专题详情
    public function spreadDetailAction()
    {
        $id = isset($_GET['id']) ? (int)($_GET['id']) : exit;
        $language = isset($_GET['language']) ? $_GET['language'] : 'en';
        $p = isset($_GET['p']) ? (int)($_GET['p']) : '';
        $spread_mod = new SpreadModel($language);
        $json = $spread_mod->getDetailJson($id,$p);
        file_put_contents('../json.json',$json);//推入文件，方便查看
        echo $json;    
    }

//获取推广图详情
    public function bannerDetailAction()
    {
        $id = isset($_GET['id']) ? (int)($_GET['id']) : exit;
        $language = isset($_GET['language']) ? $_GET['language'] : 'en';
        $p = isset($_GET['p']) ? (int)($_GET['p']) : '';
        $banner_mod = new SpreadModel($language);
        $json = $banner_mod->bannerDetailJson($id,$p);
        file_put_contents('../json.json',$json);//推入文件，方便查看
        echo $json;    
    }    
}
