<?php
/**
*   赞或者啋接口
*/
class Apps_LikeController extends Yaf_Controller_Abstract 
{
    public function likeAction() 
    {
        $packageName = isset($_GET['packageName']) ? $_GET['packageName'] : exit;
        $language = isset($_GET['language']) ? $_GET['language'] : '';
        $cancel = isset($_GET['cancel']) ? (int)$_GET['cancel'] : 0;
        if($cancel == 1)
            $sql = "update appbox_app set likeCount=likeCount-1 where package_name='$packageName'";
        else
            $sql = "update appbox_app set likeCount=likeCount+1 where package_name='$packageName'";
        $other_mod = new OtherModel($language);
        $json = $other_mod->handleLike($sql,$packageName,$cancel,'likeCount');
        file_put_contents('../json.json',$json);//推入文件，方便查看
        echo json_encode($json);
    }

    public function hateAction()
    {
        $packageName = isset($_GET['packageName']) ? $_GET['packageName'] : exit;
        $language = isset($_GET['language']) ? $_GET['language'] : '';
        $cancel = isset($_GET['cancel']) ? (int)$_GET['cancel'] : 0;//是否取消赞
        if($cancel)
            $sql = "update appbox_app set hateCount=hateCount-1 where package_name='$packageName'";
        else
            $sql = "update appbox_app set hateCount=hateCount+1 where package_name='$packageName'";

        $other_mod = new OtherModel($language);
        $json = $other_mod->handleLike($sql,$packageName,$cancel,'hateCount');
        file_put_contents('../json.json',$json);//推入文件，方便查看
        echo json_encode($json);
    }
}
