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
            'worker_num' => 10,
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
        $this->__table_init();

        $this->__seckill_goods_init();
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
        require "./lib/redis.php";
        $this->redis = \db\Redis::getInstance($this->config['redis']);
        // $this->redis = new Redis();
        // $this->redis->pconnect('127.0.0.1', 6379, '0.5');
        // require __DIR__.'/async_redis.php';
        // $this->redis = new Swoole\Async\RedisClient('127.0.0.1',  6379);


        // $this->redis->subscribe(array('add_seckill_goods'), array("HttpServer","__subscribe_func"));
    }

    /**
     * redis 订阅秒杀商品添加
     * 还是换成http请求的方式吧
     * 一直报错: PHP Fatal error:  Uncaught exception 'RedisException' with message 'read error on connection' in /Users/zhangchao/www/seckill_queue/http_server.php:58
Stack trace:
     */
    static function __subscribe_func($redis, $chan, $msg)
    {
        $this->log->put( json_encode(['__subscribe_func'=>['chan'=>$chan, 'msg'=>$msg]]) );
        switch ($chan) 
        {
            case 'add_seckill_goods':
                $good = json_decode($msg, true);
                $res = $this->table->set($good['id'], $good);
                $this->log->put( json_encode(['__subscribe_func'=>['good'=>$good, 'res'=>$res]]) );
                break;
            
            default:
                # code...
                break;
        }
    }

    // 内存表初始化
    protected function __table_init()
    {
        $this->table = new swoole_table(1024);
        $this->table->column('id', swoole_table::TYPE_INT);
        $this->table->column('number', swoole_table::TYPE_INT);
        $this->table->column('allow_num', swoole_table::TYPE_INT);
        $this->table->column('sell_number', swoole_table::TYPE_INT);
        $this->table->column('start_time', swoole_table::TYPE_STRING, 64);
        $this->table->create();
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
                }
                break;

            case "/notice/add_goods":

                $return = $this->__notice_add_goods();
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
        $good = $this->table->get( $get['id'] );
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

        $good['sell_number'] += $get['good_num'];
        /** 事务性处理 **/
        $this->table->lock();
        $res = $this->table->set($get['id'], $good);
        $this->table->unlock();
        $this->log->put( json_encode(['__do_buy_res'=>$res]) );

        if ($res) 
        {
            // 第一种:返回购买业务的url,由前端请求
            $url = $this->config['seckill']['goods_buy'];
            // $return['url'] = $url . "?" . http_build_query($get);
            // $return['msg'] = "";
            // return $return;

            // // 第二种:直接请求购买业务, 返回结果
            // require './lib/curl.php';
            // $curl = new CURL();
            // $return = $curl->get( $url . "?" . http_build_query($get) );
            $return = $this->__do_curl( $url . "?" . http_build_query($get) );
            $this->log->put( json_encode(['__do_curl_res'=>$return]) );

            // 购买失败的回滚
            $buy_res = json_decode($return, true);
            if ($buy_res['code'] != '00')
            {
                /** 事务性处理 **/
                $good['sell_number'] -= $get['good_num'];
                $this->table->lock();
                $back_res = $this->table->set($get['id'], $good);
                $this->table->unlock();
                $this->log->put( json_encode(['__do_buy_back'=>$back_res]) );
            }
        }

        return $return;
    }

    protected function __do_curl($url)
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
        $data = $this->table->get($id);
        // $data = $this->redis->hGetAll("goods:{$id}");
        $this->log->put( json_encode(['__get_goods_by_id-good'=>$data, 'id'=>$id]) );
        if (!$data)
        {
            $data = $this->redis->hGetAll("goods:{$id}");
            $this->log->put( json_encode(['__get_goods_by_id-redis-good'=>$data]) );
            if ($data)
            {
                $res = $this->table->set($id, $data);
                $this->log->put( json_encode(['__get_goods_by_id-set-good'=>$res]) );
            }
        }
        return $data;
    }

    /**
     * 初始化秒杀商品数据
     * 由 redis 到 table
     */

    protected function __seckill_goods_init()
    {
        $key = 'seckill_goods_id';
        // $list_size = $this->redis->lSize($key);
        $list_size = $this->redis->hLen($key);
        if ( $list_size <= 0 )
        {
            return true;
        }

        $goods_id = $this->redis->hGetAll($key);
        foreach ($goods_id as $key => $value) 
        {
            $this->__get_goods_by_id($value);
        }

        // $start = 0;
        // $end = 20;

        // while ( count($this->table) < $list_size ) 
        // {
        //     $lrange_data = $this->redis->lRange($key, $start, $end);
        //     foreach ($lrange_data as $key => $value) 
        //     {
        //         $this->__get_goods_by_id($value);
        //     }
        //     $start += $end;
        //     $end += $end;
        // }

        return true;
    }

}

$server = new HttpServer();
