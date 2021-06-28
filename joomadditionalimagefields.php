<?php
// $HeadURL: https://joomgallery.org/svn/joomgallery/JG-2.0/Plugins/JoomAdditionalImageFields/trunk/joomadditionalimagefields.php $
// $Id: joomadditionalimagefields.php 3740 2012-04-06 09:50:15Z chraneco $
/****************************************************************************************\
**   JoomAdditionalImageFields plugin 1.0                                               **
**   By: JoomGallery::ProjectTeam                                                       **
**   Copyright (C) 2012  JoomGallery::ProjectTeam                                       **
**   Based on: JoomGallery 1.0.0 by JoomGallery::ProjectTeam                            **
**   Released under GNU GPL Public License                                              **
**   License: http://www.gnu.org/copyleft/gpl.html or have a look                       **
**   at administrator/components/com_joomgallery/LICENSE.TXT                            **
\****************************************************************************************/

defined('_JEXEC') or die;

/**
 * Plugin for using custom additional fields for JoomGallery images
 *
 * @package JoomGallery
 * @since   1.0
 */
class plgJoomGalleryJoomAdditionalImageFields extends JPlugin
{
  /**
   * Constructor
   *
   * @param   object  $subject  The object to observe
   * @param   array   $config   An array that holds the plugin configuration
   * @return  void
   * @since   1.0
   */
  public function __construct(& $subject, $config)
  {
    parent::__construct($subject, $config);
    $this->loadLanguage();
    $this->checkXmlFile();
  }

  /**
   * onJoomAfterDisplayThumb event
   * Method is called by the view
   *
   * @param   object  $image  Image data of the image displayed
   * @return  string  HTML code which will be placed inside 'ul' tags
   * @since   1.0
   */
  public function onJoomAfterDisplayThumb($image)
  {
    if(!$this->params->get('display_after_thumb', 1))
    {
      return '';
    }

    $html = '';
    if(!$fields = $this->getAdditionalData($image))
    {
      return $html;
    }

    $html = '<li>'.implode('</li><li>', $fields).'</li>';

    return $html;
  }

  /**
   * onJoomAfterDisplayDetailImage event
   * Method is called by the view (default detail view)
   *
   * @param   object  $image  Image data of the image displayed
   * @return  string  HTML code which will be placed inside 'div' tags in default detail view
   * @since   1.0
   */
  public function onJoomAfterDisplayDetailImage($image)
  {
    if(!$this->params->get('display_after_detailimage', 1))
    {
      return '';
    }

    $html = '';
    $separator = '<div class="sectiontableentry1">
        <div class="jg_photo_left">
          %1$s
        </div>
        <div class="jg_photo_right">
          %2$s
        </div>
      </div>';

    if(!$fields = $this->getAdditionalData($image, $separator))
    {
      return $html;
    }

    $html = implode("\n", $fields);

    return $html;
  }

  /**
   * onContentPrepareForm event
   * Method is called after the form was instantiated
   *
   * @param   object  $form The form to be altered
   * @param   array   $data The associated data for the form
   * @return  boolean True on success, false otherwise
   * @since   1.0
   */
  public function onContentPrepareForm($form, $data)
  {
    if(!($form instanceof JForm))
    {
      $this->_subject->setError('JERROR_NOT_A_FORM');

      return false;
    }

    // Check we are manipulating a valid form
    $name = $form->getName();
    if(!in_array($name, array(_JOOM_OPTION.'.image', _JOOM_OPTION.'.edit', _JOOM_OPTION.'.editimages')))
    {
      return true;
    }

    // Add the registration fields to the form
    JForm::addFormPath(dirname(__FILE__).'/additionalfields');
    $form->loadFile('additional', false);

    return true;
  }

