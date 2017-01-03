<?php

    header ('Content-Type: text/html; charset=utf8');

    set_time_limit(0);      // 執行時間調成最大
    $time_out = 0.5;        // 等待時間
    $debug = true;          // 是否顯示除錯內容
    
    // Facebook 相關函式(程式主體在169行之後) //
    class Facebook_Class
    {
        public function __construct()
        {
            // do nothing
        }
            
        // 粉絲團ID陣列
        public $fan_group_id_array = array(
                      'ASAP'      => '1416143505266994',  // ASAP
/*
                      'momomall'  => '262668030599852',   // momo摩天商城
                      'momoshop'  => '165449553430',      // momo購物網
                      'pchome24h' => '203507002993446',   // pchome 24H購物
                      'udn'       => '157300877661791',   // udn買東西
                      'friday'    => '276525585887092',   // friday 購物
                      '86shop'    => '147753419266',      // 86小鋪
                      'YAHOOmall' => '131299713627429',   // YAHOO超級商城
                      'YAHOObuy'  => '164121763623843',   // YAHOO購物中心
                      'GoHappy'   => '114978071133',      // GOHAPPY線上快樂購
                      'PayEasy'   => '177718072239534',   // PayEasy女性購物
                      'ETmall'    => '555089297837415',   // 東森購物網
                      'u-mall'    => '147594428593358',   // 森森購物網
                      '7net'      => '144510778909181',   // 7net
*/
                     );
        
        // unicode編碼轉utf8
        private function unicode2utf8($str)
        {
            if(!$str) return $str;
            
            $decode = json_decode($str);
            
            if($decode) return $decode;
            
            $str = '["' . $str . '"]';
            
            $decode = json_decode($str);
            
            if(count($decode) == 1)
            {
                return $decode[0];
            }
            return $str;
        }

        // 取得access_token，輸出結果: access_token==573948802740882|OTdpA6Ua0q_wiwyZfiO5_lQFixY
        // 參考網址 : http://ichich2015.pixnet.net/blog/post/247803484-0009-0-facebook-app-php-sdk%EF%BC%8Cgraph-api-%EF%BC%8Capp-access-token
        public function get_access_token()
        {
            $app_id = '573948802740882';
            $app_secret = '599685c31fa50774ae79efc9928ad102';

            $token_url = "https://graph.facebook.com/oauth/access_token?" .
            "client_id=" . $app_id .
            "&client_secret=" . $app_secret .
            "&grant_type=client_credentials";

            $access_token = file_get_contents($token_url);

            return $access_token;
        }

        // 取得粉絲團貼文，因為取後續貼文可能取不到資料，故不在此做json_decode
        public function get_data($url)
        {
            $ch = curl_init();

            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_HEADER, 0);
            curl_setopt($ch, CURLOPT_POST, 0);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);

            $result = curl_exec($ch);

            curl_close($ch);
            
            return $result;
        }

        // 處理取得貼文內容，僅取json轉換過的出參值['posts']['data']內容至DB與文字檔
        public function ins_data($data, $fan_group_id, $fan_group_name = '', $debug = false)
        {
            date_default_timezone_set('Asia/Taipei');

            foreach ($data as $row)
            {
                // 取得"分享數"
                if (array_key_exists('shares', $row))
                {
                    $shares_count = $row['shares']['count'];
                }
                else
                {
                    $shares_count = 0;
                }

                // 取得"留言數"
                if (array_key_exists('comments', $row))
                {
                    $comments_count = $row['comments']['summary']['total_count'];
                }
                else
                {
                    $comments_count = 0;
                }
                
                if (array_key_exists('message', $row))
                {   
                    // 貼文的換行符號取代成"###"，以便儲存與後續斷詞使用
                    $ary_phase = array("\r\n","\r","\n");
                    $message = str_replace($ary_phase,"###",$this->unicode2utf8($row['message']));
                    //$message = $this->unicode2utf8($row['message']);
                }
                else
                {
                    $message = "(沒有東西)";
                }
                
                // 取得"按讚數"
                if (array_key_exists('likes', $row))
                {
                    $likes_count = $row['likes']['summary']['total_count'];
                }
                else
                {
                    $likes_count = 0;
                }
                
                // 貼文創建時間格式轉換(需轉換成台北時間)
                $created_time = date('Y/m/d H:i:s', strtotime($row['created_time']));
                
                // Log內容排序: "文章ID,創建時間,分享數,按讚數,留言數,貼文內容\n"
                $msg = $row['id'] . "," . 
                       $created_time . "," . 
                       sprintf("%5s", $shares_count) . "," .  
                       sprintf("%5s", $likes_count) . "," . 
                       sprintf("%5s", $comments_count) . "," . 
                       $message . "\r\n";
                                
                if ($debug)
                {
                    echo '文章ID: '.$row['id']."<br/><br/>";
                    echo '貼文: <br>'.$message."<br><br/>";
                    echo '分享數: '.$shares_count. "<br/>";
                    echo '按讚數: '.$likes_count. "<br/>";
                    echo '留言數: '.$comments_count. "<br/>";
                    echo '創建時間: '.$created_time. "<br/>";
                    echo "==============================================================================<br/>";
                }
            }
            $conn = null;
            
            return true;
        }
    }   // end of class Facebook_Class

    // http://localhost/xampp/jindon_get_facebook_msg.php
    // 呼叫Facebook Graph API取得臉書粉絲團貼文內容，將其結果寫至資料庫與文字檔內
    // 看單看貼文的URL組合方法: https://www.facebook.com/{$fan_group_id}/posts/{$msg_id}

    // 讀取FB相關函式
    $FB = new Facebook_Class;

    // 取得access_token
    $access_token = $FB->get_access_token();
    
    // 取得粉絲團ID陣列
    $id_array = $FB->fan_group_id_array;

    // 依照id_array的ID，依序取出貼文內容
    foreach ($id_array as $key => $id)
    {
        // 取粉絲團的貼文，留言數與按讚數直接加總
        $graph_url = "https://graph.facebook.com/{$id}/?fields=posts{comments.limit(1).summary(true),likes.limit(1).summary(true),shares,message,picture}&limit=1000&{$access_token}";
        
        if ($debug)
        {
            echo $graph_url."<br/>";
            echo "==============================================================================<br/>";
        }

        $result = json_decode($FB->get_data($graph_url), true);
        $count = count($result['posts']['data']); // 計算取得粉絲團貼文筆數

        // 取得粉絲團貼文資料(第一次)
        $res = $FB->ins_data($result['posts']['data'], $id, $key, $debug);
        
        // 資料庫已有資料，程序中斷
        if ($res === false)
        {
            break;
        }

        // 休眠
        sleep($time_out);

        // 取得後續分頁的貼文，後續的貼文入參僅要傳['data']進來就好，免['post']
        // 將取後續分頁的上限由25筆改為250筆
        $next_url = str_replace('limit=25','limit=250', $result['posts']['paging']['next']);
        do
        {
            $child_result = $FB->get_data($next_url);
            echo "再取貼文:{$next_url}<br/>";

            $next_url = ''; // 下一頁貼文的網址清空，若貼文到底就中斷跳出
            if ($child_result === false)
            {
                break;
            }
            else
            {
                $child_result = json_decode($child_result, true);
                if (isset($child_result['data']))
                {
                    $count += count($child_result['data']);


                    // 取粉絲團較早的貼文
                    $res = $FB->ins_data($child_result['data'], $id, $key, $debug);
                    
                    // 資料庫已有資料，程序中斷
                    if ($res === false)
                    {
                        break;
                    }

                    if (isset($child_result['paging']['next']))
                    {
                        $next_url = $child_result['paging']['next'];
                    }
                    else
                    {
                        $next_url = '';
                    }
                    
                    // 休眠
                    Sleep($time_out);
                }
            }
        } while ($next_url != '');
        
        echo "===== {$key} 的最後一篇，共 {$count} 筆 =====<br/><br/>";
    } // end of foreach
    
?>

