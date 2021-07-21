<?php
namespace app\api\library;

use app\common\model\Customer;
use think\cache\driver\Redis;
use think\Db;
use think\Exception;
use think\Log;
use think\Model;

class Utils
{

    //不参与签名的参数集合
    private static $unCheckParam = ['sign'] ;

    /**
     * 根据app_key获取app_secret
     * @param $app_key
     */
    public static function getAppInfo($app_key){
        try {
            $redisHelp = new Redis();
            $app_info = $redisHelp->get(Constraint::APP_KEY.$app_key);
            if(!$app_info){
                $app_data = Db::table("point_app_info")->where(["app_key"=>$app_key,'app_status'=>1])->select();
                if(empty($app_data)){
                    throw new Exception();
                }
                $app_info = array(
                    "app_id" => $app_data[0]['app_id'],
                    "app_secret" => $app_data[0]['app_secret'],
                );
                $redisHelp->set(Constraint::APP_KEY.$app_key,$app_info);
            }
            return $app_info;
        }catch (Exception $e){
            return '';
        }
    }

    /**
     * 签名校验
     * @param $params
     * @param $app_secret
     * @return bool
     */
    public static function checkSign($params,$app_secret){
        Log::info("请求参数：",json_encode($params));
        $sign = strtolower($params['sign']);
        foreach (self::$unCheckParam as $key){
            if(key_exists($key,$params)){
                unset($params[$key]);
            }
        }
        return self::makeSign($params,$app_secret) == $sign;
    }

    /**
     * 签名生成
     * @param $props
     * @param $secret
     * @return string
     */
    public static function makeSign($props, $secret) {
        ksort($props);
        $val = [];
        foreach ($props as $key => $prop) {
            if(isset($props[$key]) && strlen(trim($props[$key]))>0) {
                $val[] = $key;
                $val[] = "=".strtolower($prop)."&";
            }
        }
        $val[count($val)-1] = substr( $val[count($val)-1],0,strlen($val[count($val)-1])-1);
        $val[] = strtolower($secret);
        $text = trim(implode('', $val));
        $text = str_replace('%2A', '*', $text);
        Log::info("签名前sign:".$text);
        $localSign = strtolower(md5($text));
        Log::info("签名后sign:".$localSign);
        return $localSign;
    }

    /**
     * 生成订单号
     * @param $app_id
     */
    public static function createOrderNo($app_id){
        $redisHelp = new Redis();
        $order_code = $redisHelp->get(Constraint::ORDER_NO_CODE.$app_id);
        if(!$order_code){
            $order_code = 1001;
            $redisHelp->set(Constraint::ORDER_NO_CODE.$app_id,$order_code);
        }
        $order_code = $redisHelp->incr(Constraint::ORDER_NO_CODE.$app_id,$order_code);
        $order_no = date("Ymd",time()).$order_code.$app_id;
        return $order_no;
    }

    /**
     * 单个商品价格转换成积分(1元=多少积分)
     * @param $app_id
     * @param $price
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public static function priceConversionPointSingle($app_id,$price,$goods_count){
        $app_info = Db::table("point_app_info")->where(["app_id"=>$app_id])->select();
        if($app_info[0]['is_defaule_convert_rule'] == 1){
            $point_value_conf = Db::table("point_static_conf")->where(["conf_code"=>"POINT_VALUE"])->select();
            $point_value = $point_value_conf['conf_val'];
            return intval($price*$point_value*$goods_count);
        }
        return intval($price*$app_info[0]['app_point_value']*$goods_count);
    }


    /**
     * 积分转换成价格(1积分=多少元)
     * @param $app_id
     * @param $points 数组
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public static function pointConversionPrice($app_id,$points){
        if(!is_array($points)) return false;
        $point_value_conf = [];
        $retData = [];
        $app_info = Db::table("point_app_info")->where(["app_id"=>$app_id])->select();
        foreach ($points as $key=>$point ){
            if($app_info[0]['is_defaule_convert_rule'] == 1){
                if(empty($point_value_conf)){
                    $point_value_conf = Db::table("point_static_conf")->where(["conf_code"=>"POINT_VALUE"])->select();
                }
                $point_value = $point_value_conf['conf_val'];
                $retData[$key] = round($point/$point_value,2);
            }else{
                $point_value = $app_info[0]['app_point_value'];
                $retData[$key] = round($point/$point_value,2);
            }
        }
        return $retData;
    }



    /**
     * 检查用户存在 不存在则插入
     * @param $app_id
     * @param $user_phone
     * @return bool|mixed
     */
    public static function checkUser($app_id,$user_phone){
        $redisHelp = new Redis();
        $redis_key = Constraint::CUST_ID_CODE.$app_id.$user_phone;
        try{
            $cust_id = $redisHelp->get($redis_key);
            if($cust_id){
                return $cust_id;
            }

            //添加锁
            $lock = $redisHelp->setnx("LOCK_".$redis_key,time()+3);
            //防死锁判断
            if(!$lock){
                //前一个进程获得的锁已到释放时间：解锁并重新获得锁
                if(time()>$redisHelp->get("LOCK_".$redis_key)){
                    $redisHelp->del("LOCK_".$redis_key);
                    $redisHelp->setnx("LOCK_".$redis_key,time()+5);
                }else{
                    //返回错误
                    return false;
                }
            }

            //查询添加用户
            $cust_info = Db::table("point_customer")->where(["cust_phone"=>$user_phone])->select();
            if(!empty($cust_info)){
                $cust_id = $cust_info[0]["cust_id"];
            }else{
                Db::startTrans();
                $custService = new Customer();
                $cust_id = $custService->add_cust($user_phone,$app_id);
                if(!$cust_id){
                    Db::rollback();
                }
                Db::commit();
            }
            $redisHelp->set($redis_key,$cust_id);
            $redisHelp->del("LOCK_".$redis_key);//结束释放锁
            return $cust_id;
        }catch (Exception $e){
            Log::error("检查用户出错：".$e->getMessage());
            Utils::sendDingMessage("用户添加出错：".$e->getMessage());
            return false;
        }
    }


