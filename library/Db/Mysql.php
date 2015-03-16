<?php
/**
 * Filename: index.php   Filepath: framework\libs\db_mysql.class.php
 * @abstract
 *          数据库操作类
 * @copyright Copyright (c) 2009 www.xoyo.com
 * @author 金山软件-逍遥项目组开发团队-关永泽 GuanYongze@kingsoft.com
 * @package     XoYo Framework
 * @subpackage
 * @version   0.1.1	-	Mon Apr 13 16:27:11 CST 2009 16:27:11
 */

/*
便用方法:
$db = new db_mysql($cfg);

查询：query方法只应用于执行数据读取的SQL，默认读从库（有从库的情况下），没有从库读主库；但当第二个参数为true时直接读主库。
$slq = "SELECT * FROM table WHERE field=?";
$db->query($sql); 优先读从库，返回一个资源
$db->query($sql, true|false); 返回一个资源，参数2代表是否直接读主库
$db->getOne($sql, true|false); 返回第一列的第一个元素，参数2代表是否直接读主库
$db->getRow($sql, true|false); 返回第一列数据
$db->getAll($sql, true|false); 返回所有列

$rs = $db->query($sql);
while($row = $db->getArray($rs))
{
	print_r($row);
}

更新：execute方法只用于数据更新的SQL如insert, update, delete，execute直接操作主库
$sql = "DELETE FROM table WHERE field=?";
$db->ececute($sql);

事务：
$db->startTrans();
try
{
	$db->execute($sql);
	$db->commit();
}
catch ($e)
{
	$db->rollback();
}

简化操作：要简化操作中，$data数据不需要事先调用$db->quote($string)方法，这一操作由数据库类完成，但like查询时仍然要手动调用$db->quoteLike($string)方法
$db->select($fields, $tables, $where = '', $group_by = '', $having = '', $order_by = '', $limit = '', $master = false);
$db->insert($table_name, $data);
$db->insertMulti($tble_name, $data);
$db->update($table_name, $data, $where);
$db->replace($table_name, $data);

其它：
$db->lastId(); 返回最后一个insert id
$db->lastSql(); 返回最后一条sql语句
*/

define ( 'CLIENT_MULTI_RESULTS', 131072 );
define ( 'DB_MYSQL_SLAVE_KEY', '_DB_MYSQL_SLAVE_KEY_' );

/**
 * 数据库类
 *
 */
class Db_Mysql {

	/*
	 * 当前SQL指令
	 */
	private $sql = '';

	// 事务指令数
	private $trans_times = 0;

	// 当前连接ID
	private $link_id = null; // 当前使用的连接
	private $m_link_id = null; // 主库连接
	private $s_link_id = null; // 从库连接


	// 当前查询ID
	private $query_id = null;

	// 数据库连接参数配置
	private $config = array ();

	// 是否多库
	private $multi_server = false;

	/**
	 * 架构函数
	 *
	 * @param array $config
	 */
	public function __construct($config) {
		$this->config = $config;
		$this->multi_server = empty ( $this->config ['slave'] ) ? false : true;
	}

	/**
	 * 初始化数据库连接
	 *
	 * @param array $config
	 */

	private function initConnect($master = true) {
		if ($master || !$this->multi_server) {
			if($this->m_link_id){
				$this->link_id = $this->m_link_id;
			} else {
				$this->connect ( $this->config ['master'] );
				$this->m_link_id = $this->link_id;
			}
		} else {
			if($this->s_link_id){
				$this->link_id = $this->s_link_id;
			} else {
				$this->connect ( $this->config ['slave'] );
				$this->s_link_id = $this->link_id;
			}
		}
	}

	/**
	 * 强制重新连接数据库方法
	 *
	 * @param array $config
	 */
	public function reConnect($master = true){
		if ($master || !$this->multi_server) {
			$this->connect ( $this->config ['master'] );
			$this->m_link_id = $this->link_id;
		} else {
			$this->connect ( $this->config ['slave'] );
			$this->s_link_id = $this->link_id;
		}
	}

	/**
	 * 连接数据库方法
	 *
	 * @param array $config
	 */
	private function connect(&$config) {
		$this->link_id = mysql_connect ( $config ['host'], $config ['username'], $config ['password'], true, CLIENT_MULTI_RESULTS );
		if (! $this->link_id) {
			$this->halt ( '数据库连接错误！' );
		}
		mysql_select_db($this->config['dbname'], $this->link_id) || $this->halt('无法使用数据库！');;
		mysql_query ( "SET NAMES '" . $this->config ['charset'] . "'", $this->link_id );
	}

