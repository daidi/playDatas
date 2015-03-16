<?php
class IndexController extends Yaf_Controller_Abstract 
{
    public function indexAction() 
    {
        $data = array(1,2,3,4);
        print_r($data);
    }

    public function test1Action()
    {
        echo 111;exit;
    }
}
