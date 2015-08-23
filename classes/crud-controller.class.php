<?

namespace F;

/**
 * 
 * Fierce Web Framework
 * https://github.com/abhibeckert/Fierce
 *
 * This is free and unencumbered software released into the public domain.
 * For more information, please refer to http://unlicense.org
 * 
 */

class CrudController extends PageController
{
  public $entity = false;
  public $listFields = ['name' => 'Name'];
  public $mainTpl = 'main-admin.tpl';
  
  public $noun = false;
  public $nounPlural = false;
  
  public function __construct()
  {
    Auth::requireAdmin();
    
    if (!$this->noun) {
      $this->noun = preg_replace('/^.+\\\/', '', $this->entity);
    }
    if (!$this->nounPlural) {
      $this->nounPlural = $this->noun . 's';
    }
    
    if (!$this->displayName) {
      $this->displayName = 'Manage ' . $this->nounPlural;
    }
    
    parent::__construct();
  }
  
  public function defaultAction()
  {
    global $db;
    
    $entity = $this->entity;
    
    $items = $db->$entity->getIndexRows('crud');
    if (!$items) {
      $items = $entity::all('modified');
    }
    
    $displayField = array_keys($this->listFields)[0];
    
    $this->display('crud-list.tpl', get_defined_vars());
  }
  
  public function addAction()
  {
    $entity = $this->entity;
    $item = $entity::createNew();
    
    $this->beforeEditOrAdd($item);
    
    $formType = 'Add';

    $this->display($this->editTpl, get_defined_vars());
  }
  
  public function addSubmitAction()
  {
    $entity = $this->entity;
    $item = $entity::createNew();
    
    $postData = $_POST;
    $this->beforeSave($item, $postData);
    
    $item->setData($postData);
    $item->save();
    
    $this->afterSave($item);
    
    HTTP::redirect($this->url());
  }
  
  public function editAction()
  {
    $entity = $this->entity;
    $item = $entity::createById(@$_GET['id']);
    
    $this->beforeEditOrAdd($item);
    
    $formType = 'Edit';
    
    $this->display($this->editTpl, get_defined_vars());
  }
  
  public function beforeEditOrAdd($item)
  {
    
  }
  
  public function editSubmitAction()
  {
    $entity = $this->entity;
    $item = $entity::createById(@$_GET['id']);
    
    $postData = $_POST;
    $this->beforeSave($item, $postData);
    
    $item->setData($postData);
    $item->save();
    
    $this->afterSave($item);
    
    HTTP::redirect($this->url());
  }
  
  public function deleteAction()
  {
    $entity = $this->entity;
    $item = $entity::createById(@$_GET['id']);
    
    $item->archive();
    
    $this->afterSave($item);
    
    HTTP::redirect($this->url());
  }
  
  public function beforeSave($item, &$data)
  {
  }
  
  public function afterSave($item)
  {
  }
}
