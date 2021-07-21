<?php
namespace app\api\library;



use think\Exception;

class SKHttpClient
{


    private static $instance;

    public static function getInstance() {
        if (!isset(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * get请求
     *
     * @param String $url url地址
     * @param null $params 请求参数
     * @return null|string 结果
     */
    public function get($url, $params = NULL)
    {
        $options = array(
            'http' => array(
                'method' => 'GET',
                'header' => 'Content-type:application/x-www-form-urlencoded',
                'timeout' => 10 // 超时时间（单位:s）
            )
        );
        if (NULL != $params) {
            $url .= "?";
        }
        foreach ($params as $key => $value) {
            if(isset($value)) {
                $url = $url . urlencode($key) . "=" . urlencode($value) . '&';
            }
        }
        self::_logDebug("http url:" . $url);
        $context = stream_context_create($options);
        $result = NULL;
        try {
            $result = file_get_contents($url, FALSE, $context);
        } catch (\Exception $e) {
            self::_logErr($e->getMessage());
        }
        return $result;
    }

    /**
     * 发送post请求
     *
     * @param string $url 请求地址
     * @param array $postData post键值对数据
     * @return string
     */
    public function post($url, $postData)
    {
        if (is_array($postData)) {
            $postData = http_build_query($postData);
        }
        $options = array(
            'http' => array(
                'method' => 'POST',
                'header' => 'Content-type:application/x-www-form-urlencoded',
                'content' => $postData,
                'timeout' => 10 // 超时时间（单位:s）
            )
        );
        $context = stream_context_create($options);
        $result = NULL;
        try {
            $result = file_get_contents($url, FALSE, $context);
        } catch (Exception $e) {
            var_dump($e->getMessage());
        }
        return $result;
    }


    /**
     * 发送post请求,使用证书
     *
     * @param string $url 请求地址
     * @param array $postData post键值对数据
     * @return string
     */
    public function postHttps($url, $postData,$sslCertPath='',$sslKeyPath='')
    {
        if(is_array($postData)){
            $postData=http_build_query($postData);
        }

        $ch = curl_init();
        //超时时间
//        curl_setopt($ch,CURLOPT_TIMEOUT,30);
        //这里设置代理，如果有的话
        //curl_setopt($ch,CURLOPT_PROXY, '8.8.8.8');
        //curl_setopt($ch,CURLOPT_PROXYPORT, 8080);
        curl_setopt($ch,CURLOPT_URL, $url);
        curl_setopt($ch,CURLOPT_SSL_VERIFYPEER,FALSE);
        curl_setopt($ch,CURLOPT_SSL_VERIFYHOST,FALSE);
        //设置header
        curl_setopt($ch,CURLOPT_HEADER,FALSE);
        //要求结果为字符串且输出到屏幕上
        curl_setopt($ch,CURLOPT_RETURNTRANSFER,TRUE);
        if(!empty($sslCertPath) && !empty($sslKeyPath)){
            //设置证书
            //使用证书：cert 与 key 分别属于两个.pem文件
            //默认格式为PEM，可以注释
            curl_setopt($ch,CURLOPT_SSLCERTTYPE,'PEM');
            curl_setopt($ch,CURLOPT_SSLCERT, $sslCertPath);
            //默认格式为PEM，可以注释
            curl_setopt($ch,CURLOPT_SSLKEYTYPE,'PEM');
            curl_setopt($ch,CURLOPT_SSLKEY, $sslKeyPath);
        }
        //post提交方式
        curl_setopt($ch,CURLOPT_POST, true);
        curl_setopt($ch,CURLOPT_POSTFIELDS,$postData);
        $data = curl_exec($ch);
        //返回结果
        if($data){
            curl_close($ch);
            return $data;
        }
        else {
            $error = curl_errno($ch);
            self::_logDebug("error:".$error);
            curl_close($ch);
            return false;
        }
    }


    /**
     * 上传文件
     * @param $url
     * @param $filePath
     * @return int|mixed
     */
    public function postFile($url, $filePath){
        $file = fopen($filePath, 'r');
        $context = fread($file, filesize($filePath));
        fclose($file);
        $url .= (strstr($url, '?') ? '&md5=' : '?md5=') . md5($context);
        $bounday = "----WebKitFormBoundaryoAIdAEs4B5kx9cEP";
        $headers = array();
        array_push($headers, 'Connection: keep-alive');
        array_push($headers, 'Cache-Control: max-age=0');
        array_push($headers, 'Upgrade-Insecure-Requests: 1');
        array_push($headers, 'Content-Type: multipart/form-data; boundary='.$bounday);
        array_push($headers, 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8');
        array_push($headers, 'Accept-Encoding: gzip, deflate');
        array_push($headers, 'Accept-Language: zh-CN,zh;q=0.8');
        $fileName = pathinfo($filePath, PATHINFO_BASENAME);
        $postData = "--".$bounday."\r\nContent-Disposition: form-data; name=\"file\"; filename=\"".
            $fileName."\"\r\nContent-Type: application/octet-stream\r\n\r\n";
        $postData .= $context;
        $postData .= "\r\n--".$bounday."--\r\n";

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_FAILONERROR, false);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $data = curl_exec($ch);
        self::_logDebug($data);
        //返回结果
        if($data){
            curl_close($ch);
            return $data;
        } else {
            $error = curl_errno($ch);
            curl_close($ch);
            self:: _logDebug("curl出错，错误码:$error");
            return $error;
        }
    }


    public static function bigDataGet($url) {
        self::_log('........bigDataGet——Url......'.$url);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        if (1 == strpos("$".$url, "https://"))
        {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }
        $output = curl_exec($ch);
        if($output){
            self::_logDebug("bigDataGet——data:" . $output);
            curl_close($ch);
            return $output;
        } else {
            $error = curl_errno($ch);
            self::_logDebug("error:".$error);
            curl_close($ch);
            return false;
        }

    }


    public function GetData($url,$params) {
        $options = array(
            'http' => array(
                'method' => 'GET',
                'header' => 'Content-type:application/x-www-form-urlencoded',
                'timeout' => 10 // 超时时间（单位:s）
            )
        );
        if (NULL != $params) {
            $url .= "?";
        }
        foreach ($params as $key => $value) {
            if(isset($value)) {
                $url = $url . urlencode($key) . "=" . urlencode($value) . '&';
            }
        }
        self::_log('........get——Url......'.$url);
        $ch = curl_init();
        try {
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt ($ch, CURLOPT_CONNECTTIMEOUT,10);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HEADER, $options);
            if (1 == strpos("$".$url, "https://"))
            {
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            }
            $output = curl_exec($ch);
            if($output){
                //self::_logDebug("result：".$output);
                $data = explode("\r\n\r\n", $output);
                $body = $data[count($data)-1];
                if(!$body) {
                    $body = $data[count($data)-2];
                }
                curl_close($ch);
                //self::_logDebug("resultBody：".$body);
                return $body;
            } else {
                $error = curl_errno($ch);
                self::_logDebug("error:".$error);
                curl_close($ch);
                return json_encode([
                    'responseCode' => -99,
                    'responseMessage' => "请求接口失败，错误码: ".$error
                ]);
            }
        } catch (\Exception $e) {
            self::_logErr($e->getMessage());
            curl_close($ch);
            return json_encode([
                'responseCode' => -99,
                'responseMessage' => "请求接口异常: ".$e->getMessage()
            ]);
        }

    }

    public function curl_file_get_contents($url,$path)
    {
        $hander = curl_init();
        $fp = fopen($path,'wb');
        curl_setopt($hander,CURLOPT_URL,$url);
        curl_setopt($hander,CURLOPT_FILE,$fp);
        curl_setopt($hander,CURLOPT_HEADER,0);
        curl_setopt($hander,CURLOPT_FOLLOWLOCATION,1);
        curl_setopt($hander,CURLOPT_TIMEOUT,60);
        curl_exec($hander);
        curl_close($hander);
        fclose($fp);
        Return $path;
    }



    /**
     * 发送post请求 携带附件
     *
     * @param string $url 请求地址
     * @param array $postData post键值对数据
     * @return string
     */
    public function postWithFile($url, $postData)
    {
        $ch = curl_init();
        $bounday = "----WebKitFormBoundaryoAIdAEs4B5kx9cEP";
        $headers = array();
        array_push($headers, 'Connection: keep-alive');
        array_push($headers, 'Cache-Control: max-age=0');
        array_push($headers, 'Upgrade-Insecure-Requests: 1');
        array_push($headers, 'Content-Type: multipart/form-data; boundary='.$bounday);
        array_push($headers, 'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8');
        array_push($headers, 'Accept-Encoding: gzip, deflate');
        array_push($headers, 'Accept-Language: zh-CN,zh;q=0.8');
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        curl_setopt($ch, CURLOPT_HEADER, false);//参数设置，是否显示头部信息，1为显示，0为不显示
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);//执行结果是否被返回，0是返回，1是不返回
        $output = curl_exec($ch);
        curl_close($ch);
        if ($output){
            return json_decode($output);
        }else{
            self::_log('error:postWithFile');
            return false;
        }
    }

    /**
     * 钉钉告警opost
     * @param $remote_server
     * @param $post_string
     * @return mixed
     */
    public function sendDingMessage($remote_server, $post_string) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $remote_server);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array ('Content-Type: application/json;charset=utf-8'));
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_string);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
         curl_setopt ($ch, CURLOPT_SSL_VERIFYHOST, 0);
         curl_setopt ($ch, CURLOPT_SSL_VERIFYPEER, 0);
        $data = curl_exec($ch);
        curl_close($ch);
        return $data;
    }


}