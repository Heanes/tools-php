<?php
/**
 * @doc 生成mysql数据字典 @todo 1. 保存多组连接配置，到cookie/localStorage中去; 2. 建表语句高亮; 3. 数据字典字段鼠标浮上时自动选中，方便复制
 * @author Heanes
 * @time 2015-08-28 15:20:50
 */
ob_start();
session_start();
$baseUrl = $_SERVER['SCRIPT_NAME'];
header("Content-type: text/html; charset = utf-8");
$issetSession = isset($_SESSION['_db_config']['db_password']) && isset($_SESSION['_db_config']['db_database']) && isset($_SESSION['_db_config']['db_user']);
$issetCookie =  isset($_COOKIE['_db_config']['db_password']) && isset($_COOKIE['_db_config']['db_database']) && isset($_COOKIE['_db_config']['db_user']);
$issetGet = !empty($_GET['db']) && ( ($issetSession || $issetCookie) || (isset($_GET['password']) && isset($_GET['user'])) );
isset($_COOKIE['_db_config']['db_connect_wrong']) ? $connectWrong = $_COOKIE['_db_config']['db_connect_wrong'] : $connectWrong = false;
isset($_SESSION['_db_config']['db_connect_wrong']) ? $connectWrong = boolval($_SESSION['_db_config']['db_connect_wrong']) : null;

// 删除cookie和session信息
if(isset($_GET['unsetConfig'])){
    if($issetCookie){
        foreach($_COOKIE['_db_config'] as $index => $item){
            setcookie("_db_config".'['.$index.']', '');
        }
    }
    return session_destroy() ? true : false;
}

function getCurrentTimeStr($format = 'Y-m-d H:i:s'){
    return date($format);
}
date_default_timezone_set('PRC');
$currentTime = getCurrentTimeStr();

if (!isset($_GET['config']) && !isset($_GET['postConfig']) && !isset($_GET['mysqlConnectError']) && !isset($_GET['deleteSuccess'])) {
    //1，检测session或cookie中是否存有数据库配置
    //1.1 若无，跳转到?config地址，让用户输入数据库配置
    if (!$issetGet && !$issetSession && !$issetCookie || $connectWrong){
        ob_end_clean();
        header("Location: " . $baseUrl . "?config");
        exit;
    }else{
        $_CFG['_db_config'] = [
            'db_server'   => 'localhost',
            'db_port'     => '3306',
            'db_user'     => 'root',
            'db_password' => '123456',
            'db_database' => 'heanes.com',
        ];
        //1.2 若有，则根据配置查看数据字典页
        if ($issetSession){
            $_CFG['_db_config'] = [
                'db_server'   => $_SESSION['_db_config']['db_server'],
                'db_port'     => $_SESSION['_db_config']['db_port'],
                'db_user'     => $_SESSION['_db_config']['db_user'],
                'db_password' => $_SESSION['_db_config']['db_password'],
                'db_database' => $_SESSION['_db_config']['db_database'],
            ];
        }else{
            $_CFG['_db_config'] = [
                'db_server'   => $_COOKIE['_db_config']['db_server'],
                'db_port'     => $_COOKIE['_db_config']['db_port'],
                'db_user'     => $_COOKIE['_db_config']['db_user'],
                'db_password' => $_COOKIE['_db_config']['db_password'],
                'db_database' => $_COOKIE['_db_config']['db_database'],
            ];
        }
        // 1.3 也可以在url中指定配置，但URL只是暂时配置，不存入session或cookie
        if($issetGet){
            $_CFG['_db_config'] = [
                'db_server'   => isset($_GET['server']) ? $_GET['server'] : $_CFG['_db_config']['db_server'],
                'db_port'     => isset($_GET['port']) ? $_GET['server'] : $_CFG['_db_config']['db_port'],
                'db_user'     => isset($_GET['user']) ? $_GET['server'] : $_CFG['_db_config']['db_user'],
                'db_password' => isset($_GET['password']) ? $_GET['server'] : $_CFG['_db_config']['db_password'],
                'db_database' => isset($_GET['db']) ? $_GET['db'] : $_CFG['_db_config']['db_database'],
            ];
        }

        // 数据库连接
        $mysqli_conn = @mysqli_connect ($_CFG['_db_config']['db_server'], $_CFG['_db_config']['db_user'], $_CFG['_db_config']['db_password'], $_CFG['_db_config']['db_database'], $_CFG['_db_config']['db_port']);
        if (!$mysqli_conn || mysqli_connect_errno()){
            $_SESSION['_db_config']['db_connect_wrong'] = true;
            $_COOKIE['_db_config']['db_connect_wrong'] = 1;
            $_SESSION['_db_connect_errno'] = mysqli_connect_errno();
            $_SESSION['_db_connect_error'] = mysqli_connect_error();
            ob_end_clean();
            header("Location: " . $baseUrl . "?mysqlConnectError");
            exit;
        }else{
            $_SESSION['_db_config']['db_connect_wrong'] = false;
            $_COOKIE['_db_config']['db_connect_wrong'] = 0;
            unset($_SESSION['_db_connect_errno']);
            unset($_SESSION['_db_connect_error']);
        }
        mysqli_query ($mysqli_conn, 'SET NAMES utf8');

        $sql = 'SELECT DISTINCT TABLE_SCHEMA AS `database` FROM information_schema.TABLES';
        $table_result = mysqli_query ($mysqli_conn, $sql);
        $databases = [];
        while ($row = mysqli_fetch_assoc ($table_result)) {
            if($row['database'] != 'information_schema' && $row['database'] != 'mysql' && $row['database'] != 'performance_schema'){
                $databases[] = $row['database'];
            }
        }

        $sql = "SELECT T.TABLE_NAME AS tableName, TABLE_COMMENT as tableComment, COLUMN_NAME as columnName, COLUMN_TYPE as columnType, COLUMN_COMMENT as columnComment, IS_NULLABLE as isNullable, COLUMN_KEY as columnKey, EXTRA as extra, COLUMN_DEFAULT as columnDefault,"
                ." CHARACTER_SET_NAME as characterSetName, TABLE_COLLATION as tableCollation, COLLATION_NAME as collationName, ORDINAL_POSITION as ordinalPosition, AUTO_INCREMENT as autoIncrement, CREATE_TIME as createTime, UPDATE_TIME as updateTime"
                ." FROM INFORMATION_SCHEMA.TABLES AS T"
                ." JOIN INFORMATION_SCHEMA.COLUMNS AS C ON T.TABLE_SCHEMA = C.TABLE_SCHEMA AND C.TABLE_NAME = T.TABLE_NAME"
                ." WHERE T.TABLE_SCHEMA = '".$_CFG['_db_config']['db_database']."'ORDER BY T.TABLE_NAME, ORDINAL_POSITION";
        $table_result = mysqli_query ($mysqli_conn, $sql);
        $tableOrigin = [];
        while ($row = mysqli_fetch_assoc ($table_result)) {
            $tableOrigin[$row['tableName']][] = $row;
        }
        mysqli_free_result($table_result);

        $tables = [];
        foreach ($tableOrigin as $index => $item) {
            $tables[] = [
                'tableName' => $index,
                'tableComment' => $item[0]['tableComment'],
                'columns' => $item,
                'createTime' => $item[0]['createTime'],
                'updateTime' => $item[0]['updateTime']
            ];
        }
        // 显示show create table
        foreach ($tables as $index => &$table) {
            $sqlShowCreateTable = 'SHOW CREATE TABLE `'.$_CFG['_db_config']['db_database'].'`.`'. $table['tableName'].'`';
            $showCreateResult = mysqli_query ($mysqli_conn, $sqlShowCreateTable);
            $row = mysqli_fetch_row($showCreateResult);
            $table['createSql'] = $row[1];

        }
        mysqli_close ($mysqli_conn);

        $multiple_tables = [];
        foreach ($tables as $key => &$value) {
            // 处理驼峰
            foreach ($value['columns'] as $index => &$column) {
                $words = explode('_', trim($column['columnName']));
                $str = '';
                foreach ($words as $word) {
                    $str .= ucfirst($word);
                }
                $str = lcfirst($str);
                $tables[$key]['columns'][$index]['columnNameCamelStyle'] = $str;
            }

            $multiple_tables[$key]['origin']=$tables[$key];
        }
        // 按列字段排序
        foreach ($tables as $key => $value) {
            array_multisort($tables[$key]['columns']);
        }
        foreach ($tables as $key => $value) {
            $multiple_tables[$key]['ordered'] = $tables[$key];
        }
        foreach ($multiple_tables as $key => $value) {
            $temp1[] = $multiple_tables[$key][0];
            $temp2[] = $multiple_tables[$key][1];
        }
        if(isset($_GET['json'])){
            echo json_encode($multiple_tables);
            return true;
        }
        $total_tables = count($multiple_tables);
        $num_width = strlen($total_tables);
        // 其他配置
        $title = '数据字典- '. $_CFG['_db_config']['db_database'] . '@' . $_CFG['_db_config']['db_server'] . ' on ' . $_CFG['_db_config']['db_port'] . ' - ' . $_CFG['_db_config']['db_user'];
    }
}
//1.2.1 用户输入后提交数据，将配置数据保存到cookie或session中。
if (isset($_GET['postConfig'])) {
    $postConfig = [
        'db_server'        => $_POST['db_server'],
        'db_port'          => $_POST['db_port'],
        'db_user'          => $_POST['db_user'],
        'db_password'      => $_POST['db_password'],
        'db_database'      => $_POST['db_database'],
        'db_connect_wrong' => false,
    ];
    $_SESSION['_db_config'] = $postConfig;
    //Cookie保存配置
    if (isset($_POST['remember_config']) && $_POST['remember_config']) {
        $postConfig['db_connect_wrong'] = 0;
        foreach ($postConfig as $index => $item) {
            setcookie("_db_config".'['.$index.']', $item, time()+60*60*24*30, '/');
        }
    }else{
        if($issetCookie){
            foreach ($_COOKIE['_db_config'] as $index => $item) {
                setcookie("_db_config".'['.$index.']', '');
            }
        }
    }
    return true;
}

