<?php
    // http://localhost/xampp/jindon_analytics_output.php
    // 將TF-IDF排序，按每50個詞彙做一級距回塞MESSAGE_GROUP

    header ('Content-Type: text/html; charset=utf8');

    set_time_limit(0);  // 執行時間調成最大

    // 讀取資料庫相關函式
    require_once "jindon_db_func.php";
    $DB = new DB_Class;
    $conn = $DB->connect_db();
    
    $total_time_start = microtime(true);
    
    // 將TF-IDF的結果排序
    $sql = "SELECT CONCAT(WORD, '(', TYPE, ')') AS WORD_TYPE, Total 
              FROM `message_tfidf`
             WHERE TYPE NOT IN ('T','FW','M','ADV','DET','P','ASP', 'C','POST')
               AND WORD NOT IN ('你','您','你們','您們','我','我們','他','她','它','他們','她們', '它們')
            UNION
            SELECT CONCAT(WORD, '(', TYPE, ')') AS WORD_TYPE, Total
              FROM `message_tfidf`
             WHERE TYPE = 'FW'
               AND LENGTH(WORD) > 1
             ORDER BY Total DESC";
    $res = $conn->query($sql);
    $tfidf_array = $res->fetchAll();

    // 先清空所有級距統計結果
    $sql = "UPDATE message_group
               SET LV01 = 0, LV02 = 0, LV03 = 0, LV04 = 0, LV05 = 0, LV06 = 0, LV07 = 0, LV08 = 0, LV09 = 0, LV10 = 0";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    
    // 取出全部貼文斷詞後的結果
    $res = $conn->query("SELECT * FROM message_group WHERE LENGTH(CKIP_WORD) > 0");
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
                $sql = "UPDATE message_group
                           SET LV01 = :LV01, LV02 = :LV02, LV03 = :LV03, LV04 = :LV04, LV05 = :LV05,
                               LV06 = :LV06, LV07 = :LV07, LV08 = :LV08, LV09 = :LV09, LV10 = :LV10
                         WHERE MSG_ID = :MSG_ID";
                $bind = array(':MSG_ID' => $row['MSG_ID'], 
                              ':LV01' => $lv_array['LV01'], ':LV02' => $lv_array['LV02'], ':LV03' => $lv_array['LV03'], ':LV04' => $lv_array['LV04'], ':LV05' => $lv_array['LV05'],
                              ':LV06' => $lv_array['LV06'], ':LV07' => $lv_array['LV07'], ':LV08' => $lv_array['LV08'], ':LV09' => $lv_array['LV09'], ':LV10' => $lv_array['LV10'],
                              );
                $stmt = $conn->prepare($sql);
                $stmt->execute($bind);
                
                unset($lv_array);
                
                echo "[{$count}]已計算完前五百個詞彙的出現次數，換下一則貼文<BR>";
                ob_flush();
                flush();
                
                continue 2;
            }
        }
    }   // end of "foreach ($all_msg_array as $row)
    
    $total_time_end = microtime(true);
    $total_time = $total_time_end - $total_time_start;
    echo "<BR>㊣ 全部所花時間: {$total_time} 秒, 總筆數: {$count} ㊣";
?>