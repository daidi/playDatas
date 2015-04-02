<?php
/**
*   是否更新接口
*	收藏接口
* 	关键词发送接口
*/
class Apps_UpdateController extends Yaf_Controller_Abstract 
{
	//更新接口
    public function indexAction() 
    {
        $giftUpdateTime = isset($_GET['giftUpdateTime']) ? (int)$_GET['giftUpdateTime'] : 0;
        $spreadUpdateTime = isset($_GET['spreadUpdateTime']) ? (int)$_GET['spreadUpdateTime'] : 0;
        $dayilyUpdateTime = isset($_GET['dayilyUpdateTime']) ? (int)$_GET['dayilyUpdateTime'] : 0;
        $ver_code = isset($_GET['ver_code']) ? $_GET['ver_code'] : '';
        $language = isset($_GET['language']) ? $_GET['language'] : 'en';
    	$other_mod = new OtherModel($language);
    	$json = $other_mod->getUpdate($giftUpdateTime,$spreadUpdateTime,$dayilyUpdateTime,$ver_code);
        file_put_contents('../json.json',$json);//推入文件，方便查看
    	echo $json;
    }

    //用户添加到收藏接口
    public function addFavoriteAction()
    {
    	$uuid = isset($_GET['uuid']) ? $_GET['uuid'] : exit;
    	$packageName = isset($_GET['packageName']) ? $_GET['packageName'] : exit;
    	$cancel = isset($_GET['cancel']) ? $_GET['cancel'] : '';
    	$other_mod = new OtherModel();
    	$json = $other_mod->getFavorite($uuid,$packageName,$cancel);
        file_put_contents('../json.json',$json);//推入文件，方便查看
    	echo $json;
    }

    //关键词发送接口
    public function sendKeywordsAction()
    {
    	$other_mod = new OtherModel();
    	$json = $other_mod->getKeywords();
        file_put_contents('../json.json',$json);//推入文件，方便查看
    	echo $json;
    }

    //用户反馈接口
    public function feedbackAction()
    {        
        $other_mod = new OtherModel();
        $json = $other_mod->setFeedback();
        echo $json;
    }

    //获取rom版本的更新
    public function romUpdateAction(){
        $language = isset($_GET['language']) ? $_GET['language'] : 'en';
        $ver_code = isset($_GET['ver_code']) ? $_GET['ver_code'] : die('缺少参数！');
        $channelId = isset($_GET['channelId']) ? $_GET['channelId'] : die('缺少参数！');
        $other_mod = new OtherModel();
        $json = $other_mod->getRomUpdate($channelId,$language,$ver_code);
        echo $json;    
    }
}
