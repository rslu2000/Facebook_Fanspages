<?php
    // http://localhost/xampp/jindon_analysis_output_only_7net.php
    // 特別版本(以7net的粉絲專頁做標準，分析全部貼文是否為業配文)
    // 將TF-IDF排序，按每100個詞彙做一級距回塞{$table_name}

    header ('Content-Type: text/html; charset=utf8');

    set_time_limit(0);  // 執行時間調成最大
    ini_set("memory_limit","2048M");   // 調整記憶體大小

    // 關鍵變數
    $table_name = 'analysis_output_7net';   //寫入TABLE名稱
    $fan_group_name = '7net';               //粉絲團名稱
    
    // 讀取資料庫相關函式
    require_once "jindon_db_func.php";
    $DB = new DB_Class;
    $conn = $DB->connect_db();

    $total_time_start = microtime(true);
    
    $count = 0;

if(1){  // 清除原本analysys_output，計算TF-IDF寫回資料表
    // 指定抓7net粉絲專頁中TF-IDF高的資料
    echo "清除原本{$table_name}<BR>";
    $sql = "DELETE FROM {$table_name}";
    $stmt = $conn->prepare($sql);
    $stmt->execute();

    // 將TF-IDF的結果排序
    // 排除特定詞類，長度為1的詞彙排除
    $sql = "SELECT REPLACE(CONCAT(WORD, '(', TYPE, ')'), '`', '') AS WORD_TYPE, {$fan_group_name}
              FROM `message_tfidf` 
             WHERE {$fan_group_name} > 0 
               AND TYPE NOT IN ('T','FW','M','ADV','DET','P','ASP', 'C','POST')
               AND WORD NOT IN ('你','您','你們','您們','我','我們','他','她','它','他們','她們','它們','是','有')
            UNION
            SELECT CONCAT(WORD, '(', TYPE, ')') AS WORD_TYPE, Total
              FROM `message_tfidf`
             WHERE {$fan_group_name} > 0 
               AND TYPE = 'FW'
               AND LENGTH(WORD) > 1
          ORDER BY {$fan_group_name} DESC"

    $res = $conn->query($sql);
    $tfidf_array = $res->fetchAll();

    // 取出全部貼文斷詞後的結果，排除CHAR_LENGTH(CKIP_WORD)=3 (全形空白=沒有斷詞)的句子
    // 排除分享數、按讚數最高的前20個(MYSQL不支援SUBQUERY的LIMIT語法，只好拆成兩段處理)
    $res = $conn->query("SELECT MSG_ID
                           FROM message_group 
                          WHERE CHAR_LENGTH(CKIP_WORD) > 3
                            AND FAN_GROUP_NAME = '{$fan_group_name}'
                          ORDER BY SHARES_COUNT DESC LIMIT 20");
    $top_shares_array = $res->fetchAll();

    $res = $conn->query("SELECT MSG_ID
                           FROM message_group 
                          WHERE CHAR_LENGTH(CKIP_WORD) > 3
                            AND FAN_GROUP_NAME = '{$fan_group_name}'
                          ORDER BY LIKES_COUNT DESC LIMIT 20");
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
    
    // 去掉最後的逗點
    $not_in_str =  substr($not_in_str, 0, -1);

    $res = $conn->query("SELECT * FROM message_group 
                          WHERE CHAR_LENGTH(CKIP_WORD) > 3
                            AND FAN_GROUP_NAME = '{$fan_group_name}'
                            AND MSG_ID NOT IN ('{$not_in_str}')");
    $all_msg_array = $res->fetchAll();

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
        }
        return;
    }   // end of "foreach ($all_msg_array as $row)

}

    // 將分享數與按讚數標準化

    echo "[{$fan_group_name}]將分享數與按讚數標準化<BR>";
    
    // 取得每個粉絲團最大與最小的lv01~Lv10、分享數與按讚數
    $sql = "SELECT MAX(LV01) AS MAX_LV01, MIN(LV01) AS MIN_LV01,
                   MAX(LV02) AS MAX_LV02, MIN(LV02) AS MIN_LV02,
                   MAX(LV03) AS MAX_LV03, MIN(LV03) AS MIN_LV03,
                   MAX(LV04) AS MAX_LV04, MIN(LV04) AS MIN_LV04,
                   MAX(LV05) AS MAX_LV05, MIN(LV05) AS MIN_LV05,
                   MAX(LV06) AS MAX_LV06, MIN(LV06) AS MIN_LV06,
                   MAX(LV07) AS MAX_LV07, MIN(LV07) AS MIN_LV07,
                   MAX(LV08) AS MAX_LV08, MIN(LV08) AS MIN_LV08,
                   MAX(LV09) AS MAX_LV09, MIN(LV09) AS MIN_LV09,
                   MAX(LV10) AS MAX_LV10, MIN(LV10) AS MIN_LV10,
                   MAX(SHARES_COUNT) AS MAX_SHARES_CNT,
                   MIN(SHARES_COUNT) AS MIN_SHARES_CNT,
                   MAX(LIKES_COUNT) AS MAX_LIKES_CNT,
                   MIN(LIKES_COUNT) AS MIN_LIKES_CNT
              FROM {$table_name}
             WHERE FAN_GROUP_NAME = '{$fan_group_name}'";
    $res = $conn->query($sql);

    $value = $res->fetch();

    // 標準化 = (欄位數字 - 最小值 / 最大值 - 最小值) * 100
    $update_sql = "UPDATE {$table_name} AO
                      SET AO.STD_LV01 = (AO.LV01 - CONV({$value['MIN_LV01']}, 10 ,10)) / ({$value['MAX_LV01']} - {$value['MIN_LV01']}),
                          AO.STD_LV02 = (AO.LV02 - CONV({$value['MIN_LV02']}, 10 ,10)) / ({$value['MAX_LV02']} - {$value['MIN_LV02']}),
                          AO.STD_LV03 = (AO.LV03 - CONV({$value['MIN_LV03']}, 10 ,10)) / ({$value['MAX_LV03']} - {$value['MIN_LV03']}),
                          AO.STD_LV04 = (AO.LV04 - CONV({$value['MIN_LV04']}, 10 ,10)) / ({$value['MAX_LV04']} - {$value['MIN_LV04']}),
                          AO.STD_LV05 = (AO.LV05 - CONV({$value['MIN_LV05']}, 10 ,10)) / ({$value['MAX_LV05']} - {$value['MIN_LV05']}),
                          AO.STD_LV06 = (AO.LV06 - CONV({$value['MIN_LV06']}, 10 ,10)) / ({$value['MAX_LV06']} - {$value['MIN_LV06']}),
                          AO.STD_LV07 = (AO.LV07 - CONV({$value['MIN_LV07']}, 10 ,10)) / ({$value['MAX_LV07']} - {$value['MIN_LV07']}),
                          AO.STD_LV08 = (AO.LV08 - CONV({$value['MIN_LV08']}, 10 ,10)) / ({$value['MAX_LV08']} - {$value['MIN_LV08']}),
                          AO.STD_LV09 = (AO.LV09 - CONV({$value['MIN_LV09']}, 10 ,10)) / ({$value['MAX_LV09']} - {$value['MIN_LV09']}),
                          AO.STD_LV10 = (AO.LV10 - CONV({$value['MIN_LV10']}, 10 ,10)) / ({$value['MAX_LV10']} - {$value['MIN_LV10']}),
                          AO.STD_SHARES_COUNT = (AO.SHARES_COUNT - CONV({$value['MIN_SHARES_CNT']}, 10 ,10)) / ({$value['MAX_SHARES_CNT']} - {$value['MIN_SHARES_CNT']}),
                          AO.STD_LIKES_COUNT  = (AO.LIKES_COUNT  - CONV({$value['MIN_LIKES_CNT']} , 10, 10)) / ({$value['MAX_LIKES_CNT']}  - {$value['MIN_LIKES_CNT']}),
                          AO.STD_LIKES_LEVEL = CASE WHEN STD_LIKES_COUNT = 1 THEN 10
                                                 WHEN STD_LIKES_COUNT < 1   AND STD_LIKES_COUNT >= 0.9 THEN 9
                                                 WHEN STD_LIKES_COUNT < 0.9 AND STD_LIKES_COUNT >= 0.8 THEN 8
                                                 WHEN STD_LIKES_COUNT < 0.8 AND STD_LIKES_COUNT >= 0.7 THEN 7
                                                 WHEN STD_LIKES_COUNT < 0.7 AND STD_LIKES_COUNT >= 0.6 THEN 6
                                                 WHEN STD_LIKES_COUNT < 0.6 AND STD_LIKES_COUNT >= 0.5 THEN 5
                                                 WHEN STD_LIKES_COUNT < 0.5 AND STD_LIKES_COUNT >= 0.4 THEN 4
                                                 WHEN STD_LIKES_COUNT < 0.4 AND STD_LIKES_COUNT >= 0.3 THEN 3
                                                 WHEN STD_LIKES_COUNT < 0.3 AND STD_LIKES_COUNT >= 0.2 THEN 2
                                                 WHEN STD_LIKES_COUNT < 0.2 AND STD_LIKES_COUNT >= 0.1 THEN 1
                                                 WHEN STD_LIKES_COUNT < 0.1 AND STD_LIKES_COUNT >= 0   THEN 0
                                               END,
                          AO.STD_SHARES_LEVEL = CASE WHEN STD_SHARES_COUNT = 1 THEN 10
                                                 WHEN STD_SHARES_COUNT < 1   AND STD_SHARES_COUNT >= 0.9 THEN 9
                                                 WHEN STD_SHARES_COUNT < 0.9 AND STD_SHARES_COUNT >= 0.8 THEN 8
                                                 WHEN STD_SHARES_COUNT < 0.8 AND STD_SHARES_COUNT >= 0.7 THEN 7
                                                 WHEN STD_SHARES_COUNT < 0.7 AND STD_SHARES_COUNT >= 0.6 THEN 6
                                                 WHEN STD_SHARES_COUNT < 0.6 AND STD_SHARES_COUNT >= 0.5 THEN 5
                                                 WHEN STD_SHARES_COUNT < 0.5 AND STD_SHARES_COUNT >= 0.4 THEN 4
                                                 WHEN STD_SHARES_COUNT < 0.4 AND STD_SHARES_COUNT >= 0.3 THEN 3
                                                 WHEN STD_SHARES_COUNT < 0.3 AND STD_SHARES_COUNT >= 0.2 THEN 2
                                                 WHEN STD_SHARES_COUNT < 0.2 AND STD_SHARES_COUNT >= 0.1 THEN 1
                                                 WHEN STD_SHARES_COUNT < 0.1 AND STD_SHARES_COUNT >= 0   THEN 0
                                               END
                    WHERE AO.FAN_GROUP_NAME = '{$fan_group_name}'";
    $stmt = $conn->prepare($update_sql);
    $stmt->execute();

    $total_time_end = microtime(true);
    $total_time = $total_time_end - $total_time_start;
    echo "<BR>㊣ 全部所花時間: {$total_time} 秒, 總筆數: {$count} ㊣";
?>