  /**
   * onContentPrepareData event
   * Method is called when data is retrieved for preparing a form
   *
   * @param   string  $context  The context for the data
   * @param   object  $data     The image data object
   * @return  void
   * @since   1.0
   */
  public function onContentPrepareData($context, $data)
  {
    // Check if we are manipulating a valid form
    if(!in_array($context, array(_JOOM_OPTION.'.image', _JOOM_OPTION.'.edit', _JOOM_OPTION.'.image.batch')))
    {
      return;
    }

    if(!is_array($data))
    {
      // single image form
      $datas = array($data);
    }
    else
    {
      // batch image form
      $datas = $data;
    }

    foreach($datas as $data)
    {
      if(is_object($data) && !isset($data->additional) && isset($data->id) && $data->id)
      {
        // Load the profile data from the database.
        $db = JFactory::getDbo();
        $query = $db->getQuery(true)
              ->select('details_key, details_value')
              ->from(_JOOM_TABLE_IMAGE_DETAILS)
              ->where('id = '.(int) $data->id)
              ->where('details_key LIKE '.$db->q('additional.%'))
              ->order('ordering');
        $db->setQuery($query);
        $results = $db->loadRowList();

        // Check for a database error.
        if($db->getErrorNum())
        {
          $this->_subject->setError($db->getErrorMsg());

          return;
        }

        // Merge the profile data
        $data->additional = array();

        JForm::addFormPath(dirname(__FILE__).'/additionalfields');
        $form = JForm::getInstance('plg_joomadditionalimagefields.form', 'additional');
        foreach($results as $v)
        {
          $k = str_replace('additional.', '', $v[0]);
          if($form->getField($k, 'additional'))
          {
            $data->additional[$k] = $v[1];
          }
        }
      }
    }

    if(count($datas) == 1)
    {
      // single image form
      $data = $datas[0];
    }
    else
    {
      // batch image form
      $data = $datas;
    }
  }

  /**
   * onContentAfterSave event
   * Method is called after an image was stored successfully
   *
   * @param   string  $context  The context of the store action
   * @param   object  $table    The table object which was used for storing the image
   * @param   boolean $isNew    Determines wether it is a new image which was stored
   * @return  void
   * @since   1.0
   */
  public function onContentAfterSave($context, &$table, $isNew)
  {
    if(!isset($table->id) || !$table->id || !in_array($context, array(_JOOM_OPTION.'.image', _JOOM_OPTION.'.image.batch')))
    {
      return;
    }

    try
    {
      if($context == _JOOM_OPTION.'.image.batch')
      {
        // get old data
        $db = JFactory::getDBO();
        $query = $db->getQuery(true)
              ->select($db->quoteName(array('details_key', 'details_value')))
              ->from($db->quoteName(_JOOM_TABLE_IMAGE_DETAILS))
              ->where($db->quoteName('id') . ' = ' . $db->quote($table->id))
              ->where('details_key LIKE '.$db->q('additional.%'))
              ->order('ordering ASC');
        $db->setQuery($query);
        $old_data = $db->loadAssocList('details_key');
      }

      $db = JFactory::getDbo();
      $query = $db->getQuery(true)
            ->delete(_JOOM_TABLE_IMAGE_DETAILS)
            ->where('id = '.(int) $table->id)
            ->where('details_key LIKE '.$db->q('additional.%'));
      $db->setQuery($query);

      if(!$db->query())
      {
        throw new Exception($db->getErrorMsg());
      }

      $tuples = array();
      $order  = 1;

      $data = JRequest::getVar('additional', array(), 'post', 'array');

      if($context == _JOOM_OPTION.'.image.batch')
      {
        // filter the requested data (save only changed data sets)
        $change = JRequest::getVar('change', array(), 'post', 'array');

        foreach ($data as $k => $v)
        {
          if(!in_array($k,$change))
          {
            $data[$k] = $old_data['additional.'.$k]['details_value'];
          }
        }
      }

      JForm::addFormPath(dirname(__FILE__).'/additionalfields');
      $form = JForm::getInstance('plg_joomadditionalimagefields.form', 'additional');
      foreach($data as $k => $v)
      {
        if($form->getField($k, 'additional'))
        {
          $tuples[] = (int) $table->id.','.$db->q('additional.'.$k).','.$db->q($v).','.$order++;
        }
      }

      if(count($tuples))
      {
        $query->clear()
              ->insert(_JOOM_TABLE_IMAGE_DETAILS)
              ->values($tuples);
        $db->setQuery($query);

        if(!$db->query())
        {
          throw new Exception($db->getErrorMsg());
        }
      }
    }
    catch(Exception $e)
    {
      $this->_subject->setError($e->getMessage());

      return;
    }
  }

