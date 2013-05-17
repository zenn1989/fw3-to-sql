<?php
/*****************************************************************
******************************************************************
******************* (c) Пятинский Михаил 2013 ********************
******************************************************************
*/

$config = array(
'url' => 'http://localhost',
'db_host' => 'localhost',
'db_user' => 'mysql',
'db_pass' => 'mysql',
'db_name' => 'fish'
);


class dataMiner
{
	private $database = null;
	private $file_byte_column = null;
	private $file_byte_table = null;
	
	private $filename = null;
	
	// массив обработанных данных
	private $string_column_array = array();
	private $string_table_array = array();
	
	// байты для разрезки данных
	private $column_byte_point = array(0, 129, 1, 255, 129, 5, 0);
	private $row_start_point = array(0, 129, 1, 255, 129, 8, 0);
	private $row_string_point = null;
	
	// подключение к бд
	private function db()
	{
		global $config;
		if($this->database == null)
		{
			try
			{
				$this->database = new PDO("mysql:host={$config['db_host']};dbname={$config['db_name']}", $config['db_user'], $config['db_pass']);
				$this->database->setAttribute(PDO::MYSQL_ATTR_INIT_COMMAND, "SET NAMES utf8");
			}
			catch(PDOException $e)
			{
				exit("Database connection error ".$e);
			}
		}
		return $this->database;
	}
	
	/**
	** Открытие файлов
	*/
	public function open($file_fw3_name)
	{
		$this->filename = $file_fw3_name;
		$this->file_byte_column = file_get_contents('db/'.$file_fw3_name.'_I.FW3');
		$this->file_byte_table = file_get_contents('db/'.$file_fw3_name.'_T.FW3');
		return $this;
	}
	
	// приводит содержимое мнимой таблицы бд к человеческому представлению
	// выбирается диапазон заголовков
	// и собирается массив $this->string_column_array
	// так же собираются массивы строк для данной таблицы
	public function makeReadable()
	{
		// ищем начало и конец названия колонок и запоминаем их
		$point_string = $this->byteToString($this->column_byte_point);
		$start_point = strpos($this->file_byte_column, $point_string);
		$end_point = strpos($this->file_byte_column, $point_string, $start_point+1);
		$length = $end_point - $start_point;
		$column_string = substr($this->file_byte_column, $start_point+strlen($point_string), $length-strlen($point_string));
		$column_array = explode(" ", $column_string);
		foreach($column_array as $value)
		{
			if(strlen($value) > 0)
			{
				$this->string_column_array[] = $value;
			}
		}
		// ищем начало и конец для строк таблицы и запоминаем их
		$this->row_string_point = $this->byteToString($this->row_start_point);
		$table_start = strpos($this->file_byte_table, $this->row_string_point);
		$table_end = $this->findEndPoint();
		$table_length = $table_end-$table_start;
		$row_string = substr($this->file_byte_table, $table_start, $table_length);
		$line_row = explode($this->row_string_point, $row_string);
		$i_j = 1;
		// можно юзать и for($i=1;$i<=sizeof($line_row);$i++) - на вкус и цвет
		foreach($line_row as $single_row)
		{
			if(strlen($single_row) > 0)
			{
				$item_array = explode(" ", $single_row);
				foreach($item_array as $field)
				{
					if(strlen($field) > 0)
						$this->string_table_array[$i_j][] = $field;
				}
				$i_j++;
			}
		}
	}
	/**
	** Ищем точку где заканчиваются строки таблицы
	*/
	private function findEndPoint($start = 0, $before_starter = 0)
	{
		// это первая интерация ?
		if($start == 0)
		{
			$start = strpos($this->file_byte_table, $this->row_string_point);
		}
		if(FALSE === ($end = strpos($this->file_byte_table, $this->row_string_point, $start+1)))
		{
			return $before_starter;
		}
		return $this->findEndPoint($end+1, $start);
	}
	/**
	** Конвертируем байтовый массив в строчный вид
	*/
	public function byteToString($data)
	{
		return call_user_func_array("pack", array_merge(array("C*"), $data));
	}
	
	public function storeDb($table)
	{
		// т.к. мы парсим определенного типа файл смысла использовать prepared statement - нет, входящие данные безопасные
		// если вы эстет - не читайте код ниже ))
		$column_size = sizeof($this->string_column_array);
		$create_query = "CREATE TABLE IF NOT EXISTS `{$table}` (
						`table` VARCHAR( 24 ) NOT NULL ,";
		$insert_query = "INSERT INTO `{$table}` (`table`, ";
		for($i=0;$i<sizeof($this->string_column_array);$i++)
		{
			if($i==(sizeof($this->string_column_array)-1))
			{
				$create_query .= "`{$this->string_column_array[$i]}` decimal(24,2) NOT NULL DEFAULT '0.00'";
				$insert_query .= "`{$this->string_column_array[$i]}`";
			}
			else
			{
				$create_query .= "`{$this->string_column_array[$i]}` decimal(24,2) NOT NULL DEFAULT '0.00' , 
							 ";
				$insert_query .= "`{$this->string_column_array[$i]}`, ";
			}
		}
		$create_query .= "
						) ENGINE = MYISAM ;";
		$insert_query .= ") VALUES ";
		$s = 1;
		foreach($this->string_table_array as $row_array)
		{
			$field_size = sizeof($row_array);
			$insert_query .= "( '{$this->filename}', ";
			foreach($row_array as $field)
			{
				$insert_query .= "'{$field}', ";
			}
			$null_diff = $column_size-$field_size;
			for($i=1;$i<=$null_diff;$i++)
			{
				if($i==$null_diff)
				{
					$insert_query .= "'0.00'";
				}
				else
				{
					$insert_query .= "'0.00', ";
				}
			}
			if($s == sizeof($this->string_table_array))
			{
				$insert_query .= " );";
			}
			else
			{
				$insert_query .= " ), ";
			}
			$s++;
		}
		$this->db()->query($create_query);
		$this->db()->query($insert_query);
	}
	
	public function clean()
	{
		$this->file_byte_column = null;
		$this->file_byte_table = null;
		$this->string_column_array = array();
		$this->string_table_array = array();
	}
}

// инициируем майнер
$miner = new dataMiner;
// задаем имя файла базы fw3 и приводим его к читабельному виду
$miner->open('M1')->makeReadable();
// заносим значения в базу данных указывая имя таблицы в которую необходимо поместить данные
$miner->storeDb('bio_trachurus');


?>