<?php
    // http://localhost/xampp/jindon_count_tfidf_to_DB.php
    // 從資料庫取出已做過斷詞斷句的貼文，依粉絲團ID個別儲存文字檔供詞頻程式計算

    header ('Content-Type: text/html; charset=utf8');

    set_time_limit(0);  // 執行時間調成最大
    ini_set("memory_limit","2048M");   // 調整記憶體大小
    
    $do_wordfreq_into_db = 0;   // 詞頻程式重跑 + INSERT INTO MESSAGE_TF 控制旗標
    
    // 讀取FB相關函式
    require_once "jindon_fb_func.php";
    $FB = new Facebook_Class;

    // 讀取資料庫相關函式
    require_once "jindon_db_func.php";
    $DB = new DB_Class;
    $conn = $DB->connect_db();
    
    $total_time_start = microtime(true);
    
    // 撈取已做過斷詞後結果
    $result = $DB->query_do_ckip_word_by_fangroup_name($conn);
    $total_row = sprintf("%05s", count($result));
    echo "共有 ". $total_row . " 筆斷詞結果要轉出文字檔<BR>";
    ob_flush();
    flush();

    // 定義認定的詞類
    $word_type_array = array('A','C','POST','ADV','ASP','ADV','N','DET','M','Nv','P','T','Vi','Vt','FW');
    
    $ckip_cnt = 0;      // 斷詞處理次數
    $tf_to_db_cnt = 0;  // 詞頻塞進資料庫次數
    $tfidf_cnt = 0;     // TF-IDF計算筆數
    
    /* ins_tf_data出錯，DEBUG用
    $DB->ins_tf_data($conn, '%', 'M', '7net', '43');
    die();
    */
if($do_wordfreq_into_db){ // 清除先前做過的斷詞文字檔與詞頻檔案，並依序把資料庫中的斷詞結果依粉絲團名稱個別存成文字檔
    echo "清除先前做過的斷詞文字檔與詞頻檔案，並依序把資料庫中的斷詞結果依粉絲團名稱個別存成文字檔<BR>";
    system("del j:\\CountWordFreq\\*.txt");

    // 依序把斷詞結果依粉絲團名稱個別存成文字檔
    foreach ($result as $row)
    {
        // 各電商的斷詞結果
        $txt_name = 'tag_' . $row['FAN_GROUP_NAME'] . '.txt';
        $FB->write_data_to_logfile($txt_name, $row['CKIP_WORD'], 1);
        
        // 全部的斷詞結果
        $txt_allfile_name = 'tag_Total.txt';
        $FB->write_data_to_logfile($txt_allfile_name, $row['CKIP_WORD'], 1);
        
        $ckip_cnt++;
    }

    // 呼叫中研院詞頻計算程式，逐一產生各文字檔的詞頻計算結果
    foreach (glob("/CountWordFreq/tag_*") as $tf_file)
    {
        $dest_filename = str_replace("/CountWordFreq/tag_", "/CountWordFreq/freq_", $tf_file);
        
        echo "➨ 來源檔案 {$tf_file} 詞頻計算至 {$dest_filename}<BR>";
        
        // 呼叫中研院詞頻計算程式範例: CountWordFreq tag_momomall.txt freq_momomall_freq.txt 1
        system("j:\\CountWordFreq\CountWordFreq {$tf_file} {$dest_filename} 1");
        
        ob_flush();
        flush();
    }
}

