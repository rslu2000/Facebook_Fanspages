<?php
    // http://localhost/xampp/jindon_analytics_output_v2.php
    // V2版本，各自粉絲團各自計算，寫至各自的TABLE
    // 將TF-IDF排序，按每50個詞彙做一級距回塞$table_name (預設是analysis_output)

    header ('Content-Type: text/html; charset=utf8');

    set_time_limit(0);  // 執行時間調成最大
    ini_set("memory_limit","2048M");   // 調整記憶體大小
    
    // 讀取資料庫相關函式
    require_once "jindon_db_func.php";
    $DB = new DB_Class;
    $conn = $DB->connect_db();
    
    // 讀取FB相關函式，取得粉絲團ID
    require_once "jindon_fb_func.php";
    $FB = new Facebook_Class;
    $id_array = $FB->fan_group_id_array;
    
    $total_time_start = microtime(true);
    
    //$table_name = str_replace("-", "", "analysis_output_{$fan_group_name}"); // analysis_output_u-mall寫入會有問題，強制改成analysis_output_umall
    $table_name = "analysis_output";

    echo "清除原本{$table_name}<BR>";
    $sql = "DELETE FROM {$table_name}";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    
    // 清除原本analysys_output，計算TF-IDF寫回資料表
if(1){
    foreach ($id_array as $fan_group_name => $data)
    {
        // 將TF-IDF的結果排序
        // 排除特定詞類，長度為1的詞彙排除
        $sql = "SELECT REPLACE(CONCAT(WORD, '(', TYPE, ')'), '`', '') AS WORD_TYPE, `{$fan_group_name}`
                  FROM `message_tfidf`
                 WHERE TYPE NOT IN ('T','FW','M','ADV','DET','P','ASP', 'C','POST')
                   AND WORD NOT IN ('你','您','你們','您們','我','我們','他','她','它','他們','她們', '它們')
                UNION
                SELECT CONCAT(WORD, '(', TYPE, ')') AS WORD_TYPE, `{$fan_group_name}`
                  FROM `message_tfidf`
                 WHERE TYPE = 'FW'
                   AND LENGTH(WORD) > 1
                 ORDER BY `{$fan_group_name}` DESC";
        $res = $conn->query($sql);
        $tfidf_array = $res->fetchAll();

        // 取出全部貼文斷詞後的結果，排除CHAR_LENGTH(CKIP_WORD)=3 (全形空白=沒有斷詞)的句子
        // 排除分享數、按讚數最高的前n個(MYSQL不支援SUBQUERY的LIMIT語法，只好拆成兩段處理)
        $n = 200;
        $res = $conn->query("SELECT MSG_ID
                               FROM message_group 
                              WHERE CHAR_LENGTH(CKIP_WORD) > 3
                                AND FAN_GROUP_NAME = '{$fan_group_name}'
                              ORDER BY SHARES_COUNT DESC LIMIT {$n}");
        $top_shares_array = $res->fetchAll();

        $res = $conn->query("SELECT MSG_ID
                               FROM message_group 
                              WHERE CHAR_LENGTH(CKIP_WORD) > 3
                                AND FAN_GROUP_NAME = '{$fan_group_name}'
                              ORDER BY LIKES_COUNT DESC LIMIT {$n}");
        $top_likes_array = $res->fetchAll();

        $not_in_str = '';
        foreach($top_shares_array as $row)
        {
            $not_in_str .= "{$row['MSG_ID']},";
        }
        foreach($top_likes_array as $row)
        {
            $not_in_str .= "{$row['MSG_ID']},";
        }

        $not_in_str =  substr($not_in_str, 0, -1);  // 去掉最後的逗點

        // 取出全部貼文斷詞後的結果
        $res = $conn->query("SELECT * FROM message_group 
                              WHERE CHAR_LENGTH(CKIP_WORD) > 3
                                AND FAN_GROUP_NAME = '{$fan_group_name}'
                                AND MSG_ID NOT IN ('{$not_in_str}')");
        $all_msg_array = $res->fetchAll();

        $count = 0;
        foreach ($all_msg_array as $row)
        {
            $level_count = 0;
            $lv_array = array();
            foreach ($tfidf_array as $key => $word)
            {
                // 計算詞彙出現次數
                $level_count += substr_count($row['CKIP_WORD'], $word['WORD_TYPE']);
                
                //echo "[{$key}] 詞彙: {$word['WORD_TYPE']} 累積次數: {$level_count}<br>";
                
                // 每五十個詞彙就將累積出現次數寫入資料庫
                if (($key+1) % 50 === 0)
                {
                    $lv = sprintf("LV%02s", ($key+1) / 50);
                    //echo "{$row['MSG_ID']} 的前 {$lv} 的級距為 {$level_count} <BR>";
                    
                    $lv_array[$lv] = $level_count;
                    
                    // 詞彙出現次數歸零
                    $level_count = 0;
                }
                else if ($key >= 500)
                {
                    $count++;
                    
                    // 每五十個字一個級距，寫入DB
                    $sql = "INSERT INTO {$table_name} (FAN_GROUP_NAME, MSG_ID, LV01, LV02, LV03, LV04, LV05, LV06, LV07, LV08, LV09, LV10, SHARES_COUNT, LIKES_COUNT) VALUES
                                                        (:FAN_GROUP_NAME, :MSG_ID, :LV01, :LV02, :LV03, :LV04, :LV05, :LV06, :LV07, :LV08, :LV09, :LV10, :SHARES_COUNT, :LIKES_COUNT)";
                    $bind = array(':FAN_GROUP_NAME' => $row['FAN_GROUP_NAME'], ':MSG_ID' => $row['MSG_ID'], ':SHARES_COUNT' => $row['SHARES_COUNT'], ':LIKES_COUNT' => $row['LIKES_COUNT'],
                                  ':LV01' => $lv_array['LV01'], ':LV02' => $lv_array['LV02'], ':LV03' => $lv_array['LV03'], ':LV04' => $lv_array['LV04'], ':LV05' => $lv_array['LV05'],
                                  ':LV06' => $lv_array['LV06'], ':LV07' => $lv_array['LV07'], ':LV08' => $lv_array['LV08'], ':LV09' => $lv_array['LV09'], ':LV10' => $lv_array['LV10'],
                                  );
                    $stmt = $conn->prepare($sql);
                    $stmt->execute($bind);
                    
                    unset($lv_array);
                    
                    echo "[{$fan_group_name}][{$count}]已計算完前五百個詞彙的出現次數，換下一則貼文<BR>";
                    ob_flush();
                    flush();
                    
                    continue 2;
                }
            }   // end of "foreach ($tfidf_array as $key => $word)"
        }   // end of "foreach ($all_msg_array as $row)"
    }   // end of "foreach ($id_array as $fan_group_name => $data)"
}   // end of "if(0)"
    $total_time_end = microtime(true);
    $total_time = $total_time_end - $total_time_start;
    echo "<BR>㊣ 全部所花時間: {$total_time} 秒, 總筆數: {$count} ㊣";
?>