	/**
	 * 执行查询 主要针对 SELECT, SHOW 等数据读取指令
	 *
	 * @param string $sql 要执行的语句
	 * @param bool $master default:false 为true:强制读主库；为false：在有从库的的情况下优先读从库，否则读主库
	 *
	 * @return resource $query_id 查询ID
	 */
	public function query($sql = '', $master = false) {
		$this->sql = $sql;
		$this->initConnect ( $master );
		return $this->doExecute ( $sql );
	}


	/**
	 * unbuffered版query
	 *
	 * @see  self::query
	 *
	 * @return resource $query_id 查询ID
	 */
	public function uQuery($sql = '', $master = false) {
		$this->sql = $sql;
		$this->initConnect ( $master );
		return $this->uDoExecute ( $sql );
	}

	/**
	 * 执行语句 针对 INSERT, UPDATE 以及DELETE等数据更新
	 *
	 * @param string $sql 要执行的语句
	 *
	 * @return resource $query_id 查询ID
	 */
	public function execute($sql = '') {
		$this->sql = $sql;
		$this->initConnect ( true );
		return $this->doExecute ( $sql );
	}

	/**
	 * 执行查询
	 *
	 * @param array $config
	 */
	private function doExecute($sql) {
		($this->query_id = mysql_query ( $sql, $this->link_id )) || $this->halt ( '执行SQL查询时出错！' );
		return $this->query_id;
	}

	/**
	 * 执行查询
	 *
	 * @param array $config
	 */
	private function uDoExecute($sql) {
		($this->query_id = mysql_unbuffered_query ( $sql, $this->link_id )) || $this->halt ( '执行SQL查询时出错！' );
		return $this->query_id;
	}

	/**
	 * 按指针返回各行
	 */
	public function getArray($query_id, $mode = MYSQL_ASSOC) {
		return mysql_fetch_array ( $query_id, $mode );
	}

	/**
	 * 根据执行结果取得影响行数
	 *
	 * @return int
	 * @access  public
	 */
	public function getAffectedRows() {
		return mysql_affected_rows ( $this->link_id );
	}

	/**
	 * 根据查询结果计算结果集条数
	 *
	 * @return int
	 * @access  public
	 * 
	 */
	public function getNumRows($query_id) {
		return mysql_num_rows ( $query_id );
	}


	/**
	 * 根据查询结果计算结果集条数
	 *
	 * @return int
	 * @access  public
	 */
	public function getNumFields($query_id) {
		return mysql_num_fields ( $query_id );
	}

	/**
	 * mysql_result
	 *
	 * @return int
	 * @access  public
	 */
	public function result($query_id, $row = 0) {
		return mysql_result ( $query_id, $row );
	}

	/**
	 *
	 * 启动事务
	 *
	 * @access public
	 * @return void
	 */
	public function startTrans() {
		$this->initConnect ( true );

		//数据rollback 支持
		$this->trans_times == 0 && mysql_query ( 'START TRANSACTION', $this->link_id );
		$this->trans_times ++;
		return;
	}

	/**
	 * Alias for self::startTrans
	 */
	public function start(){
		$this->startTrans();
	}

	/**
	 * 用于非自动提交状态下面的查询提交
	 * @access public
	 * @return boolen
	 */
	public function commit() {
		if ($this->trans_times > 0) {
			$result = mysql_query ( 'COMMIT', $this->link_id ) || $this->halt ();
			$this->trans_times = 0;
		}
		return true;
	}

	/**
	 * 事务回滚
	 * @access public
	 * @return boolen
	 */
	public function rollback() {
		if ($this->trans_times > 0) {
			mysql_query ( 'ROLLBACK', $this->link_id ) || $this->halt ();
			$this->trans_times = 0;
		}
		return true;
	}

	/**
	 * 返回某一单独字段值的查询。例如SELECT COUNT(id) FROM ...
	 */
	public function getOne($sql, $master = false) {
		$one = false;
		$rs = $this->query ( $sql, $master );
		if ($rs) {
			$one = @$this->result ( $rs, 0 );
		}
		return $one;
	}

