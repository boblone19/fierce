<?php

namespace Fierce;

class DBConnectionPdo
{
  public $pdo;
  protected $structures = [];
  public $validEntities = null;
  
  public function __construct($dsn, $username, $password)
  {
    if (!$dsn) { // probably running a unit test. Assume mocked PDO will be provided
      return;
    }
    
    $this->pdo = new \PDO($dsn, $username, $password, [
    	\PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES utf8'
    ]);
    $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
  }
  
  public function checkEntity($entity)
  {
    if (isset($this->validEntities[$entity])) {
      return;
    }
    
    $q = $this->pdo->prepare("
      show tables
    ");
    $q->execute([$entity]);
    
    $this->validEntities = [];
    while ($entityName = $q->fetchColumn()) {
      $this->validEntities[$entityName] = true;
    }
    
    if (!isset($this->validEntities[$entity])) {
      throw new \Exception("Attepmted to use invalid entity '$entity'.");
    }
  }
  
  public function find($entity, $params, $orderBy, $range)
  {
    $this->checkEntity($entity);
    
    $sql = "
      SELECT * FROM `$entity`
      where 1
    ";
    
    $queryParams = [];
    foreach ($params as $column => $rule) {
      if (!is_array($rule)) {
        $rule = ['=', $rule];
      }
      
      list($operator, $value) = $rule;
      
      $sql .= "
        and `$column` $operator :$column
      ";
      $queryParams[$column] = $value;
    }
    
    if ($orderBy) {
      $asc = ($orderBy[0] != '-');
      $key = trim($orderBy, '-+');
      
      $sql .= "ORDER BY `$key` " . ($asc ? "ASC\n" : "DESC\n");
    }
    
    if ($range) {
      list($start, $end) = $range;
      
      $sql .= 'LIMIT ' . (int)$start . ', ' . (int)$end . "\n";
    }
    
    $q = $this->pdo->prepare($sql);
    $q->execute($queryParams);
    
    $rows = [];
    while ($row = $q->fetch(\PDO::FETCH_OBJ)) {
      $structure = $this->entityStructure($entity);
      foreach ($structure as $field) {
        $fieldName = $field->name;
        
        switch ($field->type) {
          case 'date':
            if ($row->$fieldName !== null) {
              $row->$fieldName = new \DateTime($row->$fieldName);
            }
            break;
          case 'datetime':
            if ($row->$fieldName !== null) {
              $row->$fieldName = new \DateTime($row->$fieldName);
            }
            break;
          case 'int':
          case 'uint':
            $row->$fieldName = (int)$row->$fieldName;
            break;
        }
      }
      
      if (isset($row->id)) {
        $rows[$row->id] = $row;
      } else {
        $rows[] = $row;
      }
    }
    
    return $rows;
  }
  
  public function byId($entity, $id)
  {
    $this->checkEntity($entity);
    
    $q = $this->pdo->prepare("
      SELECT * FROM `$entity` where `id` = :id
    ");
    $q->execute(['id' => $id]);
    
    $row = $q->fetch(\PDO::FETCH_OBJ);
    
    if (!$row) {
    	throw new \exception("Invalid id $id on $entity");
    }
    
    $structure = $this->entityStructure($entity);
    foreach ($structure as $field) {
      $fieldName = $field->name;
      
      switch ($field->type) {
        case 'date':
          if ($row->$fieldName && $row->$fieldName != '0000-00-00') {
            $row->$fieldName = new \DateTime($row->$fieldName);
          }
          break;
        case 'datetime':
          if ($row->$fieldName && $row->$fieldName != '0000-00-00 00:00:00') {
            $row->$fieldName = new \DateTime($row->$fieldName);
          }
          break;
        case 'int':
        case 'uint':
          $row->$fieldName = (int)$row->$fieldName;
          break;
      }
    }
    
    if (!$row) {
      throw new \exception('invalid id ' . $id . ' for entity ' . $entity);
    }
    
    return $row;
  }
  
  public function idExists($entity, $id)
  {
    $this->checkEntity($entity);
    
    $q = $this->pdo->prepare("
      SELECT count(*) FROM `$entity` where `id` = :id
    ");
    $q->execute(['id' => $id]);
    
    $count = $q->fetchColumn();
    
    return $count > 0;
  }
  
  public function write($entity, $id, $row, $allowOverwrite)
  {
    $this->checkEntity($entity);
    
    // clone and sanitize the row
    $row = (object)get_object_vars((object)$row); // PHP's built in clone() leaks memory (or perhaps just stops it being garbage collected)
    $row->id = $id;
    
    list($valuesSql, $values) = $this->valuesSql($entity, $row);
    
    $sql = "INSERT INTO `$entity` set\n`id` = :id,\n" . $valuesSql;
    
    if ($allowOverwrite) {
      $sql .= "\n\nON DUPLICATE KEY UPDATE\n" . $valuesSql;
    }
    
    $q = $this->pdo->prepare($sql);
    
    $q->execute($values);
  }
  
  public function insert($entity, $row)
  {
    $this->checkEntity($entity);
    
    $row = (object)get_object_vars((object)$row); // PHP's built in clone() leaks memory (or perhaps just stops it being garbage collected)
    
    list($valuesSql, $values) = $this->valuesSql($entity, $row);
    
    $sql = "INSERT INTO `$entity` set\n" . $valuesSql;
    
    $q = $this->pdo->prepare($sql);
    
    $q->execute($values);
  }
  
  public function update($entity, $where, $row)
  {
    $this->checkEntity($entity);
    
    // clone and sanitize the row
    $where = (object)get_object_vars((object)$where); // PHP's built in clone() leaks memory (or perhaps just stops it being garbage collected)
    $row = (object)get_object_vars((object)$row); // PHP's built in clone() leaks memory (or perhaps just stops it being garbage collected)
    
    list($whereSql, $where) = $this->valuesSql($entity, $where, '_where');
    list($valuesSql, $values) = $this->valuesSql($entity, $row, '_value');
    
    $sql = "UPDATE `$entity` set\n" . $valuesSql;
    $sql .= "\n\nWHERE \n" . $whereSql;
    
    $q = $this->pdo->prepare($sql);
    
    $q->execute(array_merge($where, $values));
  }
  
  public function archive($entity, $id)
  {
    $this->checkEntity($entity);
    
    // road row
    $q = $this->pdo->prepare("
      SELECT * FROM `$entity` where `id` = :id
    ");
    $q->execute(['id' => $id]);
    $row = $q->fetch(\PDO::FETCH_OBJ);
    
    // write archive
    $q = $this->pdo->prepare("
      INSERT INTO `_archive` set
      `datetime` = :datetime,
      `entity` = :entity,
      `data` = :row
    ");
    
    $q->execute([
      'datetime' => date('Y-m-d H:i:s'),
      'entity' => $entity,
      'row' => json_encode($row, JSON_PRETTY_PRINT)
    ]);
  }
  
  public function purge($entity, $id)
  {
    $this->checkEntity($entity);
    
    $sql = "DELETE FROM `$entity` where\n`id` = :id";
    
    $q = $this->pdo->prepare($sql);
    
    $q->execute(['id' => $id]);
  }
  
  public function entityStructure($entity)
  {if (isset($this->structures[$entity])) {
      return $this->structures[$entity];
    }
    
    $this->checkEntity($entity);
    
    $q = $this->pdo->prepare("
      DESCRIBE `$entity`
    ");
    $q->execute();
    
    $structure = [];
    while ($rawField = $q->fetch(\PDO::FETCH_OBJ)) {
      $field = (object)[
        'name' => $rawField->Field,
        'raw_type' => $rawField->Type,
        'null' => $rawField->Null == 'YES',
        'default' => $rawField->Default
      ];
      
      if (preg_match('/^varchar\(([0-9]+)\)/', $field->raw_type, $matches)) {
        $field->type = 'string';
        $field->length = (int)$matches[1];
      } else if ($field->raw_type == 'date') {
        $field->type = 'date';
      } else if ($field->raw_type == 'datetime') {
        $field->type = 'datetime';
      } else if ($field->raw_type == 'text') {
        $field->type = 'text';
      } else if ($field->raw_type == 'mediumtext') {
        $field->type = 'text';
      } else if ($field->raw_type == 'tinyint(1)') {
        $field->type = 'bool';
      } else if ($field->raw_type == 'int(11) unsigned') {
        $field->type = 'uint';
      } else if ($field->raw_type == 'int(11)') {
        $field->type = 'int';
      } else if ($field->raw_type == 'blob') {
        $field->type = 'blob';
      } else {
        throw new \exception("Unknown field type: " . json_encode($rawField));
      }
      
      $structure[$field->name] = $field;
    }
    
    $this->structures[$entity] = $structure;
    
    return $structure;
  }
  
  public function valuesSql($entity, $row, $suffix='')
  {
    $structure = $this->entityStructure($entity);
    
    $valuesSql = "";
    foreach ($structure as $field) {
      $fieldName = $field->name;
      
      if ($fieldName == 'id') {
        continue;
      }
      
      if (!property_exists($row, $fieldName)) {
        continue;
      }
      
      switch ($field->type) {
        case 'string':
          if ($row->$fieldName === null || $row->$fieldName === false) {
            $value = null;
          } else {
            try {
              $value = (string)$row->$fieldName;
            } catch (\exception $e) {
              throw new \exception("Unable to convert $fieldName value into a string");
            }
          }
          
          if (strlen($value) > $field->length) {
            throw new \exception('Value too long for table column ' . $fieldName);
          }
          break;
        case 'date':
          if ($row->$fieldName === null || $row->$fieldName === false) {
            $value = null;
          } else if (is_a($row->$fieldName, 'DateTime')) {
            $value = $row->$fieldName->format('Y-m-d');
          } else {
            throw new \exception('Expecting a DateTime, but got ' . gettype($row->$fieldName) . ' for ' . $fieldName);
          }
          break;
        case 'datetime':
          if ($row->$fieldName === null || $row->$fieldName === false) {
            $value = null;
          } else if (is_a($row->$fieldName, 'DateTime')) {
            $value = $row->$fieldName->format('Y-m-d H:i:s');
          } else {
            throw new \exception('Expecting a DateTime, but got ' . gettype($row->$fieldName) . ' for ' . $fieldName);
          }
          break;
        
        case 'text':
          if ($row->$fieldName === null || $row->$fieldName === false) {
            $value = null;
          } else {
            try {
              $value = (string)$row->$fieldName;
            } catch (\exception $e) {
              throw new \exception("Unable to convert $fieldName value into a string");
            }
          }

          if ($field->raw_type == 'text' && strlen($value) > 65535) {
            throw new \exception('Value too long for table column ' . $fieldName);
          }
          
          if ($field->raw_type == 'mediumtext' && strlen($value) > 16777215) {
            throw new \exception('Value too long for table column ' . $fieldName);
          }
          break;
        
        case 'int':
          $value = (int)$row->$fieldName;
          break;
        
        case 'uint':
          $value = (int)$row->$fieldName;
          if ($value < 0) {
            throw new exception('Cannot write negative value for table column ' . $fieldName);
          }
          break;
        
        case 'bool':
          $value = (int)(bool)$row->$fieldName;
          break;
        
        case 'blob':
          if ($row->$fieldName === null || $row->$fieldName === false) {
            $value = null;
          } else {
            try {
              $value = (string)$row->$fieldName;
            } catch (\exception $e) {
              throw new \exception("Unable to convert $fieldName value into a string");
            }
          }
          
          if (strlen($value) > 65535) {
            throw new \exception('Value too long for table column ' . $fieldName);
          }
          break;
        
        default:
          throw new \exception('Unknown type ' . $field->type);
      }
      
      if ($value === null && !$field->null) {
        throw new \exception('Attempt to write null to ' . $fieldName);
      }
      
      $values[$fieldName . $suffix] = $value;
      $valuesSql .= ",\n`$fieldName` = :$fieldName$suffix";
    }
    
    $valuesSql = ltrim($valuesSql, ",\n");
    
    return [$valuesSql, $values];
  }
  
  public function blankEntity($entity)
  {
    $this->checkEntity($entity);
    
  	$structure = $this->entityStructure($entity);
  	
  	$row = (object)[];
    foreach ($structure as $field) {
      $fieldName = $field->name;
      
      $row->$fieldName = $field->default;
    }
    
    return $row;
  }
  
  public function entityExists($entity)
  {
    $q = $this->pdo->prepare("
      show tables like ?
    ");
    $q->execute([$entity]);
    
    $result = $q->fetchColumn();
    
    return (bool)$result;
  }
  
  public function createEntity($entity, $columns=[])
  {
    $q = $this->pdo->prepare("
      CREATE TABLE `$entity` (
        `id` varchar(255) NOT NULL DEFAULT '',
        PRIMARY KEY (`id`)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8;
    ");
    
    $q->execute();
    
    foreach ($columns as $column) {
      if (is_string($column)) {
        $this->addColumn($entity, $column);
      } else {
        $name = $column['name'];
        unset($column['name']);
        
        $this->addColumn($entity, $name, $column);
      }
    }
  }
  
  public function removeEntity($entity)
  {
    $q = $this->pdo->prepare("
      DROP TABLE `$entity`;
    ");
    $q->execute();
  }
  
  public function addColumn($entity, $name, array $options=['type'=>'string', 'length'=>255, 'null'=>false, 'default'=>''])
  {
    $this->checkEntity($entity);
    
    if (!isset($options['type'])) {
      $options['type'] = 'string';
    }
    if (!isset($options['length'])) {
      $options['length'] = 255;
    }
    if (!isset($options['null'])) {
      $options['null'] = false;
    }
    if (!isset($options['default'])) {
      $options['default'] = $options['null'] ? null : '';
    }
    
    switch ($options['type']) {
      case 'string':
        $typeSql = 'varchar(' . $options['length'] . ')';
        break;
      case 'date':
        $typeSql = 'date';
        break;
      case 'datetime':
        $typeSql = 'datetime';
        break;
      case 'text':
        $typeSql = 'text';
        break;
      case 'mediumtext':
        $typeSql = 'mediumtext';
        break;
      case 'bool':
        $typeSql = 'tinyint(1)';
        $options['null'] = false;
        break;
      case 'int':
        $typeSql = 'int(11)';
        break;
      case 'blob':
        $typeSql = 'blob';
        break;
      case 'uint':
        $typeSql = 'int(11) unsigned';
        break;
      default:
        throw new \exception('Invalid type ' . $options['type']);
    }
    
    $nullSql = $options['null'] ? '' : 'NOT NULL';
    
    
    $defaultSql = $options['default'] == null ? '' : 'DEFAULT :default';
    
    $q = $this->pdo->prepare("
      ALTER TABLE `$entity` ADD `$name` $typeSql $nullSql $defaultSql;
    ");
    
    $q->execute([
      'default' => $options['default']
    ]);
  }
  
  public function changeColumn($entity, $oldName, $newName, array $options=['type'=>'string', 'length'=>255, 'null'=>false, 'default'=>''])
  {
    $this->checkEntity($entity);
    
    if (!isset($options['type'])) {
      $options['type'] = 'string';
    }
    if (!isset($options['length'])) {
      $options['length'] = 255;
    }
    if (!isset($options['null'])) {
      $options['null'] = false;
    }
    if (!isset($options['default'])) {
      $options['default'] = $options['null'] ? null : '';
    }
    
    switch ($options['type']) {
      case 'string':
        $typeSql = 'varchar(' . $options['length'] . ')';
        break;
      case 'date':
        $typeSql = 'date';
        break;
      case 'datetime':
        $typeSql = 'datetime';
        break;
      case 'text':
        $typeSql = 'text';
        break;
      case 'mediumtext':
        $typeSql = 'mediumtext';
        break;
      case 'bool':
        $typeSql = 'tinyint(1)';
        $options['null'] = false;
        break;
      case 'int':
        $typeSql = 'int(11)';
        break;
      case 'blob':
        $typeSql = 'blob';
        break;
      case 'uint':
        $typeSql = 'int(11) unsigned';
        break;
      default:
        throw new \exception('Invalid type ' . $options['type']);
    }
    
    $nullSql = $options['null'] ? '' : 'NOT NULL';
    
    
    $defaultSql = $options['default'] == null ? '' : 'DEFAULT :default';
    
    $q = $this->pdo->prepare("
      ALTER TABLE `$entity` CHANGE `$oldName` `$newName` $typeSql $nullSql $defaultSql;
    ");
    
    $q->execute([
      'default' => $options['default']
    ]);
  }
  
  public function removeColumn($entity, $column)
  {
    $this->checkEntity($entity);
    
    $q = $this->pdo->prepare("
      ALTER TABLE `$entity` DROP `$column`;

    ");
    
    $q->execute();
  }
  
  public function addIndex($entity, $columns, $unique)
  {
    $this->checkEntity($entity);
    
    $columnsSql = '';
    foreach ($columns as $column) {
      if ($columnsSql != '') {
        $columnsSql .= ', ';
      }
      
      $columnsSql .= '`' . $column . '`';
    }
    
    $uniqueSql = $unique ? 'unique' : '';
    
    $this->pdo->prepare("
      alter table `$entity` add $uniqueSql index ($columnsSql)
    ")->execute();
  }
  
  public function addConstraint($entity, $foreignEntity, $name=false)
  {
    if (is_array($foreignEntity)) {
      list($foreignEntity, $foreignEntityColumn) = $foreignEntity;
    } else {
      $foreignEntityColumn = 'id';
    }
    $this->checkEntity($foreignEntity);
    
    if (is_array($entity)) {
      list($entity, $entityColumn) = $entity;
    } else {
      $entityColumn = lcfirst($foreignEntity) . 'Id';
    }
    $this->checkEntity($entity);
    
    if (!$name) {
      $name = "{$entity}_{$entityColumn}";
    }
    
    $this->pdo->prepare("
      SET FOREIGN_KEY_CHECKS=0
    ")->execute();
    
    $this->pdo->prepare("
      alter table `$entity`
      add constraint `$name`
      foreign key (`$entityColumn`)
      references `$foreignEntity` (`$foreignEntityColumn`)
    ")->execute();
    
    $this->pdo->prepare("
      SET FOREIGN_KEY_CHECKS=1
    ")->execute();
  }
  
  public function removeConstraint($entity, $foreignEntity, $name=false)
  {
    if (is_array($foreignEntity)) {
      list($foreignEntity, $foreignEntityColumn) = $foreignEntity;
    } else {
      $foreignEntityColumn = 'id';
    }
    $this->checkEntity($foreignEntity);
    
    if (is_array($entity)) {
      list($entity, $entityColumn) = $entity;
    } else {
      $entityColumn = lcfirst($foreignEntity) . 'Id';
    }
    $this->checkEntity($entity);
    
    if (!$name) {
      $name = "{$entity}_{$entityColumn}";
    }
    
    $this->pdo->prepare("
      SET FOREIGN_KEY_CHECKS=0
    ")->execute();
    
    $this->pdo->prepare("
      alter table `$entity`
      drop foreign key `$name`
    ")->execute();
    
    $this->pdo->prepare("
      SET FOREIGN_KEY_CHECKS=1
    ")->execute();
  }
  
  public function begin()
  {
    $this->pdo->beginTransaction();
  }
  
  public function commit()
  {
    $this->pdo->commit();
  }
  
  public function rollBack()
  {
    $this->pdo->rollBack();
  }
}
