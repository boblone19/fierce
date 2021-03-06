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

class RedirectController extends PageController
{
  public function actionForCurrentRequest()
  {
    return 'default';
  }
  
  public function defaultAction()
  {
    $url = $this->page->content;
    
    HTTP::redirect($url);
  }
}
