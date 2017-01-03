<?php
    // http://localhost/xampp/jindon_get_ckip_word.php
    // 呼叫CKIP進行斷詞斷語，結果存成文字檔於J:\CountWordFreq目錄供詞頻計算

    header ('Content-Type: text/html; charset=utf8');

    require_once "CKIPClient.php";
    $CKIP = new CKIPClient(
       "140.109.19.104",    // CKIP_SERVER
       1501,                // CKIP_PORT
       "dsleep99",          // CKIP_USERNAME
       "H10267102"          // CKIP_PASSWORD
    );

    require_once "jindon_fb_func.php";
    $FB = new Facebook_Class;
    
    // 特殊符號
    $special_symbols = array("✿",   //小花
                             "❤",   //愛心
                             "➨",   //黑色實心向右箭頭
                             "✧",  //菱形
                             "　","～","【","】","★","↓","~","●","!!","・","⊙",
                             "\\","\"","/","”","^3^",">3<","▼", ">////////<","*",
                             "↘","←","※","→","♥","<(‵▽′)>","✔","▎","-","_","①","②",
                             "「","」","｢","｣","'","《","》","<3","㊣","◎","_",
                             "『","』","=","－","‧","( ◔ ౪◔)","(◞౪◟‵)","^ิ౪^ิ",
                             "<",">",
                             );
    
    // 換行符號(斷句用)
    $line_break = array(" ","?","，","。",",","!","！","、","^^","²","###");

    $total_time_start = microtime(true);
    
    // 讀取檔案
    foreach (glob("/fb_log/*.txt") as $filename)
    {
        $file = file($filename);
        foreach($file as $line)
        {
            $line = trim(substr($line, 70));
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
                echo "貼文內容【". $raw_text . "】<BR><BR>";
                
                $raw_text_array = explode("\n", $raw_text);
                $raw_text_array = array_filter($raw_text_array);

                $new_line = '　';
                $time_start = microtime(true);
                foreach ($raw_text_array as $raw_sentence) 
                {
                    echo "單行句子(". mb_strlen($raw_sentence, "utf-8") ."): " . $raw_sentence . "<BR>";
if (1){
                    $return_text = $CKIP->send($raw_sentence);
                    $return_term = $CKIP->getTerm();

                    foreach($return_term as $term)
                    {
                        $new_line .= $term['term'] . "(" . $term['tag']. ")　";
                    }
                    //sleep(1);
}
                }
                $time_end = microtime(true);
                $time = $time_end - $time_start;
                echo "斷詞所花時間: {$time}秒<BR>";
                echo "<BR>斷詞結果【{$new_line}】<BR>";
                echo "======================================<BR>";
                
                // 句末補上斷行符號，需塞入\r\n，不然會轉成UNIX文件格式
                $new_line .= "\r\n";

                // 寫入文字檔(tag_原始檔案名.txt)供詞頻計算程式處理
                $FB->write_data("tag_". basename($filename), $new_line, 1);
            } // end of "if ($line != '(沒有東西)')"
            sleep(5);
        }
    }
    
    $total_time_end = microtime(true);
    $total_time = $total_time_end - $total_time_start;
    echo "<BR>㊣ 全部所花時間: {$total_time}秒 ㊣";
?>