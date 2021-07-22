<?php
namespace app\api\controller;


use app\api\library\Constraint;
use app\api\library\Utils;
use app\common\controller\Api;
use think\cache\driver\Redis;
use think\Db;
use think\Exception;
use think\Log;
use think\Validate;

class Invisit extends Api {

    /**
     * 发送短信验证码
     */
    public function sendSms(){
        try {
            $mobile = $this->mobile;
            $staff_info = Db::table("dy_staff")->where(["mobile"=>$mobile,"status"=>1])->find();
            if (!empty($staff_info)){
                $this->error("您已经完成了问卷调查",[],-1);
            }
            $sms_info = Db::table("dy_sms_record")->where(["mobile"=>$mobile,"status"=>0])->order("createtime desc")->find();
            if(!empty($sms_info)&&time()-strtotime($sms_info['createtime'])<60){
                $this->error("每分钟只能发送一条短信",[],-1);
            }
            $code = Utils::getRandCode(4);
            $send_res = Utils::sendMsg([$mobile],$code,"【闻远科技】",51884);
            if (isset($send_res["code"]) && $send_res["code"] == 0){
                $ins_data = array(
                    "mobile" => $mobile,
                    "code"=>$code,
                    "createtime"=>date("Y-m-d H:i:s",time()),
                );
                Db::table("dy_sms_record")->insert($ins_data);
                $last_id = Db::getLastInsID();
                if($last_id){
                    $this->success("success",[],0);
                }
                throw new Exception("无效的验证码，请重新发送");
            }
            else{
                throw new Exception($send_res["msg"]);
            }
        }catch (Exception $e){
            Log::error("验证码发送失败：".$e->getMessage());
            $this->error("验证码发送失败：".$e->getMessage(),[],-1);
        }
    }

    /**
     * 校验验证码
     */
    public function checkCode(){
        Db::startTrans();
        try {
            $code = $this->request->post('code');
            if(empty($code)){
                throw new Exception("验证码不能为空");
            }
            $res = Db::table("dy_sms_record")->where(["mobile"=>$this->mobile,"code"=>$code,"status"=>0])->find();
            if($res){
                if(time() - strtotime($res['createtime'])>300){
                    throw new Exception("验证码已失效");
                }
                //修改验证码使用状态
                Db::table("dy_sms_record")->where(["mobile"=>$this->mobile,"code"=>$code,"status"=>0])->update(["status"=>1]);

                $staff_info = Db::table("dy_staff")->where(["mobile"=>$this->mobile])->find();
                if(empty($staff_info)){
                    //插入用户
                    $ins_data = array(
                        "mobile" => $this->mobile
                    );
                    Db::table("dy_staff")->insert($ins_data);
                }
                Db::commit();
                $this->success("校验成功",[],0);
            }
            throw new Exception("校验失败");
        }catch (Exception $e){
            Db::rollback();
            Log::error("验证码校验失败：".$e->getMessage());
            $this->error("验证码校验失败：".$e->getMessage(),[],-1);
        }
    }

    /**
     * 提交问卷
     */
    public function submit(){
        Db::startTrans();
        try {
            $staff_info = Db::table("dy_staff")->where(["mobile"=>$this->mobile])->find();
            if(empty($staff_info)){
                throw new Exception("请先登录");
            }
            if(!empty($staff_info["name"])){
                throw new Exception("确认是否已经提交过问卷");
            }

            $validate = new Validate(['user_info'=>'require','question'=>'require']);
            $check_res = $validate->check( $this->request->post());
            if(!$check_res) {
                $this->error($validate->getError(), '', 4001);
            }

            $user_info = $this->request->post('user_info');
            $question_info = $this->request->post('question');
            $user = json_decode($user_info,true);
            if(empty($user)||!is_array($user)){
                throw new Exception("请填写用户信息");
            }
            //校验员工信息
            if(in_array("",$user)){
                throw new Exception("请补全员工信息后再提交");
            }

            $question = json_decode($question_info,true);
            if(empty($question)||!is_array($question)){
                throw new Exception("请先回答问题后再提交");
            }
            if(count($question)<14){
                throw new Exception("需要回答全部的14个问题");
            }

            //补全用户信息 并将用户设置为已完成问卷
            $user["status"] = 1;
            $res_upd_staff = Db::table("dy_staff")->where(["mobile"=>$this->mobile])->update($user);
            if(!$res_upd_staff){
                throw new Exception("请确认是否已经提交过问卷");
            }

            //插入问答数据
            $staff_id = $staff_info["id"];
            $question_datas = array();
            foreach ($question as $key => $value){
                if(!isset($value["answer"])||!isset($value["question"])||empty($value["question"]||empty($value["answer"]))){
                    throw new Exception("请回答所有问题后再提交");
                }
                $question_single = array(
                    "staff_id" => $staff_id,
                    "question" => $value["question"],
                    "answer"   => $value["answer"],
                    "createtime"=>date("Y-m-d H:i:s",time()),
                    "updatetime"=>date("Y-m-d H:i:s",time()),
                );
                array_push($question_datas,$question_single);
            }
            $res = Db::table("dy_question_answer")->insertAll($question_datas);
            if(!$res){
                throw new Exception("数据存储失败，请联系管理员");
            }
            Db::commit();
            $this->success("success",[],0);
        }catch (Exception $e){
            Db::rollback();
            Log::error("问卷提交失败：".$e->getMessage());
            $this->error("问卷提交失败：".$e->getMessage(),[],-1);
        }
    }
}