<?php
    // http://localhost/xampp/jindon_get_facebook_msg.php
    // 呼叫Facebook Graph API取得臉書粉絲團貼文內容，將其結果寫至資料庫與文字檔內
    // 看單看貼文的URL組合方法: https://www.facebook.com/{$fan_group_id}/posts/{$msg_id}
    
    header ('Content-Type: text/html; charset=utf8');

    set_time_limit(0);      // 執行時間調成最大
    $time_out = 0.5;        // 等待時間
    $debug = true;          // 是否顯示除錯內容

    // 讀取FB相關函式
    require_once "jindon_fb_func.php";
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