  /**
   * onJoomAfterSearchReplace event
   * Method is called after a batch search&replace was performed on an images data
   *
   * @param   string  $id           image id
   * @param   string  $searchVal    The value to be searched for
   * @param   string  $replaceVal   Value that will replace the searched value
   * @param   integer $tmp_rep      Number of performed replacements (need to be modified in this method)
   * @return  void
   * @since   3.2
   */
  public function onJoomAfterSearchReplace($id, $searchVal, $replaceVal, $fields, &$tmp_rep)
  {
    if(!in_array('additional', $fields))
    {
      return;
    }

    try
    {
      // get current additional data
      $db = JFactory::getDBO();
      $query = $db->getQuery(true)
            ->select($db->quoteName(array('details_key', 'details_value')))
            ->from($db->quoteName(_JOOM_TABLE_IMAGE_DETAILS))
            ->where($db->quoteName('id') . ' = ' . $db->quote($id))
            ->where('details_key LIKE '.$db->q('additional.%'))
            ->order('ordering ASC');
      $db->setQuery($query);
      $datalist = $db->loadAssocList('details_key');

      if(!$db->query())
      {
        throw new Exception($db->getErrorMsg());
      }

      $tuples = array();
      $order  = 1;

      foreach ($datalist as $key => $data)
      {
        $replacements = 0;

        // Replace image informations
        $value = str_replace($searchVal,$replaceVal,$data['details_value'],$replacements);
        $tuples[] = (int) $id.','.$db->q($key).','.$db->q($value).','.$order++;

        // count replacements
        $tmp_rep = $tmp_rep + $replacements;
      }

      // delete old additional data
      $query->clear()
            ->delete(_JOOM_TABLE_IMAGE_DETAILS)
            ->where('id = '.(int) $id)
            ->where('details_key LIKE '.$db->q('additional.%'));
      $db->setQuery($query);

      if(!$db->query())
      {
        throw new Exception($db->getErrorMsg());
      }

      // insert new additional data
      if(count($tuples))
      {
        $query->clear()
              ->insert(_JOOM_TABLE_IMAGE_DETAILS)
              ->values($tuples);
        $db->setQuery($query);

        if(!$db->query())
        {
          throw new Exception($db->getErrorMsg());
        }
      }
    }
    catch(Exception $e)
    {
      $this->_subject->setError($e->getMessage());

      return;
    }
  }

  /**
   * Removes all additional image data for the given image ID
   *
   * Method is called after an image is deleted from the database
   *
   * @param   string  $context  The context of the delete action
   * @param   object  $table    The table object which was used for deleting the image
   * @return  void
   * @since   1.0
   */
  public function onContentAfterDelete($context, $table)
  {
    if(!isset($table->id) || !$table->id || $context != _JOOM_OPTION.'.image')
    {
      return;
    }

    try
    {
      $db = JFactory::getDbo();
      $query = $db->getQuery(true)
            ->delete(_JOOM_TABLE_IMAGE_DETAILS)
            ->where('id = '.(int) $table->id)
            ->where('details_key LIKE '.$db->q('additional.%'));
      $db->setQuery($query);

      if(!$db->query())
      {
        throw new Exception($db->getErrorMsg());
      }
    }
    catch(Exception $e)
    {
      $this->_subject->setError($e->getMessage());

      return;
    }
  }

  /**
   * onJoomSearch event
   * Event is triggered whenever a search in JoomGallery contents is done
   *
   * @param   string  $searchstring The escaped search string
   * @param   array   $aliase       An array containing database table aliases for used JoomGallery tables
   * @param   string  $context      The context in which the search is done
   * @return  array   An associative array containing additional query parts for the search query
   * @since   1.0
   */
  public function onJoomSearch($searchstring, $aliases, $context = '')
  {
    return array( 'images.where.or' => 'LOWER(additionalimage.details_value) LIKE \'%'.$searchstring.'%\'',
                  'images.leftjoin' =>  _JOOM_TABLE_IMAGE_DETAILS.' AS additionalimage ON additionalimage.id = '.$aliases['images'].'.id AND additionalimage.details_key LIKE \'additional.%\'');
  }

