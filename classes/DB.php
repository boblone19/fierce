<?php

/**
 * 
 * Fierce Web Framework
 * https://github.com/abhibeckert/Fierce
 *
 * This is free and unencumbered software released into the public domain.
 * For more information, please refer to http://unlicense.org
 * 
 */

namespace Fierce;

class DB
{
  public $type;
  public $connection;
  
  public function __construct($type, $pathOrDsn, $username=null, $password=null)
  {
    $this->type = $type;
    
    switch ($type) {
      case 'file':
        $this->connection = new DBConnectionFile($pathOrDsn);
        break;
      case 'pdo':
        $this->connection = new DBConnectionPdo($pathOrDsn, $username, $password);
        break;
      default:
        throw new \exception('invalid type ' . $type);
    }
  }
  
  public function __get($entity)
  {
    $entity = preg_replace('/^Fierce\\\/', '', $entity);
    
    return $this->$entity = new DBEntity($this->connection, $entity);
  }
  
  public function id()
  {
    $id = openssl_random_pseudo_bytes(16);
    $id[6] = chr(ord($id[6]) & 0x0f | 0x40); // set version to 0100
    $id[8] = chr(ord($id[8]) & 0x3f | 0x80); // set bits 6-7 to 10
    $id = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($id), 4));
    
    return $id;
  }
  
  public function begin()
  {
    $this->connection->begin();
  }
  
  public function commit()
  {
    $this->connection->commit();
  }
  
  public function rollBack()
  {
    $this->connection->rollBack();
  }
}