$tmpConfig = [];
if($issetSession){
    $tmpConfig = $_SESSION['_db_config'];
} else if($issetCookie) {
    $tmpConfig = $_COOKIE['_db_config'];
}
if($issetGet){
    $tmpConfig = $_CFG['_db_config'];
}

if (isset($_GET['config'])){
    $title = '填写数据库配置';
}
if (isset($_GET['mysqlConnectError'])){
    $title = '数据库连接错误，请重新检查数据库信息后填写数据库配置！';
}
if (isset($_GET['deleteSuccess'])){
    $title = '已经成功删除保存的配置信息！';
}
?>
<!DOCTYPE html>
<html>
<head>
<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
<meta http-equiv="X-UA-Compatible" content="IE=edge"/>
<meta name="renderer" content="webkit"/>
<meta name="author" content="Heanes heanes.com email(heanes@163.com)"/>
<title><?php echo isset($title) ? $title: '';?></title>
<style>
*{box-sizing:border-box}
a{text-decoration:none;}
a:visited{color:inherit;}
body{padding:0;margin:0;}
body,td,th {font:14px/1.3 TimesNewRoman,Arial,Verdana,tahoma,Helvetica,sans-serif}
dl{margin:0;padding:0;}
::-webkit-scrollbar-track{box-shadow:inset 0 0 6px rgba(0,0,0,0.3);-webkit-box-shadow:inset 0 0 6px rgba(0,0,0,0.3);-webkit-border-radius:10px;border-radius:10px}
::-webkit-scrollbar{width:6px;height:5px}
::-webkit-scrollbar-thumb{-webkit-border-radius:10px;border-radius:10px;background:rgba(0,0,0,0.39);}
pre{padding:0;margin:0;}
.w-wrap{width:1265px;margin:0 auto;}
.fixed{position:fixed;}
.toolbar-block{width:100%;top:0;right:0;height:38px;background-color:rgba(31,31,31,0.73);-webkit-box-shadow:0 3px 6px rgba(0,0,0,.2);-moz-box-shadow:0 3px 6px rgba(0,0,0,.2);box-shadow:0 3px 6px rgba(0,0,0,.2);z-index:100;}
.toolbar-block-placeholder{height:40px;width:100%;}
.operate-db-block{position:relative;}
.absolute-block{position:absolute;right:0;font-size:0;top:0;}
.toolbar-button-block,.toolbar-input-block{display:inline-block;}
.toolbar-input-block{height:38px;line-height:36px;}
.toolbar-input-label{color:#fff;background-color:#5b5b5b;display:inline-block;height:38px;padding:0 4px;}
.toolbar-input-block .toolbar-input{width:280px;height:36px;margin:0 8px;}
.toolbar-input-block.search-input{padding:0 4px;position:relative}
.search-result-summary{position:absolute;right:40px;top:2px;font-size:13px;color:#999;}
.delete-all-input{position:absolute;right:16px;top:12px;width:16px;height:16px;background: #bbb;color:#fff;font-weight:600;border:none;border-radius:50%;padding:0;font-size:12px;cursor:pointer;}
.delete-all-input:hover{background-color:#e69691}
.change-db{background-color:#1588d9;border-color:#46b8da;color:#fff;margin-bottom:0;font-size:14px;font-weight:400;}
a.change-db{color:#fff;}
.change-db:hover{background-color:#337ab7}
.hide-tab,.hide-tab-already{background-color:#77d26d;color:#fff;}
.hide-tab:hover,.hide-tab-already:hover{background-color:#49ab3f}
.lap-table,.lap-table-already{background-color:#8892BF;color:#fff;}
.lap-table:hover,.lap-table-already:hover{background-color:#4f5b93}
.unset-config{background-color:#0c0;color:#fff;}
.unset-config:hover{background-color:#0a8;}
.connect-info{background-color:#eee;color:#292929}
.connect-info:hover{background-color:#ccc;}
.toggle-show{position:relative;}
.toggle-show:hover .toggle-show-info-block{display:block;}
.toggle-show-info-block{position:absolute;right:0;font-size:13px;background-color:#eee;padding-top:6px;display:none;overflow-y:auto;max-height:400px;}
.toggle-show-info-block a{color:#2a28d2}
.toggle-show-info-block p{padding:6px 16px;margin:0;white-space:nowrap}
.toggle-show-info-block p span{display:inline-block;vertical-align:top;}
.toggle-show-info-block p .config-field{text-align:right;min-width:70px}
.toggle-show-info-block p .config-value{color:#2a28d2;}
.toggle-show-info-block p:hover{background-color:#ccc;}
.list-content{width:100%;margin:0 auto;padding:20px 0;}
.table-name-title-block{position:relative;padding:10px 0;}
.table-name-title-block .table-name-title{margin:0;background-color:#f8f8f8;padding:0 4px;cursor:pointer;}
.table-name-title-block .table-name-title.lap-off{border-bottom:1px solid #ddd;}
.table-name-title-block .table-name-title .lap-icon{padding:0 10px;}
.table-name-title-block .table-name-title .table-name-anchor{display:block;padding:10px 0;}
.table-name-title-block .table-other-info{top:50%;margin-top:-12px;}
.table-one-content{position:relative;}
.ul-sort-title{margin:0 0 -1px;padding:0;font-size:0;z-index:3;}
ul.ul-sort-title,ul.ul-sort-title li{list-style:none;}
.ul-sort-title li{display:inline-block;background:#fff;padding:10px 20px;border:1px solid #ddd;border-right:0;color:#333;cursor:pointer;font-size:13px;}
.ul-sort-title li.active{background:#f0f0f0;border-bottom-color:#f0f0f0;}
.ul-sort-title li:hover{background:#1588d9;border:1px solid #aaa;border-right:0;color:#fff;}
.ul-sort-title li:last-child{border-right:1px solid #ddd;}
.table-other-info{position:absolute;right:4px;top:0;color:#666;font-size:12px;line-height:24px;}
.table-other-info dt,.table-other-info dd{margin:0;padding:0;display:inline;}
.table-other-info dt{margin-left:4px;}
.table-list{margin:0 auto;}
table{border-collapse:collapse;}
table caption{text-align:left;background-color:LightGreen;line-height:2em;font-size:14px;font-weight:bold;border:1px solid #985454;padding:10px;}
table th{text-align:left;font-weight:bold;height:26px;line-height:25px;font-size:13px;border:1px solid #ddd;background:#f0f0f0;padding:5px;}
table td{height:25px;font-size:12px;border:1px solid #ddd;padding:5px;word-break:break-all;color:#333;}
.db-table-name{padding:0 6px;}
table.table-info tbody tr:hover{background-color:#f7f7f7;}
.column-index{width:40px;}
.column-field{width:200px;}
.column-data-type{width:130px;}
.column-comment{width:310px;}
.column-can-be-null{width:68px;}
.column-auto-increment{width:68px;}
.column-primary-key{width:40px;}
.column-default-value{width:54px;}
.column-character-set-name{width:54px;}
.column-collation-name{width:100px;}
.db-table-create-sql{width:1064px;}
.fix-category{position:fixed;width:300px;height:100%;overflow:auto;top:0;left:0;background:rgba(241,247,253,0.86);box-shadow:3px 0 6px rgba(0,0,0,.2);-webkit-box-shadow:3px 0 6px rgba(0,0,0,.2);-moz-box-shadow:3px 0 6px rgba(0,0,0,.2);z-index:99;}
.fix-category:hover{z-index:101;}
.fix-category-hide{left:-300px;overflow:hidden;background-color:rgba(0,23,255,0.22);cursor:pointer;}
.fix-category ul{padding:5px;margin:0;}
.fix-category ul li{margin:0;}
.fix-category ul li:hover{background:darkseagreen;}
.fix-category ul li a{display:block;padding: 5px 0 5px 8px;color:#1a407b;text-decoration:none;word-break:break-all;}
.fix-category ul li:hover a,
.fix-category ul li a:hover{color:#fff;}
.fix-category ul li .category-table-name{display:none;padding: 5px 0 5px 22px;color:#1a407b;text-decoration:none;word-break:break-all;font-size:13px;}
.fix-category ul li:hover .category-table-name{display:block;color:#fff;}
.fix-category-handle-bar{z-index:100;}
.fix-category-handle-bar-off .lap-ul{left:0}
.lap-ul{display:inline-block;width:12px;height:35px;background:rgba(12,137,42,0.43);border-bottom-right-radius:5px;border-top-right-radius:5px;position:fixed;top:50%;left:300px;cursor:pointer;border:1px solid rgba(31,199,58,0.43);font-size:12px;font-weight:normal;line-height:35px;text-align:center;z-index:100;}
.fix-category::-webkit-scrollbar-track{-webkit-box-shadow:inset 0 0 6px rgba(0,0,0,0.3);-webkit-border-radius:10px;border-radius:10px}
.fix-category::-webkit-scrollbar{width:6px;height:5px}
.fix-category::-webkit-scrollbar-thumb{-webkit-border-radius:10px;border-radius:10px;background:rgba(231,178,13,0.31);-webkit-box-shadow:inset 0 0 6px rgba(231,178,13,0.31)}
/* 错误页面 */
.error-block{width:1000px;}
.error-title-block{padding:20px 0}
.error-title{text-align:center}
.error-content-block{width:680px;margin:0 auto;padding:20px;background:#fff;border:1px solid #cfcfcf}
.content-row{padding:15px 0}
.content-row p{margin:0;}
.content-row .content-normal-p{text-indent:2em;line-height:30px;}
.text-center{text-align:center;}
.reason-p{font-size:14px;padding:16px 0;line-height:40px;text-indent:4em;color:#f08080;}
/* 配置数据库相关 */
.data-setup-title{padding:20px 0}
.setup-title{text-align:center}
.data-form-block{width:680px;margin:0 auto;padding:20px;background:#fff;border:1px solid #cfcfcf}
.input-row{padding:15px 10px;vertical-align:middle;line-height:22px}
input{background-color:#fff;border:1px solid #ccc;-webkit-box-shadow:inset 0 1px 1px rgba(0,0,0,0.075);-moz-box-shadow:inset 0 1px 1px rgba(0,0,0,0.075);box-shadow:inset 0 1px 1px rgba(0,0,0,0.075);-webkit-transition:border linear .2s,box-shadow linear .2s;-moz-transition:border linear .2s,box-shadow linear .2s;-o-transition:border linear .2s,box-shadow linear .2s;transition:border linear .2s,box-shadow linear .2s;display:inline-block;padding:4px 6px;font-size:14px;line-height:20px;color:#555;vertical-align:middle;-webkit-border-radius:4px;-moz-border-radius:4px;border-radius:4px}
input:focus{border-color:rgba(82,168,236,0.8);outline:0;outline:thin dotted \9;-webkit-box-shadow:inset 0 1px 1px rgba(0,0,0,0.075),0 0 8px rgba(82,168,236,0.6);-moz-box-shadow:inset 0 1px 1px rgba(0,0,0,0.075),0 0 8px rgba(82,168,236,0.6);box-shadow:inset 0 1px 1px rgba(0,0,0,0.075),0 0 8px rgba(82,168,236,0.6)}
.input-field{display:inline-block;width:320px}
.input-field label{display:inline-block;width:100px;text-align:right;vertical-align:middle}
.normal-input{line-height:25px}
.input-tips{display:inline-block;width:280px;padding-left:20px;vertical-align:middle}
.form-handle{padding:20px;text-align:center}
.btn{display:inline-block;text-align:center;vertical-align:middle;padding:10px 12px;text-decoration:none;margin:8px;font-size:14px;}
.btn-tight{margin:0;}
.setup-submit{width:100px;height:50px;background:#0059F7;color:#fff;border-radius:5px}
.setup-submit:hover{background-color:#f72614}
.setup-cancel{width:100px;height:50px;line-height:50px;background-color:#5cb85c;border-radius:5px;color:#fff;padding:0;}
.setup-cancel:hover{background-color:#4fa94f}
input[type="submit"],input[type="reset"]{border:none;cursor:pointer;-webkit-appearance:button}
input[type="checkbox"]{margin-right:10px;cursor:pointer;}
label.label-checkbox{width:auto;padding-left:100px;cursor:pointer}
.data-form-block .tips{width:85%;margin:0 auto;}
.data-form-block .tips .tips-p{padding:10px 14px;color:#555;font-size:13px;}
.data-form-block .tips .tips-p.notice-important{background-color:#ffefef;border:1px solid #ffd2d2}
/* 右下角 */
.right-bar-block{position:fixed;left:50%;bottom:245px;margin-left:632px;}
.right-bar-block .go-to-top{width:20px;border:1px solid #ddd;text-align:center;cursor:pointer;display:none;font-size:13px;padding:6px 0;}
</style>
</head>
<body>
<div class="wrap">
    <!-- S 头部 S -->
    <div class="header">
    </div>
    <!-- E 头部 E-->
    <!-- S 主要内容 S -->
    <div class="main">
        <div class="main-content w-wrap">
            <?php if (isset($_GET['config'])) {?>
            <div class="data-setup-title">
                <h1 class="setup-title">数据库配置</h1>
            </div>
            <div class="data-form-block">
                <div class="input-row">
                    <div class="input-field">
                        <label for="db_server">数据库主机</label>
                        <input type="text" name="db_server" id="db_server" value="<?php echo isset($tmpConfig['db_server'])?$tmpConfig['db_server']:'192.168.1.160';?>" class="normal-input" title="请输入数据库主机" placeholder="localhost" required />
                    </div>
                    <div class="input-tips">
                        <span class="tips">连接地址，如localhost、IP地址</span>
                    </div>
                </div>
                <div class="input-row">
                    <div class="input-field">
                        <label for="db_port">端口</label>
                        <input type="text" name="db_port" id="db_port" value="<?php echo isset($tmpConfig['db_port'])?$tmpConfig['db_port']:'3306';?>" class="normal-input" title="请输入端口" placeholder="请输入端口" required />
                    </div>
                    <div class="input-tips">
                        <span class="tips">数据库连接什么端口？</span>
                    </div>
                </div>
                <div class="input-row">
                    <div class="input-field">
                        <label for="db_database">数据库名</label>
                        <input type="text" name="db_database" id="db_database" value="<?php echo isset($tmpConfig['db_database'])?$tmpConfig['db_database']:'tmc';?>" class="normal-input" title="请输入数据库名" placeholder="请输入数据库名" required />
                    </div>
                    <div class="input-tips">
                        <span class="tips">将连接哪个数据库？</span>
                    </div>
                </div>
                <div class="input-row">
                    <div class="input-field">
                        <label for="db_user">用户名</label>
                        <!-- 解决浏览器自动填充数据的问题 -->
                        <label for="fake_db_user" style="display:none"></label>
                        <input type="text" name="fake_username_remembered" id="fake_db_user" style="display:none" />
                        <input type="text" name="db_user" id="db_user" value="<?php echo isset($tmpConfig['db_user'])?$tmpConfig['db_user']:'meixiansong_tms_rw';?>" class="normal-input" title="请输入用户名" placeholder="请输入用户名" required />
                    </div>
                    <div class="input-tips">
                        <span class="tips">你的MySQL用户名</span>
                    </div>
                </div>
                <div class="input-row">
                    <div class="input-field">
                        <label for="db_password">密码</label>
                        <!-- 解决浏览器自动填充数据的问题 -->
                        <label for="fake_db_password" style="display:none"></label>
                        <input type="password" name="fake_password_remembered" id="fake_db_password" style="display:none" />
                        <input type="password" name="db_password" id="db_password" autocomplete="off" value="<?php echo isset($tmpConfig['db_password'])?$tmpConfig['db_password']:'meixiansong';?>" class="normal-input" title="请输入密码" placeholder="请输入密码" required />
                    </div>
                    <div class="input-tips">
                        <span class="tips">数据库密码</span>
                    </div>
                </div>
                <div class="input-row">
                    <div class="input-field">
                        <label for="remember_config" class="label-checkbox"><input type="checkbox" name="remember_config" checked id="remember_config" value="1" />记住配置（存入Cookie）</label>
                    </div>
                </div>
                <div class="form-handle">
                    <div class="form-handle-field">
                        <span class="handle-cell"><input type="submit" class="btn setup-submit" name="setup_form_submit" id="db_set_submit" value="提交" /></span>
                        <span class="handle-cell"><a class="btn setup-cancel" href="javascript:history.back();">返回</a></span>
                    </div>
                </div>
            </div>
            <script type="text/javascript">
                var $db_set_submit = document.getElementById('db_set_submit');
                $db_set_submit.onclick = function (){
                    var $db_database = document.getElementById('db_database').value,
                        $db_user = document.getElementById('db_user').value,
                        $db_password = document.getElementById('db_password').value,
                        $db_server = document.getElementById('db_server').value,
                        $db_port = document.getElementById('db_port').value,
                        $remember_config = document.getElementById('remember_config');
                    var $remember_config_val = $remember_config.checked ? $remember_config.value : 0;
                    $.ajax({
                        url: "<?php echo $baseUrl;?>?postConfig",//请求地址
                        type: "POST",                                       //请求方式
                        data: { db_server:$db_server, db_database: $db_database, db_user: $db_user, db_password: $db_password, db_port:$db_port ,remember_config:$remember_config_val},//请求参数
                        dataType: "json",
                        success: function (response, xml) {
                            // 此处放成功后执行的代码
                            window.location.href = "<?php echo $baseUrl;?>";
                        },
                        fail: function (status) {
                            // 此处放失败后执行的代码
                            alert('出现问题：' + status);
                        }
                    });
                };
            </script>
            <?php }elseif(isset($multiple_tables)){?>
            <div class="toolbar-block fixed" id="tool_bar">
                <div class="operate-db-block w-wrap">
                    <div class="handle-block">
                        <div class="toolbar-input-block search-input">
                            <label for="search_input" class="toolbar-input-label">输入表名检索：</label>
                            <input type="text" name="search_input" id="search_input" class="toolbar-input" placeholder="search (table name only)" title="输入表名快速查找">
                            <span id="search_result_summary" class="search-result-summary">共<?php echo isset($total_tables) ? $total_tables : 0;?>个表</span>
                            <button class="delete-all-input" id="delete_search_input">X</button>
                        </div>
                    </div>
                    <div class="absolute-block">
                        <div class="toolbar-button-block">
                            <a href="javascript:void(0);" class="btn btn-tight unset-config" id="unset_config" title="清除cookie及session中保存的连接信息">安全删除配置信息</a>
                        </div>
                        <div class="toolbar-button-block">
                            <a href="javascript:void(0);" class="btn btn-tight lap-table" id="lap_table" title="折叠字典列表，仅展示表名概览">折叠内容</a>
                        </div>
                        <div class="toolbar-button-block">
                            <a href="javascript:void(0);" class="btn btn-tight hide-tab" id="hide_tab" title="每个字典只显示一个table">隐藏排序tab</a>
                        </div>
                        <div class="toolbar-button-block toggle-show">
                            <a href="?config" class="btn btn-tight change-db" title="点击可以输入配置" title="快速切换及重新填写配置切换连接">切换数据库</a>
                            <div class="toggle-show-info-block">
                                <?php foreach ($databases as $arr => $db) {?>
                                <a href="<?php echo $baseUrl . '?db=' . $db;?>"><p><?php echo $db?></p></a>
                                <?php }?>
                            </div>
                        </div>
                        <div class="toolbar-button-block toggle-show" id="connect_info">
                            <a href="javascript:void(0);" class="btn btn-tight connect-info" title="本次连接信息">连接信息</a>
                            <div class="toggle-show-info-block">
                                <p><span class="config-field">刷新时间：</span><span class="config-value"><?php echo isset($currentTime)?$currentTime:'';?></span></p>
                                <p><span class="config-field">数据库：</span><span class="config-value"><?php echo isset($tmpConfig['db_database'])?$tmpConfig['db_database']:'';?></span></p>
                                <p><span class="config-field">用户：</span><span class="config-value"><?php echo isset($tmpConfig['db_user'])?$tmpConfig['db_user']:'';?></span></p>
                                <p><span class="config-field">主机：</span><span class="config-value"><?php echo isset($tmpConfig['db_server'])?$tmpConfig['db_server']:'';?></span></p>
                                <p><span class="config-field">端口：</span><span class="config-value"><?php echo isset($tmpConfig['db_port'])?$tmpConfig['db_port']:'';?></span></p>
                                <?php if(isset($total_tables)){?>
                                    <p><span class="config-field">表总数：</span><span class="config-value"><?php echo $total_tables;?></span></p>
                                <?php }?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="toolbar-block-placeholder"></div>
            <div class="fix-category" id="fix_category">
                <div class="category-content-block">
                    <ul>
                        <?php foreach ($multiple_tables as $k => $v) {?>
                            <li>
                                <a href="#<?php echo $v['origin']['tableName']?>"><?php echo str_pad($k+1, $num_width, "0", STR_PAD_LEFT).'. '.$v['origin']['tableName']?><span class="category-table-name"><?php echo $v['origin']['tableComment']?></span></a>
                            </li>
                        <?php }?>
                    </ul>
                </div>
            </div>
            <div class="fix-category-handle-bar">
                <b class="lap-ul" id="lap_ul" title="点击折起左侧目录"><</b>
            </div>
            <div class="list-content">
                <h2 style="text-align:center;"><?php echo $title;?></h2>
                <div class="table-list" id="table_list">
                    <?php foreach ($multiple_tables as $k => $v) {?>
                        <div class="table-one-block">
                            <div class="table-name-title-block">
                                <h3 class="table-name-title lap-on">
                                    <a id="<?php echo $v['origin']['tableName'];?>" class="table-name-anchor">
                                        <span class="lap-icon">-</span>
                                        <span class="db-table-index"><?php echo str_pad($k+1, $num_width, "0", STR_PAD_LEFT)?>.</span>
                                        <span class="db-table-name"><?php echo $v['origin']['tableName']?></span>
                                        <span class="db-table-comment"><?php echo $v['origin']['tableComment']?></span>
                                    </a>
                                </h3>
                                <dl class="table-other-info">
                                    <dt>最后更新于：</dt>
                                    <dd><?php echo $v['origin']['createTime']?></dd>
                                    <?php if(!empty($v['origin']['updateTime'])){?>
                                        <dt>更新于：</dt>
                                        <dd><?php echo $v['origin']['updateTime']?></dd>
                                    <?php }?>
                                </dl>
                            </div>
                            <div class="table-one-content">
                                <ul class="ul-sort-title">
                                    <li class="active"><span>自然结构</span></li>
                                    <li><span>字段排序</span></li>
                                    <li><span>建表语句</span></li>
                                </ul>
                                <!--<dl class="table-other-info">
                                    <dt>最后更新于：</dt>
                                    <dd><?php /*echo $v['origin']['createTime']*/?></dd>
                                    <?php /*if(!empty($v['origin']['updateTime'])){*/?>
                                        <dt>更新于：</dt>
                                        <dd><?php /*echo $v['origin']['updateTime']*/?></dd>
                                    <?php /*}*/?>
                                </dl>-->
                                <table class="table-info">
                                    <thead>
                                        <tr>
                                            <th>序号</th><th>字段名</th><th>字段名驼峰形式</th><th>数据类型</th><th>注释</th><th>允许空值</th><th>默认值</th><th>自动递增</th><th>主键</th><th>字符集</th><th>排序规则</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($v['origin']['columns']as $column_key => $f) {?>
                                        <tr>
                                            <td class="column-index"><?php echo str_pad($column_key+1, strlen(count($v['origin']['columns'])), "0", STR_PAD_LEFT);?></td>
                                            <td class="column-field"><?php echo $f['columnName'];?></td>
                                            <td class="column-field"><?php echo $f['columnNameCamelStyle'];?></td>
                                            <td class="column-data-type"><?php echo $f['columnType'];?></td>
                                            <td class="column-comment"><?php echo $f['columnComment'];?></td>
                                            <td class="column-can-be-null"><?php echo $f['isNullable'];?></td>
                                            <td class="column-default-value"><?php echo $f['columnDefault'];?></td>
                                            <td class="column-auto-increment"><?php echo $f['extra'] == 'auto_increment' ? 'YES' : '';?></td>
                                            <td class="column-primary-key"><?php echo $f['columnKey'] == 'PRI' ? 'YES' : '';?></td>
                                            <td class="column-character-set-name"><?php echo $f['characterSetName'];?></td>
                                            <td class="column-collation-name"><?php echo $f['collationName'];?></td>
                                        </tr>
                                    <?php }?>
                                    </tbody>
                                </table>
                                <table class="table-info" style="display:none;">
                                    <thead>
                                    <tr>
                                        <th>序号</th><th>字段名</th><th>字段名驼峰形式</th><th>数据类型</th><th>注释</th><th>允许空值</th><th>默认值</th><th>自动递增</th><th>主键</th><th>字符集</th><th>排序规则</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                    <?php foreach ($v['ordered']['columns']as $column_key => $f) {?>
                                        <tr>
                                            <td class="column-index"><?php echo str_pad($column_key+1, strlen(count($v['origin']['columns'])), "0", STR_PAD_LEFT);?></td>
                                            <td class="column-field"><?php echo $f['columnName'];?></td>
                                            <td class="column-field"><?php echo $f['columnNameCamelStyle'];?></td>
                                            <td class="column-data-type"><?php echo $f['columnType'];?></td>
                                            <td class="column-comment"><?php echo $f['columnComment'];?></td>
                                            <td class="column-can-be-null"><?php echo $f['isNullable'];?></td>
                                            <td class="column-default-value"><?php echo $f['columnDefault'];?></td>
                                            <td class="column-auto-increment"><?php echo $f['extra'] == 'auto_increment' ? 'YES' : '';?></td>
                                            <td class="column-primary-key"><?php echo $f['columnKey'] == 'PRI' ? 'YES' : '';?></td>
                                            <td class="column-character-set-name"><?php echo $f['characterSetName'];?></td>
                                            <td class="column-collation-name"><?php echo $f['collationName'];?></td>
                                        </tr>
                                    <?php }?>
                                    </tbody>
                                </table>
                                <table class="table-info" style="display:none;">
                                    <thead>
                                    <tr>
                                        <th>建表语句</th>
                                    </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td class="db-table-create-sql"><?php echo "<pre>".$v['ordered']['createSql']."</pre>";?></td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    <?php }?>
                </div>
            </div>
            <div class="right-bar-block">
                <div class="right-bar-nav">
                    <div class="go-to-top" id="go_to_top" title="返回页面顶部">回顶部</div>
                </div>
            </div>
            <script type="text/javascript">
                // 键入字符检索表
                var $table_list_arr = [], $table_list_comment_arr = [];
                <?php foreach ($multiple_tables as $k => $v) {?>
                $table_list_arr[<?php echo $k;?>] = "<?php echo $v['origin']['tableName'];?>";
                $table_list_comment_arr[<?php echo $k;?>] = "<?php echo $v['origin']['tableComment'];?>";
                <?php }?>
                var $search_input = document.getElementById('search_input');
                $search_input.onkeyup = function(){
                    var $pattern = $search_input.value;
                    var $lap_table = document.getElementById('lap_table');
                    var table_list = document.getElementById('table_list');
                    var $fix_category = document.getElementById('fix_category');
                    var $category_ul = $fix_category.getElementsByTagName('ul');
                    var $category_li_list = $category_ul[0].children;
                    var $match_result = [];
                    for (var i = 0, $table_count = $table_list_arr.length; i < $table_count; i++){
                        if($table_list_arr[i].match($pattern) || $table_list_comment_arr[i].match($pattern)){
                            $match_result.push(i);
                            table_list.children[i].style.display = 'block';
                            table_list.children[i].children[0].className = 'table-name-title-block lap-off';
                            table_list.children[i].children[0].children[0].className = 'table-name-title lap-off';
                            table_list.children[i].children[1].style.display = 'none';
                            table_list.children[i].children[0].children[0].children[0].children[0].innerText = "+";
                            // 高亮样式
                            table_list.children[i].children[0].children[0].children[0].children[2].innerHTML =
                                table_list.children[i].children[0].children[0].children[0].children[2].innerText;
                            table_list.children[i].children[0].children[0].children[0].children[2].innerHTML =
                                table_list.children[i].children[0].children[0].children[0].children[2].innerHTML.replace($pattern, '<strong style="color:red;">'+ $pattern +'</strong>');
                            table_list.children[i].children[0].children[0].children[0].children[3].innerHTML =
                                table_list.children[i].children[0].children[0].children[0].children[3].innerText;
                            table_list.children[i].children[0].children[0].children[0].children[3].innerHTML =
                                table_list.children[i].children[0].children[0].children[0].children[3].innerHTML.replace($pattern, '<strong style="color:red;">'+ $pattern +'</strong>');
                            $category_li_list[i].children[0].style.color = '#c71212';
                        }else{
                            table_list.children[i].style.display = 'none';
                            table_list.children[i].children[0].className = 'table-name-title-block lap-on';
                            table_list.children[i].children[0].children[0].className = 'table-name-title lap-on';
                            table_list.children[i].children[1].style.display = 'block';
                            table_list.children[i].children[0].children[0].children[0].children[2].innerHTML =
                                table_list.children[i].children[0].children[0].children[0].children[2].innerText;
                            $category_li_list[i].children[0].style.color = '';
                        }
                    }
                    var $search_result_summary = document.getElementById('search_result_summary');
                    $search_result_summary.innerText = '共' + $match_result.length + '条结果';
                    // 若只有一条匹配记录，则展开显示
                    if($match_result.length == 1){
                        table_list.children[$match_result[0]].children[0].className = 'table-name-title-block lap-on';
                        table_list.children[$match_result[0]].children[0].children[0].className = 'table-name-title lap-on';
                        table_list.children[$match_result[0]].children[1].style.display = 'block';
                        table_list.children[$match_result[0]].children[0].children[0].children[0].children[0].innerText = "-";
                        $lap_table.className = 'btn btn-tight lap-table';
                        $lap_table.innerHTML = '折叠内容';
                    }else{
                        $lap_table.className = 'btn btn-tight lap-table-already';
                        $lap_table.innerHTML = '展开内容';
                        if($match_result.length == $table_list_arr.length){
                            $search_result_summary.innerText = '共' + $table_list_arr.length + '个表';
                            for(var j = 0; j<$match_result.length; j++){
                                $category_li_list[j].children[0].style.color = '';
                            }
                        }
                    }
                };
                //点击隐藏侧边导航栏
                var $fixLap = document.getElementById('lap_ul');
                $fixLap.onclick = function(){
                    var fixCategory = document.getElementById('fix_category');
                    var fixCategoryHandleBar = this.parentNode;
                    if(fixCategoryHandleBar.className == 'fix-category-handle-bar'){
                        fixCategory.className = 'fix-category fix-category-hide';
                        fixCategoryHandleBar.className = 'fix-category-handle-bar fix-category-handle-bar-off';
                        this.innerHTML='>';
                    }else if(fixCategoryHandleBar.className == 'fix-category-handle-bar fix-category-handle-bar-off'){
                        fixCategory.className = 'fix-category';
                        fixCategoryHandleBar.className = 'fix-category-handle-bar';
                        this.innerHTML='<';
                    }
                };
                var $fix_category = document.getElementById('fix_category');
                $fix_category.onclick = function () {
                    var $toolBar = document.getElementById('tool_bar');
                    $toolBar.style.position = 'absolute';
                };
                var table_list = document.getElementById('table_list');
                // 内容折叠
                var $title_arr = table_list.getElementsByTagName('h3');
                for (i = 0, $title_arr_length = $title_arr.length; i < $title_arr_length; i++){
                    $title_arr[i].onclick = function(){
                        this.parentNode.nextElementSibling.style.display = (this.parentNode.nextElementSibling.style.display === "none" ? "block" : "none");
                        this.className = (this.className == "table-name-title lap-off" ? "table-name-title lap-on" : "table-name-title lap-off");
                        this.parentNode.className = (this.parentNode.className == "table-name-title-block lap-off" ? "table-name-title-block lap-on" : "table-name-title-block lap-off");
                        this.children[0].children[0].innerText = (this.className == "table-name-title lap-on" ? '-' : '+');
                    }
                }
                // 折叠/展开所有
                var $lap_table = document.getElementById('lap_table');
                $lap_table.onclick = function(){
                    var i = 0,$title_arr_length = 0;
                    if(this.className == 'btn btn-tight lap-table'){
                        for (i = 0, $title_arr_length = $title_arr.length; i < $title_arr_length; i++){
                            $title_arr[i].className = 'table-name-title lap-off';
                            $title_arr[i].parentNode.nextElementSibling.style.display = 'none';
                            $title_arr[i].children[0].children[0].innerText = '+';
                        }
                        this.className = 'btn btn-tight lap-table-already';
                        this.innerHTML = '展开内容';
                        return true;
                    }
                    if(this.className == 'btn btn-tight lap-table-already'){
                        for (i = 0, $title_arr_length = $title_arr.length; i < $title_arr_length; i++){
                            $title_arr[i].className = 'table-name-title lap-on';
                            $title_arr[i].parentNode.nextElementSibling.style.display = 'block';
                            $title_arr[i].children[0].children[0].innerText = '-';
                        }
                        this.className = 'btn btn-tight lap-table';
                        this.innerHTML = '折叠内容';
                        return true;
                    }
                };
                // Tab切换
                var ul_arr = table_list.getElementsByTagName('ul');
                var dl_arr = table_list.getElementsByTagName('dl');
                for (i = 0, ul_arr_length = ul_arr.length; i < ul_arr_length; i++) {
                    var li_arr = ul_arr[i].getElementsByTagName('li');
                    for(var j = 0;j<li_arr.length;j++){
                        (function(j){
                            li_arr[j].onclick = function() {
                                var ul = this.parentNode;
                                //标题样式切换
                                var li = ul.getElementsByTagName('li');
                                for (var k = 0; k < li.length; k++) {
                                    li[k].className = '';
                                }
                                this.className = 'active';
                                var div = ul.parentNode;
                                //表格切换显示
                                var tables = div.getElementsByTagName('table');
                                for (var l = 0; l < tables.length; l++) {
                                    tables[l].style.display = 'none';
                                }
                                tables[j].style.display = 'block';
                            }
                        }(j));
                    }
                }
                //隐藏Tab
                var $hide_tab = document.getElementById('hide_tab');
                $hide_tab.onclick = function(){
                    var i = 0, ul_arr_length = 0;
                    if(this.className == 'btn btn-tight hide-tab-already'){
                        for (i = 0, ul_arr_length = ul_arr.length; i < ul_arr_length; i++) {
                            ul_arr[i].style.display = 'block';
                            dl_arr[i].style.display = 'block';
                        }
                        this.className = 'btn btn-tight hide-tab';
                        this.innerHTML = '隐藏排序tab';
                        return true;
                    }
                    if(this.className == 'btn btn-tight hide-tab'){
                        for (i = 0, ul_arr_length = ul_arr.length; i < ul_arr_length; i++) {
                            ul_arr[i].style.display = 'none';
                            dl_arr[i].style.display = 'none';
                        }
                        this.className = 'btn btn-tight hide-tab-already';
                        this.innerHTML = '显示排序tab';
                        return true;
                    }
                };
                //删除配置信息
                var $unset_config = document.getElementById('unset_config');
                $unset_config.onclick = function () {
                    if (!confirm('确认删除吗？')){
                        return false;
                    }
                    $.ajax({
                        url: "<?php echo $baseUrl;?>?unsetConfig",//请求地址
                        type: "POST",                                           //请求方式
                        dataType: "json",
                        success: function (response, xml) {
                            // 此处放成功后执行的代码
                            window.location.href = "<?php echo $baseUrl;?>?deleteSuccess";
                        },
                        fail: function (status) {
                            // 此处放失败后执行的代码
                            alert('出现问题：' + status);
                        }
                    });
                };
                /**
                 * @doc 删除输入
                 * @author fanggang
                 * @time 2016-03-20 21:51:46
                 */
                var $delete_search_input = document.getElementById('delete_search_input');
                var $searchInput = document.getElementById('search_input');
                $delete_search_input.onclick = function(){
                    if($searchInput.value == '') return false;
                    $searchInput.value = '';
                    //原生js主动触发事件
                    var evt = document.createEvent('MouseEvent');
                    evt.initEvent("keyup",true,true);
                    document.getElementById("search_input").dispatchEvent(evt);
                };

                //回到顶部功能
                goToTop('go_to_top', false);

                /**
                 * @doc 回到顶部功能函数
                 * @param id string DOM选择器ID
                 * @param show boolean true是一直显示按钮，false是当滚动距离超过指定高度时显示按钮
                 * @param height integer 超过高度才显示按钮
                 * @author fanggang
                 * @time 2015-11-19 15:44:51
                 */
                function goToTop (id, show, height) {
                    var oTop = document.getElementById(id);
                    oTop.onclick = scrollToTop;

                    function scrollToTop() {
                        var d = document,
                            dd = document.documentElement,
                            db = document.body,
                            top = dd.scrollTop || db.scrollTop,
                            step = Math.floor(top / 20);
                        (function() {
                            top -= step;
                            if (top > -step) {
                                dd.scrollTop == 0 ? db.scrollTop = top: dd.scrollTop = top;
                                setTimeout(arguments.callee, 20);
                            }
                        })();
                    }
                }
            </script>
            <?php }?>
            <?php if (isset($_GET['mysqlConnectError'])) {?>
                <div class="error-block">
                    <div class="error-title-block">
                        <h1 class="error-title">数据库连接错误</h1>
                    </div>
                    <div class="error-content-block">
                        <div class="content-row">
                            <p class="content-normal-p">数据库连接错误，请检查配置信息是否填写正确。</p>
                            <p class="content-p reason-p">
                                <?php echo isset($_SESSION['_db_connect_errno']) ? $_SESSION['_db_connect_errno'] : 'Unknown error code'; ?> :
                                <?php echo isset($_SESSION['_db_connect_error']) ? $_SESSION['_db_connect_error'] : 'Unknown reason'; ?>
                            </p>
                            <p class="text-center"><a href="?config" class="btn change-db">重新填写数据库配置</a></p>
                        </div>
                    </div>
                </div>
            <?php }?>
            <?php if (isset($_GET['deleteSuccess'])) {?>
                <div class="error-block">
                    <div class="error-title-block">
                        <h1 class="error-title">已经成功删除保存的配置信息</h1>
                    </div>
                    <div class="error-content-block">
                        <div class="content-row">
                            <p class="content-normal-p">已经成功删除保存的配置信息：Session与Cookie中的配置信息均已被安全删除。</p>
                            <p class="text-center"><a href="?config" class="btn change-db">重新填写数据库配置</a></p>
                        </div>
                    </div>
                </div>
            <?php }?>
        </div>
    </div>
    <!-- E 主要内容 E -->
    <div class="clear"></div>
    <!-- S 脚部 S -->
    <div class="footer"></div>
    <!-- E 脚部 E -->
    <script type="text/javascript">
        var $ = {};
        $.ajax = function ajax(options) {
            options = options || {};
            options.type = (options.type || "GET").toUpperCase();
            options.dataType = options.dataType || "json";
            var params = formatParams(options.data);

            //创建 - 非IE6 - 第一步
            /*if (window.XMLHttpRequest) {
             var xhr = new XMLHttpRequest();
             } else { //IE6及其以下版本浏览器
             var xhr = new ActiveXObject('Microsoft.XMLHTTP');
             }*/

            var xhr = createAjax();

            //接收 - 第三步
            xhr.onreadystatechange = function () {
                if (xhr.readyState == 4) {
                    var status = xhr.status;
                    if (status >= 200 && status < 300) {
                        options.success && options.success(xhr.responseText, xhr.responseXML);
                    } else {
                        options.fail && options.fail(status);
                    }
                }
            };

            //连接 和 发送 - 第二步
            if (options.type == "GET") {
                xhr.open("GET", options.url + "?" + params, true);
                xhr.send(null);
            } else if (options.type == "POST") {
                xhr.open("POST", options.url, true);
                //设置表单提交时的内容类型
                xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
                xhr.send(params);
            }
        };
        //格式化参数
        function formatParams(data) {
            var arr = [];
            for (var name in data) {
                if (data.hasOwnProperty(name)) {
                    arr.push(encodeURIComponent(name) + "=" + encodeURIComponent(data[name]));
                }
            }
            return arr.join("&");
        }

        var createAjax = function() {
            var xhr = null;
            try {
                //IE系列浏览器
                xhr = new ActiveXObject("microsoft.xmlhttp");
            } catch (e1) {
                try {
                    //非IE浏览器
                    xhr = new XMLHttpRequest();
                } catch (e2) {
                    window.alert("您的浏览器不支持ajax，请更换！");
                }
            }
            return xhr;
        };

        //浏览器滚动事件处理
        window.onscroll = function(e) {
            /**
             * 顶部导航当用户向下滚动时不钉住，向上滚动时钉住
             * @author 方刚
             * @time 2014-10-30 16:08:58
             */
            var scrollFunc = function(e) {
                e = e || window.event;
                var $toolBar = document.getElementById('tool_bar');
                if (e.wheelDelta) { // 判断浏览器IE，谷歌滑轮事件
                    if (e.wheelDelta > 0) { // 当滑轮向上滚动时
                        //alert("滑轮向上滚动");
                        $toolBar.style.position = 'fixed';
                    }
                    if (e.wheelDelta < 0) { // 当滑轮向下滚动时
                        //alert("滑轮向下滚动");
                        $toolBar.style.position = 'absolute';
                    }
                } else if (e.detail) { // Firefox滑轮事件与Chrome刚好相反
                    if (e.detail > 0) { // 当滑轮向上滚动时
                        //alert("滑轮向下滚动");
                        $toolBar.style.position = 'absolute';
                    }
                    if (e.detail < 0) { // 当滑轮向下滚动时
                        //alert("滑轮向上滚动");
                        $toolBar.style.position = 'fixed';
                    }
                }
            };
            // 给页面绑定滑轮滚动事件
            if (document.addEventListener) {
                document.addEventListener('DOMMouseScroll', scrollFunc, false);
            }
            // 滚动滑轮触发scrollFunc方法
            window.onmousewheel = document.onmousewheel = scrollFunc;

            /**
             * 如果滚动幅度超过半屏浏览器则淡出“回到顶部按钮”
             * @author 方刚
             * @times
             */
            var $go_to_top = document.getElementById('go_to_top');
            var scrollTop = document.documentElement.scrollTop || document.body.scrollTop;
            if(scrollTop > (getWindowSize().height * 3 /4)){
                $go_to_top.style.display = 'block';
            }
            else{
                $go_to_top.style.display = 'none';
            }
        };
        /**
         * 获取窗口可视宽高
         * @author 方刚
         * @time 2014-10-28 17:51:55
         * @returns Array
         */
        function getWindowSize() {
            var winHeight, winWidth;
            if (document.documentElement && document.documentElement.clientHeight && document.documentElement.clientWidth) {
                winHeight = document.documentElement.clientHeight;
                winWidth = document.documentElement.clientWidth;
            }
            var seeSize = [];
            seeSize['width'] = winWidth;
            seeSize['height'] = winHeight;
            return seeSize;
        }
        $.ajax({
            url: "<?php echo $baseUrl?>?json",
            type: "POST",
            data: {},
            dataType: "json",
            success: function (response, xml) {},
            fail: function (status) {
                alert('出现问题：' + status);
            }
        });
    </script>
</div>
</body>
</html>