    /**
     * 是否允许参加活动
     * @param $activity_id
     * @param $rule_id
     * @param $app_id
     * @return bool
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public static function ifCanJoinActivity($activity_id,$rule_id,$app_id){
        $activity_res = Db::table("point_app_rule_index")->
        where(["activity_idfk"=>$activity_id,"rule_id"=>$rule_id,"app_id"=>$app_id,"status"=>1])->select();
        if(empty($activity_res)){
            return false;
        }
        return true;
    }

    /**
     * 发送钉钉告警
     * @param $message
     * @return mixed
     */
    public static function sendDingMessage($message){
        $post_url = "https://oapi.dingtalk.com/robot/send?access_token=c19a64d62f560bb443c10ed8728f7b972224d268a9adad079634e0997bbb4d52";
        $data = array ('msgtype' => 'text','text' => array ('content' => $message));
        $data_string = json_encode($data);
        $sk = new SKHttpClient();
        $result = $sk->sendDingMessage($post_url, $data_string);
        return $result;
    }

    /**
     * 获取从今天开始往前推几天的日期
     * @param $before_day
     * @return array
     */
    public static function getBeforeDays($before_day){
        $days = [];
        for($i=0;$i<$before_day;$i++){
            $day =  date('Y-m-d', strtotime("-{$i} day"));
            $days[] = $day;
        }
        return $days;
    }

    /**
     * 发送短信验证码
     * @param $mobiles
     * @param $content
     * @param $sign
     * @param $templateId
     * @param $msg
     */
    public static function sendMsg($mobiles,$content,$sign,$templateId){
        foreach ($mobiles as $k => $v){
            if (!empty($v)){
                $url = "https://api.1cloudsp.com/api/v2/single_send";
                $params = [
                    "accesskey"=>"W8k7SEA2Biq0PoUV",
                    "secret"=>"ApxW4JB0MbiEBwrjQA003rhUcUp6XuNo",
                    "sign"=>$sign,
                    "templateId"=>$templateId,
                    "mobile"=>$v,
                    "content"=>$content,
                ];
                $sk = SKHttpClient::getInstance();
                $result = $sk->postHttps($url,$params);
                $result = json_decode($result,true);
                return $result;
            }
        }
    }


    public static function getRandCode($length){
        $info="";
        $pattern = '1234567890123456789012345678901234567890';
        for($i=0;$i<$length;$i++) {
            $info .= $pattern{mt_rand(0,39)};    //生成php随机数
        }
        return $info;
    }


    /**
     * 对象 转 数组
     *
     * @param object $obj 对象
     * @return array
     */
    public static function objectToArray($obj) {
        $obj = (array)$obj;
        foreach ($obj as $k => $v) {
            if (gettype($v) == 'resource') {
                return;
            }
            if (gettype($v) == 'object' || gettype($v) == 'array') {
                $obj[$k] = (array)object_to_array($v);
            }
        }

        return $obj;
    }
}