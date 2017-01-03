<?php
    /* MySQL讀寫相關函式 */

    class DB_Class
    {
        var $_dbConn = 0;
        var $_queryResource = 0;
        
        var $host = "localhost";
        var $username = "root";
        var $password = "takming";
        var $dbname = "fb_message";

        // 資料庫連結
        public function connect_db()
        {
            try{
                $_opts_values = array(PDO::ATTR_PERSISTENT=>true,PDO::ATTR_ERRMODE=>2,PDO::MYSQL_ATTR_INIT_COMMAND=>"SET NAMES 'utf8'");
                $db=new PDO("mysql:host={$this->host};dbname={$this->dbname};charset=utf8",
                            $this->username, $this->password, $_opts_values);
                $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); //錯誤訊息提醒

                return $db;
            } 
            catch(PDOException $e){
                //error message
                echo $e->getMessage(); 
            }
        }
        
        // 將處理好的臉書貼文寫至資料庫
        public function insert_fb_data($db, $bind)
        {
            // 檢查該筆貼文ID是否已存在於DB
            $sql = "SELECT COUNT(*) AS CNT
                      FROM message_group
                     WHERE MSG_ID = '{$bind[':MSG_ID']}'";
            $res = $db->query($sql);
            $cnt = $res->fetchColumn();
            if ($cnt > 0)
            {
                echo "貼文ID: {$bind[':MSG_ID']} 已存在資料庫中，程序中斷<BR>";
                return false;
            }
            
            // 執行寫入
            $sql = "INSERT INTO message_group (MSG_ID, FAN_GROUP_ID, FAN_GROUP_NAME, CREATED_TIME, INS_DT, 
                                   SHARES_COUNT, LIKES_COUNT, COMMENTS_COUNT, MESSAGE) VALUES 
                                  (:MSG_ID, :FAN_GROUP_ID, :FAN_GROUP_NAME, STR_TO_DATE(:CREATED_TIME,'%Y/%m/%d %H:%i:%s'), CURDATE(),
                                   :SHARES_COUNT, :LIKES_COUNT, :COMMENTS_COUNT, :MESSAGE)";

            $stmt = $db->prepare($sql);
            $result = $stmt->execute($bind);
            
            return $result;
        }
        
        // 查詢所有未斷詞斷句的貼文(排除貼文內容長度為零的貼文)
        public function query_no_ckip_word_id($db)
        {
            $sql = "SELECT MSG_ID, MESSAGE 
                      FROM message_group 
                     WHERE LENGTH(CKIP_WORD) = 0 
                       AND MESSAGE <> '(沒有東西)'";
            $res = $db->query($sql);
            
            return $res->fetchAll();
        }
        
        // 依粉絲團查詢所有已斷詞斷句的句子
        public function query_do_ckip_word_by_fangroup_name($db)
        {
            $sql = "SELECT FAN_GROUP_NAME, CKIP_WORD
                      FROM message_group
                     WHERE LENGTH(CKIP_WORD) > 0";
            $res = $db->query($sql);
            
            return $res->fetchAll();
        }
        
        // 將斷詞斷句結果update至資料庫同貼文的CKIP_WORD欄位中
        public function update_ckip_word($db, $MSG_ID, $CKIP_WORD)
        {
            // 檢查該筆貼文ID是否已存在於DB
            $sql = "SELECT COUNT(*) AS CNT
                      FROM message_group 
                     WHERE MSG_ID = '{$MSG_ID}'
                       AND LENGTH(CKIP_WORD) > 0";
            $res = $db->query($sql);
            $cnt = $res->fetchColumn();
            if ($cnt > 0)
            {
                echo "貼文ID: {$MSG_ID} 已做過斷詞斷句處理<BR>";
                return false;
            }
            
            // 執行寫入
            $sql = "UPDATE message_group
                       SET CKIP_WORD = :CKIP_WORD
                     WHERE MSG_ID = :MSG_ID";
            $bind = array(':CKIP_WORD' => $CKIP_WORD, ':MSG_ID' => $MSG_ID);

            $stmt = $db->prepare($sql);
            return $stmt->execute($bind);
        }
        
        // 清空詞頻資料表
        public function del_message_tf($db)
        {
            // 執行寫入
            $sql = "DELETE FROM message_tf";

            $stmt = $db->prepare($sql);
            return $stmt->execute();
        }
        
        // 將詞頻依詞彙與詞類新增至資料庫中
        public function ins_tf_data($db, $WORD, $TYPE, $COLUMN, $COUNT)
        {
            // 檢查該筆貼文ID是否已存在於DB
            $sql = "SELECT COUNT(*) AS CNT
                      FROM message_tf
                     WHERE WORD = '{$WORD}'
                       AND TYPE = '{$TYPE}'";
            $res = $db->query($sql);

            $cnt = $res->fetchColumn();
            if ($cnt > 0)
            {
                $sql = "UPDATE message_tf
                           SET `{$COLUMN}` = CONV({$COUNT},10,10)
                         WHERE WORD = '{$WORD}'
                           AND TYPE = '{$TYPE}'";
            }
            else
            {
                $sql = "INSERT INTO message_tf (WORD, TYPE, `{$COLUMN}`) VALUES ('{$WORD}', '{$TYPE}', CONV({$COUNT},10,10))";
            }

            $bind = array(':WORD' => $WORD, ':TYPE' => $TYPE, ':COUNT' => $COUNT);
            /*
            echo $sql . "<BR>";
            print_r($bind);
            echo "<BR>";
            */
            $stmt = $db->prepare($sql);
            return $stmt->execute($bind);
        }
        
        // 清空詞頻資料表
        public function del_message_tfidf($db)
        {
            // 執行寫入
            $sql = "DELETE FROM message_tfidf";

            $stmt = $db->prepare($sql);
            return $stmt->execute();
        }

        // 依粉絲團查詢各粉絲團的貼文數
        public function query_message_cnt_by_fangroup_name($db)
        {
            $sql = "SELECT FAN_GROUP_NAME, COUNT(*) AS CNT 
                      FROM message_group
                     GROUP BY FAN_GROUP_NAME";
            $res = $db->query($sql);
            $res = $res->fetchAll();
            
            $total_msg_cnt = 0; // 計算全部貼文的總數
            $total_tf_sum = 0;  // 計算全部詞彙的出現次數
            $result = array();
            foreach ($res as $key => $row)
            {
                // 取得所有詞彙的出現次數 by 粉絲團
                $tf_cnt_sql = "SELECT SUM(`{$row['FAN_GROUP_NAME']}`) AS TF_SUM FROM message_tf WHERE `{$row['FAN_GROUP_NAME']}` > 0";
                $tf_res = $db->query($tf_cnt_sql);
                $tf_res = $tf_res->fetchColumn();

                // 輸出陣列重整
                $result += array($row['FAN_GROUP_NAME'] => array('MSG_CNT' => $row['CNT'], 'TF_SUM' => $tf_res));
                
                $total_msg_cnt += $row['CNT'];
                $total_tf_sum += $tf_res;
            }
            $result += array('Total' => array('MSG_CNT' => $total_msg_cnt, 'TF_SUM' => $total_tf_sum));

            return $result;
        }
        
        // 查詢全部詞頻結果
        public function query_tf_word($db)
        {
            /*
            // 全部重新計算
            $sql = "SELECT *,
                           CONCAT(WORD, '(', TYPE, ')') AS WORD_TYPE 
                      FROM message_tf";
            */
            // 僅做還沒計算過的
            $sql = "SELECT *, CONCAT(WORD, '(', TYPE, ')') AS WORD_TYPE 
                      FROM message_tf TF
                     WHERE NOT EXISTS (SELECT 1 FROM message_tfidf 
                                        WHERE WORD=TF.WORD 
                                          AND TYPE = TF.TYPE)";
            $res = $db->query($sql);
            
            return $res->fetchAll();
        }
        
        // 將詞頻依詞彙與詞類新增至資料庫中
        public function ins_tfidf_data($db, $WORD, $TYPE, $ONE_WORD_TFIDF)
        {
            $str_column = "WORD, TYPE,";
            $str_values = "'`{$WORD}`', '`{$TYPE}`',";
            
            foreach ($ONE_WORD_TFIDF as $COLUMN => $row)
            {
                $str_column .= "`{$COLUMN}`,";
                $str_values .= "{$row},";
            }
            
            // 拿掉最後的逗號
            $str_column = substr($str_column, 0, -1);
            $str_values = substr($str_values, 0, -1);
            
            $sql = "INSERT INTO message_tfidf ({$str_column}) VALUES ({$str_values})";
            $bind = array(':WORD' => $WORD, ':TYPE' => $TYPE);
            
            $bind_sql = str_replace(':WORD', $WORD, $sql);
            $bind_sql = str_replace(':TYPE', $TYPE, $bind_sql);
            //echo $bind_sql . "<BR>";
            $stmt = $db->prepare($sql);
            return $stmt->execute($bind);
        }
    }
?>