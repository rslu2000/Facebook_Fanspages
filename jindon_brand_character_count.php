<?php
    // http://localhost/xampp/jindon_brand_character_count.php
    // 各粉絲團的品牌個性計算，結果寫入文字檔

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
    
    // 品牌個性五大向度
    $bc_array = array('COMPETENCE','EXCITEMENT','RUGGEDNESS','SINCERITY','SOPHISTICATION');
    
    $count = 0;

    // 取出品牌個性的關鍵字
    $bc_disctionary = array();
    foreach ($bc_array as $bc)
    {
        $sql = "SELECT WORD FROM brand_character_dictionary
                 WHERE TYPE = '{$bc}'";
        $res = $conn->query($sql);
        $bc_disctionary[$bc] = $res->fetchAll();
    }

    // 清空bc_count_result
    echo "清空bc_count_result<BR>";
    ob_flush();
    flush();
    $stmt = $conn->prepare("DELETE FROM bc_count_result");
    $stmt->execute();
    
    // 寫入bc_count_result的語法基本不變，僅改BIND的參數
    $ins_sql = "INSERT INTO bc_count_result (FAN_GROUP_NAME, BC_TYPE, WORD, COUNT) VALUES (:FAN_GROUP_NAME, :BC_TYPE, :WORD, :COUNT)";
    
    // 依各粉絲團各自統計品牌個性關鍵字出現次數
    $content = '';
    foreach ($id_array as $fan_group_name => $id)
    {
        // 讀取指定粉絲團的貼文，純圖片(貼文欄顯示'(沒有東西)'，長度14)與過短的不撈
        $sql = "SELECT MESSAGE
                  FROM message_group 
                 WHERE FAN_GROUP_ID = '{$id}'
                   AND LENGTH(MESSAGE) > 14";
        $res = $conn->query($sql);
        $message_array = $res->fetchAll();
        $message_str = serialize($message_array);
        $message_count = count($message_array);
        
        // 依品牌個性五大向度，個別計算字典檔關鍵字出現的次數
        $bc_count = array(
                      'COMPETENCE'      => 0,   // 稱職
                      'EXCITEMENT'      => 0,   // 刺激
                      'RUGGEDNESS'      => 0,   // 強壯
                      'SINCERITY'       => 0,   // 真誠
                      'SOPHISTICATION'  => 0    // 教養
                     );

        // 文字檔格式: (粉絲團名稱),'COMPETENCE','EXCITEMENT','RUGGEDNESS','SINCERITY','SOPHISTICATION',()貼文個數)
        $content .= $fan_group_name . ',';
        foreach ($bc_array as $bc)
        {
            foreach ($bc_disctionary[$bc] as $disctionary)
            {
                // 計算品牌個性關鍵字出現次數，並寫入bc_count_result資料表中
                $bc_word_count = substr_count($message_str, $disctionary['WORD']);
                $bc_count[$bc] += $bc_word_count;

                $bind = array(':FAN_GROUP_NAME' => $fan_group_name, ':BC_TYPE' => $bc, ':WORD' => $disctionary['WORD'], ':COUNT' => $bc_word_count);
                $stmt = $conn->prepare($ins_sql);
                $stmt->execute($bind);
                
                $count++;
            }
            $content .= $bc_count[$bc] . ',';
        }
        $content .= $message_count . "\r\n";
        echo $fan_group_name . " 貼文總筆數: {$message_count}<BR>";
        print_r($bc_count);
        echo "<BR>--------------------------------------------------------------<BR>";
        ob_flush();
        flush();
    }

    // 結果回寫至 J:\CountWordFreq\BC_REPORT.csv
    $report = "粉絲團,COMPETENCE,EXCITEMENT,RUGGEDNESS,SINCERITY,SOPHISTICATION,貼文總筆數\r\n"    . $content;
    $FB->write_data_to_logfile("BC_REPORT.csv", $report, 1);
    
    $total_time_end = microtime(true);
    $total_time = $total_time_end - $total_time_start;
    echo "<BR>㊣ 全部所花時間: {$total_time} 秒, 總筆數: {$count} ㊣";
?>