<?php
    /* 臉書相關函式class */

    class Facebook_Class
    {
        public function __construct()
        {
            // do nothing
        }
        
        // 粉絲團ID陣列
        public $fan_group_id_array = array(
                      'ASAP'      => '1416143505266994',  // ASAP
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
        
        // 把貼文資料寫到文字檔內(檔案名稱: 粉絲團名稱.csv)
        public function write_data_to_logfile($filename, $msg, $is_wordfreq = 0)
        {
            // 依傳入參數$is_wordfreq決定寫入檔案位置
            $path = '';
            if ($is_wordfreq == 0)
            {
                $filename .= ".txt";
                $path = "J:\\fb_log\\";
            }
            else
            {
                $path = "J:\\CountWordFreq\\";
            }
            $filepath = "{$path}{$filename}";
            $fp = fopen($filepath, "a+");
            
            // 若$is_wordfreq為1時，文字編碼轉成BIG5再寫入文字檔，詞頻程式處理才不會出現亂碼
            if ($is_wordfreq == 0)
            {
                fwrite($fp, $msg);
            }
            else
            {
                fwrite($fp, iconv("utf-8","big5//TRANSLIT//IGNORE", $msg));
            }
            
            
            fclose($fp);
            
            // 清除緩存並再次檢查文件大小
            //clearstatcache();
        }
        
        // 處理取得貼文內容，僅取json轉換過的出參值['posts']['data']內容至DB與文字檔
        public function ins_data($data, $fan_group_id, $fan_group_name = '', $debug = false)
        {
            // 讀取資料庫相關函式
            require_once "jindon_db_func.php";
            $DB = new DB_Class;
            $conn = $DB->connect_db();
            
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
                
                // 新增資料至資料庫
                $bind = array(':MSG_ID'         => $row['id'],
                              ':FAN_GROUP_ID'   => $fan_group_id, 
                              ':FAN_GROUP_NAME' => $fan_group_name, 
                              ':CREATED_TIME'   => $created_time, 
                              ':SHARES_COUNT'   => $shares_count, 
                              ':LIKES_COUNT'    => $likes_count, 
                              ':COMMENTS_COUNT' => $comments_count, 
                              ':MESSAGE'        => $message);

                $res = $DB->insert_fb_data($conn, $bind);
                if ($res === true)
                {
                    // 查詢結果寫入CSV檔
                    $this->write_data_to_logfile($fan_group_name, $msg, 0);
                }
                else
                {
                    // 資料庫已有貼文ID的話表示後續都是較舊的貼文，故直接中斷程序
                    // 20150515 改成continue
                    //continue;
                    break;
                }
                
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
            $DB = null;
            
            return true;
        }
    }
?>