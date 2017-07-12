<?php


class HttpServer
{
    private $serv;
    static $req_num = 0;

    public function __construct() 
    {
        $this->__init();

        $this->serv = new swoole_http_server("127.0.0.1", 9501);
        $this->serv->set(array(
            //上传文件的临时目录
            // 'upload_tmp_dir' => '/data/uploadfiles/',   
            //POST消息解析开关
            'http_parse_post' => false,
            'worker_num' => 5,
            // 'daemonize' => false,
            'max_request' => 10000,
            // 'dispatch_mode' => 2,
            // 'debug_mode'=> 1
        ));

        $this->serv->on('Request', array($this, 'onRequest'));
        $this->serv->start();
    }

    /**
     * 初始化 集合
     */
    protected function __init()
    {
        $this->__config_init();
        $this->__log_init();
        $this->__redis_init();
    }

    protected function __config_init()
    {
        $this->config = require "./config/config.php";
    }

    protected function __log_init()
    {
        require "./lib/log.php";
        $this->log = new log();
    }

    protected function __redis_init()
    {
        // require "./lib/redis.php";
        // $this->redis = \db\Redis::getInstance($this->config['redis']);
        $this->redis = new Redis();
        $this->redis->connect('127.0.0.1', 6379, '0.5');
    }

    // 请求
    public function onRequest ($request, $response) 
    {
        echo "Start\n";
        $this->request = $request;
        self::$req_num++;
        $return = $this->handle($request);
        $this->log->put(json_encode([self::$req_num=>$request,'return'=>$return]));
        $return = json_encode($return);
        $response->end($return);
    }

    // 处理业务
    function handle($request)
    {
        $return = false;
        if (empty($request))
        {
            return $return;
        }
        $request_uri = $request->server['request_uri'];
        switch ($request_uri) 
        {
            case "/goods/buy":
                
                if ( $this->__before_buy() )
                {
                    $return = $this->__do_buy();
                    $this->log->put(json_encode(['__do_buy_return'=>$return]));
                }
                break;

            default:
                # code...
                break;
        }

        return $return;
    }

    /**
     * 接受后台添加商品的通知
     */
    protected function __notice_add_goods()
    {
        $post = $this->request->post;
        $this->log->put( json_encode(['__notice_add_goods_post'=>$post]) );
        if ( empty($post) )
        {
            $return = array(
                    'code'   =>  '01',
                    'msg'   =>  'post数据为空',
                    'data'  =>  '',
                );

            return $return;
        }

        $set_res = $this->table->set($post['id'], $post);
        $this->log->put( json_encode(['__notice_add_goods_res'=>$set_res]) );
        $return = array(
                'code'   =>  '00',
                'msg'   =>  '成功',
                'data'  =>  $set_res,
            );
        return $return;
    }

    /**
     * 购买
     */
    protected function __do_buy()
    {
        $get = $this->request->get;
        $return = array(
                'url'   =>  '',
                'msg'   =>  '商品数量不足',
            );
        $good = $this->__get_goods_by_id( $get['id'] );

        $this->log->put( json_encode(['__do_buy_good'=>$good]) );

        if (  $good['allow_num'] < $get['good_num'] )
        {
            return $return;
        }

        if (  $good['number'] <= $good['sell_number'] )
        {
            return $return;
        }
        
        if (  $good['number'] - $good['sell_number'] < $get['good_num'] )
        {
            return $return;
        }

        $url = $this->config['seckill']['goods_buy'];
        // // 第二种:直接请求购买业务, 返回结果
        // require './lib/curl.php';
        // $curl = new CURL();
        // $return = $curl->get( $url . "?" . http_build_query($get) );
        $return = $this->__do_get_curl( $url . "?" . http_build_query($get) );
        $this->log->put( json_encode(['__do_curl_res'=>$return]) );

        return $return;
    }

    protected function __do_get_curl($url)
    {
        $ch = curl_init();
        // 设置URL和相应的选项
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HEADER, 0);

        curl_setopt($ch, CURLOPT_HTTPGET, true); //get

        // curl_setopt($ch, CURLOPT_POST, 1); //设置为POST方式
        // curl_setopt($ch, CURLOPT_HTTPHEADER, array('Expect:'));     // 发一个空的expect
        // curl_setopt($ch, CURLOPT_POSTFIELDS, $data);//POST数据
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        $responseText = curl_exec($ch);
        curl_close($ch);
        return $responseText;
    }


    /**
     * 限流
     * 用redis缓存做判断
     */
    protected function __before_buy()
    {
        $get = $this->request->get;
        $good = $this->__get_goods_by_id($get['id']);
        $this->log->put( json_encode(['__before_buy-good'=>$good]) );
        if ( $good && ($good['allow_num'] >= $get['good_num']) && (($good['number'] - $good['sell_number']) >= $get['good_num']) )
        {
            return true;
        }
        return false;
    }

    /**
     * 根据id查goods
     */
    protected function __get_goods_by_id($id)
    {

        $data = false;
        try
        {
            $data = $this->redis->hGetAll("goods:{$id}");
        } catch (Exception $e){
            $this->log->put( json_encode(['__get_goods_by_id_redis_error'=>$e->getMessage(),'id'=>$id]) );
            $this->__redis_init();
            $data = $this->redis->hGetAll("goods:{$id}");
            $this->log->put( json_encode(['__get_goods_by_id-good_again'=>$data, 'id'=>$id]) );
        }

        // $data = $this->redis->hGetAll("goods:{$id}");
        $this->log->put( json_encode(['__get_goods_by_id-good'=>$data, 'id'=>$id]) );
        if (!$data)
        {
            $data = $this->redis->hGetAll("goods:{$id}");
            $this->log->put( json_encode(['__get_goods_by_id-redis-good'=>$data]) );
        }
        return $data;
    }

}

$server = new HttpServer();