	/**
	 * 只返回一行的查询
	 */
	public function getRow($sql, $master = false) {
		$rs = $this->query ( $sql . ' LIMIT 0, 1', $master );
		if(!$rs) return false;
		$arr = $this->getArray ( $rs );
		$this->free ();
		return $arr;
	}

	/**
	 * 返回多行的查询
	 */
	public function getAll($sql, $master = false) {
		$rs = $this->query ( $sql, $master );
		if(!$rs) return false;
		$arr = array ();
		while ( $row = $this->getArray ( $rs ) ) {
			$arr [] = $row;
		}
		$this->free ();
		return $arr;
	}

	/**
	 * 返回最后写入的记录编号
	 */
	public function lastId() {
		return mysql_insert_id ( $this->link_id );
	}

	/**
	 * 获取最近一次查询的sql语句
	 * @access public
	 * @return string
	 */
	public function lastSql() {
		return $this->sql;
	}

	/**
	 * 释放查询结果
	 * @access public
	 */
	private function free() {
		@mysql_free_result ( $this->query_id );
		$this->query_id = 0;
	}

	/**
	 * 关闭数据库连接
	 */
	public function close() {
		if (is_resource ( $this->m_link_id )) {
			mysql_close ( $this->m_link_id );
		}
		if (is_resource ( $this->s_link_id )) {
			mysql_close ( $this->s_link_id );
		}
		$this->link_id = 0;
	}

	/**
	 * Escape a string according to the MySQL escape format.
	 *
	 * @param string The string to be escaped.
	 * @return string The escaped string.
	 */
	public function quote($string) {

		$this->link_id || $this->initConnect ();
		$string = mysql_real_escape_string ( $string, $this->link_id );
		return $string;

		/*
		if (function_exists ( 'mysql_real_escape_string' )) {
			$this->link_id || $this->initConnect ();
			$string = mysql_real_escape_string ( $string, $this->link_id );
		} elseif (function_exists ( 'mysql_escape_string' )) {
			$string = mysql_escape_string ( $string );
		} else {
			$string = addslashes ( $string );
		}
		return $string;
		*/
	}

	/**
	 * Escape a string used within a like command.
	 *
	 * @param string The string to be escaped.
	 * @return string The escaped string.
	 */
	public function quoteLike($string) {
		return $this->quote ( str_replace ( array ('%', '_' ), array ('\\%', '\\_' ), $string ) );
	}

	/**
	 * 简化查询
	 */
	public function select($fields, $tables, $where = '', $group_by = '', $having = '', $order_by = '', $limit = '', $master = false) {
		$sql = $this->getSelectSql($fields, $tables, $where = '', $group_by = '', $having = '', $order_by = '', $limit = '');
		return $this->getAll ( $sql, $master );
	}

	/**
	 * 插入数据
	 *
	 * @param    string     $tbl_name   表名
	 * @param    array      $info       数据
	 */
	public function insert($tble_name, $data) {
		$sql = $this->getInsertSql ( $tble_name, $data );
		return $this->execute ( $sql );
	}

	/**
	 * 插入数据
	 *
	 * @param    string     $tbl_name   表名
	 * @param    array      $info       数据
	 */
	public function insertMulti($tble_name, $data) {
		$sql = $this->getMultiInsertSql ( $tble_name, $data );
		return $this->execute ( $sql );
	}

	/**
	 * 插入数据
	 *
	 * @param    string     $tbl_name   表名
	 * @param    array      $info       数据
	 */
	public function replace($tble_name, $data) {
		$sql = $this->getReplaceSql ( $tble_name, $data );
		return $this->execute ( $sql );
	}

	/**
	 * 插入数据
	 *
	 * @param    string     $tbl_name   表名
	 * @param    array      $info       数据
	 */
	public function update($tble_name, $data, $condition = '') {
		$sql = $this->getUpdateSql ( $tble_name, $data, $condition );
		return $this->execute ( $sql );
	}

	public function getSelectSql($fields, $tables, $where = '', $group_by = '', $having = '', $order_by = '', $limit = '') {
		$sql = "SELECT {$fields} FROM {$tables}";
		empty ( $where ) || $sql .= " WHERE {$where}";
		empty ( $group_by ) || $sql .= " GROUP BY {$group_by}";
		empty ( $having ) || $sql .= " HAVING {$having}";
		empty ( $order_by ) || $sql .= " ORDER BY {$order_by}";
		empty ( $limit ) || $sql .= " LIMIT {$limit}";
		return $sql;
	}