if($do_wordfreq_into_db){ // 剔除異常詞頻結果，將詞頻結果回塞資料庫(原本資料庫內資料將被清除
    echo "剔除異常詞頻結果，將詞頻結果回塞資料庫(原本資料庫內資料將被清除)<BR>";
    
    // 清除資料庫內原本資料詞頻次數
    $DB->del_message_tf($conn);
    
    foreach (glob("/CountWordFreq/freq_*") as $freq_file)
    {
        $column = str_replace(array("/CountWordFreq/freq_",".txt"), "" , $freq_file);

        // 讀取檔案裡的內容
        $file_str = file_get_contents($freq_file);
        
        // 先讀取檔案，再偵測字串的編碼，再利用iconv和mb_convert_encoding來轉碼
        // 參考網頁: http://www.fase.idv.tw/blog/index.php?load=read&id=113
        $encoding = mb_detect_encoding($file_str, array('ASCII','EUC-CN','BIG-5','UTF-8','UCS-2LE'));
        if ($encoding != false)
        {
            $file_str = iconv($file_str, 'UTF-8', $contents);
        }
        else
        {
            $file_str = mb_convert_encoding($file_str, 'UTF-8','Unicode');
        }
        
        $line_ary = explode("\n", $file_str);
        
        foreach ($line_ary as $row)
        {
            if (strlen($row) > 0)
            {
                // 把詞頻結果轉成陣列: Array ( [0] => ﻿"(FW) [1] => 46 )
                $ary = explode("\t", $row);

                // 因斷詞結果可能為"("導致切字串出錯，因此使用"("的出現次數做條件處理
                $frame_cnt = substr_count($ary[0], '(');
                if ($frame_cnt === 1)
                {
                    $sub_ary = explode("(", $ary[0]);
                    $word = $sub_ary[0];                       // 詞彙
                    $type = str_replace(')', '', $sub_ary[1]); // 詞類
                }
                else if ($frame_cnt === 2)
                {
                    $word = '(';
                    $type = 'PARENTHESISCATEGORY';
                }
                else
                {
                    $err_msg = "{$column} 資料異常，斷詞內容【{$row}】 <BR>";
                    echo $err_msg;
                    $FB->write_data_to_logfile('error_log.txt', str_replace("<BR>", "\n", $err_msg), 1);
                    continue;
                }
                
                $tf = trim($ary[1]);                           // 詞頻統計
                
                // 若詞類是落落長的括弧(PARENTHESISCATEGORY)等符號則略過不處理
                // 若詞彙長度超過50則略過不處理
                // 若詞類是外文標記(FW)且非英文也不處理
                // 詞類若不是認定內的詞類也不處理
                if ( (strlen($type) > 4) || (strlen($word) > 50) ||
                     ($type == 'FW' && !preg_match("/^([0-9A-Za-z]+)$/", $word)) ||
                     !in_array($type, $word_type_array)
                   )
                {
                    $err_msg = "{$column} 詞彙:【{$word}】 詞類:【{$type}】 詞頻:【{$tf}】 不處理<BR>";
                    echo $err_msg;
                    $FB->write_data_to_logfile('error_log.txt', str_replace("<BR>", "\n", $err_msg), 1);
                    
                    ob_flush();
                    flush();

                    continue;
                }

                // 資料寫至資料庫 message_tf
                $DB->ins_tf_data($conn, $word, $type, $column, $tf);

                $tf_to_db_cnt++;
            }
        }
    }
}

    echo "清除TF-IDF原本資料，重新進行計算<BR>";
    
    // 清除資料庫內原本資料詞頻次數
    $DB->del_message_tfidf($conn);
    
    // 取得各粉絲團貼文數與全部貼文數
    $msg_cnt_array = $DB->query_message_cnt_by_fangroup_name($conn);

    // 撈取資料庫內的詞頻資料
    $result = $DB->query_tf_word($conn);
    
    foreach ($result as $message_tf)
    {
        // 取得有含詞彙的出現次數
        $word_type = str_replace('%','\%', $message_tf['WORD_TYPE']);  // 詞彙若為'%'，置換成\%，以便SQL可以運行
        $sql = "SELECT FAN_GROUP_NAME, COUNT(*) AS CNT FROM message_group WHERE CKIP_WORD LIKE '%{$word_type}%' GROUP BY FAN_GROUP_NAME";
        $res = $conn->query($sql);
        $row_cnt_array = $res->fetchAll();

        $row_total = 0;
        $one_word_tfidf = array();
        foreach ($row_cnt_array as $row_cnt)
        {
            if ($row_cnt['CNT'] == 0)
            {
                continue;
            }
            $tf_cnt = $message_tf[$row_cnt['FAN_GROUP_NAME']];  // 詞彙出現的次數

            // 計算TF => 詞彙出現的次數 / 粉絲團貼文中所有詞彙出現次數
            $tf = $tf_cnt / $msg_cnt_array[$row_cnt['FAN_GROUP_NAME']]['TF_SUM'];

            // 計算IDF => log( 總文檔數 / 包含詞的文檔數, 2)
            $idf = log( $msg_cnt_array[$row_cnt['FAN_GROUP_NAME']]['MSG_CNT'] / $row_cnt['CNT'] , 2);

            // 計算TF-IDF => TF * IDF
            $tf_idf = $tf * $idf;

            // 全部的結果記錄完後一次INSERT
            $one_word_tfidf[$row_cnt['FAN_GROUP_NAME']] = $tf_idf;
            
            $row_total += $row_cnt['CNT'];
        }

        if ($row_total == 0)
        {
            echo "{$message_tf['WORD']} 的總貼文出現次數為零，中斷<BR>";
            continue;
        }
        
        //計算詞彙於"全部"的TF-IDF
        $total_tf = $message_tf['Total'] / $msg_cnt_array['Total']['TF_SUM'];
        $total_idf = log( $msg_cnt_array['Total']['MSG_CNT'] / $row_total , 2);
        $total_tf_idf = $total_tf * $total_idf;

        $one_word_tfidf['Total'] = $total_tf_idf;
        
        // 資料寫至資料庫 message_tfidf
        $DB->ins_tfidf_data($conn, $message_tf['WORD'], $message_tf['TYPE'], $one_word_tfidf);
        //echo "Total TF={$total_tf} IDF={$total_idf} TF-IDF={$total_tf_idf}<BR>";

        $tfidf_cnt++;
    }

    $total_time_end = microtime(true);
    $total_time = $total_time_end - $total_time_start;
    echo "<BR>㊣ 全部所花時間: {$total_time} 秒，處理斷詞總筆數: {$ckip_cnt} 筆，處理詞頻總筆數: {$tf_to_db_cnt} 筆，TF-IDF計算筆數: {$tfidf_cnt} ㊣";
?>