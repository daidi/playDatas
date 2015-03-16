<?php
class BundlegiftController extends Yaf_Controller_Abstract
{
    //验证礼包兑换码是否有效，并且存入数据库，同时返回下一个兑换码，
    public function verfilyCodeAction() 
    {
        $packageName = isset($_REQUEST['packagename']) ? $_REQUEST['packagename'] : exit;//包名
        $code = isset($_REQUEST['key']) ? $_REQUEST['key'] : exit;//兑换码
        $gift_mod = new GiftsModel();
        $json = $gift_mod->verifyCode($packageName,$code);
        //file_put_contents('json.json',$json);//推入文件，方便查看
        echo $json;
    }
}