	/**
	 * 获取插入语句
	 *
	 * @param    string     $tbl_name   表名
	 * @param    array      $info       数据
	 */
	public function getInsertSql($tble_name, $data) {
		$sql = '';
		if (! empty ( $data ) && is_array ( $data )) {

			foreach ( array_keys ( $data ) as $key ) {
				$data [$key] = $this->quote ( $data [$key] );
			}

			$fields = "(`" . implode ( "`,`", array_keys ( $data ) ) . "`)";
			$values = "('" . implode ( "','", $data ) . "')";

			$sql = "INSERT INTO
						{$tble_name}
						{$fields}
                    VALUES
                    	{$values}";
		}
		return $sql;
	}

	/**
	 * 多数据插入
	 *
	 * @param    string     $tbl_name       表名
	 * @param    array      $data           数据，必须是形如 $array[索引][字段名]才能正确执行
	 */
	public function getMultiInsertSql($tble_name, $data) {
		$sql = '';
		if (! empty ( $data ) && is_array ( $data )) {
			$values = array ();
			$fields = array ();
			$first = true;
			foreach ( $data as $k => $v ) {
				foreach ( array_keys ( $v ) as $kk ) {
					$first && $fields [] = $kk;
					$v [$kk] = $this->quote ( $v [$kk] );
				}
				$first = false;
				$values [] = "('" . implode ( "','", $v ) . "')";
			}
			$fields = "(`" . implode ( "`,`", $fields ) . "`)";
			$values = implode ( ",", $values );

			$sql = "INSERT INTO
						{$tble_name}
						{$fields}
                    VALUES
                        {$values}";
		}
		return $sql;
	}

	/**
	 * 获取替换语句
	 *
	 * @param    string     $tbl_name   表名
	 * @param    array      $info       数据
	 */
	public function getReplaceSql($tble_name, $data) {
		$sql = '';
		if (! empty ( $data ) && is_array ( $data )) {
			foreach ( array_keys ( $data ) as $key ) {
				$data [$key] = $this->quote ( $data [$key] );
			}

			$fields = "(`" . implode ( "`,`", array_keys ( $data ) ) . "`)";
			$values = "('" . implode ( "','", $data ) . "')";

			$sql = "REPLACE INTO
						{$tble_name}
						{$fields}
                    VALUES
                        {$values}";
		}
		return $sql;
	}

	/**
	 * 更新记录
	 *
	 * @param    string     $tbl_name   表名
	 * @param    array      $info       数据
	 * @param    array      $condition  条件
	 */
	public function getUpdateSql($tble_name, $data, $condition = '') {
		$sql = '';
		if (! empty ( $data ) && is_array ( $data )) {
			$set = '';
			foreach ( $data as $k => $v ) {
				$v = $this->quote ( $v );
				$set [] = "`{$k}` = '{$v}'";
			}
			$set = implode ( ', ', $set );

			empty ( $condition ) || $condition = ' WHERE ' . $condition;

			$sql = "UPDATE
						{$tble_name}
					SET
						{$set}
						{$condition}";
		}
		return $sql;
	}

	/**
	 * 数据库异常处理
	 *
	 * @param   string  $message	//信息
	 * @return  void
	 * @access  public
	 */
	private function halt($message) {

		$out = "DB Error:";
		$out .= $message		? "[message : {$message}]" : '';
		$out .= $this->link_id	? "[error : ".mysql_error ( $this->link_id )."]" : '';
		$out .= $this->link_id	? "[errno : ".mysql_errno ( $this->link_id )."]" : '';
		$out .= $this->sql		? "[SQL : {$this->sql}]" : '';

		//throw new db_exception($out);
		//exit();
	}

	/**
	 * 获取存储过程的返回值
	 *
	 * @param $sql
	 * @param $q_name
	 * @return unknown_type
	 */
	public function getProcedureId($sql='', $q_name)
	{
		$this->sql = $sql;
		$this->initConnect ( true );
		($rs= mysql_query ( $sql, $this->link_id )) || $this->halt ( '执行SQL查询时出错！' );
		while   ($row   =   mysql_fetch_array($rs))   {
			$this->query_id = $row[$q_name];
		}
		return $this->query_id;
	}
}
?>