  /**
   * onJoomGetImageDetailsPrefix event
   *
   * Not used yet. Could be called by maintenance functions for detecting
   * used prefixes in details database table in order to clean it up
   *
   * @return  array An array of used prefixes in details table
   * @since   1.0
   */
  public function onJoomGetImageDetailsPrefixes()
  {
    return array('additional');
  }

  /**
   * onJoomAfterPrepareDisplayHTML event
   * Method is called at the end of the view method (view.html.php)
   *
   * Important Note: Please push the additional data into $itemObj->additionalData
   *
   * @param   string   $viewType  View variable
   * @param   integer  $id   id of the item
   * @param   array    $addData array with the additional data
   * @return  bool    True on success, false otherwise
   * @since   1.0
   */
  public function onJoomAfterPrepareDisplayHTML($viewType, $id, &$addData)
  {
    if($viewType == 'detail.image')
    {
      $addData['AdditionalImageFields'] = $this->getAdditionalData($id, null);
    }

    return true;
  }

  /**
   * Internal method for retrieving and formatting additional data
   * for output in frontend
   *
   * @param   object  $image      Image data of the image for which the additional data shall be retieved
   * @param   string  $separator  String for formatting the output
   * @return  array   An array of formatted additional data fields
   * @since   1.0
   */
  protected function getAdditionalData($image, $separator = '%1$s: %2$s')
  {
    static $form = null;

    if(is_null($form))
    {
      JForm::addFormPath(dirname(__FILE__).'/additionalfields');
      $form = JForm::getInstance('plg_joomadditionalimagefields.form', 'additional');
    }

    if(is_object($image) && isset($image->id))
    {
      $image = $image->id;
    }

    // Load the additional data from the database
    $db = JFactory::getDbo();
    $query = $db->getQuery(true)
          ->select('details_key, details_value')
          ->from(_JOOM_TABLE_IMAGE_DETAILS)
          ->where('id = '.(int) $image)
          ->where('details_key LIKE '.$db->q('additional.%'))
          ->order('ordering');
    $db->setQuery($query);
    $results = $db->loadRowList();

    // Check for a database error
    if($db->getErrorNum())
    {
      $this->_subject->setError($db->getErrorMsg());

      return false;
    }

    $fields = array();
    foreach($results as $result)
    {
      $k = str_replace('additional.', '', $result[0]);
      if($field = $form->getField($k, 'additional'))
      {
        if(empty($separator))
        {
          $data = array('label'=>$field->getAttribute('label'), 'value'=>$result[1]);
          $fields[$k] = (object)$data;
        }
        else
        {
          switch($field->type)
          {

            // Create behavior for each field type

            case 'text':
            default:
              if(strlen($result[1]) > 0)
              {
                $key  = 'PLG_JOOMADDITIONALIMAGEFIELDS_FIELD_'.strtoupper($k).'_VAR';
                if(JFactory::getLanguage()->hasKey($key))
                {
                  $fields[] = JText::sprintf($key, $result[1]);
                }
                else
                {
                  $fields[] = sprintf($separator, $field->title, $result[1]);
                }
              }
              break;
          }
        }
      }
    }

    if(!count($fields))
    {
      return false;
    }

    if(empty($separator))
    {
      return (object)$fields;
    }
    else
    {
      return $fields;
    }
  }

  /**
   * Checks existence of the XML file and outputs a notice if it was not found
   *
   * Additional this plugin is detached from all events, so it won't be called anymore
   *
   * @return  boolean True if file was found, false otherwise
   * @since   1.1
   */
  protected function checkXmlFile()
  {
    if(!is_file(dirname(__FILE__).'/additionalfields/additional.xml'))
    {
      JFactory::getApplication()->enqueueMessage(JText::_('PLG_JOOMGALLERY_JOOMADDITIONALIMAGEFIELDS_MISSING_XML_FILE'), 'warning');

      $this->_subject->detach($this);
    }
  }
}
