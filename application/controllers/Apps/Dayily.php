<?php
/**
*   精选接口
*/
class Apps_DayilyController extends Yaf_Controller_Abstract 
{
    public function indexAction() 
    {
        $page = isset($_GET['p']) ? $_GET['p'] : '';
        $language = isset($_GET['language']) ? $_GET['language'] : '';
        $templateUpdateTime = isset($_GET['templateUpdateTime']) ? $_GET['templateUpdateTime'] : '1419057214a';
        $dayily_mod = new DayilyModel($language);
        $json = $dayily_mod->getJson($page,$templateUpdateTime);
       file_put_contents('../json.json',$json);//推入文件，方便查看
        echo $json;
    }
}
