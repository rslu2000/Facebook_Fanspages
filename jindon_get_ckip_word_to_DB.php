<?php
    // http://localhost/xampp/jindon_get_ckip_word_to_DB.php
    // 從資料庫取出未做過斷詞斷句的貼文，呼叫CKIP進行斷詞斷語，斷句結果再回寫至該筆記錄的CKIP_WORD

    header ('Content-Type: text/html; charset=utf8;');
    set_time_limit(0);      // 執行時間調成最大

    require_once "CKIPClient.php";
    $CKIP = new CKIPClient(
       "140.109.19.104",    // CKIP_SERVER
       1501,                // CKIP_PORT
       "dsleep99",          // CKIP_USERNAME
       "H10267102"          // CKIP_PASSWORD
    );

    // 讀取FB相關函式
    require_once "jindon_fb_func.php";
    $FB = new Facebook_Class;

    // 讀取資料庫相關函式
    require_once "jindon_db_func.php";
    $DB = new DB_Class;
    $conn = $DB->connect_db();
    
    // 20150416 改在CKIPClient line:79 在iconv()忽略缺碼字，但為了提昇斷詞速度，還是要過濾特殊符號
    // 特殊符號(不排除的話會造成utf8轉big5失敗)
    $special_symbols = array("✿",   //小花
                             "❤",   //黑色實心向右愛心
                             "➨",   //黑色實心向右箭頭(較粗)
                             "➜",   //黑色實心向右箭頭
                             "✧",   //菱形
                             "♡",   //白色空心愛心
                             "☛",   //黑色實心向右手指
                             "✨",   //白色空心方塊
                             "✜",   //黑色實心梅花
                             "　","～","【","】","★","☆","↓","~","●","!!","・","⊙","◆",
                             "\\","\"","”","^3^",">3<","▼", ">////////<","*","►","♫",
                             "↘","←","※","→","♥","<(‵▽′)>","✔","▎","-","_",
                             "①","②","③","ღ","♫","♪",
                             "「","」","｢","｣","'","《","》","<3","㊣","◎","_",
                             "『","』","=","－","‧",
                             "( ◔ ౪◔)","(◞౪◟‵)","^ิ౪^ิ","(✪ω✪)b","ﾟヽ(´∀`)ﾉﾟ","(╭￣3￣)╭","(ﾟдﾟ)","(´◒`)",
                             "ლ(ωლ)","(≧∀≦)","≧ω≦","(๑˘ ₃˘๑)",
                             "<",">",
                             );
    
    // 換行符號(斷句用)
    $line_break = array(" ","?","，","。",",","!","！","、","^^","²","###");

    $total_time_start = microtime(true);

    while(1)
    {
        // 撈取未做過斷詞斷語的貼文，有資料就進行斷詞斷句，沒資料就跳出迴圈
        $result = $DB->query_no_ckip_word_id($conn);
        $total_row = sprintf("%05s", count($result));
        
        if ($total_row > 0)
        {
            echo "尚有 ". $total_row . " 筆資料需要斷詞斷句處理<BR>";
            ob_flush();
            flush();

            $count = 1;
            foreach ($result as $row)
            {
                $line = $row['MESSAGE'];

                // 首先排除純圖片的貼文
                if ($line != '(沒有東西)')
                {
                    // 移除掉貼文前後的["和"]
                    $line = str_replace(array("[\"", "\"]"), "", $line);

                    // 移除掉貼文中間的網址(有可能因為貼文時間已久，連結失效)
                    $line = preg_replace("#(http://[0-9a-z._/?=&;]+)#i","", $line);

                    // 把特殊符號取代為空值
                    $line = str_replace($special_symbols, "", $line);
                    
                    // 把每個逗號、句號視為換行符號
                    $raw_text = str_replace($line_break, "\n", $line);
                    echo  "✿" . sprintf("%05s", $count) . "/{$total_row}✿ 貼文內容【". $raw_text . "】<BR><BR>";
                    
                    $raw_text_array = explode("\n", $raw_text);
                    $raw_text_array = array_filter($raw_text_array);

                    $new_line = '　';
                    
                    // 呼叫中研院CKIP服務
                    $time_start = microtime(true);
                    foreach ($raw_text_array as $raw_sentence) 
                    {
                        echo "單行句子(". mb_strlen($raw_sentence, "utf-8") ."): " . $raw_sentence . "<BR>";
                        ob_flush();
                        flush();

                        $return_text = $CKIP->send($raw_sentence);
                        $return_term = $CKIP->getTerm();

                        foreach($return_term as $term)
                        {
                            $new_line .= $term['term'] . "(" . $term['tag']. ")　";
                        }
                        //sleep(1);
                    }
                    $time_end = microtime(true);
                    $time = $time_end - $time_start;
                    $exec_time = $time_end - $total_time_start;
                    echo "斷詞所花時間: {$time}秒，累積執行時間: {$exec_time}秒<BR>";
                    echo "<BR>斷詞結果【{$new_line}】<BR>";
                    echo "============================================================================<BR>";
                    
                    // 句末補上斷行符號，需塞入\r\n，不然會轉成UNIX文件格式
                    $new_line .= "\r\n";

                    // 寫入資料庫
                    $res = $DB->update_ckip_word($conn, $row['MSG_ID'], $new_line);
                    
                    // 寫入失敗則把結果寫至文字檔備查
                    if ($res === false)
                    {
                        $FB->write_data_to_logfile("error_data.txt", $new_line, 1);
                    }
                    $count++;
                } // end of "if ($line != '(沒有東西)')"
                sleep(1);
            }
        }   // end of "if ($total_row > 0)"
        else
        {
            // 若資料庫中撈不到沒有斷詞斷句的貼文就跳出迴圈
            break;
        }
    }   // end of "while(1)"
    
    $conn = null;
    $DB = null;
    
    $total_time_end = microtime(true);
    $total_time = $total_time_end - $total_time_start;
    echo "<BR>㊣ 全部所花時間: {$total_time}秒 ㊣";
?>